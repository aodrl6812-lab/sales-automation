<section class="card">
  <div class="page-head-row">
    <h3>게시판</h3>
    <a class="link-btn" href="index.php?action=board_form">➕ 등록</a>
  </div>
  <p class="muted">목록을 클릭하면 수정 페이지로 이동합니다.</p>

  <div class="table-wrap">
    <table class="order-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>제목</th>
          <th>작업상태</th>
          <th>상태</th>
          <th>수정일</th>
          <th>수정</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="6" class="muted">등록된 게시글이 없습니다.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php $id = (int)($r['id'] ?? 0); ?>
          <tr class="clickable-row" data-href="index.php?action=board_edit&id=<?= $id ?>">
            <td><?= $id ?></td>
            <td><?= htmlspecialchars((string)($r['title'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($r['work_status'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($r['row_status'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($r['updated_at'] ?? '')) ?></td>
            <td><a class="link-btn" href="index.php?action=board_edit&id=<?= $id ?>">✏️</a></td>
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