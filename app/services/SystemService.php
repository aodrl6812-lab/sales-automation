<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

class SystemService
{
    public function getLatestJobs(int $limit = 6): array
    {
        $limit = max(1, min(20, $limit));
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM jobs ORDER BY id DESC LIMIT :lim');
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getMenuGroups(): array
    {
        return [
            [
                'title' => '주문관리',
                'items' => [
                    ['action' => 'order_create', 'label' => '개별주문 등록'],
                    ['action' => 'board', 'label' => '게시판'],
                    ['action' => 'inspection', 'label' => '데이터 검수'],
                ],
            ],
            [
                'title' => '상품/옵션 관리',
                'items' => [
                    ['action' => 'option_manage', 'label' => '옵션관리'],
                    ['action' => 'option_unit_price', 'label' => '옵션별 단가 등록'],
                    ['action' => 'product_price_measure', 'label' => '상품 판매가 측정페이지'],
                ],
            ],
            [
                'title' => '배송관리',
                'items' => [
                    ['action' => 'invoice_upload', 'label' => '송장업로드'],
                ],
            ],
            [
                'title' => '정산/세무',
                'items' => [
                    ['action' => 'hometax_tax', 'label' => '홈택스 세금 처리'],
                ],
            ],
            [
                'title' => '데이터 분석',
                'items' => [
                    ['action' => 'ad_report', 'label' => '광고보고서 분석'],
                ],
            ],
            [
                'title' => '시스템',
                'items' => [
                    ['action' => 'site_settings', 'label' => 'site setting (기존)'],
                    ['action' => 'logout', 'label' => '로그아웃 (기존)'],
                ],
            ],
        ];
    }
}
