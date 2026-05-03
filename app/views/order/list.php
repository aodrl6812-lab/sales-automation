<section class="card">
  <div class="page-head-row">
    <h3>개별주문 등록</h3>
    <a class="link-btn" href="index.php?action=order_form">➕ 등록</a>
  </div>
  <p class="muted">목록을 클릭하면 수정 페이지로 이동합니다.</p>

  <div class="table-wrap">
    <table class="order-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>주문자명</th>
          <th>핸드폰번호</th>
          <th>수령자명</th>
          <th>주소</th>
          <th>option_id</th>
          <th>상품명</th>
          <th>수량</th>
          <th>상품가</th>
          <th>등록일</th>
          <th>수정</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="11" class="muted">등록된 개별 주문이 없습니다.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php $id = (int)($r['id'] ?? 0); ?>
          <tr class="clickable-row" data-href="index.php?action=order_edit&id=<?= $id ?>">
            <td><?= $id ?></td>
            <td><?= htmlspecialchars((string)($r['buyer_name'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($r['buyer_phone'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($r['receiver_name'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($r['receiver_address'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($r['option_id'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($r['product_name'] ?? '')) ?></td>
            <td><?= (int)($r['qty'] ?? 0) ?></td>
            <td><?= number_format((float)($r['product_price'] ?? 0), 0) ?></td>
            <td><?= htmlspecialchars((string)($r['created_at'] ?? '')) ?></td>
            <td><a class="link-btn" href="index.php?action=order_edit&id=<?= $id ?>">✏️</a></td>
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