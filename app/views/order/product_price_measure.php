<?php
$latestFinalPrice = isset($latestFinalPrice) ? (float)$latestFinalPrice : 0;
$latestInput = is_array($latestInput ?? null) ? $latestInput : [];
?>
<section class="card">
  <div class="page-head-row">
    <h3>상품 판매가 측정</h3>
  </div>
  <p class="muted">원가, 택배비, 판매수수료, 희망마진을 입력 후 저장하면 최종 판매가를 계산합니다.</p>

  <?php if (!empty($errorMessage ?? '')): ?>
    <div class="badge failed" style="margin:8px 0;"><?= htmlspecialchars((string)$errorMessage, ENT_QUOTES) ?></div>
  <?php endif; ?>

  <form method="post" action="index.php?action=product_price_measure_save" class="order-form-grid">
    <label>원가
      <input type="number" name="cost_amount" min="0" step="1" value="<?= htmlspecialchars((string)($latestInput['cost_amount'] ?? ''), ENT_QUOTES) ?>" required>
    </label>

    <label>택배비
      <input type="number" name="shipping_fee" min="0" step="1" value="<?= htmlspecialchars((string)($latestInput['shipping_fee'] ?? ''), ENT_QUOTES) ?>" required>
    </label>

    <label>판매수수료(%)
      <input type="number" name="sales_fee_percent" min="0" step="0.01" value="<?= htmlspecialchars((string)($latestInput['sales_fee_percent'] ?? ''), ENT_QUOTES) ?>" required>
    </label>

    <label>희망마진(%)
      <input type="number" name="desired_margin_percent" min="0" step="0.01" value="<?= htmlspecialchars((string)($latestInput['desired_margin_percent'] ?? ''), ENT_QUOTES) ?>" required>
    </label>

    <div class="form-actions full">
      <button type="submit" class="run-btn">저장</button>
      <span class="badge success">최종 판매가: <?= number_format($latestFinalPrice, 0) ?>원</span>
    </div>
  </form>
</section>

<section class="card" style="margin-top:12px;">
  <div class="page-head-row">
    <h3>계산 이력</h3>
  </div>

  <div class="table-wrap">
    <table class="order-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>원가</th>
          <th>택배비</th>
          <th>판매수수료(%)</th>
          <th>희망마진(%)</th>
          <th>최종 판매가</th>
          <th>저장일</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="7" class="muted">저장된 이력이 없습니다.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)($r['id'] ?? 0) ?></td>
            <td><?= number_format((float)($r['cost_amount'] ?? 0), 0) ?></td>
            <td><?= number_format((float)($r['shipping_fee'] ?? 0), 0) ?></td>
            <td><?= number_format(((float)($r['sales_fee_rate'] ?? 0)) * 100, 2) ?></td>
            <td><?= number_format(((float)($r['desired_margin_rate'] ?? 0)) * 100, 2) ?></td>
            <td><strong><?= number_format((float)($r['final_price'] ?? 0), 0) ?></strong></td>
            <td><?= htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>