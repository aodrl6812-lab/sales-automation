<?php
declare(strict_types=1);

namespace App\Controllers;

class DeliveryController
{
    public function __construct(private readonly SystemController $systemController)
    {
    }

    public function statusCheck(): void
    {
        $this->systemController->placeholder('배송상태체크', '배송상태체크 페이지 라우팅만 우선 연결했습니다.', 'delivery_status_check');
    }
}