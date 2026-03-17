<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;

function run_import_lozen_invoice(?int $jobId = null): void
{
    require_once __DIR__ . '/../bootstrap.php';
    require_once __DIR__ . '/../../vendor/autoload.php';
    require_once __DIR__ . '/../db.php';

    $pdo = db();

    job_log($jobId, 'info', "송장 엑셀 import 시작");

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