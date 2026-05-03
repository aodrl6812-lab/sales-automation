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
require_once dirname(__DIR__, 3) . '/app/db.php';

$jobId = (int)($_GET['job_id'] ?? 0);
if ($jobId <= 0) {
    respond_json(['ok' => false, 'message' => 'job_id is required'], 400);
}

$sinceId = (int)($_GET['since_id'] ?? 0);
if ($sinceId < 0) {
    $sinceId = 0;
}

try {
    $pdo = db();

    $jobStmt = $pdo->prepare('SELECT id, job_name, status, created_at, started_at, finished_at FROM jobs WHERE id = ?');
    $jobStmt->execute([$jobId]);
    $job = $jobStmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        respond_json(['ok' => false, 'message' => 'Job not found'], 404);
    }

    $logStmt = $pdo->prepare('
        SELECT id, level, message, created_at
        FROM job_logs
        WHERE job_id = ? AND id > ?
        ORDER BY id ASC
        LIMIT 500
    ');
    $logStmt->execute([$jobId, $sinceId]);
    $logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

    $lastLogId = $sinceId;
    if (!empty($logs)) {
        $last = end($logs);
        $lastLogId = (int)($last['id'] ?? $sinceId);
    }

    respond_json([
        'ok' => true,
        'job' => $job,
        'logs' => $logs,
        'last_log_id' => $lastLogId,
    ]);
} catch (Throwable $e) {
    respond_json(['ok' => false, 'message' => $e->getMessage()], 500);
}