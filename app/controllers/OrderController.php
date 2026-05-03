<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\BoardService;
use App\Services\OptionUnitPriceService;
use App\Services\OrderService;
use App\Services\ProductPriceMeasureService;
use App\Services\SystemService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class OrderController extends BaseController
{
    public function __construct(private readonly SystemController $systemController)
    {
    }

    public function create(): void
    {
        $svc = new OrderService();
        $system = new SystemService();

        $this->render('order/list', [
            'pageTitle' => '개별주문 등록',
            'activeAction' => 'order_create',
            'menuGroups' => $system->getMenuGroups(),
            'rows' => $svc->getList(300),
        ]);
    }

    public function form(?int $id = null): void
    {
        $svc = new OrderService();
        $system = new SystemService();

        $row = null;
        if ($id !== null && $id > 0) {
            $row = $svc->findById($id);
        }

        $this->render('order/form', [
            'pageTitle' => $row ? '개별주문 수정' : '개별주문 등록',
            'activeAction' => 'order_create',
            'menuGroups' => $system->getMenuGroups(),
            'row' => $row,
            'productOptions' => $svc->getProductOptions(),
        ]);
    }

    public function save(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            header('Location: index.php?action=order_create');
            exit;
        }

        $id = isset($_POST['id']) && (int)$_POST['id'] > 0 ? (int)$_POST['id'] : null;

        $svc = new OrderService();
        $svc->save($_POST, $id);

        header('Location: index.php?action=order_create');
        exit;
    }

    public function board(): void
    {
        $svc = new BoardService();
        $system = new SystemService();

        $this->render('board/list', [
            'pageTitle' => '게시판',
            'activeAction' => 'board',
            'menuGroups' => $system->getMenuGroups(),
            'rows' => $svc->getList(300),
        ]);
    }

    public function boardForm(?int $id = null): void
    {
        $svc = new BoardService();
        $system = new SystemService();

        $row = null;
        if ($id !== null && $id > 0) {
            $row = $svc->findById($id);
        }

        $this->render('board/form', [
            'pageTitle' => $row ? '게시판 수정' : '게시판 등록',
            'activeAction' => 'board',
            'menuGroups' => $system->getMenuGroups(),
            'row' => $row,
        ]);
    }

    public function boardSave(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            header('Location: index.php?action=board');
            exit;
        }

        $id = isset($_POST['id']) && (int)$_POST['id'] > 0 ? (int)$_POST['id'] : null;

        $svc = new BoardService();
        $svc->save($_POST, $id);

        header('Location: index.php?action=board');
        exit;
    }

    public function inspection(): void
    {
        $system = new SystemService();
        $pdo = \db();

        $todayStart = date('Y-m-d 00:00:00');
        $tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));

        $stmtRaw = $pdo->prepare(
            "SELECT platform, order_no, ordered_at, raw_json
             FROM orders_raw
             WHERE ordered_at >= ?
               AND ordered_at < ?
             ORDER BY platform ASC, id DESC
             LIMIT 400"
        );
        $stmtRaw->execute([$todayStart, $tomorrowStart]);
        $rawRows = $stmtRaw->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $pickFirstNonEmpty = static function (...$values): string {
            foreach ($values as $value) {
                $text = trim((string)$value);
                if ($text !== '') {
                    return $text;
                }
            }
            return '';
        };

        foreach ($rawRows as &$row) {
            $decoded = json_decode((string)($row['raw_json'] ?? ''), true);
            if (!is_array($decoded)) {
                $row['receiver_name'] = '';
                $row['receiver_phone'] = '';
                $row['product_name_option'] = '';
                $row['receiver_address'] = '';
                $row['delivery_message'] = '';
                $row['qty'] = 0;
                continue;
            }

            $receiver = is_array($decoded['receiver'] ?? null) ? $decoded['receiver'] : [];
            $orderItems = is_array($decoded['orderItems'] ?? null) ? $decoded['orderItems'] : [];
            $firstItem = isset($orderItems[0]) && is_array($orderItems[0]) ? $orderItems[0] : [];
            $firstItemRaw = is_array($firstItem['raw'] ?? null) ? $firstItem['raw'] : [];

            $sourceNaver = is_array($decoded['_source']['naver'] ?? null) ? $decoded['_source']['naver'] : [];
            $sourceContent = is_array($sourceNaver['content'] ?? null) ? $sourceNaver['content'] : [];
            $sourceOrder = is_array($sourceContent['order'] ?? null) ? $sourceContent['order'] : [];
            $sourceProductOrder = is_array($sourceContent['productOrder'] ?? null) ? $sourceContent['productOrder'] : [];
            $sourceShipping = is_array($sourceProductOrder['shippingAddress'] ?? null) ? $sourceProductOrder['shippingAddress'] : [];

            $receiverName = $pickFirstNonEmpty(
                $receiver['name'] ?? '',
                $sourceShipping['name'] ?? '',
                $decoded['receiverName'] ?? ''
            );
            $receiverPhone = $pickFirstNonEmpty(
                $receiver['safeNumber'] ?? '',
                $receiver['receiverNumber'] ?? '',
                $sourceShipping['tel1'] ?? '',
                $sourceShipping['tel2'] ?? ''
            );

            $platform = strtolower(trim((string)($row['platform'] ?? '')));
            if ($platform === 'coupang') {
                $productName = $pickFirstNonEmpty(
                    $firstItem['sellerProductName'] ?? '',
                    $firstItemRaw['sellerProductName'] ?? '',
                    $firstItem['productName'] ?? '',
                    $firstItemRaw['productName'] ?? '',
                    $sourceProductOrder['productName'] ?? ''
                );
                $optionName = $pickFirstNonEmpty(
                    $firstItem['sellerProductItemName'] ?? '',
                    $firstItemRaw['sellerProductItemName'] ?? '',
                    $firstItem['vendorItemName'] ?? '',
                    $firstItemRaw['vendorItemName'] ?? '',
                    $firstItemRaw['optionName'] ?? ''
                );
            } else {
                $productName = $pickFirstNonEmpty(
                    $firstItemRaw['productName'] ?? '',
                    $sourceProductOrder['productName'] ?? '',
                    $sourceProductOrder['productName2'] ?? '',
                    $firstItemRaw['productName'] ?? '',
                    $firstItemRaw['channelProductName'] ?? '',
                    $sourceProductOrder['productName'] ?? '',
                    $sourceProductOrder['channelProductName'] ?? ''
                );
                $optionName = $pickFirstNonEmpty(
                    $firstItemRaw['productOption'] ?? '',
                    $sourceProductOrder['productOption'] ?? '',
                    $sourceProductOrder['optionValue'] ?? '',
                    $firstItemRaw['optionName'] ?? '',
                    $sourceProductOrder['optionValue'] ?? '',
                    $sourceProductOrder['optionName'] ?? ''
                );
            }

            if ($platform === 'smartstore' && $productName !== '') {
                $productName = mb_substr($productName, 0, 25, 'UTF-8');
            }
            $productNameOption = $productName;
            if ($optionName !== '') {
                $productNameOption = $productName !== ''
                    ? ($productName . ' (' . $optionName . ')')
                    : $optionName;
            }

            $addr1 = $pickFirstNonEmpty(
                $receiver['addr1'] ?? '',
                $sourceShipping['baseAddress'] ?? '',
                $decoded['receiverAddress'] ?? ''
            );
            $addr2 = $pickFirstNonEmpty(
                $receiver['addr2'] ?? '',
                $sourceShipping['detailedAddress'] ?? ''
            );
            $receiverAddress = trim($addr1 . ' ' . $addr2);

            $deliveryMessage = $pickFirstNonEmpty(
                $decoded['parcelPrintMessage'] ?? '',
                $sourceProductOrder['shippingMemo'] ?? '',
                $sourceOrder['deliveryMemo'] ?? ''
            );

            $qty = 0;
            foreach ($orderItems as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $qty += (int)($item['shippingCount'] ?? 0);
            }
            if ($qty <= 0) {
                $qty = (int)($sourceProductOrder['quantity'] ?? $sourceProductOrder['orderQuantity'] ?? 0);
            }
            if ($qty <= 0) {
                $qty = 1;
            }

            $row['receiver_name'] = $receiverName;
            $row['receiver_phone'] = $receiverPhone;
            $row['product_name_option'] = $productNameOption;
            $row['receiver_address'] = $receiverAddress;
            $row['delivery_message'] = $deliveryMessage;
            $row['qty'] = $qty;
        }
        unset($row);

        require_once APP_ROOT . '/app/jobs/process_orders.php';

        $stmtCoupang = $pdo->prepare(
            "SELECT *
             FROM coupang_order_excel
             WHERE imported_at >= ?
               AND imported_at < ?
             ORDER BY ordered_at ASC, imported_at ASC"
        );
        $stmtCoupang->execute([$todayStart, $tomorrowStart]);
        $ordersCoupang = $stmtCoupang->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $stmtSmartstore = $pdo->prepare(
            "SELECT *
             FROM smartstore_order_excel
             WHERE imported_at >= ?
               AND imported_at < ?
             ORDER BY paid_at ASC, imported_at ASC"
        );
        $stmtSmartstore->execute([$todayStart, $tomorrowStart]);
        $ordersSmartstore = $stmtSmartstore->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $mapRows = $pdo->query('SELECT option_id, factory_product_name, unit_quantity FROM product_option_map')->fetchAll(\PDO::FETCH_ASSOC);
        $optionMap = [];
        foreach ($mapRows as $r) {
            $optionMap[(string)$r['option_id']] = $r;
        }

        $ruleRows = $pdo->query('SELECT option_id, box_qty, box_size FROM product_option_box_rule ORDER BY box_qty ASC')->fetchAll(\PDO::FETCH_ASSOC);
        $boxRules = [];
        foreach ($ruleRows as $r) {
            $boxRules[(string)$r['option_id']][] = $r;
        }

        $priceRows = $pdo->query('SELECT box_size, price FROM box_size_price')->fetchAll(\PDO::FETCH_ASSOC);
        $boxPrice = [];
        foreach ($priceRows as $r) {
            $boxPrice[(string)$r['box_size']] = (int)$r['price'];
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $rowNum = 1;

        [$rowNum, $dummyCoupangNos, $coupangPreviewRows] = \step4_append_coupang_rows(
            null,
            $sheet,
            $ordersCoupang,
            $optionMap,
            $boxRules,
            $boxPrice,
            $rowNum
        );
        [$rowNum, $dummySmartstoreNos, $smartstorePreviewRows] = \step4_append_smartstore_rows(
            null,
            $sheet,
            $ordersSmartstore,
            $optionMap,
            $boxRules,
            $boxPrice,
            $rowNum
        );
        unset($dummyCoupangNos, $dummySmartstoreNos, $rowNum);

        $normalizedRows = array_merge($coupangPreviewRows, $smartstorePreviewRows);

        $this->render('order/inspection', [
            'pageTitle' => '데이터 검수',
            'activeAction' => 'inspection',
            'menuGroups' => $system->getMenuGroups(),
            'todayStart' => $todayStart,
            'tomorrowStart' => $tomorrowStart,
            'rawRows' => $rawRows,
            'normalizedRows' => $normalizedRows,
        ]);
    }

    public function optionUnitPrice(): void
    {
        $svc = new OptionUnitPriceService();
        $system = new SystemService();

        $editRow = null;
        $editId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
        if ($editId > 0) {
            $editRow = $svc->findById($editId);
        }

        $this->render('order/option_unit_price', [
            'pageTitle' => '옵션별 단가 등록',
            'activeAction' => 'option_unit_price',
            'menuGroups' => $system->getMenuGroups(),
            'rows' => $svc->getList(500),
            'productOptions' => $svc->getProductOptions(),
            'editRow' => $editRow,
        ]);
    }

    public function optionUnitPriceSave(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            header('Location: index.php?action=option_unit_price');
            exit;
        }

        $id = isset($_POST['id']) && (int)$_POST['id'] > 0 ? (int)$_POST['id'] : null;

        $svc = new OptionUnitPriceService();
        $svc->save($_POST, $id);

        header('Location: index.php?action=option_unit_price');
        exit;
    }

    public function productPriceMeasure(): void
    {
        $svc = new ProductPriceMeasureService();
        $system = new SystemService();

        $latestInput = [
            'cost_amount' => '',
            'shipping_fee' => '',
            'sales_fee_percent' => '',
            'desired_margin_percent' => '',
        ];
        $latestFinalPrice = 0.0;
        $errorMessage = '';

        $this->render('order/product_price_measure', [
            'pageTitle' => '상품 판매가 측정페이지',
            'activeAction' => 'product_price_measure',
            'menuGroups' => $system->getMenuGroups(),
            'rows' => $svc->getList(200),
            'latestInput' => $latestInput,
            'latestFinalPrice' => $latestFinalPrice,
            'errorMessage' => $errorMessage,
        ]);
    }

    public function productPriceMeasureSave(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            header('Location: index.php?action=product_price_measure');
            exit;
        }

        $svc = new ProductPriceMeasureService();
        try {
            $svc->save($_POST);
            header('Location: index.php?action=product_price_measure');
            exit;
        } catch (\Throwable $e) {
            $system = new SystemService();
            $this->render('order/product_price_measure', [
                'pageTitle' => '상품 판매가 측정페이지',
                'activeAction' => 'product_price_measure',
                'menuGroups' => $system->getMenuGroups(),
                'rows' => $svc->getList(200),
                'latestInput' => [
                    'cost_amount' => (string)($_POST['cost_amount'] ?? ''),
                    'shipping_fee' => (string)($_POST['shipping_fee'] ?? ''),
                    'sales_fee_percent' => (string)($_POST['sales_fee_percent'] ?? ''),
                    'desired_margin_percent' => (string)($_POST['desired_margin_percent'] ?? ''),
                ],
                'latestFinalPrice' => 0,
                'errorMessage' => $e->getMessage(),
            ]);
        }
    }
}
