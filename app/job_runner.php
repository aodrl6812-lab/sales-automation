<?php

require_once __DIR__ . '/db.php';

function job_create(string $jobName, ?string $requestedBy = null): int {
    $pdo = db();
    $pdo->exec("UPDATE jobs
                SET status='failed', finished_at=NOW()
                WHERE status='running'
                  AND started_at IS NOT NULL
                  AND started_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");

    $stmt = $pdo->prepare("INSERT INTO jobs(job_name, status, requested_by) VALUES(?, 'queued', ?)");
    $stmt->execute([$jobName, $requestedBy]);
    return (int)$pdo->lastInsertId();
}

function job_start(int $jobId): void {
    $pdo = db();
    $stmt = $pdo->prepare("UPDATE jobs SET status='running', started_at=NOW() WHERE id=?");
    $stmt->execute([$jobId]);
}

function job_finish(int $jobId, bool $success): void {
    $pdo = db();
    $status = $success ? 'success' : 'failed';
    $stmt = $pdo->prepare("UPDATE jobs SET status=?, finished_at=NOW() WHERE id=?");
    $stmt->execute([$status, $jobId]);
}

function normalize_job_log_level(string $level): string {
    $lv = strtolower(trim($level));

    return match ($lv) {
        'info', 'warn', 'error' => $lv,
        'warning' => 'warn',
        'success' => 'info',
        'failed' => 'error',
        default => 'info',
    };
}

function job_log(int $jobId, string $level, string $message): void {
    $pdo = db();
    $safeLevel = normalize_job_log_level($level);
    $stmt = $pdo->prepare("INSERT INTO job_logs(job_id, level, message) VALUES(?,?,?)");
    $stmt->execute([$jobId, $safeLevel, $message]);
}