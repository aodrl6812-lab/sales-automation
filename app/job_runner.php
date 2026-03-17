<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function job_create(string $jobName, ?string $requestedBy = null): int {
    $pdo = db();
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

function job_log(int $jobId, string $level, string $message): void {
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO job_logs(job_id, level, message) VALUES(?,?,?)");
    $stmt->execute([$jobId, $level, $message]);
}