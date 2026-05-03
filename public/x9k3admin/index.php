<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
define('APP_ROOT', $root);

require_once APP_ROOT . '/app/bootstrap.php';

require_once APP_ROOT . '/app/controllers/BaseController.php';
require_once APP_ROOT . '/app/services/SystemService.php';
require_once APP_ROOT . '/app/services/OrderService.php';
require_once APP_ROOT . '/app/services/BoardService.php';
require_once APP_ROOT . '/app/services/OptionUnitPriceService.php';
require_once APP_ROOT . '/app/services/ProductPriceMeasureService.php';
require_once APP_ROOT . '/app/controllers/SystemController.php';
require_once APP_ROOT . '/app/controllers/OrderController.php';
require_once APP_ROOT . '/app/controllers/DeliveryController.php';

$action = isset($_GET['action']) ? (string)$_GET['action'] : 'dashboard';

if ($action === 'option_manage' || $action === 'option_save') {
    require_once APP_ROOT . '/app/jobs/option_manage.php';
    if ($action === 'option_manage') {
        run_option_manage_page();
    } else {
        run_option_save();
    }
    exit;
}

if ($action === 'site_settings' || $action === 'site_settings_save') {
    require_once APP_ROOT . '/app/jobs/site_settings.php';
    if ($action === 'site_settings') {
        run_site_settings_page();
    } else {
        run_site_settings_save();
    }
    exit;
}

if ($action === 'invoice_upload') {
    require __DIR__ . '/upload_invoice.php';
    exit;
}

if ($action === 'logout') {
    header('Location: index.php?logout=1');
    exit;
}

$systemController = new \App\Controllers\SystemController();
$orderController = new \App\Controllers\OrderController($systemController);
$deliveryController = new \App\Controllers\DeliveryController($systemController);

switch ($action) {
    case 'order_create':
        $orderController->create();
        break;
    case 'order_form':
        $orderController->form();
        break;
    case 'order_edit':
        $orderController->form(isset($_GET['id']) ? (int)$_GET['id'] : null);
        break;
    case 'order_save':
        $orderController->save();
        break;
    case 'board':
        $orderController->board();
        break;
    case 'board_form':
        $orderController->boardForm();
        break;
    case 'board_edit':
        $orderController->boardForm(isset($_GET['id']) ? (int)$_GET['id'] : null);
        break;
    case 'board_save':
        $orderController->boardSave();
        break;
    case 'inspection':
        $orderController->inspection();
        
		break;
    
	case 'option_unit_price':       $orderController->optionUnitPrice();
        break;
    case 'option_unit_price_save':
        $orderController->optionUnitPriceSave();
        break;
    case 'product_price_measure':
        $orderController->productPriceMeasure();
        break;
    case 'product_price_measure_save':
        $orderController->productPriceMeasureSave();
        break;
    case 'delivery_status_check':
        $deliveryController->statusCheck();
        break;
    case 'hometax_tax':
        $systemController->hometaxTax();
        break;
    case 'ad_report':
        $systemController->adReport();
        break;
    case 'site_monitor':
        $systemController->siteMonitor();
        break;
    case 'dashboard':
    default:
        $systemController->dashboard();
        break;
}
