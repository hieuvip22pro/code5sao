<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/payment_gateways.php';
require_login();
$uid = current_user_id();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('/wallet'); }
csrf_verify();

$amount = (int)($_POST['amount'] ?? 0);
$provider = $_POST['provider'] ?? '';
if (!in_array($provider, ['vnpay', 'momo'], true)) { flash_set('error', 'Cổng thanh toán không hợp lệ.'); redirect('/wallet'); }
if ($amount < TOPUP_MIN_ONLINE) { flash_set('error', 'Số tiền nạp tối thiểu ' . money(TOPUP_MIN_ONLINE)); redirect('/wallet'); }

$pc = get_payment_config($pdo, $provider);
if (!$pc || !$pc['active']) { flash_set('error', 'Cổng ' . strtoupper($provider) . ' chưa được kích hoạt.'); redirect('/wallet'); }

$origin = function_exists('seo_site_origin') ? seo_site_origin() : (( !empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']);
$base = rtrim($origin, '/') . rtrim(BASE_URL, '/');
$returnUrl = $base . '/payment_return?provider=' . $provider;
$ipnUrl = $base . '/payment_ipn?provider=' . $provider;
$ipAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$orderInfo = 'Nap vi CodeMarket #' . $uid;

$ref = create_payment_txn($pdo, $uid, $provider, $amount);

if ($provider === 'vnpay') {
    $url = vnpay_create_payment_url($pc['config'], $ref, $amount, $orderInfo, $returnUrl, $ipAddr);
    header('Location: ' . $url);
    exit;
} else { // momo
    $res = momo_create_payment($pc['config'], $ref, $amount, $orderInfo, $returnUrl, $ipnUrl);
    if (!empty($res['payUrl'])) { header('Location: ' . $res['payUrl']); exit; }
    payment_mark_failed($pdo, $ref, json_encode($res));
    flash_set('error', $res['error'] ?? 'Không tạo được thanh toán Momo.');
    redirect('/wallet');
}
