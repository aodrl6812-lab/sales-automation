<?php
$id = isset($row['id']) ? (int)$row['id'] : 0;
$isEdit = $id > 0;
$titleVal = (string)($row['title'] ?? '');
$contentVal = (string)($row['work_content'] ?? '');
$workStatusVal = (string)($row['work_status'] ?? '등록');
$rowStatusVal = (string)($row['row_status'] ?? '진행');
?>
<section class="card">
  <h3><?= $isEdit ? '게시판 수정' : '게시판 등록' ?></h3>

  <form method="post" action="index.php?action=board_save" class="order-form-grid">
    <?php if ($isEdit): ?>
      <input type="hidden" name="id" value="<?= $id ?>">
    <?php endif; ?>

    <label class="full">제목
      <input type="text" name="title" value="<?= htmlspecialchars($titleVal) ?>" required>
    </label>

    <label class="full">작업내용
      <textarea name="work_content" rows="6" required><?= htmlspecialchars($contentVal) ?></textarea>
    </label>

    <label>작업상태
      <select name="work_status" required>
        <?php foreach (['등록','진행','완료'] as $s): ?>
          <option value="<?= $s ?>" <?= $workStatusVal === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>상태
      <select name="row_status" required>
        <?php foreach (['진행','삭제'] as $s): ?>
          <option value="<?= $s ?>" <?= $rowStatusVal === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <div class="form-actions full">
      <button type="submit" class="run-btn"><?= $isEdit ? '수정' : '저장' ?></button>
      <a class="link-btn" href="index.php?action=board">취소</a>
    </div>
  </form>
</section>