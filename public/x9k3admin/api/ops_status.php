<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ob_start();

function ops_json(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/db.php';

function coupang_creds(): array
{
    $env = envv('APP_ENV', 'local');
    $vendorId = trim((string)envv('COUPANG_VENDOR_ID', ''));
    $accessKey = trim((string)envv($env === 'prod' ? 'COUPANG_ACCESS_KEY_PROD' : 'COUPANG_ACCESS_KEY_DEV', ''));
    $secretKey = trim((string)envv($env === 'prod' ? 'COUPANG_SECRET_KEY_PROD' : 'COUPANG_SECRET_KEY_DEV', ''));

    return [
        'ok' => ($vendorId !== '' && $accessKey !== '' && $secretKey !== ''),
        'vendorId' => $vendorId,
        'accessKey' => $accessKey,
        'secretKey' => $secretKey,
    ];
}

function coupang_auth_header(string $accessKey, string $secretKey, string $method, string $path, string $query): string
{
    $datetime = gmdate('ymd\\THis\\Z');
    $message = $datetime . $method . $path . $query;
    $signature = hash_hmac('sha256', $message, $secretKey);
    return "CEA algorithm=HmacSHA256, access-key={$accessKey}, signed-date={$datetime}, signature={$signature}";
}

function coupang_get(string $path, array $query, array $creds, int $timeoutSec = 16): array
{
    $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $auth = coupang_auth_header((string)$creds['accessKey'], (string)$creds['secretKey'], 'GET', $path, $queryString);
    $url = 'https://api-gateway.coupang.com' . $path . ($queryString !== '' ? ('?' . $queryString) : '');

    $maxAttempts = max(1, (int)envv('COUPANG_API_RETRY', '2'));
    $connectTimeout = max(2, (int)envv('COUPANG_CONNECT_TIMEOUT', '4'));

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: ' . $auth,
                'X-EXTENDED-TIMEOUT: 90000',
            ],
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = $errno ? curl_error($ch) : '';
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            if ($attempt < $maxAttempts) {
                usleep(150000 * $attempt);
                continue;
            }
            return ['ok' => false, 'http' => 0, 'json' => null, 'error' => 'curl: ' . $err];
        }

        $json = json_decode((string)$raw, true);
        if (!is_array($json)) {
            if ($attempt < $maxAttempts) {
                usleep(150000 * $attempt);
                continue;
            }
            return ['ok' => false, 'http' => $http, 'json' => null, 'error' => 'invalid_json'];
        }

        if ($http >= 200 && $http < 300) {
            return ['ok' => true, 'http' => $http, 'json' => $json, 'error' => ''];
        }

        $retryable = in_array($http, [429, 500, 502, 503, 504], true);
        if ($retryable && $attempt < $maxAttempts) {
            usleep(200000 * $attempt);
            continue;
        }

        return ['ok' => false, 'http' => $http, 'json' => $json, 'error' => (string)($json['message'] ?? 'http_error')];
    }

    return ['ok' => false, 'http' => 0, 'json' => null, 'error' => 'unknown'];
}

function arr_get($arr, array $path, $default = null)
{
    $cur = $arr;
    foreach ($path as $k) {
        if (!is_array($cur) || !array_key_exists($k, $cur)) {
            return $default;
        }
        $cur = $cur[$k];
    }
    return $cur;
}

function ops_cache_file(string $vendorId): string
{
    $dir = dirname(__DIR__, 3) . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir . '/ops_status_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $vendorId) . '.json';
}

function ops_cache_read(string $vendorId, int $ttlSec): ?array
{
    $file = ops_cache_file($vendorId);
    if (!is_file($file)) {
        return null;
    }

    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return null;
    }

    $generated = (int)($json['_generated_ts'] ?? 0);
    if ($generated <= 0 || (time() - $generated) > $ttlSec) {
        return null;
    }

    return $json;
}

function ops_cache_write(string $vendorId, array $payload): void
{
    $file = ops_cache_file($vendorId);
    $payload['_generated_ts'] = time();
    @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE));
}

function count_online_inquiries(array $creds): array
{
    $vendorId = (string)$creds['vendorId'];
    $path = "/v2/providers/openapi/apis/api/v5/vendors/{$vendorId}/onlineInquiries";
    $query = [
        'vendorId' => $vendorId,
        'answeredType' => 'NOANSWER',
        'inquiryStartAt' => date('Y-m-d', strtotime('-7 days')),
        'inquiryEndAt' => date('Y-m-d'),
        'pageNum' => 1,
        'pageSize' => 1,
    ];

    $res = coupang_get($path, $query, $creds);
    if (!$res['ok']) {
        return ['count' => 0, 'warning' => 'inquiry api failed'];
    }

    $total = (int)arr_get($res['json'], ['data', 'pagination', 'totalElements'], -1);
    if ($total >= 0) {
        return ['count' => $total, 'warning' => ''];
    }

    $list = arr_get($res['json'], ['data', 'content'], []);
    return ['count' => is_array($list) ? count($list) : 0, 'warning' => ''];
}

function count_call_center(array $creds): array
{
    $vendorId = (string)$creds['vendorId'];
    $path = "/v2/providers/openapi/apis/api/v5/vendors/{$vendorId}/callCenterInquiries";
    $query = [
        'vendorId' => $vendorId,
        'partnerCounselingStatus' => 'NO_ANSWER',
        'inquiryStartAt' => date('Y-m-d', strtotime('-7 days')),
        'inquiryEndAt' => date('Y-m-d'),
        'pageNum' => 1,
        'pageSize' => 1,
    ];

    $res = coupang_get($path, $query, $creds);
    if (!$res['ok']) {
        return ['count' => 0, 'warning' => 'call center api failed'];
    }

    $total = (int)arr_get($res['json'], ['data', 'pagination', 'totalElements'], -1);
    if ($total >= 0) {
        return ['count' => $total, 'warning' => ''];
    }

    $list = arr_get($res['json'], ['data', 'content'], []);
    return ['count' => is_array($list) ? count($list) : 0, 'warning' => ''];
}

function count_return_or_cancel(array $creds, string $cancelType): array
{
    $vendorId = (string)$creds['vendorId'];
    $path = "/v2/providers/openapi/apis/api/v6/vendors/{$vendorId}/returnRequests";

    $count = 0;
    $loop = 0;
    $nextToken = null;
    $maxLoop = max(1, (int)envv('COUPANG_CLAIM_MAX_PAGES', '8'));

    do {
        $query = [
            'searchType' => 'timeFrame',
            'createdAtFrom' => date('Y-m-d\\TH:i', strtotime('-7 days')),
            'createdAtTo' => date('Y-m-d\\TH:i'),
            'cancelType' => $cancelType,
            'maxPerPage' => 50,
        ];
        if ($nextToken !== null && $nextToken !== '') {
            $query['nextToken'] = $nextToken;
        }

        $res = coupang_get($path, $query, $creds);
        if (!$res['ok']) {
            return ['count' => $count, 'warning' => ($cancelType === 'CANCEL' ? 'cancel api failed' : 'return api failed')];
        }

        $rows = arr_get($res['json'], ['data'], []);
        if (is_array($rows)) {
            $count += count($rows);
        }

        $nextToken = (string)arr_get($res['json'], ['nextToken'], '');
        $loop++;
    } while ($nextToken !== '' && $loop < $maxLoop);

    return ['count' => $count, 'warning' => ''];
}

function count_exchange(array $creds): array
{
    $vendorId = (string)$creds['vendorId'];
    $path = "/v2/providers/openapi/apis/api/v4/vendors/{$vendorId}/exchangeRequests";

    $count = 0;
    $loop = 0;
    $nextToken = null;
    $maxLoop = max(1, (int)envv('COUPANG_CLAIM_MAX_PAGES', '8'));

    do {
        $query = [
            'createdAtFrom' => date('Y-m-d\\TH:i:s', strtotime('-7 days')),
            'createdAtTo' => date('Y-m-d\\TH:i:s'),
            'maxPerPage' => 50,
        ];
        if ($nextToken !== null && $nextToken !== '') {
            $query['nextToken'] = $nextToken;
        }

        $res = coupang_get($path, $query, $creds);
        if (!$res['ok']) {
            return ['count' => $count, 'warning' => 'exchange api failed'];
        }

        $rows = arr_get($res['json'], ['data'], []);
        if (is_array($rows)) {
            $count += count($rows);
        }

        $nextToken = (string)arr_get($res['json'], ['nextToken'], '');
        $loop++;
    } while ($nextToken !== '' && $loop < $maxLoop);

    return ['count' => $count, 'warning' => ''];
}

function count_sold_out(array $creds): array
{
    try {
        $pdo = db();
        $sampleLimit = max(10, min(80, (int)envv('COUPANG_SOLDOUT_SAMPLE_LIMIT', '20')));
        $sql = 'SELECT DISTINCT option_id
                FROM coupang_order_excel
                WHERE option_id IS NOT NULL
                  AND option_id <> ""
                ORDER BY imported_at DESC
                LIMIT ' . (int)$sampleLimit;
        $optionIds = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return ['count' => 0, 'warning' => 'soldout option list query failed'];
    }

    if (!$optionIds) {
        return ['count' => 0, 'warning' => ''];
    }

    $soldOut = 0;
    foreach ($optionIds as $id) {
        $vendorItemId = preg_replace('/[^0-9]/', '', (string)$id);
        if ($vendorItemId === '') {
            continue;
        }

        $path = '/v2/providers/seller_api/apis/api/v1/marketplace/vendor-items/' . $vendorItemId . '/inventories';
        $res = coupang_get($path, [], $creds, 10);
        if (!$res['ok']) {
            continue;
        }

        $stock = (int)arr_get($res['json'], ['data', 'amountInStock'], 0);
        $onSale = (bool)arr_get($res['json'], ['data', 'onSale'], true);
        if ($onSale && $stock <= 0) {
            $soldOut++;
        }
    }

    return ['count' => $soldOut, 'warning' => ''];
}

try {
    $creds = coupang_creds();
    if (!$creds['ok']) {
        ops_json([
            'ok' => true,
            'metrics' => [
                'inquiry' => 0,
                'soldout' => 0,
                'customer_center' => 0,
                'claim_cancel' => 0,
                'claim_return' => 0,
                'claim_exchange' => 0,
            ],
            'warnings' => ['coupang api credentials missing'],
            'source' => [
                'generated_at' => date('Y-m-d H:i:s'),
                'basis' => 'coupang_openapi',
                'cached' => false,
            ],
        ]);
    }

    $cacheTtl = max(10, (int)envv('OPS_STATUS_CACHE_TTL', '60'));
    $cached = ops_cache_read((string)$creds['vendorId'], $cacheTtl);
    if (is_array($cached)) {
        $cached['source']['cached'] = true;
        ops_json($cached);
    }

    $warnings = [];

    $inquiry = count_online_inquiries($creds);
    if ($inquiry['warning'] !== '') { $warnings[] = $inquiry['warning']; }

    $soldout = count_sold_out($creds);
    if ($soldout['warning'] !== '') { $warnings[] = $soldout['warning']; }

    $customerCenter = count_call_center($creds);
    if ($customerCenter['warning'] !== '') { $warnings[] = $customerCenter['warning']; }

    $cancel = count_return_or_cancel($creds, 'CANCEL');
    if ($cancel['warning'] !== '') { $warnings[] = $cancel['warning']; }

    $return = count_return_or_cancel($creds, 'RETURN');
    if ($return['warning'] !== '') { $warnings[] = $return['warning']; }

    $exchange = count_exchange($creds);
    if ($exchange['warning'] !== '') { $warnings[] = $exchange['warning']; }

    $payload = [
        'ok' => true,
        'metrics' => [
            'inquiry' => (int)$inquiry['count'],
            'soldout' => (int)$soldout['count'],
            'customer_center' => (int)$customerCenter['count'],
            'claim_cancel' => (int)$cancel['count'],
            'claim_return' => (int)$return['count'],
            'claim_exchange' => (int)$exchange['count'],
        ],
        'warnings' => $warnings,
        'source' => [
            'generated_at' => date('Y-m-d H:i:s'),
            'basis' => 'coupang_openapi',
            'cached' => false,
        ],
    ];

    ops_cache_write((string)$creds['vendorId'], $payload);
    ops_json($payload);
} catch (Throwable $e) {
    ops_json([
        'ok' => false,
        'message' => $e->getMessage(),
    ], 500);
}