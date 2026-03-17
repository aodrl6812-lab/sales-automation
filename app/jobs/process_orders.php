<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function run_process_orders(int $jobId, string $from, string $to): void{
	step1_collect_orders($jobId);
	step2_normalize_coupang($jobId, $from, $to);
	step4_make_lozen_file($jobId);
}

function coupang_auth_header(string $accessKey, string $secretKey, string $method, string $path, string $query): array {

    $datetime = gmdate("ymd\THis\Z");
    $message = $datetime . $method . $path . $query;
    $signature = hash_hmac('sha256', $message, $secretKey);

    $authorization = "CEA algorithm=HmacSHA256, access-key={$accessKey}, signed-date={$datetime}, signature={$signature}";

    return [$authorization, $datetime];
}

function step1_collect_orders(int $jobId): void {
    $env = envv('APP_ENV', 'local');
    $vendorId  = envv('COUPANG_VENDOR_ID');

    if ($env === 'prod') {
        $accessKey = envv('COUPANG_ACCESS_KEY_PROD');
        $secretKey = envv('COUPANG_SECRET_KEY_PROD');
    } else {
        $accessKey = envv('COUPANG_ACCESS_KEY_DEV');
        $secretKey = envv('COUPANG_SECRET_KEY_DEV');
    }

    if ($accessKey === '' || $secretKey === '' || $vendorId === '') {
        job_log($jobId, 'error', 'COUPANG 환경변수 누락 (.env 확인)');
        return;
    }

    job_log($jobId, 'info', "쿠팡 주문 수집 시작");

    $method = "GET";
    $path   = "/v2/providers/openapi/apis/api/v4/vendors/{$vendorId}/ordersheets";

    // 쿠팡 API 필수 파라미터
    $fromDate = date('Y-m-d', strtotime('-5 days'));
    $toDate   = date('Y-m-d');

    $pdo = db();

    $ins = $pdo->prepare("
        INSERT INTO orders_raw (platform, order_no, raw_json, ordered_at)
        VALUES ('coupang', ?, ?, NOW())
        ON DUPLICATE KEY UPDATE raw_json = VALUES(raw_json)
    ");

    $nextToken = null;
    $saved = 0;
    $loop = 0;

    do {

        $params = [
            "createdAtFrom" => $fromDate,
            "createdAtTo"   => $toDate,
            "status"        => "ACCEPT",
        ];

        if ($nextToken) {
            $params["nextToken"] = $nextToken;
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
            "Content-Type: application/json",
            "Authorization: {$authorization}",
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {

            job_log($jobId, 'error', "HTTP {$httpCode}");

            $body = (string)$response;

            if (!mb_check_encoding($body, 'UTF-8')) {
                job_log($jobId, 'error', '응답 UTF8 아님 len=' . strlen($body) . ' sha1=' . sha1($body));
            } else {
                job_log($jobId, 'error', mb_substr($body, 0, 2000));
            }

            return;
        }

        $dataArray = json_decode((string)$response, true);

        if (!is_array($dataArray) || !isset($dataArray['data'])) {
            job_log($jobId, 'warn', '응답 파싱 실패');
            return;
        }

        $rows = $dataArray['data'];

        job_log($jobId, 'info', "API 응답 건수: " . count($rows));

        foreach ($rows as $row) {

            $orderNo =
                $row['orderId']
                ?? $row['orderNo']
                ?? $row['orderNumber']
                ?? null;

            if (!$orderNo) continue;

            $ins->execute([
                (string)$orderNo,
                json_encode($row, JSON_UNESCAPED_UNICODE),
            ]);

            $saved++;
        }

        $nextToken = $dataArray['nextToken'] ?? null;

        $loop++;

        job_log($jobId, 'info', "loop={$loop} nextToken=" . ($nextToken ?? 'null'));

    } while ($nextToken);

    job_log($jobId, 'info', "총 저장 건수 {$saved}");
    job_log($jobId, 'info', "쿠팡 주문 수집 종료");
}

function step2_normalize_coupang(int $jobId, string $from, string $to): void {
    job_log($jobId, 'info', "쿠팡 정규화 시작: {$from} ~ {$to}");

    $pdo = db();
	$prepareIds = [];

    $stmt = $pdo->prepare("
        SELECT id, order_no, raw_json
        FROM orders_raw
        WHERE platform='coupang'
          AND is_normalized = 0
          AND created_at BETWEEN ? AND ?
        ORDER BY id ASC
    ");

    $stmt->execute([$from, $to]);

    $up = $pdo->prepare("
        INSERT INTO coupang_order_excel (
            order_no,
            option_id,
            qty,
            ordered_at,
            carrier_name,
            tracking_no,
            buyer_name,
            buyer_phone,
            receiver_name,
            receiver_phone,
            zipcode,
            receiver_address,
            delivery_message,
            shipment_box_id,
            source_file
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
            shipment_box_id = VALUES(shipment_box_id)
    ");

    $read = 0;
    $written = 0;	

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

        $read++;

        $raw = json_decode($row['raw_json'], true);
        if (!is_array($raw)) continue;

        $orderedAt = $raw['orderedAt'] ?? null;
        if (!$orderedAt) continue;

        $orderedAt2 = str_replace('T', ' ', $orderedAt);

        $receiver = $raw['receiver'] ?? [];
        $orderer  = $raw['orderer'] ?? [];

        $carrier  = 'KGB';
        $tracking = $raw['invoiceNumber'] ?? null;

        $buyerName  = $orderer['name'] ?? null;
        $buyerPhone = $orderer['safeNumber'] ?? ($orderer['ordererNumber'] ?? null);

        $recvName  = $receiver['name'] ?? null;
        $recvPhone = $receiver['safeNumber'] ?? ($receiver['receiverNumber'] ?? null);

        $zipcode = $receiver['postCode'] ?? null;
        $addr1   = $receiver['addr1'] ?? null;
        $addr2   = $receiver['addr2'] ?? null;

        $fullAddr = trim((string)$addr1 . ' ' . (string)$addr2);

        $deliveryMsg = $raw['parcelPrintMessage'] ?? null;

        // 🔥 shipmentBoxId 위치 (쿠팡 실제 구조)
        $shipmentBoxId = $raw['shipmentBoxId'] ?? null;

		$orderItems = $raw['orderItems'] ?? [];        

        foreach ($orderItems as $item) {

            $optionId = $item['vendorItemId'] ?? null;
            $qty      = $item['shippingCount'] ?? 1;

            if (!$optionId) continue;

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
                'API'
            ]);

            $written++;
        }

		if ($shipmentBoxId) {
			$prepareIds[$shipmentBoxId] = $shipmentBoxId;
		}

        $pdo->prepare("
            UPDATE orders_raw
            SET is_normalized = 1,
                normalized_at = NOW()
            WHERE id = ?
        ")->execute([$row['id']]);	
    }

    job_log($jobId, 'info', "읽음: {$read}, 저장: {$written}");
    job_log($jobId, 'info', "쿠팡 정규화 종료");

	if ($prepareIds) {
		$chunks = array_chunk(array_values($prepareIds), 50);

		foreach ($chunks as $ids) {
			step3_coupang_prepare($jobId, $ids);
		}

	}
}

function step3_coupang_prepare(int $jobId, array $shipmentBoxIds): void {
    if (!$shipmentBoxIds) return;

    $env = envv('APP_ENV', 'local');
    $vendorId  = envv('COUPANG_VENDOR_ID');

    if ($env === 'prod') {
        $accessKey = envv('COUPANG_ACCESS_KEY_PROD');
        $secretKey = envv('COUPANG_SECRET_KEY_PROD');
    } else {
        $accessKey = envv('COUPANG_ACCESS_KEY_DEV');
        $secretKey = envv('COUPANG_SECRET_KEY_DEV');
    }

    $path = "/v2/providers/openapi/apis/api/v4/vendors/{$vendorId}/ordersheets/acknowledgement";

    $method = "PUT";

    $datetime = gmdate("ymd") . 'T' . gmdate("His") . 'Z';

    $message = $datetime . $method . $path;

    $signature = hash_hmac('sha256', $message, $secretKey);

    $authorization =
        "CEA algorithm=HmacSHA256, access-key={$accessKey}, signed-date={$datetime}, signature={$signature}";

    $url = "https://api-gateway.coupang.com{$path}";

    $body = json_encode([
        "vendorId" => $vendorId,
        "shipmentBoxIds" => $shipmentBoxIds
    ]);

    $ch = curl_init();

	curl_setopt_array($ch, [
		CURLOPT_URL => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CUSTOMREQUEST => "PUT",
		CURLOPT_HTTPHEADER => [
			"Authorization: {$authorization}",
			"Content-Type: application/json"
		],
		CURLOPT_POSTFIELDS => $body
	]);

    $response = curl_exec($ch);

	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

	job_log($jobId, 'info', '쿠팡 prepare HTTP: '.$httpCode);
	job_log($jobId, 'info', '쿠팡 prepare response: '.$response);

    job_log($jobId, 'info', '쿠팡 상품준비중 호출: '.count($shipmentBoxIds));
}

function step4_make_lozen_file(?int $jobId = null): void {
    $pdo = db();

    job_log($jobId, 'info', "로젠 엑셀 생성 시작");

    $sql = "
    SELECT *
    FROM coupang_order_excel
    WHERE COALESCE(tracking_no,'') = ''
      AND lozen_exported_at IS NULL
    ORDER BY ordered_at ASC
    ";

    $stmt = $pdo->query($sql);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    job_log($jobId, 'info', '조회된 주문 수: ' . count($orders));

    if (!$orders) {
        job_log($jobId, 'info', "출력할 주문 없음");
        return;
    }

    // 옵션 매핑
    $mapRows = $pdo->query("
        SELECT option_id, factory_product_name, unit_quantity
        FROM product_option_map
    ")->fetchAll(PDO::FETCH_ASSOC);

    $optionMap = [];

    foreach ($mapRows as $r) {
        $optionMap[$r['option_id']] = $r;
    }

    // 박스 규칙
    $ruleRows = $pdo->query("
        SELECT option_id, box_qty, box_size
        FROM product_option_box_rule
        ORDER BY box_qty ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $boxRules = [];

    foreach ($ruleRows as $r) {
        $boxRules[$r['option_id']][] = $r;
    }

    // 박스 가격
    $priceRows = $pdo->query("
        SELECT box_size, price
        FROM box_size_price
    ")->fetchAll(PDO::FETCH_ASSOC);

    $boxPrice = [];

    foreach ($priceRows as $r) {
        $boxPrice[$r['box_size']] = $r['price'];
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    $rowNum = 1;

    $exportedOrderNos = [];

    foreach ($orders as $order) {

        $optionId = $order['option_id'];

        if (!isset($optionMap[$optionId])) {

            job_log($jobId, 'warn', "옵션 매핑 없음: {$optionId}");
            continue;
        }

        $map = $optionMap[$optionId];

        $realQty = (int)$order['qty'] * (int)$map['unit_quantity'];

        $rules = $boxRules[$optionId] ?? [];

        if (!$rules) {

            job_log($jobId, 'warn', "박스 규칙 없음: {$optionId}");
            continue;
        }

        // 큰 박스부터 계산
        usort($rules, function($a,$b){
            return $b['box_qty'] <=> $a['box_qty'];
        });

        $remainQty = $realQty;

        $totalPrice = 0;

        foreach ($rules as $rule) {

            $boxQty = (int)$rule['box_qty'];
            $boxSize = $rule['box_size'];

            if ($remainQty >= $boxQty) {

                $count = intdiv($remainQty, $boxQty);

                $remainQty = $remainQty % $boxQty;

                $price = $boxPrice[$boxSize] ?? 0;

                $totalPrice += $price * $count;
            }
        }

        // 남은 수량 처리
        if ($remainQty > 0) {

            $lastRule = end($rules);

            $boxSize = $lastRule['box_size'];

            $price = $boxPrice[$boxSize] ?? 0;

            $totalPrice += $price;
        }

        $sheet->fromArray([
            $order['receiver_name'],
            $order['receiver_phone'],
            '',
            $map['factory_product_name'] . ', ' . $order['qty'] . '개',
            '',
            $order['receiver_address'],
            $order['delivery_message'],
            $realQty,
            $totalPrice,
            $order['order_no']
        ], null, "A{$rowNum}");

        $exportedOrderNos[$order['order_no']] = true;

        $rowNum++;
    }

    if (empty($exportedOrderNos)) {

        job_log($jobId, 'warn', "매핑된 주문 없음");
        return;
    }

    $dir = __DIR__ . '/../../storage/lozen';

    if (!is_dir($dir)) {

        mkdir($dir, 0777, true);
    }

    $filename = 'lozen_upload_' . date('Ymd_His') . '.xlsx';

    $path = $dir . '/' . $filename;

    $writer = new Xlsx($spreadsheet);

    $writer->save($path);

    $orderNos = array_keys($exportedOrderNos);

    $inClause = implode(',', array_fill(0, count($orderNos), '?'));

    $update = $pdo->prepare("
        UPDATE coupang_order_excel
        SET lozen_exported_at = NOW()
        WHERE order_no IN ($inClause)
    ");

    $update->execute($orderNos);

    job_log($jobId, 'info', "로젠 엑셀 생성 완료: {$filename}");
    job_log($jobId, 'info', "처리 건수: " . count($orderNos));
}


function run_reset_lozen(): void
{
    require_once __DIR__ . '/../db.php';

    $pdo = db();

    $pdo->exec("
        UPDATE coupang_order_excel
        SET lozen_exported_at = NULL
        WHERE COALESCE(tracking_no,'') = ''
    ");

    header("Location: ?action=make_lozen_file");

    exit;
}