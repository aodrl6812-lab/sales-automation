<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

class OptionUnitPriceService
{
    public function __construct()
    {
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $pdo = db();
        $sql = 'CREATE TABLE IF NOT EXISTS option_unit_prices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            option_product_name VARCHAR(255) NOT NULL,
            cost_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_option_product_name (option_product_name),
            KEY idx_option_unit_prices_updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
        $pdo->exec($sql);
    }

    public function getProductOptions(): array
    {
        $pdo = db();
        $sql = 'SELECT DISTINCT factory_product_name
                FROM product_option_map
                WHERE factory_product_name IS NOT NULL
                  AND factory_product_name <> ""
                ORDER BY factory_product_name ASC';
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($rows)) {
            return [];
        }
        return array_values(array_filter(array_map(static fn($v) => trim((string)$v), $rows), static fn($v) => $v !== ''));
    }

    public function getList(int $limit = 300): array
    {
        $limit = max(1, min(1000, $limit));
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, option_product_name, cost_amount, updated_at
                               FROM option_unit_prices
                               ORDER BY id DESC
                               LIMIT :lim');
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findById(int $id): ?array
    {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM option_unit_prices WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function save(array $data, ?int $id = null): int
    {
        $optionName = trim((string)($data['option_product_name'] ?? ''));
        $cost = (float)($data['cost_amount'] ?? 0);

        if ($optionName === '') {
            throw new \InvalidArgumentException('option_product_name is required');
        }

        if ($cost < 0) {
            $cost = 0;
        }

        $pdo = db();

        if ($id === null) {
            $stmt = $pdo->prepare('INSERT INTO option_unit_prices (option_product_name, cost_amount)
                                   VALUES (?, ?)
                                   ON DUPLICATE KEY UPDATE cost_amount = VALUES(cost_amount), updated_at = NOW()');
            $stmt->execute([$optionName, $cost]);

            $newId = (int)$pdo->lastInsertId();
            if ($newId > 0) {
                return $newId;
            }

            $stmt2 = $pdo->prepare('SELECT id FROM option_unit_prices WHERE option_product_name = ?');
            $stmt2->execute([$optionName]);
            return (int)($stmt2->fetchColumn() ?: 0);
        }

        $stmt = $pdo->prepare('UPDATE option_unit_prices
                               SET option_product_name = ?, cost_amount = ?
                               WHERE id = ?');
        $stmt->execute([$optionName, $cost, $id]);
        return $id;
    }
}