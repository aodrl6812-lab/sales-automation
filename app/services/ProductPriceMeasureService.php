<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

class ProductPriceMeasureService
{
    private const INCOME_TAX_RATE = 0.06;

    public function __construct()
    {
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $pdo = db();
        $sql = 'CREATE TABLE IF NOT EXISTS product_price_measurements (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            cost_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            shipping_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
            sales_fee_rate DECIMAL(8,6) NOT NULL DEFAULT 0,
            desired_margin_rate DECIMAL(8,6) NOT NULL DEFAULT 0,
            income_tax_rate DECIMAL(8,6) NOT NULL DEFAULT 0.06,
            final_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_product_price_measurements_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
        $pdo->exec($sql);
    }

    public function calculateFinalPrice(float $costAmount, float $shippingFee, float $salesFeeRate, float $desiredMarginRate): float
    {
        if ($costAmount < 0) {
            $costAmount = 0;
        }
        if ($shippingFee < 0) {
            $shippingFee = 0;
        }

        $salesFeeRate = max(0.0, $salesFeeRate);
        $desiredMarginRate = max(0.0, $desiredMarginRate);

        $costExVat = $costAmount / 1.1;
        $shippingExVat = $shippingFee / 1.1;

        $marginAdjusted = $desiredMarginRate / (1 - self::INCOME_TAX_RATE);
        $denominator = 1 - ($salesFeeRate * 1.1) - $marginAdjusted;

        if ($denominator <= 0) {
            throw new \RuntimeException('수수료/마진 비율 합이 너무 커서 계산할 수 없습니다.');
        }

        $raw = (($costExVat + $shippingExVat) / $denominator) * 1.1;
        return (float)(ceil($raw / 10) * 10);
    }

    public function save(array $data): int
    {
        $costAmount = max(0.0, (float)($data['cost_amount'] ?? 0));
        $shippingFee = max(0.0, (float)($data['shipping_fee'] ?? 0));
        $salesFeeRate = max(0.0, ((float)($data['sales_fee_percent'] ?? 0)) / 100);
        $desiredMarginRate = max(0.0, ((float)($data['desired_margin_percent'] ?? 0)) / 100);

        $finalPrice = $this->calculateFinalPrice($costAmount, $shippingFee, $salesFeeRate, $desiredMarginRate);

        $pdo = db();
        $stmt = $pdo->prepare('INSERT INTO product_price_measurements
            (cost_amount, shipping_fee, sales_fee_rate, desired_margin_rate, income_tax_rate, final_price)
            VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $costAmount,
            $shippingFee,
            $salesFeeRate,
            $desiredMarginRate,
            self::INCOME_TAX_RATE,
            $finalPrice,
        ]);

        return (int)$pdo->lastInsertId();
    }

    public function getList(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM product_price_measurements ORDER BY id DESC LIMIT :lim');
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}