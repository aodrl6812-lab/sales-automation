<section class="card">
  <div class="page-head-row">
    <h3>데이터 검수</h3>
  </div>
  <p class="muted">
    오늘 신규주문 기준(<?= htmlspecialchars((string)$todayStart) ?> ~ <?= htmlspecialchars((string)$tomorrowStart) ?>) 데이터입니다.
  </p>
</section>

<section class="card" style="margin-top:16px;">
  <div class="page-head-row">
    <h3>1) orders_raw 원본 파싱</h3>
    <span class="muted">총 <?= count($rawRows) ?>건</span>
  </div>

  <div class="table-wrap">
    <table class="order-table">
      <thead>
        <tr>
          <th>플랫폼</th>
          <th>수취인명</th>
          <th>수취인전화번호</th>
          <th>상품명(옵션명)</th>
          <th>수취인주소</th>
          <th>배송메세지</th>
          <th>주문수량</th>
          <th>주문번호</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rawRows)): ?>
        <tr><td colspan="8" class="muted">오늘 수집된 orders_raw 데이터가 없습니다.</td></tr>
      <?php else: ?>
        <?php foreach ($rawRows as $row): ?>
          <tr>
            <td><?= htmlspecialchars((string)($row['platform'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($row['receiver_name'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($row['receiver_phone'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($row['product_name_option'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($row['receiver_address'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($row['delivery_message'] ?? '')) ?></td>
            <td><?= (int)($row['qty'] ?? 0) ?></td>
            <td><?= htmlspecialchars((string)($row['order_no'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card" style="margin-top:16px;">
  <div class="page-head-row">
    <h3>2) 사이트 정규화 테이블 통합</h3>
    <span class="muted">총 <?= count($normalizedRows) ?>건</span>
  </div>

  <div class="table-wrap">
    <table class="order-table">
      <thead>
        <tr>
          <th>플랫폼</th>
          <th>수취인명</th>
          <th>수취인전화번호</th>
          <th>상품명(옵션명)</th>
          <th>수취인주소</th>
          <th>배송메세지</th>
          <th>주문수량</th>
          <th>주문번호</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($normalizedRows)): ?>
        <tr><td colspan="8" class="muted">오늘 정규화된 데이터가 없습니다.</td></tr>
      <?php else: ?>
        <?php foreach ($normalizedRows as $row): ?>
          <tr>
            <td><?= htmlspecialchars((string)($row['platform'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($row['receiver_name'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($row['receiver_phone'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($row['product_name_export'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($row['receiver_address'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($row['delivery_message'] ?? '')) ?></td>
            <td><?= (int)($row['qty'] ?? 0) ?></td>
            <td><?= htmlspecialchars((string)($row['export_no'] ?? $row['order_no'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
