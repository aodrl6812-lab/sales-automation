<?php
	declare(strict_types=1);

	require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

	$action = $_GET['action'] ?? null;

	// 🔥 Job 시스템 타기 전에 먼저 처리
	if ($action === 'option_manage' || $action === 'option_save') {
		require_once dirname(__DIR__, 2) . '/app/jobs/option_manage.php';

		if($action === 'option_manage') run_option_manage_page();
		else run_option_save();
		exit;
	}

	if ($action) {
		$jobName = match($action) {
			'process_orders'	=> 'process_orders',
			'process_shipping'	=> 'process_shipping',
			default				=> null
		};

		if($jobName){
			$jobId = job_create($jobName, 'mobile');
			job_start($jobId);

			try {
				if ($action === 'process_orders'){
					require_once dirname(__DIR__, 2) . '/app/jobs/process_orders.php';
					run_process_orders($jobId, $from, $to);
				} else if ($action === 'process_shipping'){
					require_once dirname(__DIR__, 2) . '/app/jobs/process_shipping.php';
					run_process_shipping($jobId, $from, $to);
				}
 
				job_finish($jobId, true);
			} catch (Throwable $e) {
				job_log($jobId, 'error', $e->getMessage());
				job_finish($jobId, false);
			}

			header('Location: /x9k3admin/index.php?job=' . $jobId);
			exit;
		}
	}
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ship New</title>
  <style>
    body{font-family:system-ui,Segoe UI,Apple SD Gothic Neo,Malgun Gothic,sans-serif;margin:16px;max-width:760px}
    .btn{display:block;padding:14px 16px;margin:10px 0;border-radius:12px;border:1px solid #ddd;text-decoration:none}
    .card{border:1px solid #eee;border-radius:12px;padding:12px;margin-top:12px}
    .muted{color:#666;font-size:13px}
    pre{white-space:pre-wrap;background:#fafafa;border:1px solid #eee;border-radius:12px;padding:12px}
  </style>
</head>
<body>
  <a href="?logout=1">로그아웃</a>
  <h2>대시보드</h2>
  <form method="get" style="margin-bottom:12px">
	  <div class="card">
		<b>주문 수집 기간</b>
		<div class="muted">기본: 오늘 00:00:00 ~ 현재시간</div>		
		<div style="display:flex; gap:8px; margin-top:8px; flex-wrap:wrap;">
		  <input name="from" value="<?= htmlspecialchars($from) ?>" placeholder="YYYY-mm-dd HH:ii:ss" style="padding:10px;border:1px solid #ddd;border-radius:10px;flex:1;min-width:220px">
		  <input name="to" value="<?= htmlspecialchars($to) ?>" placeholder="YYYY-mm-dd HH:ii:ss" style="padding:10px;border:1px solid #ddd;border-radius:10px;flex:1;min-width:220px">
		  <button style="padding:10px 14px;border:1px solid #ddd;border-radius:10px;background:#fff;">적용</button>
		</div>
	  </div>
	</form>
  <a class="btn" href="?action=option_manage">옵션ID별 상품명 등록</a>
  <a class="btn" href="?action=process_orders&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">1) 신규주문 수집</a>
  <a class="btn" href="/x9k3admin/upload_invoice.php" style="background:#3498db;color:#fff; border:none; border-radius:6px;">2) 로젠 송장 업로드</a>
  

  <!--
   <a class="btn" href="?action=normalize_coupang&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">1-2) 쿠팡 정규화 실행</a>
  <a class="btn" href="?action=lozen">2-1) 로젠 파일 생성</a>
  <a class="btn" href="?action=reset_lozen">2-2) 로젠 생성 리셋</a>

  <a class="btn" href="?action=import_lozen_invoice">3-2) 송장번호 업로드</a>
  <a class="btn" href="?action=ship">4) 배송중 변경</a>
  -->

  <div class="card">
    <b>최근 작업</b>
    <div class="muted">작업 ID를 눌러 로그 확인</div>
    <ul>
      <?php foreach ($latestJobs as $j): ?>
        <li>
          <a href="?job=<?= (int)$j['id'] ?>">#<?= (int)$j['id'] ?></a>
          <?= htmlspecialchars($j['job_name']) ?> /
          <?= htmlspecialchars($j['status']) ?> /
          <?= htmlspecialchars((string)$j['created_at']) ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <?php if ($selectedJob): ?>
    <div class="card">
      <b>작업 로그 #<?= $selectedJob ?></b>
      <pre><?php foreach ($logs as $l) { echo '['.$l['level'].'] '.$l['message']."\n"; } ?></pre>
    </div>
  <?php endif; ?>
</body>
</html>