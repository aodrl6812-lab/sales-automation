<section class="grid main-grid">
  <article class="card">
    <h2>실행 센터</h2>
    <p class="muted">버튼 실행 시 백그라운드로 작업이 진행되고, 아래 모니터에서 실시간 로그를 확인할 수 있습니다.</p>

    <div class="summary-strip">
      <div class="summary-head"><strong>판매/배송</strong><span class="summary-base">오늘 기준</span></div>
      <div class="summary-grid">
        <a class="summary-item summary-link" href="orders_detail.php?type=total"><div class="summary-value" id="cntTotalOrders">0</div><div class="summary-label">총 주문수</div></a>
        <a class="summary-item summary-link" href="orders_detail.php?type=instruction"><div class="summary-value" id="cntShippingInstruction">0</div><div class="summary-label">배송지시</div></a>
        <a class="summary-item summary-link" href="orders_detail.php?type=delivering"><div class="summary-value" id="cntDelivering">0</div><div class="summary-label">배송중</div></a>
      </div>
    </div>

    <div class="flow">
      <div class="step">
        <div><div class="step-title">1) 신규주문 수집</div><div class="step-desc">쿠팡 주문 수집, 정규화, 로젠 업로드 파일 생성까지 자동 처리</div></div>
        <button class="run-btn" data-action="process_orders">실행</button>
      </div>
      <div class="step">
        <div><div class="step-title">2) 배송 반영</div><div class="step-desc">송장 업로드 후 배송지시 API 반영 + 배송상태 당기기</div></div>
        <button class="run-btn" data-action="process_shipping">실행</button>
      </div>
    </div>

    <div class="panel" id="monitorPanel">
      <div class="panel-top">
        <strong id="monitorTitle">작업 모니터</strong>
        <div class="panel-top-right"><span class="badge" id="monitorBadge">대기</span><button type="button" class="monitor-close" id="monitorClose">닫기</button></div>
      </div>
      <pre class="monitor-log" id="monitorLog">실행 버튼을 누르면 로그가 누적 표시됩니다.</pre>
    </div>
  </article>

  <aside class="card">
    <h3>운영 현황</h3>
    <p class="muted">쿠팡 API 기반 실시간 집계 데이터입니다.</p>
    <div class="ops-board">
      <section class="ops-card">
        <div class="ops-head"><strong class="ops-title">상단 현황</strong><span class="ops-sub">운영 핵심 4항목</span></div>
        <div class="ops-row">
          <a class="ops-item" href="orders_detail.php?type=delay"><div class="ops-num">31</div><div class="ops-label">배송지연</div></a>
          <div class="ops-item"><div class="ops-num" id="opsInquiry">0</div><div class="ops-label">문의현황</div></div>
          <div class="ops-item"><div class="ops-num" id="opsSoldout">0</div><div class="ops-label">상품판매현황(품절)</div></div>
          <div class="ops-item"><div class="ops-num" id="opsCustomerCenter">0</div><div class="ops-label">고객센터</div></div>
        </div>
      </section>

      <section class="ops-card">
        <div class="ops-head"><strong class="ops-title">클레임 현황</strong><span class="ops-sub">최근 7일</span></div>
        <div class="ops-row">
          <div class="ops-item"><div class="ops-num" id="opsClaimCancel">0</div><div class="ops-label">취소</div></div>
          <div class="ops-item"><div class="ops-num" id="opsClaimReturn">0</div><div class="ops-label">반품</div></div>
          <div class="ops-item"><div class="ops-num" id="opsClaimExchange">0</div><div class="ops-label">교환</div></div>
          <div class="ops-item ops-item-empty"></div>
        </div>
      </section>
    </div>
  </aside>
</section>

<section class="card recent-card">
  <h3>최근 작업</h3>
  <p class="muted">최근 6개 작업 상태</p>
  <div class="jobs">
    <?php foreach (($latestJobs ?? []) as $j): ?>
      <?php $status = (string)($j['status'] ?? 'queued'); $cls = $status === 'success' ? 'success' : ($status === 'failed' ? 'failed' : ($status === 'running' ? 'running' : '')); ?>
      <div class="job-item" data-job-id="<?= (int)($j['id'] ?? 0) ?>" role="button" tabindex="0">
        <div><div><strong>#<?= (int)($j['id'] ?? 0) ?></strong> <?= htmlspecialchars((string)($j['job_name'] ?? '')) ?></div><div class="job-meta"><?= htmlspecialchars((string)($j['created_at'] ?? '')) ?></div></div>
        <span class="badge <?= $cls ?>"><?= htmlspecialchars($status) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</section>