<?php
declare(strict_types=1);

use App\Services\SystemService;

require_once __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/services/SystemService.php';

$error = '';
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!isset($_FILES['invoice_file'])) {
        $error = '파일을 선택하세요.';
    } else {
        $file = $_FILES['invoice_file'];

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = '업로드에 실패했습니다.';
        } else {
            $ext = strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION));

            if (!in_array($ext, ['xlsx', 'xls'], true)) {
                $error = 'xlsx/xls 파일만 업로드 가능합니다.';
            } else {
                $uploadDir = APP_ROOT . '/storage/invoice/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $filename = 'invoice_' . date('Ymd_His') . '.' . $ext;
                $target = $uploadDir . $filename;

                if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
                    $error = '파일 저장에 실패했습니다.';
                } else {
                    $success = '업로드 완료: ' . $filename;
                }
            }
        }
    }
}

$service = new SystemService();
$pageTitle = '송장업로드';
$menuGroups = $service->getMenuGroups();
$activeAction = 'invoice_upload';

require APP_ROOT . '/app/views/layout/header.php';
?>
<section class="card">
  <div class="page-head-row">
    <h3>송장업로드</h3>
  </div>
  <p class="muted">업로드 후 대시보드에서 배송반영 작업을 실행하세요.</p>

  <form method="post" enctype="multipart/form-data">
    <div class="order-form-grid">
      <label class="full">송장 파일 선택 (.xlsx, .xls)
        <input type="file" name="invoice_file" accept=".xlsx,.xls" required>
      </label>

      <div class="form-actions full">
        <button class="run-btn" type="submit">업로드</button>
        <a class="link-btn" href="index.php?action=dashboard">대시보드로 이동</a>
      </div>

      <?php if ($success !== ''): ?>
        <div class="badge success full"><?= htmlspecialchars($success, ENT_QUOTES) ?></div>
      <?php endif; ?>

      <?php if ($error !== ''): ?>
        <div class="badge failed full"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
      <?php endif; ?>
    </div>
  </form>
</section>
<?php
require APP_ROOT . '/app/views/layout/footer.php';