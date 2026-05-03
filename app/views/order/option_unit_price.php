<?php
$editId = isset($editRow['id']) ? (int)$editRow['id'] : 0;
$isEdit = $editId > 0;
$selectedOption = (string)($editRow['option_product_name'] ?? '');
$costAmount = isset($editRow['cost_amount']) ? (float)$editRow['cost_amount'] : 0;
?>
<section class="card">
  <div class="page-head-row">
    <h3>옵션별 단가 등록/수정</h3>
  </div>

  <form method="post" action="index.php?action=option_unit_price_save" class="order-form-grid">
    <?php if ($isEdit): ?>
      <input type="hidden" name="id" value="<?= $editId ?>">
    <?php endif; ?>

    <label>옵션_상품명 선택
      <select name="option_product_name" required>
        <option value="">선택하세요</option>
        <?php foreach (($productOptions ?? []) as $opt): ?>
          <option value="<?= htmlspecialchars((string)$opt) ?>" <?= $selectedOption === (string)$opt ? 'selected' : '' ?>><?= htmlspecialchars((string)$opt) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>원가
      <input type="number" min="0" step="1" name="cost_amount" value="<?= (int)$costAmount ?>" required>
    </label>

    <div class="form-actions full">
      <button type="submit" class="run-btn"><?= $isEdit ? '수정' : '저장' ?></button>
      <a class="link-btn" href="index.php?action=option_unit_price">취소</a>
    </div>
  </form>
</section>

<section class="card" style="margin-top:12px;">
  <div class="page-head-row">
    <h3>옵션별 단가 리스트</h3>
  </div>

  <div class="table-wrap">
    <table class="order-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>옵션_상품명</th>
          <th>단가금액</th>
          <th>수정일</th>
          <th>수정</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="5" class="muted">등록된 단가가 없습니다.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php $id = (int)($r['id'] ?? 0); ?>
          <tr class="clickable-row" data-href="index.php?action=option_unit_price&edit_id=<?= $id ?>">
            <td><?= $id ?></td>
            <td><?= htmlspecialchars((string)($r['option_product_name'] ?? '')) ?></td>
            <td><?= number_format((float)($r['cost_amount'] ?? 0), 0) ?></td>
            <td><?= htmlspecialchars((string)($r['updated_at'] ?? '')) ?></td>
            <td><a class="link-btn" href="index.php?action=option_unit_price&edit_id=<?= $id ?>">✏️</a></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<script>
(function(){
  var rows = document.querySelectorAll('.clickable-row[data-href]');
  rows.forEach(function(row){
    row.addEventListener('click', function(e){
      var t = e.target;
      if (t && t.closest && t.closest('a')) return;
      window.location.href = row.getAttribute('data-href');
    });
  });
})();
</script>