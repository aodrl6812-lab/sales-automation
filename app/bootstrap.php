<?php

date_default_timezone_set('Asia/Seoul');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('session.gc_maxlifetime', '604800'); // 7 days
session_set_cookie_params([
  'lifetime' => 604800, // 7 days
  'path' => '/',
  'httponly' => true,
  'samesite' => 'Lax',
]);

session_start();

$from = $_GET['from'] ?? date('Y-m-d 00:00:00', strtotime('-3 day'));
$to   = $_GET['to']   ?? date('Y-m-d H:i:s', strtotime('+4 hours'));

// 1) logout first
if (isset($_GET['logout'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'] ?? false, $p['httponly'] ?? true);
    }
    session_unset();
    session_destroy();
    header('Location: /index.php');
    exit;
}

$BASE = dirname(__DIR__);
require_once $BASE . '/app/bootstrap.php';
require_once $BASE . '/app/job_runner.php';
require_once $BASE . '/vendor/autoload.php';
require_once $BASE . '/app/db.php';

$adminPw = envv('ADMIN_PASSWORD');
if (!isset($_SESSION['auth'])) {
    if (($_POST['pw'] ?? '') === $adminPw) {
        $_SESSION['auth'] = true;
    } else {
        echo '<form method="post"><input type="password" name="pw" placeholder="Password"><button>Login</button></form>';
        exit;
    }
}

$pdo = db();
$latestJobs = $pdo->query('SELECT * FROM jobs ORDER BY id DESC LIMIT 10')->fetchAll();
$selectedJob = isset($_GET['job']) ? (int)$_GET['job'] : 0;

$logs = [];
if ($selectedJob) {
    $stmt = $pdo->prepare('SELECT * FROM job_logs WHERE job_id=? ORDER BY id ASC');
    $stmt->execute([$selectedJob]);
    $logs = $stmt->fetchAll();
}

function logMessage($jobId, $message)
{
    try {
        $pdo = db();

        $stmt = $pdo->prepare('INSERT INTO job_logs (job_id, message, created_at) VALUES (?, ?, NOW())');
        $stmt->execute([$jobId, $message]);
    } catch (Exception $e) {
        error_log($message);
    }
}
