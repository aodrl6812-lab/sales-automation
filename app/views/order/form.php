<?php
$id = isset($row['id']) ? (int)$row['id'] : 0;
$isEdit = $id > 0;
$buyerName = (string)($row['buyer_name'] ?? '');
$buyerPhone = (string)($row['buyer_phone'] ?? '');
$receiverName = (string)($row['receiver_name'] ?? '');
$receiverAddress = (string)($row['receiver_address'] ?? '');
$optionId = (string)($row['option_id'] ?? '');
$productName = (string)($row['product_name'] ?? '');
$qty = (int)($row['qty'] ?? 1);
$sizeSQty = (int)($row['size_s_qty'] ?? 0);
$sizeMQty = (int)($row['size_m_qty'] ?? 0);
$sizeLQty = (int)($row['size_l_qty'] ?? 0);
$sizeXLQty = (int)($row['size_xl_qty'] ?? 0);
$productPrice = (float)($row['product_price'] ?? 0);
$deliveryMessage = (string)($row['delivery_message'] ?? '');
?>
<section class="card">
  <h3><?= $isEdit ? '개별주문 수정' : '개별주문 등록' ?></h3>

  <form method="post" action="index.php?action=order_save" class="order-form-grid">
    <?php if ($isEdit): ?>
      <input type="hidden" name="id" value="<?= $id ?>">
    <?php endif; ?>

    <label>주문자명
      <input type="text" name="buyer_name" value="<?= htmlspecialchars($buyerName) ?>" required>
    </label>

    <label>핸드폰번호
      <input type="text" name="buyer_phone" value="<?= htmlspecialchars($buyerPhone) ?>" required>
    </label>

    <label>수령자명
      <input type="text" name="receiver_name" value="<?= htmlspecialchars($receiverName) ?>" required>
    </label>

    <label class="full">주소
      <input type="text" name="receiver_address" value="<?= htmlspecialchars($receiverAddress) ?>" required>
    </label>

    <label class="full">상품명 (product_option_map 선택)
      <select name="option_id" required>
        <option value="">선택하세요</option>
        <?php foreach (($productOptions ?? []) as $opt): ?>
          <?php
            $optId = (string)($opt['option_id'] ?? '');
            $optName = (string)($opt['factory_product_name'] ?? '');
            $selected = ($optionId !== '' && $optionId === $optId)
              || ($optionId === '' && $productName !== '' && $productName === $optName);
          ?>
          <option value="<?= htmlspecialchars($optId) ?>" <?= $selected ? 'selected' : '' ?>>
            <?= htmlspecialchars($optName) ?> (<?= htmlspecialchars($optId) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>주문수량
      <input type="number" min="1" name="qty" value="<?= $qty ?>" required>
    </label>

    <label>상품가
      <input type="number" min="0" step="1" name="product_price" value="<?= (int)$productPrice ?>" required>
    </label>

    <label>배송사이즈 S 수량
      <input type="number" min="0" name="size_s_qty" value="<?= $sizeSQty ?>">
    </label>

    <label>배송사이즈 M 수량
      <input type="number" min="0" name="size_m_qty" value="<?= $sizeMQty ?>">
    </label>

    <label>배송사이즈 L 수량
      <input type="number" min="0" name="size_l_qty" value="<?= $sizeLQty ?>">
    </label>

    <label>배송사이즈 XL 수량
      <input type="number" min="0" name="size_xl_qty" value="<?= $sizeXLQty ?>">
    </label>

    <label class="full">배송메세지
      <input type="text" name="delivery_message" value="<?= htmlspecialchars($deliveryMessage) ?>">
    </label>

    <div class="form-actions full">
      <button type="submit" class="run-btn"><?= $isEdit ? '수정' : '저장' ?></button>
      <a class="link-btn" href="index.php?action=order_create">취소</a>
    </div>
  </form>
</section>