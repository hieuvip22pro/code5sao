<?php
/** IPN (server-to-server) - xac thuc va cong tien. Khong xuat HTML nguoi dung. */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/payment_gateways.php';

$provider = $_GET['provider'] ?? 'vnpay';
header('Content-Type: application/json');

if ($provider === 'vnpay') {
    $pc = get_payment_config($pdo, 'vnpay');
    $ref = $_GET['vnp_TxnRef'] ?? '';
    $code = $_GET['vnp_ResponseCode'] ?? '';
    if (!$pc || !vnpay_verify($pc['config'], $_GET)) {
        echo json_encode(['RspCode' => '97', 'Message' => 'Invalid signature']); exit;
    }
    $s = $pdo->prepare('SELECT * FROM payment_transactions WHERE txn_ref = ?');
    $s->execute([$ref]);
    $t = $s->fetch();
    if (!$t) { echo json_encode(['RspCode' => '01', 'Message' => 'Order not found']); exit; }
    $expected = (int)round($t['amount'] * 100);
    if ((int)($_GET['vnp_Amount'] ?? 0) !== $expected) { echo json_encode(['RspCode' => '04', 'Message' => 'Invalid amount']); exit; }
    if ($t['status'] === 'success') { echo json_encode(['RspCode' => '02', 'Message' => 'Order already confirmed']); exit; }
    if ($code === '00') {
        try { payment_mark_success_and_credit($pdo, $ref, $_GET['vnp_TransactionNo'] ?? null, json_encode($_GET)); }
        catch (Exception $e) { echo json_encode(['RspCode' => '99', 'Message' => 'Error']); exit; }
        echo json_encode(['RspCode' => '00', 'Message' => 'Confirm Success']); exit;
    } else {
        payment_mark_failed($pdo, $ref, json_encode($_GET));
        echo json_encode(['RspCode' => '00', 'Message' => 'Confirm Success']); exit;
    }
} elseif ($provider === 'momo') {
    $pc = get_payment_config($pdo, 'momo');
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = $_POST;
    $ref = $body['orderId'] ?? '';
    if (!$pc || !momo_verify_ipn($pc['config'], $body)) {
        http_response_code(400); echo json_encode(['message' => 'Invalid signature']); exit;
    }
    if ((string)($body['resultCode'] ?? '1') === '0') {
        try { payment_mark_success_and_credit($pdo, $ref, $body['transId'] ?? null, json_encode($body)); } catch (Exception $e) {}
    } else {
        payment_mark_failed($pdo, $ref, json_encode($body));
    }
    echo json_encode(['message' => 'received']); exit;
}
echo json_encode(['message' => 'unknown provider']);
