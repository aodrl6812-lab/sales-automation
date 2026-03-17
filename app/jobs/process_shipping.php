<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;

function run_process_shipping(int $jobId, string $from, string $to): void{
	step1_import_lozen_invoice($jobId);
	step2_mark_shipped($jobId);
}

function step1_import_lozen_invoice(?int $jobId = null): void {
    job_log($jobId, 'info', "송장 엑셀 import 시작");
    $pdo = db();

    $dir = dirname(__DIR__, 2) . '/storage/invoice/';
    $files = glob($dir . '*.xlsx');
	$file = $files[0];
    if (!$files) {
        job_log($jobId, 'info', "송장 파일 없음");
        return;
    }    

    job_log($jobId, 'info', "파일 처리: " . basename($file));

    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray();
    $count = 0;

    foreach ($rows as $i => $row) {

        if ($i < 3) continue;

        $order_no = trim((string)$row[18]);
        $tracking_no = trim((string)$row[3]);

        if (!$order_no || !$tracking_no) continue;

        $stmt = $pdo->prepare("
            UPDATE coupang_order_excel
            SET
                tracking_no = :tracking,
                lozen_uploaded_at = NOW()
            WHERE order_no = :order_no
        ");

        $stmt->execute([
            ':tracking' => $tracking_no,
            ':order_no' => $order_no
        ]);
        $count++;
    }

    job_log($jobId, 'info', "송장 저장 완료: {$count}건");
    rename($file, $dir . 'processed_' . basename($file));
}

function step2_mark_shipped(?int $jobId = null): void {
    $pdo = db();

    job_log($jobId,'info','쿠팡 배송중 처리 시작');

    /* 배송중 처리 대상
    tracking_no 존재
    아직 배송중 처리 안된 주문 */

    $sql = "
    SELECT
        order_no,
		option_id,
		shipment_box_id,
        tracking_no,
        carrier_name		
    FROM coupang_order_excel
    WHERE tracking_no IS NOT NULL
      AND tracking_no != ''
      AND shipped_at IS NULL
	  AND carrier_name != ''
    LIMIT 100
    ";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        job_log($jobId,'info','배송중 처리 대상 없음');
        return;
    }

    /* 쿠팡 API는 orderItemInvoices 최대 50개 */
    $chunks = array_chunk($rows,50);
    foreach ($chunks as $chunk) {
        $invoices = [];
        foreach ($chunk as $row) {
            $invoices[] = [
				"shipmentBoxId"			=> (int)$row['shipment_box_id'],
				"orderId"				=> (int)$row['order_no'],
				"vendorItemId"			=> (int)$row['option_id'],
				"deliveryCompanyCode"	=> $row['carrier_name'],
				"invoiceNumber"			=> $row['tracking_no'],
				"splitShipping"			=> false,
				"preSplitShipped"		=> false,
				// Add estimatedShippingDate as empty string for normal shipping
                'estimatedShippingDate' => '',
            ];
        }

        $success = call_coupang_ship_api($jobId,$invoices);

        /* 성공 시 shipped_at 업데이트 */
		if($success){
			$update = $pdo->prepare("
			UPDATE coupang_order_excel
			SET shipped_at = NOW()
			WHERE order_no = :order_no
			");

			foreach ($chunk as $row) {
				$update->execute([":order_no"=>$row['order_no']]);
			}

			job_log($jobId, 'info', '배송지시 DB 업데이트 완료');
        } else {
            job_log($jobId, 'error', '쿠팡 송장 업로드 API 실패 - DB 업데이트 안함');
        }
    }
    job_log($jobId,'info','쿠팡 배송중 처리 완료');
}



function call_coupang_ship_api(?int $jobId,array $invoices): bool
{

    if (empty($invoices)) {
        return false;
    }

    $env = envv('APP_ENV','local');
    $vendorId = envv('COUPANG_VENDOR_ID');
    if ($env === 'prod') {
        $accessKey = envv('COUPANG_ACCESS_KEY_PROD');
        $secretKey = envv('COUPANG_SECRET_KEY_PROD');
    } else {
        $accessKey = envv('COUPANG_ACCESS_KEY_DEV');
        $secretKey = envv('COUPANG_SECRET_KEY_DEV');
    }
	
	$method = "POST";
    $path = "/v2/providers/openapi/apis/api/v4/vendors/{$vendorId}/orders/invoices";
	$datetime = gmdate("ymd").'T'.gmdate("His").'Z';
    $message = $datetime.$method.$path;
    $signature = hash_hmac('sha256',$message,$secretKey);
    $authorization =
    "CEA algorithm=HmacSHA256, access-key={$accessKey}, signed-date={$datetime}, signature={$signature}";

	$body = json_encode([
        "vendorId"=>$vendorId,
        "orderSheetInvoiceApplyDtos"=>$invoices
    ]);
    $url = "https://api-gateway.coupang.com".$path;

    $ch = curl_init();
    curl_setopt_array($ch,[
        CURLOPT_URL=>$url,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_HTTPHEADER=>[
            "Authorization: {$authorization}",
            "Content-Type: application/json"
        ],

        CURLOPT_POSTFIELDS=>$body
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);

    job_log($jobId,'info','쿠팡 배송중 HTTP: '.$httpCode);
    job_log($jobId,'info','쿠팡 배송중 response: '.$response);

	if($httpCode !== 200){
		return false;
	}

	$json = json_decode($response, true);
	if (!isset($json['data']['responseMessage'])) {
        return false;
    }
	if(!isset($json['code'])){
		return false;
	}

	return isset($json['data']['responseCode']) && $json['data']['responseCode'] === 0;
}