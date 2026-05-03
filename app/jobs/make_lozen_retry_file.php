<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function run_make_lozen_retry_file($jobId): void
{
    $pdo = db();
    ensure_smartstore_retry_dispatch_columns($pdo);

    job_log($jobId, 'info', 'Lozen retry file generation started');

    $sql = '
    SELECT *
    FROM coupang_order_excel
    WHERE COALESCE(tracking_no, "") = ""
      AND shipped_at IS NULL
    ORDER BY ordered_at ASC
    ';

    $stmt = $pdo->query($sql);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sqlSmartstore = '
    SELECT *
    FROM smartstore_order_excel
    WHERE COALESCE(tracking_no, "") = ""
      AND dispatched_at IS NULL
      AND COALESCE(dispatch_result, "") != "SUCCESS"
    ORDER BY paid_at ASC, imported_at ASC
    ';
    $stmtSmartstore = $pdo->query($sqlSmartstore);
    $ordersSmartstore = $stmtSmartstore->fetchAll(PDO::FETCH_ASSOC);

    job_log($jobId, 'info', 'Retry target count(coupang): ' . count($orders));
    job_log($jobId, 'info', 'Retry target count(smartstore): ' . count($ordersSmartstore));

    if (!$orders && !$ordersSmartstore) {
        job_log($jobId, 'info', 'No retry targets');
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
    $exportedCoupang = 0;
    $exportedSmartstore = 0;

    foreach ($orders as $order) {
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

        $unitKorean = (string)json_decode('"\uAC1C"');
        $productNameForExport = make_lozen_retry_unit_to_korean((string)$map['factory_product_name'])
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

        $rowNum++;
        $exportedCoupang++;
    }

    $rawSmartstoreStmt = $pdo->prepare(
        'SELECT raw_json
         FROM orders_raw
         WHERE platform = "smartstore"
           AND order_no = ?
         ORDER BY id DESC
         LIMIT 1'
    );

    foreach ($ordersSmartstore as $order) {
        $rawSmartstoreStmt->execute([(string)($order['order_no'] ?? '')]);
        $rawRow = $rawSmartstoreStmt->fetch(PDO::FETCH_ASSOC);
        $rawSmart = is_array($rawRow) ? json_decode((string)($rawRow['raw_json'] ?? ''), true) : null;
        if (!is_array($rawSmart)) {
            $rawSmart = [];
        }

        $sourceNaver = is_array($rawSmart['_source']['naver'] ?? null) ? $rawSmart['_source']['naver'] : [];
        $sourceContent = is_array($sourceNaver['content'] ?? null) ? $sourceNaver['content'] : [];
        $sourceProductOrder = is_array($sourceContent['productOrder'] ?? null) ? $sourceContent['productOrder'] : [];
        $sourceShipping = is_array($sourceProductOrder['shippingAddress'] ?? null) ? $sourceProductOrder['shippingAddress'] : [];

        $qty = (int)($order['qty'] ?? ($sourceProductOrder['quantity'] ?? 0));
        if ($qty <= 0) {
            $qty = 1;
        }

        $productNameSource = trim((string)($order['product_name'] ?? ''));
        if ($productNameSource === '') {
            $productNameSource = trim((string)($sourceProductOrder['productName'] ?? ''));
        }
        if ($productNameSource === '') {
            $productNameSource = 'SMARTSTORE';
        }

        $optionCandidates = [];
        $candidateValues = [
            $order['option_info'] ?? null,
            $order['seller_product_code'] ?? null,
            $sourceProductOrder['optionCode'] ?? null,
            $sourceProductOrder['productId'] ?? null,
            $sourceProductOrder['itemNo'] ?? null,
            $rawSmart['shipmentBoxId'] ?? null,
        ];
        foreach ($candidateValues as $candidate) {
            $candidateValue = trim((string)$candidate);
            if ($candidateValue !== '') {
                $optionCandidates[$candidateValue] = true;
            }
        }

        $optionId = '';
        foreach (array_keys($optionCandidates) as $candidate) {
            if (isset($optionMap[$candidate])) {
                $optionId = $candidate;
                break;
            }
        }

        if ($optionId === '') {
            $productHint = trim((string)($sourceProductOrder['productOption'] ?? ''));
            $toLower = static function (string $value): string {
                return function_exists('mb_strtolower')
                    ? mb_strtolower($value, 'UTF-8')
                    : strtolower($value);
            };
            $lookupText = $toLower($productNameSource . ' ' . $productHint);
            $bestOptionId = '';
            $bestScore = -1;

            foreach ($optionMap as $mapOptionId => $mapRow) {
                $factoryName = $toLower((string)$mapRow['factory_product_name']);
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

            if ($bestScore > 0) {
                $optionId = $bestOptionId;
            }
        }

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

        $receiverName = trim((string)($order['receiver_name'] ?? ''));
        if ($receiverName === '') {
            $receiverName = trim((string)($sourceShipping['name'] ?? ($rawSmart['receiver']['name'] ?? '')));
        }

        $receiverPhone = trim((string)($order['receiver_phone1'] ?? ''));
        if ($receiverPhone === '') {
            $receiverPhone = trim((string)($order['receiver_phone2'] ?? ''));
        }
        if ($receiverPhone === '') {
            $receiverPhone = trim((string)($sourceShipping['tel1'] ?? ($rawSmart['receiver']['safeNumber'] ?? '')));
        }

        $baseAddress = trim((string)($sourceShipping['baseAddress'] ?? ($rawSmart['receiver']['addr1'] ?? '')));
        $detailAddress = trim((string)($sourceShipping['detailedAddress'] ?? ($rawSmart['receiver']['addr2'] ?? '')));
        $addressText = trim((string)($order['address_text'] ?? ''));
        if ($addressText === '') {
            $addressText = trim($baseAddress . ' ' . $detailAddress);
        }

        $deliveryMessage = trim((string)(
            $sourceProductOrder['shippingMemo']
            ?? $rawSmart['parcelPrintMessage']
            ?? ''
        ));

        $exportNo = trim((string)($order['product_order_no'] ?? ''));
        if ($exportNo === '') {
            $exportNo = trim((string)($sourceProductOrder['productOrderId'] ?? ''));
        }
        if ($exportNo === '') {
            $exportNo = trim((string)($order['order_no'] ?? ''));
        }

        $unitKorean = (string)json_decode('"\uAC1C"');
        $productNameForExport = make_lozen_retry_unit_to_korean($productNameBase)
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
        $sheet->setCellValueExplicit("J{$rowNum}", $exportNo, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

        $rowNum++;
        $exportedSmartstore++;
    }

    if ($rowNum === 1) {
        job_log($jobId, 'warn', 'No mapped retry orders');
        return;
    }

    $dir = __DIR__ . '/../../storage/lozen';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $filename = 'lozen_retry_' . date('Ymd_His') . '.xlsx';
    $path = $dir . '/' . $filename;

    $writer = new Xlsx($spreadsheet);
    $writer->save($path);

    job_log($jobId, 'info', 'Lozen retry file created: ' . $filename);
    job_log(
        $jobId,
        'info',
        'Retry rows exported: total=' . ($rowNum - 1)
        . ', coupang=' . $exportedCoupang
        . ', smartstore=' . $exportedSmartstore
    );
}

function ensure_smartstore_retry_dispatch_columns(PDO $pdo): void
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

function make_lozen_retry_unit_to_korean(string $value): string
{
    $unitKorean = (string)json_decode('"\uAC1C"');
    $normalized = preg_replace('/\bea\b/ui', $unitKorean, $value);
    return is_string($normalized) ? $normalized : $value;
}
