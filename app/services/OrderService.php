<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

class OrderService
{
    public function __construct()
    {
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $pdo = db();
        $sql = 'CREATE TABLE IF NOT EXISTS manual_orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            buyer_name VARCHAR(100) NOT NULL,
            buyer_phone VARCHAR(30) NOT NULL,
            receiver_name VARCHAR(100) NOT NULL,
            receiver_address VARCHAR(255) NOT NULL,
            option_id VARCHAR(64) NULL,
            product_name VARCHAR(255) NOT NULL,
            qty INT NOT NULL DEFAULT 1,
            size_s_qty INT NOT NULL DEFAULT 0,
            size_m_qty INT NOT NULL DEFAULT 0,
            size_l_qty INT NOT NULL DEFAULT 0,
            size_xl_qty INT NOT NULL DEFAULT 0,
            product_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            delivery_message VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_manual_orders_created_at (created_at),
            KEY idx_manual_orders_option_id (option_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
        $pdo->exec($sql);

        $this->ensureColumn($pdo, 'option_id', 'ALTER TABLE manual_orders ADD COLUMN option_id VARCHAR(64) NULL AFTER receiver_address');
        $this->ensureColumn($pdo, 'size_s_qty', 'ALTER TABLE manual_orders ADD COLUMN size_s_qty INT NOT NULL DEFAULT 0 AFTER qty');
        $this->ensureColumn($pdo, 'size_m_qty', 'ALTER TABLE manual_orders ADD COLUMN size_m_qty INT NOT NULL DEFAULT 0 AFTER size_s_qty');
        $this->ensureColumn($pdo, 'size_l_qty', 'ALTER TABLE manual_orders ADD COLUMN size_l_qty INT NOT NULL DEFAULT 0 AFTER size_m_qty');
        $this->ensureColumn($pdo, 'size_xl_qty', 'ALTER TABLE manual_orders ADD COLUMN size_xl_qty INT NOT NULL DEFAULT 0 AFTER size_l_qty');

        $stmt = $pdo->query('SHOW INDEX FROM manual_orders WHERE Key_name = "idx_manual_orders_option_id"');
        if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec('ALTER TABLE manual_orders ADD KEY idx_manual_orders_option_id (option_id)');
        }
    }

    private function ensureColumn(PDO $pdo, string $column, string $alterSql): void
    {
        $stmt = $pdo->query('SHOW COLUMNS FROM manual_orders LIKE ' . $pdo->quote($column));
        if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec($alterSql);
        }
    }

    public function getList(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM manual_orders ORDER BY id DESC LIMIT :lim');
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findById(int $id): ?array
    {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM manual_orders WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getProductOptions(): array
    {
        $pdo = db();
        $sql = 'SELECT DISTINCT option_id, factory_product_name
                FROM product_option_map
                WHERE option_id IS NOT NULL
                  AND option_id <> ""
                  AND factory_product_name IS NOT NULL
                  AND factory_product_name <> ""
                ORDER BY factory_product_name ASC
                LIMIT 1000';
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    private function resolveProductNameByOptionId(string $optionId): string
    {
        if ($optionId === '') {
            return '';
        }

        $pdo = db();
        $stmt = $pdo->prepare('SELECT factory_product_name FROM product_option_map WHERE option_id = ? LIMIT 1');
        $stmt->execute([$optionId]);
        $name = (string)($stmt->fetchColumn() ?: '');
        return trim($name);
    }

    public function save(array $data, ?int $id = null): int
    {
        $optionId = trim((string)($data['option_id'] ?? ''));
        $resolvedName = $this->resolveProductNameByOptionId($optionId);

        $payload = [
            'buyer_name' => trim((string)($data['buyer_name'] ?? '')),
            'buyer_phone' => trim((string)($data['buyer_phone'] ?? '')),
            'receiver_name' => trim((string)($data['receiver_name'] ?? '')),
            'receiver_address' => trim((string)($data['receiver_address'] ?? '')),
            'option_id' => $optionId,
            'product_name' => $resolvedName !== '' ? $resolvedName : trim((string)($data['product_name'] ?? '')),
            'qty' => (int)($data['qty'] ?? 1),
            'size_s_qty' => max(0, (int)($data['size_s_qty'] ?? 0)),
            'size_m_qty' => max(0, (int)($data['size_m_qty'] ?? 0)),
            'size_l_qty' => max(0, (int)($data['size_l_qty'] ?? 0)),
            'size_xl_qty' => max(0, (int)($data['size_xl_qty'] ?? 0)),
            'product_price' => (float)($data['product_price'] ?? 0),
            'delivery_message' => trim((string)($data['delivery_message'] ?? '')),
        ];

        if ($payload['qty'] < 1) {
            $payload['qty'] = 1;
        }

        $pdo = db();
        if ($id === null) {
            $stmt = $pdo->prepare('INSERT INTO manual_orders
                (buyer_name, buyer_phone, receiver_name, receiver_address, option_id, product_name, qty, size_s_qty, size_m_qty, size_l_qty, size_xl_qty, product_price, delivery_message)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $payload['buyer_name'],
                $payload['buyer_phone'],
                $payload['receiver_name'],
                $payload['receiver_address'],
                $payload['option_id'] !== '' ? $payload['option_id'] : null,
                $payload['product_name'],
                $payload['qty'],
                $payload['size_s_qty'],
                $payload['size_m_qty'],
                $payload['size_l_qty'],
                $payload['size_xl_qty'],
                $payload['product_price'],
                $payload['delivery_message'],
            ]);
            return (int)$pdo->lastInsertId();
        }

        $stmt = $pdo->prepare('UPDATE manual_orders
            SET buyer_name=?, buyer_phone=?, receiver_name=?, receiver_address=?, option_id=?, product_name=?, qty=?, size_s_qty=?, size_m_qty=?, size_l_qty=?, size_xl_qty=?, product_price=?, delivery_message=?
            WHERE id=?');
        $stmt->execute([
            $payload['buyer_name'],
            $payload['buyer_phone'],
            $payload['receiver_name'],
            $payload['receiver_address'],
            $payload['option_id'] !== '' ? $payload['option_id'] : null,
            $payload['product_name'],
            $payload['qty'],
            $payload['size_s_qty'],
            $payload['size_m_qty'],
            $payload['size_l_qty'],
            $payload['size_xl_qty'],
            $payload['product_price'],
            $payload['delivery_message'],
            $id,
        ]);

        return $id;
    }
}