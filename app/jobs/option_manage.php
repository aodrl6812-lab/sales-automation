<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

function run_option_manage_page(): void
{
    $pdo = db();

    $list = $pdo->query("
        SELECT 
            m.option_id,
            m.factory_product_name,
            GROUP_CONCAT(
                CONCAT(r.box_qty,' → ',r.box_size)
                ORDER BY r.box_qty
                SEPARATOR ', '
            ) AS rules
        FROM product_option_map m
        LEFT JOIN product_option_box_rule r
            ON m.option_id = r.option_id
        GROUP BY m.option_id
        ORDER BY m.option_id DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "<h2>옵션ID 상품명 등록</h2>";

    echo '<form method="post" action="?action=option_save">';

    echo '<input 
        type="text" 
        name="option_id" 
        placeholder="옵션ID (vendorItemId)" 
        required 
        style="width:100%;padding:10px;margin-bottom:8px;">';

    echo '<input 
        type="text" 
        name="factory_product_name" 
        placeholder="공장용 상품명" 
        required 
        style="width:100%;padding:10px;margin-bottom:8px;">';

    echo '<h3 style="margin-top:20px;">박스 단위 규칙</h3>';

    echo '
    <table id="boxRuleTable" border="1" width="100%" style="margin-bottom:10px;">
        <thead>
            <tr>
                <th>묶음수량</th>
                <th>박스사이즈</th>
                <th>삭제</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
    ';

    echo '<button type="button" onclick="addBoxRow()">+ 박스단위 추가</button>';

    echo '<br><br>';

    echo '<button style="
        width:100%;
        padding:12px;
        background:#4CAF50;
        color:#fff;
        border:0;
        border-radius:6px;
        font-size:16px;
    ">저장</button>';

    echo '</form>';

    echo "<hr>";
    echo "<h3>최근 등록 목록</h3>";

    echo "<table border='1' width='100%'>";
    echo "<tr>
            <th>옵션ID</th>
            <th>상품명</th>
            <th>박스규칙</th>
          </tr>";

    foreach ($list as $row) {

        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['option_id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['factory_product_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['rules'] ?? '-') . "</td>";
        echo "</tr>";
    }

    echo "</table>";

    echo "<br>";

    echo '<a href="index.php" style="
        display:block;
        padding:12px;
        text-align:center;
        border:1px solid #ddd;
        border-radius:12px;
        text-decoration:none;
        margin-top:12px;
    ">← 대시보드로 이동</a>';

    echo '
    <script>

    function addBoxRow(qty="", size="")
    {
        const tbody = document.querySelector("#boxRuleTable tbody");

        const tr = document.createElement("tr");

        tr.innerHTML = `
            <td>
                <input type="number"
                       name="box_qty[]"
                       value="${qty}"
                       required
                       style="width:100%;">
            </td>

            <td>
                <select name="box_size[]" required style="width:100%;">
                    <option value="">선택</option>
                    <option value="S" ${size==="S"?"selected":""}>S</option>
                    <option value="M" ${size==="M"?"selected":""}>M</option>
                    <option value="L" ${size==="L"?"selected":""}>L</option>
                    <option value="XL" ${size==="XL"?"selected":""}>XL</option>
                </select>
            </td>

            <td>
                <button type="button"
                        onclick="this.closest(\'tr\').remove()">
                    삭제
                </button>
            </td>
        `;

        tbody.appendChild(tr);
    }

    </script>
    ';
}

function run_option_save(): void
{
    $pdo = db();

    $optionId = trim($_POST['option_id'] ?? '');
    $productName = trim($_POST['factory_product_name'] ?? '');

    $boxQtyList  = $_POST['box_qty'] ?? [];
    $boxSizeList = $_POST['box_size'] ?? [];

    if (!$optionId || !$productName) {
        echo "값이 부족합니다.";
        return;
    }

    try {

        $pdo->beginTransaction();

        // 옵션 기본 정보 저장
        $stmt = $pdo->prepare("
            INSERT INTO product_option_map
            (option_id, factory_product_name, unit_quantity, box_size)
            VALUES (?, ?, 1, '')
            ON DUPLICATE KEY UPDATE
                factory_product_name = VALUES(factory_product_name)
        ");

        $stmt->execute([
            $optionId,
            $productName
        ]);

        // 기존 박스 규칙 삭제
        $pdo->prepare("
            DELETE FROM product_option_box_rule
            WHERE option_id = ?
        ")->execute([$optionId]);

        // 새 박스 규칙 저장
        for ($i = 0; $i < count($boxQtyList); $i++) {

            if (
                empty($boxQtyList[$i]) ||
                empty($boxSizeList[$i])
            ) {
                continue;
            }

            $stmt = $pdo->prepare("
                INSERT INTO product_option_box_rule
                (option_id, box_qty, box_size)
                VALUES (?, ?, ?)
            ");

            $stmt->execute([
                $optionId,
                (int)$boxQtyList[$i],
                $boxSizeList[$i]
            ]);
        }

        $pdo->commit();

    } catch (Exception $e) {

        $pdo->rollBack();

        echo "저장 중 오류 발생: " . $e->getMessage();
        return;
    }

    header("Location: ?action=option_manage");
    exit;
}