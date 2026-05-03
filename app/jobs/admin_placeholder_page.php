<?php
declare(strict_types=1);

function run_admin_placeholder_page(string $title, string $description = ''): void
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeDesc = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');

    echo '<!doctype html>';
    echo '<html lang="ko"><head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Ship New v2 - ' . $safeTitle . '</title>';
    echo '<style>';
    echo ':root{--bg:#f4f7fb;--card:#fff;--text:#1a2433;--muted:#64748b;--line:#e4ebf3;--primary:#3182f6;--radius:18px;--shadow:0 10px 28px rgba(17,24,39,.07)}*{box-sizing:border-box}';
    echo 'body{margin:0;font-family:"SUIT","Pretendard","Noto Sans KR","Apple SD Gothic Neo",sans-serif;background:var(--bg);color:var(--text)}';
    echo '.wrap{max-width:880px;margin:0 auto;padding:26px 16px 40px}.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);padding:20px}';
    echo 'h1{margin:0 0 8px;font-size:24px}.muted{color:var(--muted);font-size:14px;line-height:1.5}.box{margin-top:14px;padding:14px;border:1px dashed #c9d7ea;border-radius:12px;background:#fbfdff}';
    echo '.row{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}.btn{display:inline-block;border:1px solid var(--line);border-radius:12px;padding:10px 14px;text-decoration:none;color:var(--text);font-weight:700;background:#fff}';
    echo '.btn.primary{background:var(--primary);border-color:transparent;color:#fff}';
    echo '</style></head><body>';
    echo '<div class="wrap"><section class="card">';
    echo '<h1>' . $safeTitle . '</h1>';
    echo '<p class="muted">' . ($safeDesc !== '' ? $safeDesc : 'UI 라우팅만 연결된 준비 중 페이지입니다.') . '</p>';
    echo '<div class="box">현재는 페이지 뼈대만 연결되어 있습니다. 이후 기능을 순차적으로 채울 예정입니다.</div>';
    echo '<div class="row">';
    echo '<a class="btn primary" href="index.php">대시보드로 돌아가기</a>';
    echo '</div>';
    echo '</section></div>';
    echo '</body></html>';
    exit;
}