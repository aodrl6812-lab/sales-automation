<?php
declare(strict_types=1);

/**
 * Naver SmartStore order collector.
 *
 * Constraints:
 * - No schema changes
 * - No modification to existing Coupang/shipping flow
 * - Save into orders_raw with platform='smartstore'
 *
 * Stored raw_json is "coupang-like canonical" so a future normalizer can reuse
 * the same extraction approach used by step2_normalize_coupang().
 */
function run_collect_naver_orders(int $jobId, string $from, string $to): void
{
    $baseUrl = rtrim(envv('NAVER_API_BASE_URL', 'https://api.commerce.naver.com/external'), '/');
    $ordersPath = envv('NAVER_ORDERS_PATH', '/v1/pay-order/seller/product-orders');
    $pageSize = max(1, min(300, (int)envv('NAVER_ORDER_PAGE_SIZE', '100')));
    $maxPages = max(1, min(500, (int)envv('NAVER_ORDER_MAX_PAGES', '30')));
    $status = trim(envv('NAVER_PRODUCT_ORDER_STATUS', 'PLACE_ORDER'));
    $allowedStatuses = naver_collect_allowed_new_statuses($status);

    if (trim(envv('NAVER_TOKEN_URL', '')) === '') {
        $defaultTokenUrl = 'https://api.commerce.naver.com/external/v1/oauth2/token';
        putenv('NAVER_TOKEN_URL=' . $defaultTokenUrl);
        $_ENV['NAVER_TOKEN_URL'] = $defaultTokenUrl;
        $_SERVER['NAVER_TOKEN_URL'] = $defaultTokenUrl;
    }

    $token = naver_collect_get_access_token($jobId);
    if ($token === '') {
        job_log($jobId, 'error', 'Naver access token unavailable');
        return;
    }

    $url = $baseUrl . '/' . ltrim($ordersPath, '/');
    $pdo = db();
    $saveStmt = $pdo->prepare(
        "INSERT INTO orders_raw (platform, order_no, raw_json, ordered_at)
         VALUES ('smartstore', ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            raw_json = VALUES(raw_json),
            ordered_at = VALUES(ordered_at)"
    );
    job_log($jobId, 'info', 'Naver order collection started');
    job_log($jobId, 'info', "Range: {$from} ~ {$to}");
    job_log($jobId, 'info', 'Endpoint: ' . $url);

    $timezoneName = trim(envv('NAVER_API_TIMEZONE', 'Asia/Seoul'));
    try {
        $tz = new DateTimeZone($timezoneName);
    } catch (Throwable $e) {
        $tz = new DateTimeZone('Asia/Seoul');
    }

    $fromTs = strtotime($from);
    $toTs = strtotime($to);
    if ($fromTs === false) {
        $fromTs = strtotime('-1 day');
    }
    if ($toTs === false) {
        $toTs = time();
    }

    if ($toTs < $fromTs) {
        $toTs = $fromTs;
    }

    $windowMaxSeconds = 86400 - 1; // API constraint: from~to must be within 24h.
    $cursorFromTs = $fromTs;
    $page = 0;
    $saved = 0;
    $seen = [];
    $fetchedTotal = 0;
    $skippedByStatus = 0;
    $skippedBySeen = 0;

    while ($cursorFromTs <= $toTs) {
        $windowToTs = min($cursorFromTs + $windowMaxSeconds, $toTs);
        $windowFromIso = (new DateTimeImmutable('@' . $cursorFromTs))
            ->setTimezone($tz)
            ->format('Y-m-d\TH:i:s.000P');
        $windowToIso = (new DateTimeImmutable('@' . $windowToTs))
            ->setTimezone($tz)
            ->format('Y-m-d\TH:i:s.000P');

        $nextToken = null;
        $moreFrom = null;
        $moreSequence = null;
        $pageInWindow = 0;

        do {
            $page++;
            $pageInWindow++;

            $query = [
                'from' => $windowFromIso,
                'to' => $windowToIso,
                'size' => $pageSize,
            ];

            if ($status !== '') {
                $query['productOrderStatus'] = $status;
            }

            if ($moreFrom !== null && $moreFrom !== '') {
                $query['from'] = $moreFrom;
                if ($moreSequence !== null && $moreSequence !== '') {
                    $query['moreSequence'] = (string)$moreSequence;
                }
            }

            if ($nextToken !== null && $nextToken !== '') {
                $query['nextToken'] = $nextToken;
            }

            $resp = naver_collect_request_json(
                $jobId,
                'GET',
                $url,
                $query,
                null,
                [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                ]
            );

            if (!$resp['ok']) {
                job_log(
                    $jobId,
                    'error',
                    'Naver API request failed at page=' . $page
                    . ' window=' . $windowFromIso . ' ~ ' . $windowToIso
                );
                return;
            }

            $orders = naver_collect_extract_orders($resp['json']);
            $nextToken = naver_collect_extract_next_token($resp['json']);

            $more = (array)($resp['json']['more'] ?? []);
            $moreFromValue = $more['moreFrom'] ?? null;
            $moreFrom = (is_string($moreFromValue) && $moreFromValue !== '')
                ? $moreFromValue
                : null;
            $moreSequence = $more['moreSequence'] ?? null;

            if (!$orders) {
                job_log($jobId, 'info', 'No orders in page=' . $page);
            }

            foreach ($orders as $order) {
                $fetchedTotal++;
                if (!naver_collect_is_new_order_target($order, $allowedStatuses)) {
                    $skippedByStatus++;
                    continue;
                }

                $orderNo = naver_collect_make_order_no($order);
                if ($orderNo === '') {
                    continue;
                }

                if (isset($seen[$orderNo])) {
                    $skippedBySeen++;
                    continue;
                }
                $seen[$orderNo] = true;

                $canonical = naver_collect_to_coupang_like($order);
                $orderedAt = (string)($canonical['orderedAt'] ?? '');
                $orderedAtMysql = naver_collect_to_mysql_datetime($orderedAt) ?? date('Y-m-d H:i:s');

                $json = json_encode($canonical, JSON_UNESCAPED_UNICODE);
                if (!is_string($json) || $json === '') {
                    job_log($jobId, 'warn', 'Skip order: json_encode failed order_no=' . $orderNo);
                    continue;
                }

                $saveStmt->execute([$orderNo, $json, $orderedAtMysql]);
                $saved++;
            }

            job_log(
                $jobId,
                'info',
                'Page=' . $page
                . ', fetched=' . count($orders)
                . ', nextToken=' . ($nextToken ?: 'null')
                . ', moreFrom=' . ($moreFrom ?: 'null')
                . ', window=' . $windowFromIso . ' ~ ' . $windowToIso
            );
        } while (($nextToken || $moreFrom) && $pageInWindow < $maxPages);

        if (($nextToken || $moreFrom) && $pageInWindow >= $maxPages) {
            job_log(
                $jobId,
                'warn',
                'Window page limit reached: ' . $windowFromIso . ' ~ ' . $windowToIso
            );
        }

        $cursorFromTs = $windowToTs + 1;
    }

    job_log(
        $jobId,
        'info',
        'Naver order collection finished. fetched=' . $fetchedTotal
        . ', saved=' . $saved
        . ', skipped_status=' . $skippedByStatus
        . ', skipped_seen=' . $skippedBySeen
    );
}
function naver_collect_get_access_token(int $jobId): string
{
    $env = strtoupper(trim(envv('APP_ENV', 'local')));

    $staticTokenKeys = [
        'NAVER_ACCESS_TOKEN',
        'NAVER_OAUTH_ACCESS_TOKEN',
        'NAVER_ACCESS_TOKEN_' . $env,
    ];

    foreach ($staticTokenKeys as $tokenKey) {
        $tokenValue = trim(envv($tokenKey, ''));
        if ($tokenValue !== '') {
            return $tokenValue;
        }
    }

    $tokenUrl = trim(envv('NAVER_TOKEN_URL', ''));
    if ($tokenUrl === '') {
        $tokenUrl = trim(envv('NAVER_OAUTH_TOKEN_URL', ''));
    }
    if ($tokenUrl === '') {
        $tokenUrl = 'https://api.commerce.naver.com/external/v1/oauth2/token';
    }

    $clientId = trim(envv('NAVER_CLIENT_ID', ''));
    if ($clientId === '') {
        $clientId = trim(envv('NAVER_COMMERCE_CLIENT_ID', ''));
    }
    if ($clientId === '') {
        $clientId = trim(envv('NAVER_API_CLIENT_ID', ''));
    }
    if ($clientId === '') {
        $clientId = trim(envv('NAVER_CLIENT_ID_' . $env, ''));
    }

    $clientSecret = trim(envv('NAVER_CLIENT_SECRET', ''));
    if ($clientSecret === '') {
        $clientSecret = trim(envv('NAVER_COMMERCE_CLIENT_SECRET', ''));
    }
    if ($clientSecret === '') {
        $clientSecret = trim(envv('NAVER_API_CLIENT_SECRET', ''));
    }
    if ($clientSecret === '') {
        $clientSecret = trim(envv('NAVER_CLIENT_SECRET_' . $env, ''));
    }

    $tokenType = strtoupper(trim(envv('NAVER_TOKEN_TYPE', envv('NAVER_OAUTH_TOKEN_TYPE', 'SELF'))));
    if (!in_array($tokenType, ['SELF', 'SELLER'], true)) {
        $tokenType = 'SELF';
    }

    $accountId = trim(envv('NAVER_ACCOUNT_ID', envv('NAVER_SELLER_ACCOUNT_ID', '')));

    $missing = [];
    if ($clientId === '') {
        $missing[] = 'NAVER_CLIENT_ID';
    }
    if ($clientSecret === '') {
        $missing[] = 'NAVER_CLIENT_SECRET';
    }

    if ($missing) {
        job_log(
            $jobId,
            'warn',
            'Naver token issue config missing: ' . implode(', ', $missing)
        );
        return '';
    }

    $makeSignedBody = static function (
        string $type,
        string $id,
        string $secret,
        ?string $sellerAccountId
    ): array {
        $timestamp = (string)floor(microtime(true) * 1000);
        $password = $id . '_' . $timestamp;
        $hashed = crypt($password, $secret);
        $clientSecretSign = '';

        if (is_string($hashed) && $hashed !== '' && $hashed[0] !== '*') {
            $clientSecretSign = base64_encode($hashed);
        }

        $body = [
            'client_id' => $id,
            'timestamp' => $timestamp,
            'grant_type' => 'client_credentials',
            'type' => $type,
        ];

        if ($type === 'SELLER' && $sellerAccountId !== null && $sellerAccountId !== '') {
            $body['account_id'] = $sellerAccountId;
        }

        if ($clientSecretSign !== '') {
            $body['client_secret_sign'] = $clientSecretSign;
        }

        return $body;
    };

    $candidateTypes = [$tokenType];
    if (!in_array('SELF', $candidateTypes, true)) {
        $candidateTypes[] = 'SELF';
    }

    if ($tokenType === 'SELLER' && $accountId === '') {
        job_log($jobId, 'warn', 'NAVER_ACCOUNT_ID missing for SELLER. fallback type=SELF');
        $candidateTypes = ['SELF'];
    }

    foreach ($candidateTypes as $candidateType) {
        $body = $makeSignedBody($candidateType, $clientId, $clientSecret, $accountId);

        $resp = naver_collect_request_json(
            $jobId,
            'POST',
            $tokenUrl,
            [],
            http_build_query($body, '', '&', PHP_QUERY_RFC3986),
            ['Content-Type: application/x-www-form-urlencoded']
        );

        if ($resp['ok']) {
            $token = (string)($resp['json']['access_token'] ?? '');
            if ($token !== '') {
                if ($candidateType !== $tokenType) {
                    job_log(
                        $jobId,
                        'warn',
                        'Naver token issued with fallback type=' . $candidateType
                    );
                }
                return $token;
            }

            job_log($jobId, 'error', 'Naver token response missing access_token');
            return '';
        }
    }

    job_log($jobId, 'error', 'Failed to issue Naver access token');
    return '';
}

function naver_collect_request_json(

    int $jobId,

    string $method,

    string $url,

    array $query = [],

    ?string $body = null,

    array $headers = []

): array {

    $finalUrl = $url;

    if ($query) {

        $qs = http_build_query($query);

        $finalUrl .= (str_contains($finalUrl, '?') ? '&' : '?') . $qs;

    }



    $ch = curl_init();

    curl_setopt_array($ch, [

        CURLOPT_URL => $finalUrl,

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_TIMEOUT => 25,

        CURLOPT_CONNECTTIMEOUT => 7,

        CURLOPT_CUSTOMREQUEST => strtoupper($method),

        CURLOPT_HTTPHEADER => $headers,

    ]);

    if ($body !== null) {

        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    }



    $raw = curl_exec($ch);

    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $curlErr = curl_error($ch);

    curl_close($ch);



    $rawText = is_string($raw) ? $raw : '';

    if ($curlErr !== '') {

        job_log($jobId, 'error', 'cURL error: ' . $curlErr);

        return ['ok' => false, 'http' => 0, 'json' => [], 'raw' => $rawText];

    }



    $decoded = json_decode($rawText, true);

    if ($http < 200 || $http >= 300 || !is_array($decoded)) {

        $preview = mb_substr($rawText, 0, 1200);

        job_log($jobId, 'error', 'HTTP=' . $http . ' body=' . $preview);

        return ['ok' => false, 'http' => $http, 'json' => [], 'raw' => $rawText];

    }



    return ['ok' => true, 'http' => $http, 'json' => $decoded, 'raw' => $rawText];

}



/**

 * Accepts multiple response shapes to reduce coupling with endpoint variants.

 *

 * @return array<int,array<string,mixed>>

 */

function naver_collect_extract_orders(array $json): array

{

    $candidates = [];



    if (isset($json['data']) && is_array($json['data'])) {

        $candidates[] = $json['data'];

    }

    $candidates[] = $json;



    foreach ($candidates as $node) {

        foreach (['orders', 'contents', 'items', 'productOrders'] as $key) {

            if (isset($node[$key]) && is_array($node[$key])) {

                return array_values(array_filter($node[$key], 'is_array'));

            }

        }

    }



    return [];

}



function naver_collect_extract_next_token(array $json): ?string

{

    $paths = [

        ['nextToken'],

        ['data', 'nextToken'],

        ['pagination', 'nextToken'],

        ['data', 'pagination', 'nextToken'],

    ];



    foreach ($paths as $path) {

        $val = $json;

        foreach ($path as $k) {

            if (!is_array($val) || !array_key_exists($k, $val)) {

                $val = null;

                break;

            }

            $val = $val[$k];

        }

        if (is_string($val) && $val !== '') {

            return $val;

        }

    }



    return null;

}



function naver_collect_make_order_no(array $order): string

{

    $orderId = (string)(

        $order['orderId']

        ?? $order['payOrderId']

        ?? $order['orderNo']

        ?? ''

    );

    if ($orderId === '') {

        $seed = json_encode($order, JSON_UNESCAPED_UNICODE) ?: uniqid('nvr_', true);

        return 'NVR-HASH-' . substr(sha1($seed), 0, 20);

    }

    return 'NVR-' . $orderId;

}



/**

 * Converts a Naver order payload into the same logical keys used by coupang raw.

 *

 * @return array<string,mixed>

 */

function naver_collect_to_coupang_like(array $order): array

{

    $orderer = (array)($order['orderer'] ?? []);

    $receiver = (array)($order['receiver'] ?? $order['shippingAddress'] ?? []);

    $items = naver_collect_extract_items($order);



    $normalizedItems = [];

    foreach ($items as $item) {

        $normalizedItems[] = [

            'vendorItemId' => (string)(

                $item['sellerManagementCode']

                ?? $item['productId']

                ?? $item['channelProductId']

                ?? $item['productOrderId']

                ?? ''

            ),

            'shippingCount' => (int)($item['quantity'] ?? $item['orderQuantity'] ?? 1),

            'raw' => $item,

        ];

    }



    $shipmentBoxId = (string)(

        $order['shipmentBoxId']

        ?? $order['productOrderId']

        ?? $order['orderId']

        ?? ''

    );



    return [

        '_meta' => [

            'platform' => 'naver',

            'schema' => 'coupang-like-v1',

            'collected_at' => date('c'),

        ],

        'orderedAt' => (string)(

            $order['orderedAt']

            ?? $order['paidAt']

            ?? $order['paymentDate']

            ?? $order['createdAt']

            ?? $order['lastChangedDate']

            ?? $order['content']['order']['paymentDate']

            ?? $order['content']['order']['orderDate']

            ?? $order['content']['productOrder']['orderDate']

            ?? $order['content']['productOrder']['placeOrderDate']

            ?? $order['orderDate']

            ?? date('c')

        ),

        'invoiceNumber' => (string)(

            $order['trackingNumber']

            ?? $order['invoiceNo']

            ?? ''

        ),

        'shipmentBoxId' => $shipmentBoxId,

        'orderer' => [

            'name' => (string)($orderer['name'] ?? $order['ordererName'] ?? ''),

            'safeNumber' => (string)($orderer['tel1'] ?? $orderer['phoneNumber'] ?? ''),

            'ordererNumber' => (string)($orderer['tel2'] ?? ''),

        ],

        'receiver' => [

            'name' => (string)($receiver['name'] ?? $order['receiverName'] ?? ''),

            'safeNumber' => (string)($receiver['tel1'] ?? $receiver['phoneNumber'] ?? ''),

            'receiverNumber' => (string)($receiver['tel2'] ?? ''),

            'postCode' => (string)($receiver['zipCode'] ?? $receiver['postCode'] ?? ''),

            'addr1' => (string)($receiver['baseAddress'] ?? $receiver['address1'] ?? ''),

            'addr2' => (string)($receiver['detailedAddress'] ?? $receiver['address2'] ?? ''),

        ],

        'parcelPrintMessage' => (string)($order['deliveryMemo'] ?? $order['deliveryMessage'] ?? ''),

        'orderItems' => $normalizedItems,

        '_source' => [

            'naver' => $order,

        ],

    ];

}



/**

 * @return array<int,array<string,mixed>>

 */

function naver_collect_extract_items(array $order): array

{

    foreach (['orderItems', 'productOrders', 'items', 'products'] as $key) {

        if (isset($order[$key]) && is_array($order[$key])) {

            return array_values(array_filter($order[$key], 'is_array'));

        }

    }



    if (isset($order['productOrderId']) || isset($order['productId'])) {

        return [$order];

    }



    return [];

}

function naver_collect_extract_product_order_no(array $order): string
{
    return trim((string)(
        $order['productOrderId']
        ?? $order['content']['productOrder']['productOrderId']
        ?? $order['content']['productOrderId']
        ?? ''
    ));
}

function naver_collect_extract_product_order_status(array $order): string
{
    $candidate = (string)(
        $order['productOrderStatus']
        ?? $order['status']
        ?? $order['content']['productOrder']['productOrderStatus']
        ?? $order['content']['productOrder']['status']
        ?? ''
    );
    return strtoupper(trim($candidate));
}

function naver_collect_allowed_new_statuses(string $configuredStatus): array
{
    $raw = trim(envv('NAVER_NEW_ORDER_STATUSES', ''));
    if ($raw !== '') {
        $statuses = array_filter(array_map('trim', explode(',', strtoupper($raw))));
        if (!empty($statuses)) {
            return array_values(array_unique($statuses));
        }
    }

    $configured = strtoupper(trim($configuredStatus));
    if ($configured === '') {
        $configured = 'PLACE_ORDER';
    }

    if ($configured === 'PLACE_ORDER') {
        return ['PLACE_ORDER', 'PLACED', 'PAYED'];
    }

    return [$configured];
}

function naver_collect_is_new_order_target(array $order, array $allowedStatuses): bool
{
    $currentStatus = naver_collect_extract_product_order_status($order);
    if ($currentStatus === '') {
        // Some response shapes omit status even when productOrderStatus query is applied.
        // In that case, trust the API-side filter and do not drop the order here.
        return true;
    }
    return in_array($currentStatus, $allowedStatuses, true);
}



function naver_collect_to_mysql_datetime(string $value): ?string

{

    if ($value === '') {

        return null;

    }

    $ts = strtotime($value);

    if ($ts === false) {

        return null;

    }

    return date('Y-m-d H:i:s', $ts);

}
