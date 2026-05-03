<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\SystemService;

class SystemController extends BaseController
{
    public function dashboard(): void
    {
        $service = new SystemService();

        $this->render('system/dashboard', [
            'pageTitle' => '대시보드',
            'activeAction' => 'dashboard',
            'menuGroups' => $service->getMenuGroups(),
            'latestJobs' => $service->getLatestJobs(6),
        ]);
    }

    public function hometaxTax(): void
    {
        $this->placeholder('홈텍스 세금 처리', '엑셀 업로드 → 변환 → 다운로드 기능은 다음 단계에서 구현합니다.', 'hometax_tax');
    }

    public function adReport(): void
    {
        $this->placeholder('광고보고서 분석', '광고 분석 기능은 다음 단계에서 구현합니다.', 'ad_report');
    }

    public function siteMonitor(): void
    {
        $this->placeholder('온라인판매사이트 정상페이지 모니터링', '모니터링 기능은 다음 단계에서 구현합니다.', 'site_monitor');
    }

    public function placeholder(string $title, string $description, string $activeAction): void
    {
        $service = new SystemService();

        $this->render('system/placeholder', [
            'pageTitle' => $title,
            'activeAction' => $activeAction,
            'menuGroups' => $service->getMenuGroups(),
            'title' => $title,
            'description' => $description,
            'latestJobs' => $service->getLatestJobs(6),
        ]);
    }
}