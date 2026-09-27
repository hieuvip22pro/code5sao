<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/payment_gateways.php';
require_login();

$provider = $_GET['provider'] ?? 'vnpay';
$success = false;
$amount = 0;
$ref = '';

if ($provider === 'vnpay') {
    $pc = get_payment_config($pdo, 'vnpay');
    $ref = $_GET['vnp_TxnRef'] ?? '';
    $code = $_GET['vnp_ResponseCode'] ?? '';
    $amount = (int)(($_GET['vnp_Amount'] ?? 0)) / 100;
    if ($pc && vnpay_verify($pc['config'], $_GET)) {
        if ($code === '00') {
            try { payment_mark_success_and_credit($pdo, $ref, $_GET['vnp_TransactionNo'] ?? null, json_encode($_GET)); $success = true; }
            catch (Exception $e) { $success = false; }
        } else {
            payment_mark_failed($pdo, $ref, json_encode($_GET));
        }
    }
} elseif ($provider === 'momo') {
    $pc = get_payment_config($pdo, 'momo');
    $ref = $_GET['orderId'] ?? '';
    $amount = (int)($_GET['amount'] ?? 0);
    if ($pc && ($_GET['resultCode'] ?? '1') === '0') {
        try { payment_mark_success_and_credit($pdo, $ref, $_GET['transId'] ?? null, json_encode($_GET)); $success = true; }
        catch (Exception $e) { $success = false; }
    } else {
        payment_mark_failed($pdo, $ref, json_encode($_GET));
    }
}

$page_title = 'Kết quả thanh toán';
require_once __DIR__ . '/includes/header.php';
?>
<div class="section"><div class="container" style="max-width:520px;text-align:center;">
  <?php if ($success): ?>
    <div style="font-size:56px;">✅</div>
    <h2>Nạp tiền thành công!</h2>
    <p>Đã cộng <strong><?= money($amount) ?></strong> vào ví của bạn.</p>
    <p class="hint">Mã giao dịch: <?= e($ref) ?></p>
  <?php else: ?>
    <div style="font-size:56px;">❌</div>
    <h2>Thanh toán không thành công</h2>
    <p>Giao dịch bị huỷ hoặc chữa ký không hợp lệ. Nếu đã bị trừ tiền, vui lòng liên hệ hỗ trợ.</p>
    <?php if ($ref): ?><p class="hint">Mã giao dịch: <?= e($ref) ?></p><?php endif; ?>
  <?php endif; ?>
  <div style="margin-top:20px;display:flex;gap:8px;justify-content:center;">
    <a href="<?= BASE_URL ?>/wallet" class="btn btn-primary">Về ví</a>
    <a href="<?= BASE_URL ?>/support" class="btn btn-outline">Liên hệ hỗ trợ</a>
  </div>
</div></div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
