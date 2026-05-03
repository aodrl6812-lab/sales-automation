<?php
declare(strict_types=1);

use App\Services\SystemService;

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../services/SystemService.php';

function run_option_manage_page(): void
{
    $pdo = db();

    $list = $pdo->query(
        'SELECT m.option_id, m.factory_product_name,
                GROUP_CONCAT(CONCAT(r.box_qty, "개=", r.box_size) ORDER BY r.box_qty SEPARATOR ", ") AS rules
         FROM product_option_map m
         LEFT JOIN product_option_box_rule r ON m.option_id = r.option_id
         GROUP BY m.option_id
         ORDER BY m.option_id DESC
         LIMIT 100'
    )->fetchAll(PDO::FETCH_ASSOC);

    $service = new SystemService();
    $pageTitle = '옵션 관리';
    $menuGroups = $service->getMenuGroups();
    $activeAction = 'option_manage';

    require APP_ROOT . '/app/views/layout/header.php';
    ?>
    <section class="card">
      <div class="page-head-row">
        <h3>옵션 등록/수정</h3>
      </div>
      <p class="muted">옵션 ID, 공장 상품명, 박스 규칙을 저장합니다.</p>

      <form method="post" action="index.php?action=option_save">
        <div class="order-form-grid">
          <label>옵션 ID (vendorItemId)
            <input type="text" name="option_id" required>
          </label>

          <label>공장용 상품명
            <input type="text" name="factory_product_name" required>
          </label>

          <div class="full">
            <h3 style="margin:6px 0 10px;">박스 규칙</h3>
            <table id="boxRuleTable" class="order-table">
              <thead>
                <tr>
                  <th>수량</th>
                  <th>박스 사이즈</th>
                  <th>삭제</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
            <div class="form-actions" style="margin-top:10px;">
              <button class="link-btn" type="button" onclick="addBoxRow()">+ 규칙 추가</button>
            </div>
          </div>

          <div class="form-actions full">
            <button class="run-btn" type="submit">저장</button>
            <a class="link-btn" href="index.php?action=dashboard">대시보드로 이동</a>
          </div>
        </div>
      </form>
    </section>

    <section class="card" style="margin-top:12px;">
      <div class="page-head-row">
        <h3>최근 등록 목록</h3>
      </div>
      <p class="muted">최근 100개 옵션 기준</p>

      <div class="table-wrap">
        <table class="order-table">
          <thead>
            <tr>
              <th>옵션 ID</th>
              <th>상품명</th>
              <th>박스 규칙</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($list)): ?>
            <tr><td colspan="3" class="muted">등록된 옵션이 없습니다.</td></tr>
          <?php else: ?>
            <?php foreach ($list as $row): ?>
              <tr>
                <td><?= htmlspecialchars((string)($row['option_id'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string)($row['factory_product_name'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string)($row['rules'] ?? '-')) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <script>
    function addBoxRow(qty, size) {
      qty = qty || '';
      size = size || '';
      var tbody = document.querySelector('#boxRuleTable tbody');
      var tr = document.createElement('tr');
      tr.innerHTML = '<td><input type="number" name="box_qty[]" value="' + qty + '" required min="1"></td>' +
                     '<td><select name="box_size[]" required>' +
                     '<option value="">선택</option>' +
                     '<option value="S" ' + (size === 'S' ? 'selected' : '') + '>S</option>' +
                     '<option value="M" ' + (size === 'M' ? 'selected' : '') + '>M</option>' +
                     '<option value="L" ' + (size === 'L' ? 'selected' : '') + '>L</option>' +
                     '<option value="XL" ' + (size === 'XL' ? 'selected' : '') + '>XL</option>' +
                     '</select></td>' +
                     '<td><button class="link-btn" type="button" onclick="this.closest(\'tr\').remove()">삭제</button></td>';
      tbody.appendChild(tr);
    }
    addBoxRow();
    </script>
    <?php
    require APP_ROOT . '/app/views/layout/footer.php';
}

function run_option_save(): void
{
    $pdo = db();

    $optionId = trim((string)($_POST['option_id'] ?? ''));
    $productName = trim((string)($_POST['factory_product_name'] ?? ''));
    $boxQtyList = $_POST['box_qty'] ?? [];
    $boxSizeList = $_POST['box_size'] ?? [];

    if ($optionId === '' || $productName === '') {
        header('Location: index.php?action=option_manage');
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('INSERT INTO product_option_map (option_id, factory_product_name, unit_quantity, box_size)
                               VALUES (?, ?, 1, "")
                               ON DUPLICATE KEY UPDATE factory_product_name = VALUES(factory_product_name)');
        $stmt->execute([$optionId, $productName]);

        $pdo->prepare('DELETE FROM product_option_box_rule WHERE option_id = ?')->execute([$optionId]);

        $count = min(count($boxQtyList), count($boxSizeList));
        for ($i = 0; $i < $count; $i++) {
            $qty = (int)($boxQtyList[$i] ?? 0);
            $size = trim((string)($boxSizeList[$i] ?? ''));
            if ($qty < 1 || $size === '') {
                continue;
            }

            $stmt = $pdo->prepare('INSERT INTO product_option_box_rule (option_id, box_qty, box_size) VALUES (?, ?, ?)');
            $stmt->execute([$optionId, $qty, $size]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    header('Location: index.php?action=option_manage');
    exit;
}