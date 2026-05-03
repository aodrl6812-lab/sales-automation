<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/db.php';

$pdo = db();
$type = (string)($_GET['type'] ?? 'total');
$validTypes = ['total', 'instruction', 'delivering', 'delay'];
if (!in_array($type, $validTypes, true)) {
    $type = 'total';
}

function map_sales_site(array $row): string
{
    $src = strtolower((string)($row['source_file'] ?? ''));
    if ($src === '' || $src === 'api' || strpos($src, 'coupang') !== false) {
        return '荑좏뙜';
    }
    if (strpos($src, 'smart') !== false || strpos($src, '?ㅽ넗??) !== false) {
        return '?ㅻ쭏?몄뒪?좎뼱';
    }
    return '湲고?';
}

function map_delivery_state(array $row): string
{
    if ((int)($row['is_delivered'] ?? 0) === 1) {
        return '諛곗넚?꾨즺';
    }
    if ((int)($row['is_delivering'] ?? 0) === 1) {
        return '諛곗넚以?;
    }
    if (!empty($row['shipped_at'])) {
        return '諛곗넚吏??;
    }
    return '二쇰Ц?섏쭛';
}

// 硫붿씤 ??쒕낫??api/summary.php)? ?숈씪 湲곗?
$baseImportedWhere = 'imported_at >= CONCAT(DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 DAY), "%Y-%m-%d"), " 16:00:00")';

$totalOrders = (int)$pdo->query(
    'SELECT COUNT(DISTINCT order_no)
     FROM coupang_order_excel
     WHERE ' . $baseImportedWhere
)->fetchColumn();

$shippingInstruction = (int)$pdo->query(
    'SELECT COUNT(DISTINCT order_no)
     FROM coupang_order_excel
     WHERE ' . $baseImportedWhere . '
       AND tracking_no != ""'
)->fetchColumn();

$deliveringCount = (int)$pdo->query(
    'SELECT COUNT(DISTINCT order_no)
     FROM coupang_order_excel
     WHERE is_delivered = 0'
)->fetchColumn();

$delayCount = (int)$pdo->query(
    'SELECT COUNT(DISTINCT order_no)
     FROM coupang_order_excel
     WHERE shipped_at IS NOT NULL
       AND shipped_at <= DATE_SUB(NOW(), INTERVAL 2 DAY)
       AND is_delivered = 0'
)->fetchColumn();

$where = '';
switch ($type) {
    case 'instruction':
        $where = $baseImportedWhere . ' AND tracking_no != ""';
        break;
    case 'delivering':
        $where = 'is_delivered = 0';
        break;
    case 'delay':
        $where = 'shipped_at IS NOT NULL AND shipped_at <= DATE_SUB(NOW(), INTERVAL 2 DAY) AND is_delivered = 0';
        break;
    case 'total':
    default:
        $where = $baseImportedWhere;
        break;
}

$sql =
    'SELECT
        order_no,
        source_file,
        carrier_name,
        tracking_no,
        reg_product_name,
        reg_option_name,
        qty,
        receiver_name,
        receiver_phone,
        receiver_address,
        is_delivering,
        is_delivered,
        shipped_at,
        ordered_at,
        imported_at
     FROM coupang_order_excel
     WHERE ' . $where . '
     ORDER BY COALESCE(ordered_at, imported_at, shipped_at) DESC
     LIMIT 500';

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$typeTitle = [
    'total' => '珥?二쇰Ц??,
    'instruction' => '諛곗넚吏??,
    'delivering' => '諛곗넚以?,
    'delay' => '諛곗넚吏??,
][$type];
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>諛곗넚愿由??곸꽭</title>
  <style>
    :root{--bg:#f4f7fb;--card:#fff;--text:#1a2433;--muted:#64748b;--line:#e4ebf3;--shadow:0 10px 28px rgba(17,24,39,.07)}
    *{box-sizing:border-box}
    body{margin:0;font-family:"SUIT","Pretendard","Noto Sans KR","Apple SD Gothic Neo",sans-serif;background:var(--bg);color:var(--text)}
    .wrap{max-width:1320px;margin:0 auto;padding:18px 14px 30px}
    .home-strip{position:sticky;top:0;z-index:60;margin-bottom:10px;padding:10px 14px;border:1px solid var(--line);border-radius:14px;background:#fff;box-shadow:var(--shadow);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
    .home-strip-link{font-size:20px;font-weight:800;letter-spacing:-.2px;color:var(--text);text-decoration:none}
    .home-strip-link:hover{text-decoration:underline}
    .home-actions{display:flex;gap:8px;flex-wrap:wrap}
    .home-btn{border:1px solid var(--line);background:#fff;color:var(--text);border-radius:12px;padding:10px 14px;cursor:pointer;text-decoration:none;font-weight:600}
    .head{display:flex;align-items:flex-end;justify-content:space-between;gap:10px;margin-bottom:12px}
    .head h1{margin:0;font-size:22px}
    .head p{margin:4px 0 0;color:var(--muted);font-size:13px}
    .back{font-size:13px;color:#1f6fe2;text-decoration:none}
    .summary{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
    .summary-item{display:block;text-decoration:none;color:inherit;background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px;box-shadow:var(--shadow)}
    .summary-item.active{border-color:#8bb6ff;background:#f6f9ff}
    .summary-label{font-size:13px;color:var(--muted)}
    .summary-value{margin-top:8px;font-size:34px;font-weight:800;line-height:1}
    .table-card{background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:var(--shadow);overflow:hidden}
    .table-head{padding:12px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:8px}
    .table-head strong{font-size:15px}
    .count{font-size:12px;color:var(--muted)}
    .table-wrap{overflow:auto}
    table{width:100%;border-collapse:collapse;min-width:1180px}
    th,td{padding:10px 10px;border-bottom:1px solid var(--line);font-size:13px;vertical-align:top}
    th{background:#f8fafc;font-weight:700;white-space:nowrap}
    tr:hover td{background:#fbfdff}
    .muted{color:var(--muted)}
    .product{line-height:1.35}
    .status{font-weight:700}
    @media (max-width:900px){
      .summary{grid-template-columns:1fr 1fr}
      .head{flex-direction:column;align-items:flex-start}
    }
  </style>
</head>
<body>
<div class="wrap">
  <div class="home-strip">
    <a class="home-strip-link" href="index.php?action=dashboard">Ship New v2 Console</a>
    <div class="home-actions">
      <a class="home-btn" href="index.php?action=delivery_status_check">諛곗넚?곹깭 泥댄겕</a>
      <a class="home-btn" href="index.php?action=inspection">誘몃같???묒? ?ъ깮??/a>
    </div>
  </div>

  <section class="head">
    <div>
      <h1>諛곗넚愿由?<span class="muted" style="font-weight:600">?곸꽭 蹂닿린</span></h1>
      <p>?꾩옱 <?= htmlspecialchars($typeTitle) ?>???대떦?섎뒗 二쇰Ц ?곗씠?곕? ?곹깭蹂꾨줈 ?뺤씤?⑸땲??</p>
    </div>
    <a class="back" href="index.php?action=dashboard">硫붿씤?쇰줈 ?뚯븘媛湲?/a>
  </section>

  <section class="summary">
    <a class="summary-item <?= $type === 'total' ? 'active' : '' ?>" href="orders_detail.php?type=total">
      <div class="summary-label">珥?二쇰Ц??/div>
      <div class="summary-value"><?= $totalOrders ?></div>
    </a>
    <a class="summary-item <?= $type === 'instruction' ? 'active' : '' ?>" href="orders_detail.php?type=instruction">
      <div class="summary-label">諛곗넚吏??/div>
      <div class="summary-value"><?= $shippingInstruction ?></div>
    </a>
    <a class="summary-item <?= $type === 'delivering' ? 'active' : '' ?>" href="orders_detail.php?type=delivering">
      <div class="summary-label">諛곗넚以?/div>
      <div class="summary-value"><?= $deliveringCount ?></div>
    </a>
    <a class="summary-item <?= $type === 'delay' ? 'active' : '' ?>" href="orders_detail.php?type=delay">
      <div class="summary-label">諛곗넚吏??/div>
      <div class="summary-value"><?= $delayCount ?></div>
    </a>
  </section>

  <section class="table-card">
    <div class="table-head">
      <strong><?= htmlspecialchars($typeTitle) ?> 紐⑸줉</strong>
      <span class="count">珥?<?= count($rows) ?>嫄?/span>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>二쇰Ц踰덊샇</th>
            <th>?먮ℓ?ъ씠??/th>
            <th>?앸같??/th>
            <th>?≪옣踰덊샇</th>
            <th>?깅줉?곹뭹紐??듭뀡/?섎웾</th>
            <th>?섎졊???곕씫泥?/th>
            <th>諛곗넚吏</th>
            <th>諛곗넚?곹깭</th>
            <th>二쇰Ц?쇱떆</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9" class="muted">?대떦 議곌굔 ?곗씠?곌? ?놁뒿?덈떎.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= htmlspecialchars((string)$r['order_no']) ?></td>
              <td><?= htmlspecialchars(map_sales_site($r)) ?></td>
              <td><?= htmlspecialchars((string)($r['carrier_name'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string)($r['tracking_no'] ?? '')) ?></td>
              <td class="product">
                <?= htmlspecialchars((string)($r['reg_product_name'] ?? '')) ?><br>
                <span class="muted"><?= htmlspecialchars((string)($r['reg_option_name'] ?? '')) ?></span><br>
                <strong><?= (int)($r['qty'] ?? 0) ?>媛?/strong>
              </td>
              <td>
                <?= htmlspecialchars((string)($r['receiver_name'] ?? '')) ?><br>
                <span class="muted"><?= htmlspecialchars((string)($r['receiver_phone'] ?? '')) ?></span>
              </td>
              <td><?= htmlspecialchars((string)($r['receiver_address'] ?? '')) ?></td>
              <td class="status"><?= htmlspecialchars(map_delivery_state($r)) ?></td>
              <td><?= htmlspecialchars((string)($r['ordered_at'] ?? $r['imported_at'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
</body>
</html>
