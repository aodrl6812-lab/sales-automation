(function(){
  'use strict';

  function byId(id){ return document.getElementById(id); }

  async function readJsonSafe(res){
    const text = await res.text();
    try { return JSON.parse(text); } catch (_) {
      const s = text.indexOf('{');
      const e = text.lastIndexOf('}');
      if (s >= 0 && e > s) return JSON.parse(text.slice(s, e + 1));
      throw new Error('Invalid JSON response');
    }
  }

  function syncHomeStripHeight(){
    var strip = document.querySelector('.home-strip');
    if(!strip) return;
    var h = Math.max(0, Math.ceil(strip.getBoundingClientRect().height));
    document.documentElement.style.setProperty('--home-strip-h', String(h) + 'px');
  }

  function syncSidebarTogglePosition(){
    var toggle = byId('sidebarEdgeToggle');
    if(!toggle) return;

    if(window.innerWidth <= 1180){
      toggle.style.top = '62vh';
      return;
    }

    toggle.style.top = '';
  }

  function initSidebarToggle(){
    const layout = byId('adminLayout');
    const toggle = byId('sidebarEdgeToggle');
    if(!layout || !toggle) return;

    const storageKey = 'x9k3admin.sidebarCollapsed';

    const apply = function(collapsed){
      layout.classList.toggle('sidebar-collapsed', collapsed);
      toggle.textContent = collapsed ? '\u25B6' : '\u25C0';
      toggle.setAttribute('aria-label', collapsed ? 'Open menu' : 'Close menu');
      toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      syncSidebarTogglePosition();
    };

    let isCollapsed = false;
    try {
      isCollapsed = window.localStorage.getItem(storageKey) === '1';
    } catch (_e) {}

    layout.classList.add('sidebar-initializing');
    syncHomeStripHeight();
    apply(isCollapsed);
    requestAnimationFrame(function(){
      layout.classList.remove('sidebar-initializing');
    });

    toggle.addEventListener('click', function(){
      const next = !layout.classList.contains('sidebar-collapsed');
      apply(next);
      try {
        window.localStorage.setItem(storageKey, next ? '1' : '0');
      } catch (_e) {}
    });

    document.querySelectorAll('.menu-link').forEach(function(link){
      link.addEventListener('click', function(){
        apply(true);
        try {
          window.localStorage.setItem(storageKey, '1');
        } catch (_e) {}
      });
    });
  }

  async function loadSummary(){
    const total = byId('cntTotalOrders');
    const instruction = byId('cntShippingInstruction');
    const delivering = byId('cntDelivering');
    if(!total || !instruction || !delivering) return;

    try {
      const res = await fetch('api/summary.php', { cache: 'no-store' });
      const json = await readJsonSafe(res);
      if(!json.ok) return;
      const m = json.metrics || {};
      total.textContent = String(Number(m.total_orders || 0));
      instruction.textContent = String(Number(m.shipping_instruction || 0));
      delivering.textContent = String(Number(m.delivering || 0));
    } catch (_e) {
      total.textContent = '0';
      instruction.textContent = '0';
      delivering.textContent = '0';
    }
  }

  async function loadOpsStatus(){
    const ids = {
      inquiry: byId('opsInquiry'),
      soldout: byId('opsSoldout'),
      customer_center: byId('opsCustomerCenter'),
      claim_cancel: byId('opsClaimCancel'),
      claim_return: byId('opsClaimReturn'),
      claim_exchange: byId('opsClaimExchange')
    };

    if(!ids.inquiry) return;

    try {
      const res = await fetch('api/ops_status.php', { cache: 'no-store' });
      const json = await readJsonSafe(res);
      if(!json.ok) return;
      const m = json.metrics || {};
      ids.inquiry.textContent = String(Number(m.inquiry || 0));
      ids.soldout.textContent = String(Number(m.soldout || 0));
      ids.customer_center.textContent = String(Number(m.customer_center || 0));
      ids.claim_cancel.textContent = String(Number(m.claim_cancel || 0));
      ids.claim_return.textContent = String(Number(m.claim_return || 0));
      ids.claim_exchange.textContent = String(Number(m.claim_exchange || 0));
    } catch (_e) {
      Object.keys(ids).forEach(function(k){ if(ids[k]) ids[k].textContent = '0'; });
    }
  }

  let monitorTimer = null;
  let monitorLastLogId = 0;

  function stopJobMonitor(){
    if(monitorTimer){
      clearTimeout(monitorTimer);
      monitorTimer = null;
    }
  }

  function scheduleJobMonitor(jobId){
    stopJobMonitor();
    monitorTimer = setTimeout(function(){
      pollJobStatus(jobId);
    }, 1200);
  }

  function appendJobLogs(monitorLog, logs){
    if(!monitorLog || !Array.isArray(logs) || !logs.length) return;
    const chunk = logs.map(function(l){
      const lv = String(l.level || 'info');
      const msg = String(l.message || '');
      return '[' + lv + '] ' + msg;
    }).join('\n');

    if(monitorLog.textContent.trim() === ''){
      monitorLog.textContent = chunk;
    } else {
      monitorLog.textContent += '\n' + chunk;
    }
  }

  async function pollJobStatus(jobId){
    const monitorBadge = byId('monitorBadge');
    const monitorLog = byId('monitorLog');
    const monitorTitle = byId('monitorTitle');
    if(!monitorBadge || !monitorLog || !monitorTitle) return;

    try {
      const res = await fetch('api/job_status.php?job_id=' + encodeURIComponent(String(jobId)) + '&since_id=' + encodeURIComponent(String(monitorLastLogId)), { cache: 'no-store' });
      const json = await readJsonSafe(res);
      if(!json.ok){
        monitorBadge.textContent = 'failed';
        monitorLog.textContent += '\n[error] ' + String(json.message || 'Status poll failed');
        stopJobMonitor();
        return;
      }

      appendJobLogs(monitorLog, json.logs || []);
      monitorLastLogId = Number(json.last_log_id || monitorLastLogId || 0);

      const st = String((json.job && json.job.status) || '').toLowerCase();
      if(st === 'success' || st === 'failed'){
        monitorBadge.textContent = st;
        if(st === 'success'){
          monitorBadge.classList.remove('failed');
          monitorBadge.classList.add('success');
        } else {
          monitorBadge.classList.remove('success');
          monitorBadge.classList.add('failed');
        }
        monitorLog.textContent += '\n[info] Job finished: ' + st;
        loadSummary();
        stopJobMonitor();
        return;
      }

      monitorBadge.textContent = 'running';
      scheduleJobMonitor(jobId);
    } catch (e) {
      monitorBadge.textContent = 'failed';
      monitorLog.textContent += '\n[error] ' + (e && e.message ? e.message : 'Status poll error');
      stopJobMonitor();
    }
  }

  async function startJob(action){
    const monitorTitle = byId('monitorTitle');
    const monitorBadge = byId('monitorBadge');
    const monitorLog = byId('monitorLog');
    const panel = byId('monitorPanel');
    if(!monitorTitle || !monitorBadge || !monitorLog) return;
    if(panel) panel.style.display = 'block';

    stopJobMonitor();
    monitorLastLogId = 0;
    monitorTitle.textContent = 'Job running';
    monitorBadge.textContent = 'running';
    monitorBadge.classList.remove('success', 'failed');
    monitorLog.textContent = '[info] Job started...';

    try {
      const res = await fetch('api/run.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: action })
      });
      const json = await readJsonSafe(res);
      if(!json.ok) throw new Error(json.message || 'Job start failed');

      const jobId = Number(json.job_id || 0);
      monitorTitle.textContent = 'Job #' + String(jobId || '');
      monitorLog.textContent += '\n[info] Background job is running.';

      if(jobId > 0){
        scheduleJobMonitor(jobId);
      } else {
        monitorBadge.textContent = 'failed';
        monitorLog.textContent += '\n[error] Invalid job id';
      }
    } catch (e) {
      monitorBadge.textContent = 'failed';
      monitorBadge.classList.add('failed');
      monitorLog.textContent += '\n[error] ' + (e && e.message ? e.message : 'Unknown error');
      stopJobMonitor();
    }
  }

  async function openExistingJob(jobId){
    const monitorTitle = byId('monitorTitle');
    const monitorBadge = byId('monitorBadge');
    const monitorLog = byId('monitorLog');
    const panel = byId('monitorPanel');
    if(!monitorTitle || !monitorBadge || !monitorLog) return;
    if(panel) panel.style.display = 'block';

    stopJobMonitor();
    monitorLastLogId = 0;
    monitorTitle.textContent = 'Job #' + String(jobId || '');
    monitorBadge.classList.remove('success', 'failed');
    monitorBadge.textContent = 'running';
    monitorLog.textContent = '[info] Loading selected job logs...';

    try {
      const res = await fetch('api/job_status.php?job_id=' + encodeURIComponent(String(jobId)) + '&since_id=0', { cache: 'no-store' });
      const json = await readJsonSafe(res);
      if(!json.ok){
        monitorBadge.textContent = 'failed';
        monitorLog.textContent += '\n[error] ' + String(json.message || 'Failed to load job');
        return;
      }

      monitorLog.textContent = '';
      appendJobLogs(monitorLog, json.logs || []);
      monitorLastLogId = Number(json.last_log_id || 0);

      const st = String((json.job && json.job.status) || '').toLowerCase();
      monitorBadge.textContent = st || 'running';
      if(st === 'success'){
        monitorBadge.classList.add('success');
      } else if(st === 'failed'){
        monitorBadge.classList.add('failed');
      } else {
        scheduleJobMonitor(jobId);
      }
    } catch (e) {
      monitorBadge.textContent = 'failed';
      monitorBadge.classList.add('failed');
      monitorLog.textContent += '\n[error] ' + (e && e.message ? e.message : 'Load job failed');
    }
  }

  function bindActions(){
    document.querySelectorAll('.run-btn[data-action]').forEach(function(btn){
      btn.addEventListener('click', function(){
        startJob(btn.getAttribute('data-action') || '');
      });
    });

    const btnCheck = byId('btnCheckDeliveryStatus');
    if(btnCheck){ btnCheck.addEventListener('click', function(){ startJob('check_delivery_status'); }); }

    const btnRetry = byId('btnMakeLozenRetry');
    if(btnRetry){ btnRetry.addEventListener('click', function(){ startJob('make_lozen_retry_file'); }); }

    const monitorClose = byId('monitorClose');
    if(monitorClose){
      monitorClose.addEventListener('click', function(){
        const panel = byId('monitorPanel');
        if(panel) panel.style.display = 'none';
        stopJobMonitor();
      });
    }

    document.querySelectorAll('.job-item[data-job-id]').forEach(function(item){
      const open = function(){
        const jobId = Number(item.getAttribute('data-job-id') || '0');
        if(jobId > 0){
          openExistingJob(jobId);
        }
      };

      item.addEventListener('click', open);
      item.addEventListener('keydown', function(e){
        if(e.key === 'Enter' || e.key === ' '){
          e.preventDefault();
          open();
        }
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function(){
    initSidebarToggle();
    bindActions();
    loadSummary();
    loadOpsStatus();
  });
})();