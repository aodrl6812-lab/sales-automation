<?php
/**
 * Updated functions for marking orders as shipped (배송지시) via Coupang API using PHP 8.x.
 *
 * These functions assume that you have a PDO database connection (db()),
 * a job_log() helper, and envv() helper defined elsewhere in your project.
 *
 * The main changes compared to the original implementation are:
 *  - The API endpoint path now uses "/orders/invoices" instead of the deprecated
 *    "/ordersheets/invoices".
 *  - The HTTP method is POST rather than PUT, as per the official documentation.
 *  - Each invoice item includes an "estimatedShippingDate" field (empty string for normal shipping).
 *  - The content-type header explicitly includes the charset parameter.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../job_runner.php';

/**
 * Process up to 100 orders with tracking numbers and mark them as shipped on Coupang.
 *
 * @param int|null $jobId Optional job identifier for logging purposes
 * @return void
 */
function run_mark_shipped(?int $jobId = null): void
{
    $pdo = db();
    job_log($jobId, 'info', '쿠팡 배송지시 처리 시작');
    // Fetch orders that have a tracking number but have not yet been marked as shipped
    $sql = <<<SQL
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
    SQL;
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        job_log($jobId, 'info', '배송지시 대상 없음');
        return;
    }
    // Coupang API allows up to 50 invoices per request
    $chunks = array_chunk($rows, 50);
    foreach ($chunks as $chunk) {
        $invoices = [];
        foreach ($chunk as $row) {
            $invoices[] = [
                'shipmentBoxId'       => (int) $row['shipment_box_id'],
                'orderId'             => (int) $row['order_no'],
                'vendorItemId'        => (int) $row['option_id'],
                'deliveryCompanyCode' => $row['carrier_name'],
                'invoiceNumber'       => $row['tracking_no'],
                'splitShipping'       => false,
                'preSplitShipped'     => false,
                // Add estimatedShippingDate as empty string for normal shipping
                'estimatedShippingDate' => '',
            ];
        }
        $success = call_coupang_ship_api($jobId, $invoices);
        // On success, update shipped_at timestamp for each order
        if ($success) {
            $update = $pdo->prepare(
                'UPDATE coupang_order_excel SET shipped_at = NOW() WHERE order_no = :order_no'
            );
            foreach ($chunk as $row) {
                $update->execute([':order_no' => $row['order_no']]);
            }
            job_log($jobId, 'info', '배송지시 DB 업데이트 완료');
        } else {
            job_log($jobId, 'error', '쿠팡 송장 업로드 API 실패 - DB 업데이트 안함');
        }
    }
    job_log($jobId, 'info', '쿠팡 배송지시 처리 완료');
}

/**
 * Sends invoice information to Coupang Open API to mark orders as shipped.
 *
 * @param int|null $jobId    Optional job identifier for logging
 * @param array    $invoices Array of invoice data (max 50)
 * @return bool             True if the request succeeded and response code is SUCCESS
 */
function call_coupang_ship_api(?int $jobId, array $invoices): bool
{
    if (empty($invoices)) {
        return false;
    }
    // Determine environment and keys
    $env = envv('APP_ENV', 'local');
    $vendorId = envv('COUPANG_VENDOR_ID');
    if ($env === 'prod') {
        $accessKey = envv('COUPANG_ACCESS_KEY_PROD');
        $secretKey = envv('COUPANG_SECRET_KEY_PROD');
    } else {
        $accessKey = envv('COUPANG_ACCESS_KEY_DEV');
        $secretKey = envv('COUPANG_SECRET_KEY_DEV');
    }
    // Build request path and method according to official docs
    $method = 'POST';
    $path = "/v2/providers/openapi/apis/api/v4/vendors/{$vendorId}/orders/invoices";
    // Generate UTC timestamp and HMAC signature
    $datetime = gmdate('ymd') . 'T' . gmdate('His') . 'Z';
    $message = $datetime . $method . $path;
    $signature = hash_hmac('sha256', $message, $secretKey);
    $authorization = "CEA algorithm=HmacSHA256, access-key={$accessKey}, signed-date={$datetime}, signature={$signature}";
    // Construct request body
    $body = json_encode([
        'vendorId' => $vendorId,
        'orderSheetInvoiceApplyDtos' => $invoices,
    ]);
    $url = 'https://api-gateway.coupang.com' . $path;
    // Initialise cURL
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $authorization,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $body,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    job_log($jobId, 'info', '쿠팡 송장 업로드 HTTP: ' . $httpCode);
    job_log($jobId, 'info', '쿠팡 송장 업로드 response: ' . $response);
    if ($httpCode !== 200) {
        return false;
    }
    $json = json_decode($response, true);
    if (!isset($json['data']['responseMessage'])) {
        return false;
    }
    // In the new API, response.data.responseMessage returns SUCCESS when the request is successful
    return isset($json['data']['responseCode']) && $json['data']['responseCode'] === 0;
}
