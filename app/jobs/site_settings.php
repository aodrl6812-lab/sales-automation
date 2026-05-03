<?php
declare(strict_types=1);

use App\Services\SystemService;

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../services/SystemService.php';

function site_settings_marketplaces(): array
{
    return [
        'coupang' => 'Coupang',
        'smartstore' => 'SmartStore',
        '11st' => '11st',
        'gmarket' => 'Gmarket',
        'auction' => 'Auction',
    ];
}

function ensure_site_settings_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS site_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            site_code VARCHAR(50) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_site_code (site_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function run_site_settings_page(): void
{
    $pdo = db();
    ensure_site_settings_table($pdo);

    $sites = site_settings_marketplaces();
    $rows = $pdo->query('SELECT site_code, is_active FROM site_settings')->fetchAll(PDO::FETCH_ASSOC);

    $activeMap = [];
    foreach ($rows as $row) {
        $activeMap[(string)$row['site_code']] = (int)$row['is_active'] === 1;
    }

    $saved = isset($_GET['saved']) && (string)$_GET['saved'] === '1';

    $service = new SystemService();
    $pageTitle = 'Site Settings';
    $menuGroups = $service->getMenuGroups();
    $activeAction = 'site_settings';

    require APP_ROOT . '/app/views/layout/header.php';
    ?>
    <section class="card">
      <div class="page-head-row">
        <h3>Site Settings</h3>
      </div>
      <p class="muted">마켓별 활성/비활성을 설정합니다.</p>

      <?php if ($saved): ?>
        <div class="badge success" style="margin-bottom:10px;">저장 완료</div>
      <?php endif; ?>

      <form method="post" action="index.php?action=site_settings_save">
        <div class="order-form-grid">
          <div class="full">
            <?php foreach ($sites as $code => $label): ?>
              <label style="display:flex;align-items:center;gap:8px;padding:6px 0;">
                <input type="checkbox" name="sites[]" value="<?= htmlspecialchars($code, ENT_QUOTES) ?>" <?= !empty($activeMap[$code]) ? 'checked' : '' ?>>
                <span><?= htmlspecialchars($label, ENT_QUOTES) ?></span>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="form-actions full">
            <button class="run-btn" type="submit">저장</button>
            <a class="link-btn" href="index.php?action=dashboard">대시보드로 이동</a>
          </div>
        </div>
      </form>
    </section>
    <?php
    require APP_ROOT . '/app/views/layout/footer.php';
}

function run_site_settings_save(): void
{
    $pdo = db();
    ensure_site_settings_table($pdo);

    $sites = site_settings_marketplaces();
    $selected = array_map('strval', $_POST['sites'] ?? []);

    $stmt = $pdo->prepare(
        'INSERT INTO site_settings (site_code, is_active, updated_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            is_active = VALUES(is_active),
            updated_at = VALUES(updated_at)'
    );

    $pdo->beginTransaction();
    try {
        foreach ($sites as $siteCode => $_label) {
            $isActive = in_array($siteCode, $selected, true) ? 1 : 0;
            $stmt->execute([$siteCode, $isActive]);
        }
        $pdo->commit();
        header('Location: index.php?action=site_settings&saved=1');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header('Location: index.php?action=site_settings');
        exit;
    }
}