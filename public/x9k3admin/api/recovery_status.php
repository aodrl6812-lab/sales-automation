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

function has_column(PDO $pdo, string $table, string $column): bool
{
    $dbName = envv('DB_NAME', 'ship_new');
    $st = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    $st->execute([$dbName, $table, $column]);
    return (int)$st->fetchColumn() > 0;
}

try {
    $pdo = db();
	
	/*$rawCollectedStmt = $pdo->query(
        'SELECT COUNT(*)
         FROM orders_raw
         WHERE created_at >= CURDATE()
           AND created_at < DATE_ADD(CURDATE("Y-M-D h:i:s"), INTERVAL 1 DAY)'
    );*/
	$rawCollectedStmt = $pdo->query(
        'SELECT COUNT(*)
         FROM orders_raw
         WHERE created_at >= CONCAT(DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 DAY), "%Y-%m-%d"), "14:00:00")'
    );
    $rawCollected = (int)($rawCollectedStmt->fetchColumn() ?: 0);

    $rawPendingStmt = $pdo->query(
        'SELECT COUNT(*)
         FROM orders_raw
         WHERE is_normalized = 0
           AND created_at >= CURDATE()
           AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)'
    );
    $rawPending = (int)($rawPendingStmt->fetchColumn() ?: 0);

    $excelDateCol = has_column($pdo, 'coupang_order_excel', 'imported_at') ? 'imported_at' : 'created_at';

    $excelPendingStmt = $pdo->query(
        'SELECT COUNT(DISTINCT order_no)
         FROM coupang_order_excel
         WHERE ' . $excelDateCol . ' >= CURDATE()
           AND ' . $excelDateCol . ' < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
           AND COALESCE(tracking_no, "") = ""
           AND lozen_exported_at IS NULL'
    );
    $excelPending = (int)($excelPendingStmt->fetchColumn() ?: 0);

    respond_json([
        'ok' => true,
        'counts' => [
            'raw_collected_today' => $rawCollected,
            'raw_pending_normalize' => $rawPending,
            'excel_pending_lozen' => $excelPending,
        ],
        'actions' => [
            'can_recover_normalize' => $rawPending > 0,
            'can_recover_lozen' => $rawPending === 0 && $excelPending > 0,
        ],
        'today' => date('Y-m-d'),
    ]);
} catch (Throwable $e) {
    respond_json(['ok' => false, 'message' => $e->getMessage()], 500);
}