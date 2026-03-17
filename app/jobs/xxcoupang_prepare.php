<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../job_runner.php';

function run_coupang_prepare(int $jobId, array $shipmentBoxIds): void
{
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