<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle ?? 'Ship New v2') ?></title>
  <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="wrap">
  <div class="home-strip">
    <a class="home-strip-link" href="index.php?action=dashboard">Ship New v2 Console</a>
    <div class="top-actions">
      <button type="button" class="link-btn" id="btnCheckDeliveryStatus">배송 상태 동기화</button>
      <button type="button" class="link-btn" id="btnMakeLozenRetry">미배송 엑셀 재다운</button>
    </div>
  </div>

  <div class="layout" id="adminLayout">
    <div class="sidebar-wrap">
      <aside class="sidebar">
        <div class="side-brand">
          <h1>Admin Menu</h1>
          <p>Left sidebar routing</p>
        </div>
        <?php foreach (($menuGroups ?? []) as $group): ?>
          <div class="menu-group">
            <div class="menu-group-title"><?= htmlspecialchars((string)$group['title']) ?></div>
            <?php foreach (($group['items'] ?? []) as $item): ?>
              <?php $isActive = (($activeAction ?? '') === (string)$item['action']); ?>
              <a class="menu-link<?= $isActive ? ' active' : '' ?>" href="index.php?action=<?= urlencode((string)$item['action']) ?>"><?= htmlspecialchars((string)$item['label']) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </aside>
      <button type="button" class="sidebar-edge-toggle" id="sidebarEdgeToggle" aria-label="Close menu" aria-expanded="true">&#x25C0;</button>
    </div>
    <main>