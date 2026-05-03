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

function ensure_summary_columns(PDO $pdo): void
{
    $dbName = envv('DB_NAME', 'ship_new');

    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = "coupang_order_excel"
           AND COLUMN_NAME IN ("delivery_status", "delivery_status_checked_at", "delivery_completed_at", "is_delivering", "is_delivered")'
    );
    $stmt->execute([$dbName]);

    $exists = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $exists[(string)$row['COLUMN_NAME']] = true;
    }

    $alters = [];
    if (!isset($exists['delivery_status'])) {
        $alters[] = 'ADD COLUMN delivery_status VARCHAR(30) NULL AFTER shipped_at';
    }
    if (!isset($exists['delivery_status_checked_at'])) {
        $alters[] = 'ADD COLUMN delivery_status_checked_at DATETIME NULL AFTER delivery_status';
    }
    if (!isset($exists['delivery_completed_at'])) {
        $alters[] = 'ADD COLUMN delivery_completed_at DATETIME NULL AFTER delivery_status_checked_at';
    }
    if (!isset($exists['is_delivering'])) {
        $alters[] = 'ADD COLUMN is_delivering TINYINT(1) NOT NULL DEFAULT 0 AFTER delivery_completed_at';
    }
    if (!isset($exists['is_delivered'])) {
        $alters[] = 'ADD COLUMN is_delivered TINYINT(1) NOT NULL DEFAULT 0 AFTER is_delivering';
    }

    if ($alters) {
        $pdo->exec('ALTER TABLE coupang_order_excel ' . implode(', ', $alters));
    }
}

try {
    $pdo = db();
    ensure_summary_columns($pdo);

    $windowExpr = 'CONCAT(DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 DAY), "%Y-%m-%d"), " 16:00:00")';

    $totalStmt = $pdo->query(
        'SELECT COUNT(DISTINCT order_no)
         FROM coupang_order_excel
         WHERE imported_at >= ' . $windowExpr
    );
    $totalOrders = (int)($totalStmt->fetchColumn() ?: 0);

    $instructionStmt = $pdo->query(
        'SELECT COUNT(DISTINCT order_no)
         FROM coupang_order_excel
         WHERE imported_at >= ' . $windowExpr . '
           AND shipped_at IS NOT NULL
           AND is_delivering = 0
           AND is_delivered = 0'
    );
    $shippingInstruction = (int)($instructionStmt->fetchColumn() ?: 0);

    $deliveringStmt = $pdo->query(
        'SELECT COUNT(DISTINCT order_no)
         FROM coupang_order_excel
         WHERE imported_at >= ' . $windowExpr . '
           AND is_delivering = 1
           AND is_delivered = 0'
    );
    $deliveringCount = (int)($deliveringStmt->fetchColumn() ?: 0);

    respond_json([
        'ok' => true,
        'metrics' => [
            'total_orders' => $totalOrders,
            'shipping_instruction' => $shippingInstruction,
            'delivering' => $deliveringCount,
        ],
        'source' => [
            'today' => date('Y-m-d'),
            'basis' => 'coupang_order_excel',
        ],
    ]);
} catch (Throwable $e) {
    respond_json(['ok' => false, 'message' => $e->getMessage()], 500);
}