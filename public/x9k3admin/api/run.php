<?php

ini_set('display_errors', '0');
ob_start();

function respond_json(array $payload, int $status = 200): void
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
require_once dirname(__DIR__, 3) . '/app/job_runner.php';
require_once dirname(__DIR__, 3) . '/app/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$action = (string)($data['action'] ?? '');
$from = isset($data['from']) ? (string)$data['from'] : date('Y-m-d 00:00:00', strtotime('-1 day'));
$to = isset($data['to']) ? (string)$data['to'] : date('Y-m-d H:i:s', strtotime('+3 hours'));

$jobName = match ($action) {
    'process_orders' => 'process_orders',
    'process_shipping' => 'process_shipping',
    'check_delivery_status' => 'check_delivery_status',
    'recover_normalize' => 'recover_normalize',
    'recover_lozen' => 'recover_lozen',
    'make_lozen_retry_file' => 'make_lozen_retry_file',
    default => null,
};

if ($jobName === null) {
    respond_json(['ok' => false, 'message' => 'Invalid action'], 400);
}

try {
    $jobId = job_create($jobName, 'v2-ui');

    $phpBinary = envv('PHP_CLI_BIN', PHP_BINARY);
    $runner = dirname(__DIR__, 3) . '/app/run_job.php';

    if (!is_file($phpBinary)) {
        $fallbackPhp = 'C:\\APM\\PHP\\php.exe';
        if (is_file($fallbackPhp)) {
            $phpBinary = $fallbackPhp;
        }
    }

    $fromArg = '"' . str_replace('"', '\\"', $from) . '"';
    $toArg = '"' . str_replace('"', '\\"', $to) . '"';
    $cmd = 'cmd /c start "" /B "' . $phpBinary . '" "' . $runner . '" ' . $jobId . ' ' . $fromArg . ' ' . $toArg;

    $dispatched = false;
    if (function_exists('popen') && function_exists('pclose')) {
        $handle = @popen($cmd, 'r');
        if (is_resource($handle)) {
            @pclose($handle);
            $dispatched = true;
        }
    }

    if ($dispatched) {
        job_log($jobId, 'info', 'Dispatched background runner');

        usleep(400000);
        $st = db()->prepare('SELECT status, started_at FROM jobs WHERE id = ?');
        $st->execute([$jobId]);
        $current = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $stillQueued = (($current['status'] ?? '') === 'queued') && empty($current['started_at']);

        if ($stillQueued) {
            job_log($jobId, 'warn', 'Runner not picked up. Switching to sync mode.');
            $dispatched = false;
        }
    }

    if (!$dispatched) {
        job_log($jobId, 'warn', 'Background dispatch failed. Running synchronously.');

        job_start($jobId);
        try {
            require_once dirname(__DIR__, 3) . '/app/jobs/process_orders.php';
            require_once dirname(__DIR__, 3) . '/app/jobs/process_shipping.php';
            require_once dirname(__DIR__, 3) . '/app/jobs/make_lozen_retry_file.php';

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
            }

            job_finish($jobId, true);
        } catch (Throwable $inner) {
            job_log($jobId, 'error', $inner->getMessage());
            job_finish($jobId, false);
        }
    }

    respond_json(['ok' => true, 'job_id' => $jobId, 'dispatched' => $dispatched]);
} catch (Throwable $e) {
    respond_json(['ok' => false, 'message' => $e->getMessage()], 500);
}