<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Shipping process entry point: import Lozen invoice file and request Coupang shipment API.
 */
function run_process_shipping(int $jobId, string $from, string $to): void
{
    $pdo = db();
    ensure_coupang_order_excel_delivery_columns($pdo);
    ensure_smartstore_order_excel_delivery_columns($pdo);

    $updatedOrderNos = step1_import_lozen_invoice($jobId);
    step2_mark_shipped($jobId, $updatedOrderNos);
}

/**
 * Delivery status sync entry point: refresh shipped orders to DELIVERING/DELIVERED.
 */
function run_check_delivery_status(int $jobId): void
{
    $pdo = db();
    ensure_coupang_order_excel_delivery_columns($pdo);
    step3_sync_delivery_status($jobId);
}

/**
 * Ensure delivery-status columns exist in coupang_order_excel before status sync.
 */
function ensure_coupang_order_excel_delivery_columns(PDO $pdo): void
{
    $dbName = envv('DB_NAME', 'ship_new');

    $colStmt = $pdo->prepare(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = 'coupang_order_excel'
           AND COLUMN_NAME IN (
             'delivery_status',
             'delivery_status_checked_at',
             'delivery_completed_at',
             'is_delivering',
             'is_delivered'
           )"
    );
    $colStmt->execute([$dbName]);

    $exists = [];
    foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $exists[(string)$row['COLUMN_NAME']] = true;
    }

    $alters = [];
    if (!isset($exists['delivery_status'])) {
        $alters[] = 'ADD COLUMN delivery_status VARCHAR(30) NULL AFTER shipped_at';
    }
    if (!isset($exists['delivery_status_checked_at'])) {
        $alters[] = 'ADD COLUMN delivery_status_checked_at DATETIME NULL AFTER delivery_status';
    }
    if (!isset($exists['delivery_completed_at'])) {
        $alters[] = 'ADD COLUMN delivery_completed_at DATETIME NULL AFTER delivery_status_checked_at';
    }
    if (!isset($exists['is_delivering'])) {
        $alters[] = 'ADD COLUMN is_delivering TINYINT(1) NOT NULL DEFAULT 0 AFTER delivery_completed_at';
    }
    if (!isset($exists['is_delivered'])) {
        $alters[] = 'ADD COLUMN is_delivered TINYINT(1) NOT NULL DEFAULT 0 AFTER is_delivering';
    }

    if ($alters) {
        $pdo->exec('ALTER TABLE coupang_order_excel ' . implode(', ', $alters));
    }
}

/**
 * Ensure dispatch-status columns exist in smartstore_order_excel before dispatch.
 */
function ensure_smartstore_order_excel_delivery_columns(PDO $pdo): void
{
    $dbName = envv('DB_NAME', 'ship_new');

    $colStmt = $pdo->prepare(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = 'smartstore_order_excel'
           AND COLUMN_NAME IN (
             'dispatched_at',
             'dispatch_result',
             'dispatch_result_checked_at'
           )"
    );
    $colStmt->execute([$dbName]);

    $exists = [];
    foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $exists[(string)$row['COLUMN_NAME']] = true;
    }

    $alters = [];
    if (!isset($exists['dispatched_at'])) {
        $alters[] = 'ADD COLUMN dispatched_at DATETIME NULL AFTER tracking_no';
    }
    if (!isset($exists['dispatch_result'])) {
        $alters[] = 'ADD COLUMN dispatch_result VARCHAR(30) NULL AFTER dispatched_at';
    }
    if (!isset($exists['dispatch_result_checked_at'])) {
        $alters[] = 'ADD COLUMN dispatch_result_checked_at DATETIME NULL AFTER dispatch_result';
    }

    if ($alters) {
        $pdo->exec('ALTER TABLE smartstore_order_excel ' . implode(', ', $alters));
    }
}

/**
 * STEP 1: Import Lozen invoice file and map tracking_no + order_no to coupang_order_excel.
 */
function step1_import_lozen_invoice(?int $jobId = null): array
{
    job_log($jobId, 'info', 'Invoice import started');
    $pdo = db();

    $dir = dirname(__DIR__, 2) . '/storage/invoice/';
    $all = glob($dir . '*.xlsx') ?: [];

    // Exclude already processed files (processed_*) and keep only new files.
    $files = array_values(array_filter($all, static function (string $f): bool {
        return stripos(basename($f), 'processed_') !== 0;
    }));

    if (!$files) {
        job_log($jobId, 'info', 'No new invoice file found');
        return [];
    }

    // Process only the latest file first.
    usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

    $file = $files[0];
    job_log($jobId, 'info', 'Processing file: ' . basename($file));

    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray();

    $stmt = $pdo->prepare(
        "UPDATE coupang_order_excel
         SET tracking_no = :tracking,
             lozen_uploaded_at = NOW(),
             shipped_at = COALESCE(shipped_at, NOW())
         WHERE order_no = :order_no"
    );
    $stmtSmartstore = $pdo->prepare(
        "UPDATE smartstore_order_excel
         SET tracking_no = :tracking,
             carrier_name = COALESCE(NULLIF(carrier_name, ''), :carrier_code)
         WHERE product_order_no = :order_key
            OR order_no = :order_key"
    );

    $count = 0;
    $updatedOrderNos = [];
    $smartstoreCarrierCode = trim(envv('NAVER_DISPATCH_DELIVERY_COMPANY_CODE', 'KGB'));

    foreach ($rows as $i => $row) {
        if ($i < 3) {
            continue;
        }

        $orderNo = trim((string)($row[18] ?? ''));
        $trackingNo = trim((string)($row[3] ?? ''));

        if ($orderNo === '' || $trackingNo === '') {
            continue;
        }

        $stmt->execute([
            ':tracking' => $trackingNo,
            ':order_no' => $orderNo,
        ]);
        $stmtSmartstore->execute([
            ':tracking' => $trackingNo,
            ':carrier_code' => $smartstoreCarrierCode,
            ':order_key' => $orderNo,
        ]);

        $count++;
        $updatedOrderNos[$orderNo] = true;
    }

    job_log($jobId, 'info', 'Invoice import completed: ' . $count . ' rows');

    $processedPath = $dir . 'processed_' . basename($file);
    if (is_file($processedPath)) {
        $processedPath = $dir . 'processed_' . date('Ymd_His') . '_' . basename($file);
    }
    rename($file, $processedPath);

    return array_keys($updatedOrderNos);
}

/**
 * STEP 2: Dispatch by platform.
 */
function step2_mark_shipped(?int $jobId = null, array $orderNosFromInvoice = []): void
{
    $invoiceOrderNos = array_values(array_unique(array_filter(array_map('strval', $orderNosFromInvoice))));
    job_log($jobId, 'info', 'Shipping instruction started');

    step2_mark_shipped_coupang($jobId, $invoiceOrderNos);
    step2_mark_shipped_smartstore($jobId, $invoiceOrderNos);
}

/**
 * STEP 2-COUPANG: Call shipment API and mark shipped_at.
 */
function step2_mark_shipped_coupang(?int $jobId = null, array $orderNosFromInvoice = []): void
{
    $pdo = db();

    $where = [
        'tracking_no IS NOT NULL',
        'tracking_no != ""',
        'carrier_name != ""',
    ];
    $params = [];

    if ($orderNosFromInvoice) {
        $in = implode(',', array_fill(0, count($orderNosFromInvoice), '?'));
        $where[] = "order_no IN ($in)";
        $params = array_merge($params, $orderNosFromInvoice);
    } else {
        $where[] = 'shipped_at IS NULL';
    }

    $sql = "
        SELECT DISTINCT
            order_no, option_id, shipment_box_id, tracking_no, carrier_name, lozen_uploaded_at
        FROM coupang_order_excel
        WHERE " . implode("\n          AND ", $where) . "
        ORDER BY lozen_uploaded_at DESC
        LIMIT 200
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        job_log($jobId, 'info', 'No shipping target rows');
        return;
    }

    $shipChunkSize = max(1, min(50, (int)envv('COUPANG_SHIP_CHUNK_SIZE', '50')));
    $chunks = array_chunk($rows, $shipChunkSize);
    job_log($jobId, 'info', 'Coupang shipping targets=' . count($rows) . ', chunks=' . count($chunks) . ', chunk_size=' . $shipChunkSize);
    $update = $pdo->prepare('UPDATE coupang_order_excel SET shipped_at = NOW() WHERE order_no = :order_no');

    $totalSuccess = 0;
    foreach ($chunks as $chunk) {
        $succeededRows = ship_chunk_with_fallback($jobId, $chunk, 0);
        if (!$succeededRows) {
            continue;
        }

        foreach ($succeededRows as $row) {
            $update->execute([':order_no' => $row['order_no']]);
        }

        $totalSuccess += count($succeededRows);
    }

    job_log($jobId, 'success', 'Coupang shipping instruction completed: ' . $totalSuccess . ' rows');
}

/**
 * STEP 2-SMARTSTORE: Call dispatch API.
 */
function step2_mark_shipped_smartstore(?int $jobId = null, array $orderNosFromInvoice = []): void
{
    if (!$orderNosFromInvoice) {
        job_log($jobId, 'info', 'No invoice order keys for smartstore dispatch');
        return;
    }

    require_once __DIR__ . '/collect_naver_orders.php';

    if (!function_exists('naver_collect_get_access_token') || !function_exists('naver_collect_request_json')) {
        job_log($jobId, 'error', 'Naver collect helpers not found');
        return;
    }

    $pdo = db();
    $limit = max(30, min(500, (int)envv('NAVER_DISPATCH_LIMIT', '200')));
    $in = implode(',', array_fill(0, count($orderNosFromInvoice), '?'));

    $sql = "
        SELECT DISTINCT
            product_order_no,
            order_no,
            tracking_no,
            carrier_name
        FROM smartstore_order_excel
        WHERE tracking_no IS NOT NULL
          AND tracking_no != ''
          AND (product_order_no IN ($in) OR order_no IN ($in))
        LIMIT {$limit}
    ";

    $params = array_merge($orderNosFromInvoice, $orderNosFromInvoice);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        job_log($jobId, 'info', 'No smartstore dispatch target rows');
        return;
    }

    $chunkSize = max(1, min(30, (int)envv('NAVER_DISPATCH_CHUNK_SIZE', '30')));
    $chunks = array_chunk($rows, $chunkSize);
    job_log(
        $jobId,
        'info',
        'Smartstore dispatch targets=' . count($rows)
        . ', chunks=' . count($chunks)
        . ', chunk_size=' . $chunkSize
    );

    $totalSuccess = 0;
    $updateSuccess = $pdo->prepare(
        "UPDATE smartstore_order_excel
         SET dispatched_at = NOW(),
             dispatch_result = 'SUCCESS',
             dispatch_result_checked_at = NOW()
         WHERE product_order_no = :product_order_no
            OR order_no = :order_no"
    );
    foreach ($chunks as $chunk) {
        $successRows = smartstore_dispatch_chunk_with_fallback($jobId, $chunk, 0);
        foreach ($successRows as $row) {
            $productOrderNo = trim((string)($row['product_order_no'] ?? ''));
            $orderNo = trim((string)($row['order_no'] ?? ''));
            $updateSuccess->execute([
                ':product_order_no' => $productOrderNo,
                ':order_no' => $orderNo,
            ]);
        }
        $totalSuccess += count($successRows);
    }

    job_log($jobId, 'success', 'Smartstore dispatch completed: ' . $totalSuccess . ' rows');
}

/**
 * Call shipment API for one chunk. If failed, split chunk recursively and retry.
 */
function ship_chunk_with_fallback(?int $jobId, array $chunk, int $depth = 0): array
{
    if (!$chunk) {
        return [];
    }

    $invoices = [];
    foreach ($chunk as $row) {
        $invoices[] = [
            'shipmentBoxId' => (int)$row['shipment_box_id'],
            'orderId' => (int)$row['order_no'],
            'vendorItemId' => (int)$row['option_id'],
            'deliveryCompanyCode' => $row['carrier_name'],
            'invoiceNumber' => $row['tracking_no'],
            'splitShipping' => false,
            'preSplitShipped' => false,
            'estimatedShippingDate' => '',
        ];
    }

    if (call_coupang_ship_api($jobId, $invoices)) {
        return $chunk;
    }

    $count = count($chunk);
    if ($count <= 1) {
        job_log($jobId, 'error', 'Shipping instruction failed for order_no=' . (string)$chunk[0]['order_no']);
        return [];
    }

    $half = intdiv($count, 2);
    job_log($jobId, 'warn', 'Chunk failed, retry split: ' . $count . ' -> ' . $half);

    return array_merge(
        ship_chunk_with_fallback($jobId, array_slice($chunk, 0, $half)),
        ship_chunk_with_fallback($jobId, array_slice($chunk, $half))
    );
}

function smartstore_dispatch_chunk_with_fallback(?int $jobId, array $chunk, int $depth = 0): array
{
    if (!$chunk) {
        return [];
    }

    $result = call_smartstore_dispatch_api($jobId, $chunk);
    if ($result['ok']) {
        return $result['successRows'];
    }

    $count = count($chunk);
    if ($count <= 1) {
        $row = $chunk[0] ?? [];
        $productOrderNo = trim((string)($row['product_order_no'] ?? ($row['order_no'] ?? '')));
        job_log(
            $jobId,
            'error',
            'Smartstore dispatch failed for product_order_no=' . $productOrderNo
        );
        return [];
    }

    $half = intdiv($count, 2);
    job_log(
        $jobId,
        'warn',
        'Smartstore chunk failed, retry split: ' . $count . ' -> ' . $half
    );

    return array_merge(
        smartstore_dispatch_chunk_with_fallback($jobId, array_slice($chunk, 0, $half), $depth + 1),
        smartstore_dispatch_chunk_with_fallback($jobId, array_slice($chunk, $half), $depth + 1)
    );
}

function call_smartstore_dispatch_api(?int $jobId, array $rows): array
{
    $rows = array_values(array_filter($rows, static function ($row): bool {
        if (!is_array($row)) {
            return false;
        }
        $productOrderNo = trim((string)($row['product_order_no'] ?? ($row['order_no'] ?? '')));
        $trackingNo = trim((string)($row['tracking_no'] ?? ''));
        return $productOrderNo !== '' && $trackingNo !== '';
    }));

    if (!$rows) {
        return ['ok' => true, 'successRows' => []];
    }

    $token = naver_collect_get_access_token((int)$jobId);
    if ($token === '') {
        job_log($jobId, 'error', 'Naver access token unavailable');
        return ['ok' => false, 'successRows' => []];
    }

    $deliveryMethod = strtoupper(trim(envv('NAVER_DISPATCH_DELIVERY_METHOD', 'DELIVERY')));
    $defaultCompanyCode = trim(envv('NAVER_DISPATCH_DELIVERY_COMPANY_CODE', 'KGB'));
    $dispatchDate = format_smartstore_dispatch_datetime();

    $dispatchProductOrders = [];
    $rowByProductOrderId = [];

    foreach ($rows as $row) {
        $productOrderId = trim((string)($row['product_order_no'] ?? ($row['order_no'] ?? '')));
        $trackingNo = trim((string)($row['tracking_no'] ?? ''));
        $carrierCode = trim((string)($row['carrier_name'] ?? ''));
        if ($carrierCode === '') {
            $carrierCode = $defaultCompanyCode;
        }

        $item = [
            'productOrderId' => $productOrderId,
            'deliveryMethod' => $deliveryMethod,
            'dispatchDate' => $dispatchDate,
        ];

        if ($deliveryMethod !== 'NOTHING') {
            $item['deliveryCompanyCode'] = $carrierCode;
            $item['trackingNumber'] = $trackingNo;
        }

        $dispatchProductOrders[] = $item;
        $rowByProductOrderId[$productOrderId] = $row;
    }

    $body = json_encode(
        ['dispatchProductOrders' => $dispatchProductOrders],
        JSON_UNESCAPED_UNICODE
    );
    if (!is_string($body) || $body === '') {
        job_log($jobId, 'error', 'Failed to build smartstore dispatch request body');
        return ['ok' => false, 'successRows' => []];
    }

    $baseUrl = rtrim(envv('NAVER_API_BASE_URL', 'https://api.commerce.naver.com/external'), '/');
    $url = $baseUrl . '/v1/pay-order/seller/product-orders/dispatch';

    $resp = naver_collect_request_json(
        (int)$jobId,
        'POST',
        $url,
        [],
        $body,
        [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]
    );

    if (!$resp['ok']) {
        return ['ok' => false, 'successRows' => []];
    }

    $json = is_array($resp['json'] ?? null) ? $resp['json'] : [];
    $data = is_array($json['data'] ?? null) ? $json['data'] : [];

    $successIdsRaw = is_array($data['successProductOrderIds'] ?? null)
        ? $data['successProductOrderIds']
        : [];
    $failInfos = is_array($data['failProductOrderInfos'] ?? null)
        ? $data['failProductOrderInfos']
        : [];

    $successIds = array_values(array_unique(array_filter(array_map(
        static fn ($v) => trim((string)$v),
        $successIdsRaw
    ))));

    $successRows = [];
    foreach ($successIds as $productOrderId) {
        if (isset($rowByProductOrderId[$productOrderId])) {
            $successRows[] = $rowByProductOrderId[$productOrderId];
        }
    }

    job_log(
        $jobId,
        'info',
        'Smartstore dispatch response: requested=' . count($dispatchProductOrders)
        . ', success=' . count($successRows)
        . ', fail=' . count($failInfos)
    );

    if (!empty($failInfos)) {
        $preview = json_encode(array_slice($failInfos, 0, 3), JSON_UNESCAPED_UNICODE);
        job_log($jobId, 'warn', 'Smartstore dispatch fail sample: ' . (string)$preview);
    }

    return ['ok' => true, 'successRows' => $successRows];
}

/**
 * STEP 3: Sync delivery status.
 * 1) DELIVERING => is_delivering=1
 * 2) DELIVERED/FINAL_DELIVERY => is_delivered=1
 */
function step3_sync_delivery_status(?int $jobId = null): void
{
    $pdo = db();

    $toDelivering = 0;
    $toDelivered = 0;
    $syncLimit = max(50, min(500, (int)envv('COUPANG_DELIVERY_SYNC_LIMIT', '200')));
    $recheckMinutes = max(5, min(120, (int)envv('COUPANG_DELIVERY_RECHECK_MINUTES', '15')));

    $targetsSql =
        "SELECT DISTINCT order_no, shipment_box_id
         FROM coupang_order_excel
         WHERE shipped_at IS NOT NULL
           AND is_delivering = 0
           AND is_delivered = 0
           AND shipment_box_id > 0
           AND (delivery_status_checked_at IS NULL OR delivery_status_checked_at < DATE_SUB(NOW(), INTERVAL " . (int)$recheckMinutes . " MINUTE))
         LIMIT " . (int)$syncLimit;

    $targets = $pdo->query($targetsSql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($targets as $t) {
        $orderNo = (string)$t['order_no'];
        $hist = call_coupang_delivery_history_api($jobId, (int)$t['shipment_box_id']);
        $latest = pick_latest_delivery_status($hist ?? []);
        if (!$latest) {
            continue;
        }

        $status = (string)($latest['deliveryStatus'] ?? '');
        $updatedAt = normalize_iso_to_mysql((string)($latest['updatedAt'] ?? ''));

        if ($status === 'DELIVERING') {
            $pdo->prepare(
                "UPDATE coupang_order_excel
                 SET delivery_status = ?,
                     delivery_status_checked_at = NOW(),
                     is_delivering = 1
                 WHERE order_no = ?"
            )->execute([$status, $orderNo]);

            $toDelivering++;
            continue;
        }

        if (in_array($status, ['FINAL_DELIVERY', 'DELIVERED'], true)) {
            $pdo->prepare(
                "UPDATE coupang_order_excel
                 SET delivery_status = ?,
                     delivery_status_checked_at = NOW(),
                     is_delivered = 1,
                     is_delivering = 0,
                     delivery_completed_at = COALESCE(?, NOW())
                 WHERE order_no = ?"
            )->execute([$status, $updatedAt, $orderNo]);

            $toDelivered++;
            continue;
        }

        if ($status !== '') {
            $pdo->prepare(
                "UPDATE coupang_order_excel
                 SET delivery_status = ?,
                     delivery_status_checked_at = NOW()
                 WHERE order_no = ?"
            )->execute([$status, $orderNo]);
        }
    }

    $deliveringSql =
        "SELECT DISTINCT order_no, shipment_box_id
         FROM coupang_order_excel
         WHERE is_delivering = 1
           AND is_delivered = 0
           AND shipment_box_id > 0
           AND (delivery_status_checked_at IS NULL OR delivery_status_checked_at < DATE_SUB(NOW(), INTERVAL " . (int)$recheckMinutes . " MINUTE))
         LIMIT " . (int)$syncLimit;

    $deliveringRows = $pdo->query($deliveringSql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($deliveringRows as $t) {
        $orderNo = (string)$t['order_no'];
        $hist = call_coupang_delivery_history_api($jobId, (int)$t['shipment_box_id']);
        $latest = pick_latest_delivery_status($hist ?? []);
        if (!$latest) {
            continue;
        }

        $status = (string)($latest['deliveryStatus'] ?? '');
        $updatedAt = normalize_iso_to_mysql((string)($latest['updatedAt'] ?? ''));

        if (in_array($status, ['FINAL_DELIVERY', 'DELIVERED'], true)) {
            $pdo->prepare(
                "UPDATE coupang_order_excel
                 SET delivery_status = ?,
                     delivery_status_checked_at = NOW(),
                     is_delivered = 1,
                     is_delivering = 0,
                     delivery_completed_at = COALESCE(?, NOW())
                 WHERE order_no = ?"
            )->execute([$status, $updatedAt, $orderNo]);

            $toDelivered++;
            continue;
        }

        if ($status !== 'DELIVERING' && $status !== '') {
            $pdo->prepare(
                "UPDATE coupang_order_excel
                 SET delivery_status = ?,
                     delivery_status_checked_at = NOW(),
                     is_delivering = 0
                 WHERE order_no = ?"
            )->execute([$status, $orderNo]);
        }
    }

    job_log($jobId, 'info', 'Delivery status sync completed. to_delivering=' . $toDelivering . ', to_delivered=' . $toDelivered);
}

function call_coupang_delivery_history_api(?int $jobId, int $shipmentBoxId): ?array
{
    if ($shipmentBoxId <= 0) {
        return null;
    }

    $vendorId = envv('COUPANG_VENDOR_ID');
    $isProd = envv('APP_ENV') === 'prod';
    $accessKey = envv($isProd ? 'COUPANG_ACCESS_KEY_PROD' : 'COUPANG_ACCESS_KEY_DEV');
    $secretKey = envv($isProd ? 'COUPANG_SECRET_KEY_PROD' : 'COUPANG_SECRET_KEY_DEV');

    if ($vendorId === '' || $accessKey === '' || $secretKey === '') {
        job_log($jobId, 'error', 'Coupang API credentials are missing');
        return null;
    }

    $method = 'GET';
    $path = "/v2/providers/openapi/apis/api/v5/vendors/{$vendorId}/ordersheets/{$shipmentBoxId}/history";

    $datetime = gmdate('ymd\THis\Z');
    $signature = hash_hmac('sha256', $datetime . $method . $path, $secretKey);
    $authorization = "CEA algorithm=HmacSHA256, access-key={$accessKey}, signed-date={$datetime}, signature={$signature}";

    $maxAttempts = max(1, (int)envv('COUPANG_API_RETRY', '2'));
    $timeout = max(8, (int)envv('COUPANG_DELIVERY_HISTORY_TIMEOUT', '12'));
    $connectTimeout = max(2, (int)envv('COUPANG_CONNECT_TIMEOUT', '4'));

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init('https://api-gateway.coupang.com' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_HTTPHEADER => [
                "Authorization: {$authorization}",
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = $errno ? curl_error($ch) : '';
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            if ($attempt < $maxAttempts) {
                usleep(150000 * $attempt);
                continue;
            }
            job_log($jobId, 'warn', 'Delivery history curl failed. box=' . $shipmentBoxId . ', err=' . $err);
            return null;
        }

        if ($httpCode === 200) {
            $json = json_decode((string)$response, true);
            if (is_array($json)) {
                return $json;
            }
            if ($attempt < $maxAttempts) {
                usleep(150000 * $attempt);
                continue;
            }
            job_log($jobId, 'warn', 'Delivery history JSON parse failed. box=' . $shipmentBoxId);
            return null;
        }

        $retryable = in_array($httpCode, [429, 500, 502, 503, 504], true);
        if ($retryable && $attempt < $maxAttempts) {
            usleep(200000 * $attempt);
            continue;
        }

        job_log($jobId, 'warn', 'Delivery history API failed. HTTP=' . $httpCode . ', box=' . $shipmentBoxId);
        return null;
    }

    return null;
}

function call_coupang_ship_api(?int $jobId, array $invoices): bool
{
    if (empty($invoices)) {
        return false;
    }

    $vendorId = envv('COUPANG_VENDOR_ID');
    $isProd = envv('APP_ENV') === 'prod';
    $accessKey = envv($isProd ? 'COUPANG_ACCESS_KEY_PROD' : 'COUPANG_ACCESS_KEY_DEV');
    $secretKey = envv($isProd ? 'COUPANG_SECRET_KEY_PROD' : 'COUPANG_SECRET_KEY_DEV');

    if ($vendorId === '' || $accessKey === '' || $secretKey === '') {
        job_log($jobId, 'error', 'Coupang API credentials are missing');
        return false;
    }

    $method = 'POST';
    $path = "/v2/providers/openapi/apis/api/v4/vendors/{$vendorId}/orders/invoices";

    $datetime = gmdate('ymd\THis\Z');
    $signature = hash_hmac('sha256', $datetime . $method . $path, $secretKey);
    $authorization = "CEA algorithm=HmacSHA256, access-key={$accessKey}, signed-date={$datetime}, signature={$signature}";

    $payload = [
        'vendorId' => $vendorId,
        'orderSheetInvoiceApplyDtos' => $invoices,
    ];

    $maxAttempts = max(1, (int)envv('COUPANG_API_RETRY', '2'));
    $timeout = max(12, (int)envv('COUPANG_SHIP_TIMEOUT', '18'));
    $connectTimeout = max(2, (int)envv('COUPANG_CONNECT_TIMEOUT', '4'));

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init('https://api-gateway.coupang.com' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                "Authorization: {$authorization}",
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = $errno ? curl_error($ch) : '';
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        job_log($jobId, 'info', 'Ship API HTTP=' . $httpCode . ', attempt=' . $attempt . ', size=' . count($invoices));

        if ($errno !== 0) {
            if ($attempt < $maxAttempts) {
                usleep(180000 * $attempt);
                continue;
            }
            job_log($jobId, 'warn', 'Ship API curl failed: ' . $err);
            return false;
        }

        if ($httpCode === 200) {
            $json = json_decode((string)$response, true);
            if (is_array($json) && isset($json['data']['responseCode']) && (int)$json['data']['responseCode'] === 0) {
                return true;
            }
            if ($attempt < $maxAttempts) {
                usleep(180000 * $attempt);
                continue;
            }
            return false;
        }

        $retryable = in_array($httpCode, [429, 500, 502, 503, 504], true);
        if ($retryable && $attempt < $maxAttempts) {
            usleep(180000 * $attempt);
            continue;
        }

        return false;
    }

    return false;
}

function extract_delivery_history_rows(array $history): array
{
    // 1) root is already a list
    if (array_keys($history) === range(0, count($history) - 1)) {
        return $history;
    }

    // 2) data is already a list
    if (isset($history['data']) && is_array($history['data'])
        && array_keys($history['data']) === range(0, count($history['data']) - 1)) {
        return $history['data'];
    }

    // 3) common list keys under data
    if (isset($history['data']) && is_array($history['data'])) {
        foreach (['details', 'deliveryHistories', 'histories', 'history', 'items'] as $key) {
            if (isset($history['data'][$key]) && is_array($history['data'][$key])) {
                return $history['data'][$key];
            }
        }
    }

    // 4) common list keys under root
    foreach (['details', 'deliveryHistories', 'histories', 'history', 'items'] as $key) {
        if (isset($history[$key]) && is_array($history[$key])) {
            return $history[$key];
        }
    }

    return [];
}

/**
 * Pick latest one row from delivery history.
 */
function pick_latest_delivery_status(array $history): ?array
{
    $rows = extract_delivery_history_rows($history);
    if (!$rows) {
        return null;
    }

    $rows = array_values(array_filter($rows, static function ($row): bool {
        return is_array($row) && isset($row['deliveryStatus']);
    }));

    if (!$rows) {
        return null;
    }

    usort($rows, static function (array $a, array $b): int {
        $ta = strtotime((string)($a['updatedAt'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['updatedAt'] ?? '')) ?: 0;
        return $tb <=> $ta;
    });

    return $rows[0] ?? null;
}

/*** ISO8601 -> MySQL DATETIME ***/
function normalize_iso_to_mysql(string $iso): ?string
{
    if ($iso === '') {
        return null;
    }

    $ts = strtotime($iso);
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $ts);
}

function format_smartstore_dispatch_datetime(): string
{
    $tzName = trim(envv('NAVER_DISPATCH_TIMEZONE', 'Asia/Seoul'));
    if ($tzName === '') {
        $tzName = 'Asia/Seoul';
    }

    try {
        $tz = new DateTimeZone($tzName);
    } catch (Throwable $e) {
        $tz = new DateTimeZone('Asia/Seoul');
    }

    return (new DateTimeImmutable('now', $tz))->format('Y-m-d\\TH:i:s.vP');
}
