<?php
declare(strict_types=1);

namespace App\Controllers;

abstract class BaseController
{
    protected function render(string $view, array $data = []): void
    {
        $pageTitle = (string)($data['pageTitle'] ?? 'Ship New v2');
        $menuGroups = (array)($data['menuGroups'] ?? []);
        $activeAction = (string)($data['activeAction'] ?? 'dashboard');

        extract($data, EXTR_SKIP);

        require APP_ROOT . '/app/views/layout/header.php';
        require APP_ROOT . '/app/views/' . $view . '.php';
        require APP_ROOT . '/app/views/layout/footer.php';
    }
}