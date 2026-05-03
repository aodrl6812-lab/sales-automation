<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function run_process_orders(int $jobId, string $from, string $to): void
{
    step1_collect_orders($jobId, $from, $to);
    step2_normalize($jobId, $from, $to);
    step4_make_lozen_file($jobId);
}

function coupang_auth_header(string $accessKey, string $secretKey, string $method, string $path, string $query): array
{
    $datetime = gmdate('ymd\\THis\\Z');
    $message = $datetime . $method . $path . $query;
    $signature = hash_hmac('sha256', $message, $secretKey);

    $authorization = "CEA algorithm=HmacSHA256, access-key={$accessKey}, signed-date={$datetime}, signature={$signature}";

    return [$authorization, $datetime];
}


function coupang_get_json_with_retry(string $url, array $headers, int $maxAttempts = 3): array
{
    $maxAttempts = max(1, $maxAttempts);    
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        $curlErr = curl_errno($ch);
        $curlMsg = $curlErr ? curl_error($ch) : '';
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
            
        if ($curlErr !== 0) {
            if ($attempt < $maxAttempts) {
                usleep(150000 * $attempt);
                continue;
            }
            return ['ok' => false, 'http' => 0, 'json' => null, 'raw' => (string)$response, 'error' => 'curl: ' . $curlMsg];
        }
        
        $decoded = json_decode((string)$response, true);
        if ($httpCode === 200 && is_array($decoded)) {
            return ['ok' => true, 'http' => $httpCode, 'json' => $decoded, 'raw' => (string)$response, 'error' => ''];
        }

        $retryable = in_array($httpCode, [429, 500, 502, 503, 504], true) || !is_array($decoded);
        if ($retryable && $attempt < $maxAttempts) {
            usleep(200000 * $attempt);
            continue;
        }

        return ['ok' => false, 'http' => $httpCode, 'json' => is_array($decoded) ? $decoded : null, 'raw' => (string)$response, 'error' => 'http=' . $httpCode];
    }

    return ['ok' => false, 'http' => 0, 'json' => null, 'raw' => '', 'error' => 'unknown'];
}

function step1_collect_orders(int $jobId, string $from, string $to): void
{
    $pdo = db();
    $activeSites = [];

    try {
        $rows = $pdo->query(
            'SELECT site_code
             FROM site_settings
             WHERE is_active = 1'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $siteCode = strtolower(trim((string)($row['site_code'] ?? '')));
            if ($siteCode !== '') {
                $activeSites[$siteCode] = true;
            }
        }
    } catch (Throwable $e) {
        job_log($jobId, 'warn', 'site_settings lookup failed. fallback=coupang');
    }

    if (!$activeSites) {
        $activeSites = ['coupang' => true];
    }

    [$fromDate, $toDate, $fromDateTime, $toDateTime] = step1_resolve_collection_range($to);
    job_log($jobId, 'info', "Collection range unified: {$fromDateTime} ~ {$toDateTime}");

    if (isset($activeSites['coupang'])) {
        collect_coupang_orders($jobId, $fromDate, $toDate);
    }

    if (isset($activeSites['smartstore'])) {
        require_once __DIR__ . '/collect_naver_orders.php';
        if (function_exists('run_collect_naver_orders')) {
            run_collect_naver_orders($jobId, $fromDateTime, $toDateTime);
        } else {
            job_log($jobId, 'error', 'run_collect_naver_orders not found');
        }
    }

    foreach (array_keys($activeSites) as $siteCode) {
        if (!in_array($siteCode, ['coupang', 'smartstore'], true)) {
            job_log($jobId, 'info', 'step1 skip unsupported site: ' . $siteCode);
        }
    }
}

function collect_coupang_orders(int $jobId, string $fromDate, string $toDate): void
{
    $pdo = db();

    $env = envv('APP_ENV', 'local');
    $vendorId = envv('COUPANG_VENDOR_ID');

    if ($env === 'prod') {
        $accessKey = envv('COUPANG_ACCESS_KEY_PROD');
        $secretKey = envv('COUPANG_SECRET_KEY_PROD');
    } else {
        $accessKey = envv('COUPANG_ACCESS_KEY_DEV');
        $secretKey = envv('COUPANG_SECRET_KEY_DEV');
    }

    if ($accessKey === '' || $secretKey === '' || $vendorId === '') {
        job_log($jobId, 'error', 'COUPANG env var missing (.env check)');
        return;
    }

    job_log($jobId, 'info', 'Coupang order collection started');

    $method = 'GET';
    $path = "/v2/providers/openapi/apis/api/v4/vendors/{$vendorId}/ordersheets";

    $ins = $pdo->prepare(
        "INSERT INTO orders_raw (platform, order_no, raw_json, ordered_at)
         VALUES ('coupang', ?, ?, NOW())
         ON DUPLICATE KEY UPDATE raw_json = VALUES(raw_json)"
    );

    $nextToken = null;
    $saved = 0;
    $loop = 0;

    do {
        $params = [
            'createdAtFrom' => $fromDate,
            'createdAtTo' => $toDate,
            'status' => 'ACCEPT',
        ];

        if ($nextToken) {
            $params['nextToken'] = $nextToken;
        }

        $query = http_build_query($params);

        [$authorization] = coupang_auth_header(
            $accessKey,
            $secretKey,
            $method,
            $path,
            $query
        );

        $url = "https://api-gateway.coupang.com{$path}?{$query}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "Authorization: {$authorization}",
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            job_log($jobId, 'error', "HTTP {$httpCode}");

            $body = (string)$response;
            if (!mb_check_encoding($body, 'UTF-8')) {
                job_log($jobId, 'error', 'Non UTF-8 response len=' . strlen($body) . ' sha1=' . sha1($body));
            } else {
                job_log($jobId, 'error', mb_substr($body, 0, 2000));
            }
            return;
        }

        $dataArray = json_decode((string)$response, true);
        if (!is_array($dataArray) || !isset($dataArray['data'])) {
            job_log($jobId, 'warn', 'Response parse failed');
            return;
        }

        $rows = $dataArray['data'];
        job_log($jobId, 'info', 'API row count: ' . count($rows));

        foreach ($rows as $row) {
            $orderNo = $row['orderId']
                ?? $row['orderNo']
                ?? $row['orderNumber']
                ?? null;
            if (!$orderNo) {
                continue;
            }

            $ins->execute([
                (string)$orderNo,
                json_encode($row, JSON_UNESCAPED_UNICODE),
            ]);

            $saved++;
        }

        $nextToken = $dataArray['nextToken'] ?? null;
        $loop++;

        job_log(
            $jobId,
            'info',
            'loop=' . $loop . ' nextToken=' . ($nextToken ?? 'null')
        );
    } while ($nextToken);

    job_log($jobId, 'info', 'Saved rows: ' . $saved);
    job_log($jobId, 'info', 'Coupang order collection finished');
}

function step2_normalize(int $jobId, string $from, string $to): void
{
    job_log($jobId, 'info', "Normalize started: {$from} ~ {$to}");

    $pdo = db();
    [$read, $written, $prepareIds] = step2_normalize_coupang_orders($pdo, $from, $to);

    job_log($jobId, 'info', "Read: {$read}, Written: {$written}");
    job_log($jobId, 'info', 'Normalize finished');

    [$readNaver, $writtenNaver, $smartstoreProductOrderIds] = step2_normalize_smartstore_orders($pdo, $from, $to);
    job_log($jobId, 'info', "Smartstore Read: {$readNaver}, Written: {$writtenNaver}");

    step3_prepare($jobId, $prepareIds, $smartstoreProductOrderIds);
}

function step2_normalize_coupang_orders(PDO $pdo, string $from, string $to): array
{
    $prepareIds = [];

    $stmt = $pdo->prepare(
        'SELECT id, order_no, raw_json
         FROM orders_raw
         WHERE platform = "coupang"
           AND is_normalized = 0
           AND created_at BETWEEN ? AND ?
         ORDER BY id ASC'
    );
    $stmt->execute([$from, $to]);

    $up = $pdo->prepare(
        'INSERT INTO coupang_order_excel (
            order_no, option_id, qty, ordered_at, carrier_name, tracking_no,
            buyer_name, buyer_phone, receiver_name, receiver_phone,
            zipcode, receiver_address, delivery_message, shipment_box_id, source_file
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            option_id = VALUES(option_id),
            qty = VALUES(qty),
            ordered_at = VALUES(ordered_at),
            carrier_name = VALUES(carrier_name),
            tracking_no = VALUES(tracking_no),
            buyer_name = VALUES(buyer_name),
            buyer_phone = VALUES(buyer_phone),
            receiver_name = VALUES(receiver_name),
            receiver_phone = VALUES(receiver_phone),
            zipcode = VALUES(zipcode),
            receiver_address = VALUES(receiver_address),
            delivery_message = VALUES(delivery_message),
            shipment_box_id = VALUES(shipment_box_id)'
    );

    $read = 0;
    $written = 0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $read++;

        $raw = json_decode((string)$row['raw_json'], true);
        if (!is_array($raw)) {
            continue;
        }

        $orderedAt = $raw['orderedAt'] ?? null;
        if (!$orderedAt) {
            continue;
        }

        $orderedAt2 = str_replace('T', ' ', $orderedAt);

        $receiver = $raw['receiver'] ?? [];
        $orderer = $raw['orderer'] ?? [];

        $carrier = 'KGB';
        $tracking = $raw['invoiceNumber'] ?? null;

        $buyerName = $orderer['name'] ?? null;
        $buyerPhone = $orderer['safeNumber'] ?? ($orderer['ordererNumber'] ?? null);

        $recvName = $receiver['name'] ?? null;
        $recvPhone = $receiver['safeNumber'] ?? ($receiver['receiverNumber'] ?? null);

        $zipcode = $receiver['postCode'] ?? null;
        $addr1 = $receiver['addr1'] ?? null;
        $addr2 = $receiver['addr2'] ?? null;

        $fullAddr = trim((string)$addr1 . ' ' . (string)$addr2);

        $deliveryMsg = $raw['parcelPrintMessage'] ?? null;
        $shipmentBoxId = $raw['shipmentBoxId'] ?? null;

        $orderItems = $raw['orderItems'] ?? [];

        foreach ($orderItems as $item) {
            $optionId = $item['vendorItemId'] ?? null;
            $qty = $item['shippingCount'] ?? 1;

            if (!$optionId) {
                continue;
            }

            $up->execute([
                (string)$row['order_no'],
                (string)$optionId,
                (int)$qty,
                $orderedAt2,
                $carrier,
                $tracking,
                $buyerName,
                $buyerPhone,
                $recvName,
                $recvPhone,
                $zipcode,
                $fullAddr,
                $deliveryMsg,
                $shipmentBoxId,
                'API',
            ]);

            $written++;
        }

        if ($shipmentBoxId) {
            $prepareIds[$shipmentBoxId] = $shipmentBoxId;
        }

        $pdo->prepare(
            'UPDATE orders_raw
             SET is_normalized = 1,
                 normalized_at = NOW()
             WHERE id = ?'
        )->execute([$row['id']]);
    }

    return [$read, $written, array_values($prepareIds)];
}

function step2_normalize_smartstore_orders(PDO $pdo, string $from, string $to): array
{
    ensure_smartstore_delivery_message_column($pdo);
    $successCheckStmt = $pdo->prepare(
        'SELECT 1
         FROM smartstore_order_excel
         WHERE BINARY product_order_no = BINARY ?
           AND COALESCE(dispatch_result, "") = "SUCCESS"
         LIMIT 1'
    );

    $stmtNaver = $pdo->prepare(
        'SELECT r.id, r.order_no, r.raw_json
         FROM orders_raw r
         WHERE r.platform = "smartstore"
           AND r.created_at BETWEEN ? AND ?
           AND (
               r.is_normalized = 0
               OR NOT EXISTS (
                   SELECT 1
                   FROM smartstore_order_excel s
                   WHERE BINARY s.order_no = BINARY r.order_no
               )
               OR EXISTS (
                   SELECT 1
                   FROM smartstore_order_excel s
                   WHERE BINARY s.order_no = BINARY r.order_no
                     AND (
                         COALESCE(s.receiver_name, "") = ""
                         OR COALESCE(s.receiver_phone1, "") = ""
                         OR COALESCE(s.address_text, "") = ""
                         OR COALESCE(s.delivery_message, "") = ""
                         OR BINARY COALESCE(s.option_info, "") = BINARY COALESCE(s.product_order_no, "")
                     )
               )
           )
         ORDER BY r.id ASC'
    );
    $stmtNaver->execute([$from, $to]);

    $upNaver = $pdo->prepare(
        'INSERT INTO smartstore_order_excel (
            imported_at,
            source_file,
            product_order_no,
            order_no,
            carrier_name,
            tracking_no,
            buyer_name,
            receiver_name,
            qty,
            option_info,
            paid_at,
            buyer_phone,
            receiver_phone1,
            receiver_phone2,
            address_text,
            delivery_message,
            zipcode,
            product_no,
            product_name,
            delivery_method,
            sales_channel,
            buyer_id,
            seller_product_code
        ) VALUES (
            NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            source_file = VALUES(source_file),
            order_no = VALUES(order_no),
            carrier_name = VALUES(carrier_name),
            tracking_no = VALUES(tracking_no),
            buyer_name = VALUES(buyer_name),
            receiver_name = VALUES(receiver_name),
            qty = VALUES(qty),
            option_info = VALUES(option_info),
            paid_at = VALUES(paid_at),
            buyer_phone = VALUES(buyer_phone),
            receiver_phone1 = VALUES(receiver_phone1),
            receiver_phone2 = VALUES(receiver_phone2),
            address_text = VALUES(address_text),
            delivery_message = VALUES(delivery_message),
            zipcode = VALUES(zipcode),
            product_no = VALUES(product_no),
            product_name = VALUES(product_name),
            delivery_method = VALUES(delivery_method),
            sales_channel = VALUES(sales_channel),
            buyer_id = VALUES(buyer_id),
            seller_product_code = VALUES(seller_product_code)'
    );

    $readNaver = 0;
    $writtenNaver = 0;
    $prepareProductOrderIds = [];

    while ($row = $stmtNaver->fetch(PDO::FETCH_ASSOC)) {
        $readNaver++;

        $raw = json_decode((string)$row['raw_json'], true);
        if (!is_array($raw)) {
            continue;
        }

        $sourceNaver = is_array($raw['_source']['naver'] ?? null) ? $raw['_source']['naver'] : [];
        $sourceContent = is_array($sourceNaver['content'] ?? null) ? $sourceNaver['content'] : [];
        $sourceOrder = is_array($sourceContent['order'] ?? null) ? $sourceContent['order'] : [];
        $sourceProductOrder = is_array($sourceContent['productOrder'] ?? null) ? $sourceContent['productOrder'] : [];
        if (!$sourceOrder && is_array($sourceNaver['order'] ?? null)) {
            $sourceOrder = $sourceNaver['order'];
        }
        if (!$sourceProductOrder && is_array($sourceNaver['productOrder'] ?? null)) {
            $sourceProductOrder = $sourceNaver['productOrder'];
        }

        $orderedAt = trim((string)(
            $raw['orderedAt']
            ?? $sourceOrder['paymentDate']
            ?? $sourceOrder['orderDate']
            ?? $sourceProductOrder['orderDate']
            ?? ''
        ));
        $paidAt = null;
        if ($orderedAt !== '') {
            $paidAtTs = strtotime($orderedAt);
            if ($paidAtTs !== false) {
                $paidAt = date('Y-m-d H:i:s', $paidAtTs);
            }
        }
        if ($paidAt === null) {
            $paidAt = date('Y-m-d H:i:s');
        }

        $orderer = is_array($raw['orderer'] ?? null) ? $raw['orderer'] : [];
        $receiver = is_array($raw['receiver'] ?? null) ? $raw['receiver'] : [];
        $items = is_array($raw['orderItems'] ?? null) ? $raw['orderItems'] : [];
        $firstItem = isset($items[0]) && is_array($items[0]) ? $items[0] : [];
        $firstItemRaw = is_array($firstItem['raw'] ?? null) ? $firstItem['raw'] : [];

        $sourceShipping = is_array($sourceProductOrder['shippingAddress'] ?? null) ? $sourceProductOrder['shippingAddress'] : [];
        $pickFirstNonEmpty = static function (...$values): string {
            foreach ($values as $value) {
                $text = trim((string)$value);
                if ($text !== '') {
                    return $text;
                }
            }
            return '';
        };

        $productOrderNo = $pickFirstNonEmpty(
            $raw['shipmentBoxId'] ?? '',
            $sourceProductOrder['productOrderId'] ?? '',
            $sourceNaver['productOrderId'] ?? ''
        );
        if ($productOrderNo === '') {
            $productOrderNo = (string)$row['order_no'];
        }
        if ($productOrderNo !== '') {
            $successCheckStmt->execute([$productOrderNo]);
            $alreadySuccess = (bool)$successCheckStmt->fetchColumn();
            if ($alreadySuccess) {
                $pdo->prepare(
                    'UPDATE orders_raw
                     SET is_normalized = 1,
                         normalized_at = NOW()
                     WHERE id = ?'
                )->execute([$row['id']]);
                continue;
            }
            $prepareProductOrderIds[$productOrderNo] = $productOrderNo;
        }

        $qtyTotal = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qtyTotal += (int)($item['shippingCount'] ?? 0);
        }
        $sourceQty = (int)(
            $sourceProductOrder['quantity']
            ?? $sourceProductOrder['orderQuantity']
            ?? 0
        );
        if ($sourceQty > $qtyTotal) {
            $qtyTotal = $sourceQty;
        }
        if ($qtyTotal <= 0) {
            $qtyTotal = 1;
        }

        $optionInfo = $pickFirstNonEmpty(
            $sourceProductOrder['optionCode'] ?? '',
            $sourceProductOrder['itemNo'] ?? '',
            $sourceProductOrder['productId'] ?? '',
            $firstItemRaw['sellerManagementCode'] ?? '',
            $sourceProductOrder['sellerManagementCode'] ?? '',
            $firstItem['vendorItemId'] ?? ''
        );
        $sellerProductCode = $pickFirstNonEmpty(
            $sourceProductOrder['optionCode'] ?? '',
            $sourceProductOrder['itemNo'] ?? '',
            $firstItemRaw['sellerManagementCode'] ?? '',
            $sourceProductOrder['sellerManagementCode'] ?? ''
        );
        $productNo = $pickFirstNonEmpty(
            $firstItemRaw['productId'] ?? '',
            $firstItemRaw['channelProductId'] ?? '',
            $sourceProductOrder['productId'] ?? '',
            $sourceProductOrder['channelProductId'] ?? ''
        );
        $productName = $pickFirstNonEmpty(
            $firstItemRaw['productName'] ?? '',
            $firstItemRaw['channelProductName'] ?? '',
            $sourceProductOrder['productName'] ?? '',
            $sourceProductOrder['channelProductName'] ?? ''
        );
        $carrierName = $pickFirstNonEmpty(
            $raw['carrierName'] ?? '',
            $sourceProductOrder['deliveryCompanyCode'] ?? ''
        );
        $trackingNo = $pickFirstNonEmpty(
            $raw['invoiceNumber'] ?? '',
            $sourceProductOrder['trackingNumber'] ?? ''
        );
        $buyerName = $pickFirstNonEmpty(
            $orderer['name'] ?? '',
            $sourceOrder['ordererName'] ?? ''
        );
        $buyerId = trim((string)($sourceOrder['ordererId'] ?? ''));
        $buyerPhone = $pickFirstNonEmpty(
            $orderer['safeNumber'] ?? '',
            $orderer['ordererNumber'] ?? '',
            $sourceOrder['ordererTel'] ?? '',
            $sourceOrder['ordererTel1'] ?? '',
            $sourceOrder['ordererTel2'] ?? ''
        );
        $receiverName = $pickFirstNonEmpty(
            $receiver['name'] ?? '',
            $sourceShipping['name'] ?? ''
        );
        $receiverPhone = $pickFirstNonEmpty(
            $receiver['safeNumber'] ?? '',
            $receiver['receiverNumber'] ?? '',
            $sourceShipping['tel1'] ?? '',
            $sourceShipping['tel2'] ?? ''
        );
        $receiverPhone2 = $pickFirstNonEmpty(
            $receiver['receiverNumber'] ?? '',
            $sourceShipping['tel2'] ?? ''
        );
        $zipcode = $pickFirstNonEmpty(
            $receiver['postCode'] ?? '',
            $sourceShipping['zipCode'] ?? '',
            $sourceShipping['postCode'] ?? ''
        );
        $addressText = trim(
            $pickFirstNonEmpty($receiver['addr1'] ?? '', $sourceShipping['baseAddress'] ?? '')
            . ' '
            . $pickFirstNonEmpty($receiver['addr2'] ?? '', $sourceShipping['detailedAddress'] ?? '')
        );
        $deliveryMessage = $pickFirstNonEmpty(
            $raw['parcelPrintMessage'] ?? '',
            $sourceProductOrder['shippingMemo'] ?? '',
            $sourceOrder['deliveryMemo'] ?? ''
        );
        $deliveryMethod = trim((string)($sourceProductOrder['deliveryMethod'] ?? ''));

        $upNaver->execute([
            'NAVER_API',
            $productOrderNo,
            (string)$row['order_no'],
            $carrierName,
            $trackingNo,
            $buyerName,
            $receiverName,
            $qtyTotal,
            $optionInfo,
            $paidAt,
            $buyerPhone,
            $receiverPhone,
            $receiverPhone2,
            $addressText,
            $deliveryMessage,
            $zipcode,
            $productNo,
            $productName,
            $deliveryMethod !== '' ? $deliveryMethod : null,
            'SMARTSTORE',
            $buyerId !== '' ? $buyerId : null,
            $sellerProductCode !== '' ? $sellerProductCode : null,
        ]);

        $writtenNaver++;

        $pdo->prepare(
            'UPDATE orders_raw
             SET is_normalized = 1,
                 normalized_at = NOW()
             WHERE id = ?'
        )->execute([$row['id']]);
    }

    return [$readNaver, $writtenNaver, array_values($prepareProductOrderIds)];
}

function ensure_smartstore_delivery_message_column(PDO $pdo): void
{
    $dbName = envv('DB_NAME', 'ship_new');
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = 'smartstore_order_excel'
           AND COLUMN_NAME = 'delivery_message'
         LIMIT 1"
    );
    $stmt->execute([$dbName]);
    $exists = (bool)$stmt->fetchColumn();

    if (!$exists) {
        $pdo->exec(
            "ALTER TABLE smartstore_order_excel
             ADD COLUMN delivery_message VARCHAR(255) NULL AFTER address_text"
        );
    }
}

function step3_prepare(
    int $jobId,
    array $coupangShipmentBoxIds,
    array $smartstoreProductOrderIds = []
): void
{
    if ($coupangShipmentBoxIds) {
        $prepareChunkSize = max(1, (int)envv('COUPANG_PREPARE_CHUNK_SIZE', '10'));
        $chunks = array_chunk(array_values($coupangShipmentBoxIds), $prepareChunkSize);

        foreach ($chunks as $ids) {
            step3_prepare_coupang($jobId, $ids);
        }
    }

    if ($smartstoreProductOrderIds) {
        $prepareChunkSize = max(1, (int)envv('NAVER_PREPARE_CHUNK_SIZE', '30'));
        $chunks = array_chunk(array_values($smartstoreProductOrderIds), $prepareChunkSize);

        foreach ($chunks as $ids) {
            step3_prepare_smartstore($jobId, $ids);
        }
    }
}

function step3_prepare_coupang(int $jobId, array $shipmentBoxIds): void
{
    if (!$shipmentBoxIds) {
        return;
    }

    $env = envv('APP_ENV', 'local');
    $vendorId = envv('COUPANG_VENDOR_ID');

    if ($env === 'prod') {
        $accessKey = envv('COUPANG_ACCESS_KEY_PROD');
        $secretKey = envv('COUPANG_SECRET_KEY_PROD');
    } else {
        $accessKey = envv('COUPANG_ACCESS_KEY_DEV');
        $secretKey = envv('COUPANG_SECRET_KEY_DEV');
    }

    $path = "/v2/providers/openapi/apis/api/v4/vendors/{$vendorId}/ordersheets/acknowledgement";
    $method = 'PUT';

    $datetime = gmdate('ymd') . 'T' . gmdate('His') . 'Z';
    $message = $datetime . $method . $path;
    $signature = hash_hmac('sha256', $message, $secretKey);
    $authorization = "CEA algorithm=HmacSHA256, access-key={$accessKey}, signed-date={$datetime}, signature={$signature}";

    $url = 'https://api-gateway.coupang.com' . $path;
    $body = json_encode([
        'vendorId' => $vendorId,
        'shipmentBoxIds' => $shipmentBoxIds,
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_HTTPHEADER => [
            "Authorization: {$authorization}",
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 25,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $count = count($shipmentBoxIds);
    job_log($jobId, 'info', 'Prepare HTTP: ' . $httpCode);
    job_log($jobId, 'info', 'Prepare response: ' . $response);

    $ok = false;
    if ($httpCode === 200) {
        $json = json_decode((string)$response, true);
        $ok = is_array($json)
            && isset($json['data']['responseCode'])
            && in_array((int)$json['data']['responseCode'], [0, 1], true);
    }

    if ($ok) {
        job_log($jobId, 'success', 'Prepare success count: ' . $count);
    } else {
        job_log($jobId, 'error', 'Prepare failed count: ' . $count);
    }
}

function step3_prepare_smartstore(int $jobId, array $productOrderIds): void
{
    $productOrderIds = array_values(array_filter(array_map(
        static fn ($v) => trim((string)$v),
        $productOrderIds
    )));

    if (!$productOrderIds) {
        return;
    }

    require_once __DIR__ . '/collect_naver_orders.php';

    if (!function_exists('naver_collect_get_access_token') || !function_exists('naver_collect_request_json')) {
        job_log($jobId, 'error', 'Naver collect helpers not found');
        return;
    }

    $token = naver_collect_get_access_token($jobId);
    if ($token === '') {
        job_log($jobId, 'error', 'Naver access token unavailable');
        return;
    }

    $baseUrl = rtrim(envv('NAVER_API_BASE_URL', 'https://api.commerce.naver.com/external'), '/');
    $url = $baseUrl . '/v1/pay-order/seller/product-orders/confirm';

    $body = json_encode(
        ['productOrderIds' => $productOrderIds],
        JSON_UNESCAPED_UNICODE
    );

    if (!is_string($body) || $body === '') {
        job_log($jobId, 'error', 'Failed to build smartstore prepare request body');
        return;
    }

    $resp = naver_collect_request_json(
        $jobId,
        'POST',
        $url,
        [],
        $body,
        [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]
    );

    $count = count($productOrderIds);

    if (!$resp['ok']) {
        job_log($jobId, 'error', 'Smartstore prepare failed count: ' . $count);
        return;
    }

    $json = is_array($resp['json'] ?? null) ? $resp['json'] : [];
    $data = is_array($json['data'] ?? null) ? $json['data'] : [];

    $successIds = is_array($data['successProductOrderIds'] ?? null)
        ? $data['successProductOrderIds']
        : [];
    $failInfos = is_array($data['failProductOrderInfos'] ?? null)
        ? $data['failProductOrderInfos']
        : [];

    job_log(
        $jobId,
        'info',
        'Smartstore prepare requested=' . $count
        . ', success=' . count($successIds)
        . ', fail=' . count($failInfos)
    );

    if (!empty($failInfos)) {
        $preview = json_encode(array_slice($failInfos, 0, 5), JSON_UNESCAPED_UNICODE);
        job_log($jobId, 'warn', 'Smartstore prepare fail sample: ' . (string)$preview);
    }
}

function step4_make_lozen_file(?int $jobId = null): void
{
    $pdo = db();
    ensure_smartstore_lozen_export_column($pdo);

    job_log($jobId, 'info', 'Lozen file generation started');

    $sql = '
    SELECT *
    FROM coupang_order_excel
    WHERE COALESCE(tracking_no,"") = ""
      AND lozen_exported_at IS NULL
    ORDER BY ordered_at ASC
    ';

    $stmt = $pdo->query($sql);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sqlSmartstore = '
    SELECT *
    FROM smartstore_order_excel
    WHERE COALESCE(tracking_no,"") = ""
      AND lozen_exported_at IS NULL
    ORDER BY paid_at ASC, imported_at ASC
    ';
    $stmtSmartstore = $pdo->query($sqlSmartstore);
    $ordersSmartstore = $stmtSmartstore->fetchAll(PDO::FETCH_ASSOC);

    job_log($jobId, 'info', 'Coupang order count: ' . count($orders));
    job_log($jobId, 'info', 'Smartstore order count: ' . count($ordersSmartstore));

    if (!$orders && !$ordersSmartstore) {
        job_log($jobId, 'info', 'No orders to export');
        return;
    }

    $mapRows = $pdo->query('SELECT option_id, factory_product_name, unit_quantity FROM product_option_map')->fetchAll(PDO::FETCH_ASSOC);
    $optionMap = [];
    foreach ($mapRows as $r) {
        $optionMap[$r['option_id']] = $r;
    }

    $ruleRows = $pdo->query('SELECT option_id, box_qty, box_size FROM product_option_box_rule ORDER BY box_qty ASC')->fetchAll(PDO::FETCH_ASSOC);
    $boxRules = [];
    foreach ($ruleRows as $r) {
        $boxRules[$r['option_id']][] = $r;
    }

    $priceRows = $pdo->query('SELECT box_size, price FROM box_size_price')->fetchAll(PDO::FETCH_ASSOC);
    $boxPrice = [];
    foreach ($priceRows as $r) {
        $boxPrice[$r['box_size']] = $r['price'];
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    $rowNum = 1;
    [$rowNum, $exportedOrderNos, $coupangExportRows] = step4_append_coupang_rows(
        $jobId,
        $sheet,
        $orders,
        $optionMap,
        $boxRules,
        $boxPrice,
        $rowNum
    );
    [$rowNum, $exportedSmartstoreNos, $smartstoreExportRows] = step4_append_smartstore_rows(
        $jobId,
        $sheet,
        $ordersSmartstore,
        $optionMap,
        $boxRules,
        $boxPrice,
        $rowNum
    );

    if (empty($exportedOrderNos) && empty($exportedSmartstoreNos)) {
        job_log($jobId, 'warn', 'No mapped orders');
        return;
    }

    $exportRows = array_merge($coupangExportRows, $smartstoreExportRows);
    $baselineSummary = step4_build_baseline_summary($orders, $ordersSmartstore, $optionMap);
    $exportSummary = step4_build_export_summary($exportRows);
    $compareResult = step4_compare_summaries($baselineSummary, $exportSummary);

    if (!$compareResult['ok']) {
        foreach ($compareResult['diffs'] as $diff) {
            job_log($jobId, 'error', 'Lozen validation mismatch: ' . $diff);
        }
        job_log($jobId, 'error', 'Lozen file generation blocked by validation gate');
        return;
    }

    $riskRows = step4_build_risk_orders($exportRows);
    step4_write_risk_sheet($spreadsheet, $riskRows);

    $dir = __DIR__ . '/../../storage/lozen';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $filename = 'lozen_upload_' . date('Ymd_His') . '.xlsx';
    $path = $dir . '/' . $filename;

    $writer = new Xlsx($spreadsheet);
    $writer->save($path);

    $orderNos = array_keys($exportedOrderNos);
    if ($orderNos) {
        $inClause = implode(',', array_fill(0, count($orderNos), '?'));
        $update = $pdo->prepare(
            "UPDATE coupang_order_excel
             SET lozen_exported_at = NOW()
             WHERE order_no IN ($inClause)"
        );
        $update->execute($orderNos);
    }

    $smartstoreNos = array_values(array_keys($exportedSmartstoreNos));
    if ($smartstoreNos) {
        $inClause = implode(',', array_fill(0, count($smartstoreNos), '?'));
        $updateSmartstore = $pdo->prepare(
            "UPDATE smartstore_order_excel
             SET lozen_exported_at = NOW()
             WHERE product_order_no IN ($inClause)
                OR order_no IN ($inClause)"
        );
        $updateSmartstore->execute(array_merge($smartstoreNos, $smartstoreNos));
    }

    job_log($jobId, 'info', 'Lozen file created: ' . $filename);
    job_log(
        $jobId,
        'info',
        'Processed orders: coupang=' . count($exportedOrderNos)
        . ', smartstore=' . count($exportedSmartstoreNos)
    );
    job_log($jobId, 'info', 'Risk review row count: ' . count($riskRows));
}

function step4_append_coupang_rows(
    ?int $jobId,
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
    array $orders,
    array $optionMap,
    array $boxRules,
    array $boxPrice,
    int $rowNum
): array {
    $exportedOrderNos = [];
    $exportRows = [];

    foreach ($orders as $order) {
        // 1) 옵션/박스 규칙 매칭
        $optionId = $order['option_id'];

        if (!isset($optionMap[$optionId])) {
            job_log($jobId, 'warn', 'No option mapping: ' . $optionId);
            continue;
        }

        $map = $optionMap[$optionId];
        $realQty = (int)$order['qty'] * (int)$map['unit_quantity'];

        $rules = $boxRules[$optionId] ?? [];
        if (!$rules) {
            job_log($jobId, 'warn', 'No box rule: ' . $optionId);
            continue;
        }

        // 2) 박스 수량/택배비 계산
        usort($rules, function ($a, $b) {
            return $b['box_qty'] <=> $a['box_qty'];
        });

        $remainQty = $realQty;
        $totalPrice = 0;
        $packageCount = 0;

        foreach ($rules as $rule) {
            $boxQty = (int)$rule['box_qty'];
            $boxSize = $rule['box_size'];

            if ($remainQty >= $boxQty) {
                $count = intdiv($remainQty, $boxQty);
                $remainQty = $remainQty % $boxQty;
                $price = $boxPrice[$boxSize] ?? 0;
                $totalPrice += $price * $count;
                $packageCount += $count;
            }
        }

        if ($remainQty > 0) {
            $lastRule = end($rules);
            $boxSize = $lastRule['box_size'];
            $price = $boxPrice[$boxSize] ?? 0;
            $totalPrice += $price;
            $packageCount += 1;
        }

        // 3) 로젠 엑셀 행 작성
        $unitKorean = (string)json_decode('"\uAC1C"');
        $productNameForExport = normalize_lozen_unit_to_korean((string)$map['factory_product_name'])
            . ', '
            . (int)$order['qty']
            . $unitKorean;

        $sheet->fromArray([
            $order['receiver_name'],
            $order['receiver_phone'],
            '',
            $productNameForExport,
            '',
            $order['receiver_address'],
            $order['delivery_message'],
            $packageCount,
            $totalPrice,
            $order['order_no'],
        ], null, "A{$rowNum}");

        $exportRows[] = [
            'platform' => 'coupang',
            'order_no' => (string)$order['order_no'],
            'option_id' => (string)$optionId,
            'product_id' => '',
            'qty' => (int)$order['qty'],
            'receiver_name' => (string)$order['receiver_name'],
            'receiver_phone' => (string)$order['receiver_phone'],
            'receiver_address' => (string)$order['receiver_address'],
            'delivery_message' => (string)($order['delivery_message'] ?? ''),
            'product_name_export' => (string)$productNameForExport,
            'package_count' => (int)$packageCount,
            'delivery_price' => (int)$totalPrice,
            'export_no' => (string)$order['order_no'],
            'mapped' => true,
            'dispatched_at' => (string)($order['dispatched_at'] ?? ''),
        ];

        $exportedOrderNos[$order['order_no']] = true;
        $rowNum++;
    }

    return [$rowNum, $exportedOrderNos, $exportRows];
}

function step4_append_smartstore_rows(
    ?int $jobId,
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
    array $ordersSmartstore,
    array $optionMap,
    array $boxRules,
    array $boxPrice,
    int $rowNum
): array {
    $exportedSmartstoreNos = [];
    $exportRows = [];

    foreach ($ordersSmartstore as $order) {
        // 1) normalized table fields
        $orderNo = trim((string)($order['order_no'] ?? ''));
        $productOrderNo = trim((string)($order['product_order_no'] ?? ''));

        $qty = (int)($order['qty'] ?? 0);
        if ($qty <= 0) {
            $qty = 1;
        }

        $productNameSource = trim((string)($order['product_name'] ?? ''));
        if ($productNameSource === '') {
            $productNameSource = 'SMARTSTORE';
        }

        // 2) option mapping from normalized fields
        $optionId = step4_resolve_smartstore_option_id($order, $optionMap, $productNameSource);

        // 3) package count and delivery cost
        $packageCount = max(1, $qty);
        $totalPrice = 0;
        $productNameBase = $productNameSource;

        if ($optionId === '') {
            job_log($jobId, 'warn', 'Smartstore option mapping miss: product_order_no=' . (string)($order['product_order_no'] ?? ''));
        } else {
            $map = $optionMap[$optionId];
            $productNameBase = (string)$map['factory_product_name'];
            $realQty = $qty * (int)$map['unit_quantity'];

            $rules = $boxRules[$optionId] ?? [];
            if (!$rules) {
                job_log($jobId, 'warn', 'No box rule: ' . $optionId);
            } else {
                usort($rules, function ($a, $b) {
                    return $b['box_qty'] <=> $a['box_qty'];
                });

                $remainQty = $realQty;
                $totalPrice = 0;
                $packageCount = 0;

                foreach ($rules as $rule) {
                    $boxQty = (int)$rule['box_qty'];
                    $boxSize = $rule['box_size'];

                    if ($remainQty >= $boxQty) {
                        $count = intdiv($remainQty, $boxQty);
                        $remainQty = $remainQty % $boxQty;
                        $price = $boxPrice[$boxSize] ?? 0;
                        $totalPrice += $price * $count;
                        $packageCount += $count;
                    }
                }

                if ($remainQty > 0) {
                    $lastRule = end($rules);
                    $boxSize = $lastRule['box_size'];
                    $price = $boxPrice[$boxSize] ?? 0;
                    $totalPrice += $price;
                    $packageCount += 1;
                }

                if ($packageCount <= 0) {
                    $packageCount = max(1, $qty);
                }
            }
        }

        // 4) write lozen row
        $receiverName = trim((string)($order['receiver_name'] ?? ''));
        if ($receiverName === '') {
            $receiverName = trim((string)($order['buyer_name'] ?? ''));
        }

        $receiverPhone = trim((string)($order['receiver_phone1'] ?? ''));
        if ($receiverPhone === '') {
            $receiverPhone = trim((string)($order['receiver_phone2'] ?? ''));
        }
        if ($receiverPhone === '') {
            $receiverPhone = trim((string)($order['buyer_phone'] ?? ''));
        }

        $addressText = trim((string)($order['address_text'] ?? ''));
        $deliveryMessage = trim((string)(
            $order['delivery_message']
            ?? $order['shipping_memo']
            ?? $order['delivery_request']
            ?? ''
        ));

        $smartstoreExportNo = $productOrderNo;
        if ($smartstoreExportNo === '') {
            $smartstoreExportNo = $orderNo;
        }

        $unitKorean = (string)json_decode('"\uAC1C"');
        $productNameForExport = normalize_lozen_unit_to_korean($productNameBase)
            . ', '
            . $qty
            . $unitKorean;

        $sheet->fromArray([
            $receiverName,
            $receiverPhone,
            '',
            $productNameForExport,
            '',
            $addressText,
            $deliveryMessage,
            $packageCount,
            $totalPrice,
            '',
        ], null, "A{$rowNum}");
        $sheet->setCellValueExplicit("J{$rowNum}", $smartstoreExportNo, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

        $exportRows[] = [
            'platform' => 'smartstore',
            'order_no' => $orderNo,
            'option_id' => (string)$optionId,
            'product_id' => (string)($order['product_no'] ?? ''),
            'qty' => (int)$qty,
            'receiver_name' => (string)$receiverName,
            'receiver_phone' => (string)$receiverPhone,
            'receiver_address' => (string)$addressText,
            'delivery_message' => (string)$deliveryMessage,
            'product_name_export' => (string)$productNameForExport,
            'package_count' => (int)$packageCount,
            'delivery_price' => (int)$totalPrice,
            'export_no' => (string)$smartstoreExportNo,
            'mapped' => $optionId !== '',
            'dispatched_at' => (string)($order['dispatched_at'] ?? ''),
        ];

        if ($smartstoreExportNo !== '') {
            $exportedSmartstoreNos[$smartstoreExportNo] = true;
        }
        $rowNum++;
    }

    return [$rowNum, $exportedSmartstoreNos, $exportRows];
}

function step4_build_baseline_summary(array $orders, array $ordersSmartstore, array $optionMap): array
{
    $summary = [
        'order_count' => 0,
        'total_qty' => 0,
        'option_qty' => [],
        'receiver_group' => [],
    ];

    foreach ($orders as $order) {
        $orderNo = trim((string)($order['order_no'] ?? ''));
        if ($orderNo === '') {
            continue;
        }
        $qty = max(1, (int)($order['qty'] ?? 0));
        $optionId = trim((string)($order['option_id'] ?? ''));
        $receiverName = trim((string)($order['receiver_name'] ?? ''));
        $receiverPhone = trim((string)($order['receiver_phone'] ?? ''));

        $summary['order_count']++;
        $summary['total_qty'] += $qty;

        $optionKey = 'coupang|' . $orderNo . '|' . $optionId;
        $summary['option_qty'][$optionKey] = ($summary['option_qty'][$optionKey] ?? 0) + $qty;

        $receiverKey = 'coupang|' . $receiverName . '|' . $receiverPhone;
        $summary['receiver_group'][$receiverKey] = ($summary['receiver_group'][$receiverKey] ?? 0) + 1;
    }

    foreach ($ordersSmartstore as $order) {
        $orderNo = trim((string)($order['order_no'] ?? ''));
        if ($orderNo === '') {
            $orderNo = trim((string)($order['product_order_no'] ?? ''));
        }
        if ($orderNo === '') {
            continue;
        }
        $qty = max(1, (int)($order['qty'] ?? 0));
        $productNameSource = trim((string)($order['product_name'] ?? ''));
        if ($productNameSource === '') {
            $productNameSource = 'SMARTSTORE';
        }
        $optionId = step4_resolve_smartstore_option_id($order, $optionMap, $productNameSource);
        if ($optionId === '') {
            $optionId = trim((string)($order['product_no'] ?? ''));
        }
        $receiverName = trim((string)($order['receiver_name'] ?? ''));
        $receiverPhone = trim((string)($order['receiver_phone1'] ?? ''));
        if ($receiverPhone === '') {
            $receiverPhone = trim((string)($order['receiver_phone2'] ?? ''));
        }
        if ($receiverPhone === '') {
            $receiverPhone = trim((string)($order['buyer_phone'] ?? ''));
        }

        $summary['order_count']++;
        $summary['total_qty'] += $qty;

        $optionKey = 'smartstore|' . $orderNo . '|' . $optionId;
        $summary['option_qty'][$optionKey] = ($summary['option_qty'][$optionKey] ?? 0) + $qty;

        $receiverKey = 'smartstore|' . $receiverName . '|' . $receiverPhone;
        $summary['receiver_group'][$receiverKey] = ($summary['receiver_group'][$receiverKey] ?? 0) + 1;
    }

    ksort($summary['option_qty']);
    ksort($summary['receiver_group']);
    return $summary;
}

function step4_resolve_smartstore_option_id(array $order, array $optionMap, string $productNameSource): string
{
    $productOrderNo = trim((string)($order['product_order_no'] ?? ''));

    $optionCandidates = [];
    $candidateValues = [
        $order['option_info'] ?? null,
        $order['seller_product_code'] ?? null,
        $order['product_no'] ?? null,
        $productOrderNo,
    ];
    foreach ($candidateValues as $candidate) {
        $candidateValue = trim((string)$candidate);
        if ($candidateValue !== '') {
            $optionCandidates[$candidateValue] = true;
        }
    }

    foreach (array_keys($optionCandidates) as $candidate) {
        if (isset($optionMap[$candidate])) {
            return (string)$candidate;
        }
    }

    $productHint = trim((string)($order['option_info'] ?? ''));
    $toLower = static function (string $value): string {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    };

    $lookupText = $toLower($productNameSource . ' ' . $productHint);
    $bestOptionId = '';
    $bestScore = -1;

    foreach ($optionMap as $mapOptionId => $mapRow) {
        $factoryName = $toLower((string)($mapRow['factory_product_name'] ?? ''));
        $score = 0;

        if (preg_match_all('/\d+\s*\S+/u', $lookupText, $tokens) && !empty($tokens[0])) {
            foreach ($tokens[0] as $token) {
                $tokenNorm = str_replace(' ', '', $toLower((string)$token));
                if ($tokenNorm !== '' && str_contains(str_replace(' ', '', $factoryName), $tokenNorm)) {
                    $score += 2;
                }
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestOptionId = (string)$mapOptionId;
        }
    }

    return $bestScore > 0 ? $bestOptionId : '';
}

function step4_build_export_summary(array $exportRows): array
{
    $summary = [
        'order_count' => 0,
        'total_qty' => 0,
        'option_qty' => [],
        'receiver_group' => [],
    ];

    foreach ($exportRows as $row) {
        $platform = trim((string)($row['platform'] ?? ''));
        $orderNo = trim((string)($row['order_no'] ?? ''));
        if ($platform === '' || $orderNo === '') {
            continue;
        }
        $qty = max(1, (int)($row['qty'] ?? 0));
        $optionId = trim((string)($row['option_id'] ?? ''));
        if ($optionId === '') {
            $optionId = trim((string)($row['product_id'] ?? ''));
        }
        $receiverName = trim((string)($row['receiver_name'] ?? ''));
        $receiverPhone = trim((string)($row['receiver_phone'] ?? ''));

        $summary['order_count']++;
        $summary['total_qty'] += $qty;

        $optionKey = $platform . '|' . $orderNo . '|' . $optionId;
        $summary['option_qty'][$optionKey] = ($summary['option_qty'][$optionKey] ?? 0) + $qty;

        $receiverKey = $platform . '|' . $receiverName . '|' . $receiverPhone;
        $summary['receiver_group'][$receiverKey] = ($summary['receiver_group'][$receiverKey] ?? 0) + 1;
    }

    ksort($summary['option_qty']);
    ksort($summary['receiver_group']);
    return $summary;
}

function step4_compare_summaries(array $baseline, array $export): array
{
    $diffs = [];

    if ((int)$baseline['order_count'] !== (int)$export['order_count']) {
        $diffs[] = 'order_count baseline=' . (int)$baseline['order_count'] . ', export=' . (int)$export['order_count'];
    }
    if ((int)$baseline['total_qty'] !== (int)$export['total_qty']) {
        $diffs[] = 'total_qty baseline=' . (int)$baseline['total_qty'] . ', export=' . (int)$export['total_qty'];
    }
    if (($baseline['option_qty'] ?? []) !== ($export['option_qty'] ?? [])) {
        $diffs[] = 'option_qty mismatch';
    }
    if (($baseline['receiver_group'] ?? []) !== ($export['receiver_group'] ?? [])) {
        $diffs[] = 'receiver_group mismatch';
    }

    return [
        'ok' => empty($diffs),
        'diffs' => $diffs,
    ];
}

function step4_build_risk_orders(array $exportRows): array
{
    $risks = [];
    $seen = [];

    foreach ($exportRows as $row) {
        $riskReasons = [];
        $platform = trim((string)($row['platform'] ?? ''));
        $orderNo = trim((string)($row['order_no'] ?? ''));
        $qty = max(1, (int)($row['qty'] ?? 0));
        $receiverName = trim((string)($row['receiver_name'] ?? ''));
        $rawReceiverPhone = trim((string)($row['receiver_phone'] ?? ''));
        $receiverPhone = preg_replace('/\D+/', '', $rawReceiverPhone);
        $isSafeNumberFormat = (bool)preg_match('/^\d{4}-\d{4}-\d{4}$/', $rawReceiverPhone);
        $address = trim((string)($row['receiver_address'] ?? ''));
        $mapped = (bool)($row['mapped'] ?? false);
        $dispatchedAt = trim((string)($row['dispatched_at'] ?? ''));

        if ($address === '' || mb_strlen($address, 'UTF-8') < 8 || preg_match('/[<>]/u', $address)) {
            $riskReasons[] = '주소위험';
        }
        if (
            !$isSafeNumberFormat
            && ($receiverPhone === '' || strlen($receiverPhone) < 9 || strlen($receiverPhone) > 11)
        ) {
            $riskReasons[] = '연락처위험';
        }
        if (!$mapped) {
            $riskReasons[] = '상품매핑위험';
        }
        if ($qty >= 2) {
            $riskReasons[] = '수량위험';
        }
        if ($dispatchedAt !== '') {
            $riskReasons[] = '중복재출고위험';
        }

        if (empty($riskReasons)) {
            continue;
        }

        $key = $platform . '|' . $orderNo . '|' . $receiverName . '|' . $receiverPhone;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $risks[] = [
            'platform' => $platform,
            'order_no' => $orderNo,
            'receiver_name' => $receiverName,
            'receiver_phone' => (string)($row['receiver_phone'] ?? ''),
            'product_name' => (string)($row['product_name_export'] ?? ''),
            'qty' => $qty,
            'reason' => implode(', ', $riskReasons),
            'address' => $address,
            'delivery_message' => (string)($row['delivery_message'] ?? ''),
        ];
    }

    return $risks;
}

function step4_write_risk_sheet(Spreadsheet $spreadsheet, array $riskRows): void
{
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle('검수대상');

    $sheet->fromArray(
        ['플랫폼', '주문번호', '수취인이름', '수취인전화', '상품명', '수량', '위험사유', '주소', '배송메세지'],
        null,
        'A1'
    );

    $rowNum = 2;
    foreach ($riskRows as $row) {
        $sheet->fromArray(
            [
                $row['platform'],
                $row['order_no'],
                $row['receiver_name'],
                $row['receiver_phone'],
                $row['product_name'],
                $row['qty'],
                $row['reason'],
                $row['address'],
                $row['delivery_message'],
            ],
            null,
            'A' . $rowNum
        );
        $rowNum++;
    }
}

function step1_resolve_collection_range(string $to): array
{
    $toTs = strtotime($to);
    if ($toTs === false) {
        $toTs = time();
    }

    $weekday = (int)date('N', $toTs); // 1=Mon ... 7=Sun
    $todayDate = date('Y-m-d', $toTs);
    $fromStartTs = 0;

    if ($weekday >= 2 && $weekday <= 6) {
        // Tue~Sat: previous day 14:30
        $fromStartTs = strtotime(date('Y-m-d 14:30:00', strtotime('-1 day', $toTs)));
    } else {
        // Sun or Mon: Friday 14:30
        $fridayDate = date('Y-m-d', strtotime('last friday', strtotime($todayDate . ' 23:59:59')));
        if ($weekday === 5) {
            $fridayDate = $todayDate;
        }
        $fromStartTs = strtotime($fridayDate . ' 14:30:00');
    }

    if ($fromStartTs === false || $fromStartTs <= 0) {
        $fromStartTs = strtotime(date('Y-m-d 14:30:00', strtotime('-1 day', $toTs)));
    }

    return [
        date('Y-m-d', $fromStartTs),
        date('Y-m-d', $toTs),
        date('Y-m-d H:i:s', $fromStartTs),
        date('Y-m-d H:i:s', $toTs),
    ];
}

function run_reset_lozen(): void
{
    require_once __DIR__ . '/../db.php';

    $pdo = db();

    $pdo->exec(
        "UPDATE coupang_order_excel
         SET lozen_exported_at = NULL
         WHERE COALESCE(tracking_no,'') = ''"
    );
    $pdo->exec(
        "UPDATE smartstore_order_excel
         SET lozen_exported_at = NULL
         WHERE COALESCE(tracking_no,'') = ''"
    );

    header('Location: ?action=make_lozen_file');
    exit;
}

function ensure_smartstore_lozen_export_column(PDO $pdo): void
{
    $dbName = envv('DB_NAME', 'ship_new');
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = 'smartstore_order_excel'
           AND COLUMN_NAME = 'lozen_exported_at'
         LIMIT 1"
    );
    $stmt->execute([$dbName]);
    $exists = (bool)$stmt->fetchColumn();

    if (!$exists) {
        $pdo->exec(
            "ALTER TABLE smartstore_order_excel
             ADD COLUMN lozen_exported_at DATETIME NULL AFTER tracking_no"
        );
    }
}



function normalize_lozen_unit_to_korean(string $value): string
{
    $unitKorean = (string)json_decode('"\uAC1C"');
    $normalized = preg_replace('/\bea\b/ui', $unitKorean, $value);
    return is_string($normalized) ? $normalized : $value;
}
