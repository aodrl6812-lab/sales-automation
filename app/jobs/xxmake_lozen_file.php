<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function run_make_lozen_file(?int $jobId = null): void
{
    require_once __DIR__ . '/../bootstrap.php';
    require_once __DIR__ . '/../../vendor/autoload.php';
    require_once __DIR__ . '/../db.php';

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