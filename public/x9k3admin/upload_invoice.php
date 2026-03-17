<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_FILES['invoice_file'])) {
        die("파일 없음");
    }

    $file = $_FILES['invoice_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        die("업로드 오류");
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);

    if (!in_array(strtolower($ext), ['xlsx','xls'])) {
        die("엑셀 파일만 업로드 가능");
    }

    $uploadDir = __DIR__ . '/../../storage/invoice/';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $filename = 'invoice_' . date('Ymd_His') . '.' . $ext;

    $target = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        die("파일 저장 실패");
    }

    echo "업로드 성공<br>";
    echo "파일: " . $filename;

	header('Location: /x9k3admin/index.php?action=process_shipping');
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>송장 업로드</title>
</head>

<body>
<div style="margin-bottom:20px;">
	<a href="/x9k3admin">
		<button style="padding:12px 20px;font-size:14px;background:#555;color:#fff;border:none;border-radius:6px;cursor:pointer;">← 대시보드</button>
	</a>
</div>

<h2>로젠 송장 엑셀 업로드</h2>

<form method="post" enctype="multipart/form-data">

<input type="file" name="invoice_file" required>

<br><br>

<button type="submit">업로드</button>

</form>

</body>
</html>