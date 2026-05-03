<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/job_runner.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

$jobId = isset($argv[1]) ? (int)$argv[1] : 0;
$from = isset($argv[2]) ? (string)$argv[2] : date('Y-m-d 00:00:00', strtotime('-3 day'));
$to = isset($argv[3]) ? (string)$argv[3] : date('Y-m-d H:i:s', strtotime('+3 hours'));

if ($jobId <= 0) {
    exit(1);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT job_name FROM jobs WHERE id = ? LIMIT 1');
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    exit(1);
}

$jobName = (string)$job['job_name'];

job_start($jobId);

try {
    require_once __DIR__ . '/jobs/process_orders.php';
    require_once __DIR__ . '/jobs/process_shipping.php';
    require_once __DIR__ . '/jobs/make_lozen_retry_file.php';

    if ($jobName === 'process_orders') {
        run_process_orders($jobId, $from, $to);
    } elseif ($jobName === 'process_shipping') {
        run_process_shipping($jobId, $from, $to);
    } elseif ($jobName === 'check_delivery_status') {
        run_check_delivery_status($jobId);
    } elseif ($jobName === 'recover_normalize') {
        step2_normalize_coupang($jobId, $from, $to);
    } elseif ($jobName === 'recover_lozen') {
        step4_make_lozen_file($jobId);
    } elseif ($jobName === 'make_lozen_retry_file') {
        run_make_lozen_retry_file($jobId);
    } else {
        throw new RuntimeException('Unsupported job_name: ' . $jobName);
    }

    job_finish($jobId, true);
} catch (Throwable $e) {
    job_log($jobId, 'error', $e->getMessage());
    job_finish($jobId, false);
}