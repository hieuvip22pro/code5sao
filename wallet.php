<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/topup_functions.php';
require_once __DIR__ . '/includes/withdraw_functions.php';
require_once __DIR__ . '/includes/totp.php';
require_once __DIR__ . '/includes/security_functions.php';
require_login();

$uid = current_user_id();
$withdrawError = null;
$withdrawOtpSent = false;
$wForm = ['amount'=>'', 'bank_name'=>'', 'bank_account'=>'', 'account_holder'=>'', 'user_note'=>''];

// Tạo yêu cầu nạp tiền mới -> chuyển sang trang hiển thị QR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_topup'])) {
    csrf_verify();
    $amount = (float)($_POST['amount'] ?? 0);
    try {
        $topupId = create_topup_request($pdo, $uid, $amount);
        redirect('/topup_qr?id=' . $topupId);
    } catch (Exception $e) {
        flash_set('error', $e->getMessage());
        redirect('/wallet');
    }
}

// Gửi yêu cầu rút tiền
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_withdraw'])) {
    csrf_verify();
    $amount = (float)($_POST['amount'] ?? 0);
    $bankName = trim($_POST['bank_name'] ?? '');
    $bankAccount = trim($_POST['bank_account'] ?? '');
    $accountHolder = trim($_POST['account_holder'] ?? '');
    $userNote = trim($_POST['user_note'] ?? '');
    // Giữ lại giá trị đã nhập để hiển thị lại khi cần nhập mã 2 lớp
    $wForm = ['amount'=>$_POST['amount'] ?? '', 'bank_name'=>$bankName, 'bank_account'=>$bankAccount, 'account_holder'=>$accountHolder, 'user_note'=>$userNote];

    // ===== Bảo mật 2 lớp khi rút tiền (nếu tài khoản đã bật 2FA) =====
    $meSec = get_user($pdo, $uid);
    $wMethod = user_2fa_method($meSec);
    $code2fa = trim($_POST['withdraw_2fa_code'] ?? '');
    $pass2fa = true;

    if ($wMethod !== 'none') {
        if ($wMethod === 'email' && $code2fa === '' && empty($_POST['withdraw_otp_sent'])) {
            // Lần đầu bấm: gửi mã OTP qua email rồi yêu cầu nhập
            $otp = security_generate_code(6);
            $pdo->prepare('UPDATE users SET email_otp_code=?, email_otp_expires=DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id=?')->execute([$otp, $uid]);
            send_otp_email($meSec['email'], $otp, 'Mã xác nhận rút tiền');
            $withdrawError = 'Đã gửi mã xác thực tới email của bạn. Nhập mã để xác nhận yêu cầu rút tiền.';
            $withdrawOtpSent = true;
            $pass2fa = false;
        } elseif (!verify_user_2fa($pdo, $meSec, $code2fa)) {
            $withdrawError = 'Mã xác thực 2 lớp không đúng hoặc đã hết hạn.';
            $withdrawOtpSent = ($wMethod === 'email');
            $pass2fa = false;
        }
    }

    if ($pass2fa) {
        try {
            $reqId = create_withdrawal_request($pdo, $uid, $amount, $bankName, $bankAccount, $accountHolder, $userNote);
            flash_set('success', 'Đã gửi yêu cầu rút tiền #' . $reqId . '. Vui lòng chờ admin duyệt.');
            redirect('/wallet');
        } catch (Exception $e) {
            $withdrawError = $e->getMessage();
        }
    }
}

$me = get_user($pdo, $uid);

$tx = $pdo->prepare('SELECT * FROM wallet_transactions WHERE user_id=? ORDER BY created_at DESC LIMIT 50');
$tx->execute([$uid]); $tx = $tx->fetchAll();

$topups = $pdo->prepare('SELECT * FROM wallet_topups WHERE user_id=? ORDER BY created_at DESC LIMIT 30');
$topups->execute([$uid]); $topups = $topups->fetchAll();

$withdrawals = $pdo->prepare('SELECT * FROM withdrawal_requests WHERE user_id=? ORDER BY created_at DESC LIMIT 30');
$withdrawals->execute([$uid]); $withdrawals = $withdrawals->fetchAll();

$page_title = 'Ví của tôi';
require_once __DIR__ . '/includes/header.php';
?>
<div class="section">
    <div class="container">
        <!-- Đã bỏ max-width để form số dư dàn đều hoặc theo grid mặc định -->
        <div class="stat-cards" style="grid-template-columns:1fr; margin-bottom: 24px;">
            <div class="stat-card"><div class="label">SỐ DƯ VÍ HIỆN TẠI</div><div class="value mono" style="color:var(--accent);font-size:32px;"><?= money($me['wallet_balance']) ?></div></div>
        </div>

        <div style="display:flex; gap:24px; flex-wrap:wrap; margin-bottom:32px; align-items:stretch;">
            
            <!-- Cột trái: Form nạp tiền + Lưu ý (Đã bỏ max-width:400px) -->
            <div style="display:flex; flex-direction:column; gap:24px; flex:1; min-width:300px;">
                <div class="form-card" style="margin:0; height:100%;">
                    <h3 style="font-size:16px;">💳 Nạp tiền (tối thiểu 10.000đ)</h3>
                    <p class="hint">Quét mã QR bằng app ngân hàng bất kỳ — tiền vào ví tự động, không cần chờ admin duyệt.</p>
                    <form method="post" style="display:flex; gap:10px; margin-top:12px;">
                        <?= csrf_input() ?>
                        <input type="number" name="amount" min="10000" step="1" placeholder="VD: 10000 (Tối thiểu 10000)" style="flex:1;" required>
                        <button class="btn btn-primary" type="submit" name="create_topup" value="1">Tạo mã QR</button>
                    </form>
                    <?php
                        require_once __DIR__ . '/includes/payment_gateways.php';
                        $__activePg = get_active_payment_providers($pdo);
                    ?>
                    <?php if ($__activePg): ?>
                    <div style="margin-top:16px; border-top:1px solid var(--border); padding-top:14px;">
                        <p class="hint" style="margin-bottom:8px;">Hoặc nạp nhanh qua cổng thanh toán online:</p>
                        <form method="post" action="<?= BASE_URL ?>/payment_start" style="display:flex; gap:8px; flex-wrap:wrap;">
                            <?= csrf_input() ?>
                            <input type="number" name="amount" min="10000" step="1" placeholder="Số tiền" style="flex:1; min-width:140px;" required>
                            <?php foreach ($__activePg as $__pg): ?>
                            <button class="btn btn-outline" type="submit" name="provider" value="<?= $__pg ?>"><?= strtoupper($__pg) ?></button>
                            <?php endforeach; ?>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="form-card" style="margin:0; height:100%;">
                    <h3 style="font-size:16px;">📌 Lưu ý khi nạp tiền</h3>
                    <ul class="hint" style="margin-top:12px; padding-left:20px; line-height:1.8; margin-bottom:0;">
                        <li>Hệ thống xử lý nạp tiền hoàn toàn tự động 24/7.</li>
                        <li>Vui lòng quét đúng mã QR được tạo và không thay đổi nội dung chuyển khoản.</li>
                        <li>Tiền sẽ được cộng vào ví trong vòng 1-3 phút sau khi giao dịch thành công.</li>
                        <li>Nếu sau 10 phút chưa nhận được số dư, vui lòng liên hệ admin với ảnh chụp biên lai để được hỗ trợ nhanh nhất.</li>
                    </ul>
                </div>
            </div>

            <!-- Cột phải: Form rút tiền (Đã bỏ max-width:400px) -->
            <div class="form-card" style="margin:0; flex:1; min-width:300px; display:flex; flex-direction:column;">
                <h3 style="font-size:16px;">🏦 Rút tiền về ngân hàng (tối thiểu 50.000đ)</h3>
                <p class="hint">Gửi yêu cầu, admin sẽ duyệt và chuyển khoản thủ công. Tiền được giữ lại ngay khi gửi yêu cầu.</p>
                <?php if ($withdrawError): ?><div class="alert alert-error" style="margin-top:10px;"><?= e($withdrawError) ?></div><?php endif; ?>
                <form method="post" style="margin-top:12px; display:flex; flex-direction:column; flex:1;">
                    <?= csrf_input() ?>
                    <div class="form-group">
                        <label>Số tiền muốn rút</label>
                        <input type="number" name="amount" min="50000" step="1" placeholder="VD: 200000" value="<?= e($wForm['amount']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Tên ngân hàng</label>
                        <input type="text" name="bank_name" placeholder="VD: Vietcombank, MBBank..." value="<?= e($wForm['bank_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Số tài khoản</label>
                        <input type="text" name="bank_account" placeholder="VD: 0071000899999" value="<?= e($wForm['bank_account']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Tên chủ tài khoản</label>
                        <input type="text" name="account_holder" placeholder="VD: NGUYEN VAN A" value="<?= e($wForm['account_holder']) ?>" required>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Ghi chú (không bắt buộc)</label>
                        <textarea name="user_note" rows="3" style="height:100%; min-height:80px;" placeholder="VD: Vui lòng chuyển sau 18h, SĐT liên hệ 09xx..."><?= e($wForm['user_note']) ?></textarea>
                    </div>
                    <br>
<?php $wMethodView = user_2fa_method($me); if ($wMethodView !== 'none'): ?>
                    <div class="form-group">
                        <label>🔐 Mã xác thực 2 lớp <?= $wMethodView === 'email' ? '(gửi qua email)' : '(từ app Authenticator)' ?></label>
                        <input type="text" name="withdraw_2fa_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="6" placeholder="Nhập mã 6 số"<?= ($wMethodView === 'totp' || $withdrawOtpSent) ? ' required' : '' ?>>
                        <?php if ($wMethodView === 'email'): ?><small class="hint">Bấm "Gửi yêu cầu rút tiền" lần đầu để nhận mã qua email, rồi nhập mã và bấm lại để xác nhận.</small><?php endif; ?>
                    </div>
                    <?php if ($withdrawOtpSent): ?><input type="hidden" name="withdraw_otp_sent" value="1"><?php endif; ?>
<?php endif; ?>
                    <br> <br>
                    <button class="btn btn-accent btn-block" style="margin-top:auto;" type="submit" name="request_withdraw" value="1">Gửi yêu cầu rút tiền</button>
                </form>
            </div>
        </div>

        <div class="section-head"><h2>Lịch sử rút tiền</h2></div>
        <?php if (!$withdrawals): ?><div class="empty">Bạn chưa gửi yêu cầu rút tiền nào.</div><?php else: ?>
        <div style="overflow-x:auto; margin-bottom:32px;">
        <table>
            <tr><th>#</th><th>Số tiền</th><th>Ngân hàng</th><th>Ghi chú của bạn</th><th>Trạng thái</th><th>Ghi chú admin</th><th>Ngày gửi</th></tr>
            <?php
            $wTag = ['pending' => 'sold_hidden', 'approved' => 'active', 'rejected' => 'disabled'];
            $wLabel = ['pending' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Từ chối (đã hoàn ví)'];
            ?>
            <?php foreach ($withdrawals as $w): ?>
            <tr>
                <td class="mono">#<?= $w['id'] ?></td>
                <td class="mono"><?= money($w['amount']) ?></td>
                <td>
                    <div><?= e($w['bank_name']) ?></div>
                    <div class="mono hint"><?= e($w['bank_account']) ?> - <?= e($w['account_holder']) ?></div>
                </td>
                <td><?= !empty($w['user_note']) ? nl2br(e($w['user_note'])) : '<span class="hint">-</span>' ?></td>
                <td><span class="tag tag-<?= $wTag[$w['status']] ?? 'disabled' ?>"><?= $wLabel[$w['status']] ?? $w['status'] ?></span></td>
                <td><?= $w['admin_note'] ? nl2br(e($w['admin_note'])) : '<span class="hint">-</span>' ?></td>
                <td><?= time_ago($w['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>

        <div class="section-head"><h2>Lịch sử nạp tiền</h2></div>
        <?php if (!$topups): ?><div class="empty">Chưa có yêu cầu nạp tiền nào.</div><?php else: ?>
        <div style="overflow-x:auto; margin-bottom:32px;">
        <table>
            <tr><th>Mã</th><th>Số tiền</th><th>Trạng thái</th><th>Thời gian</th><th></th></tr>
            <?php
            $statusTag = ['pending' => 'sold_hidden', 'completed' => 'active', 'expired' => 'disabled', 'cancelled' => 'disabled'];
            $statusLabel = ['pending' => 'Đang chờ', 'completed' => 'Thành công', 'expired' => 'Hết hạn', 'cancelled' => 'Đã huỷ'];
            ?>
            <?php foreach ($topups as $t): ?>
            <tr>
                <td class="mono"><?= e($t['code']) ?></td>
                <td class="mono"><?= money($t['amount']) ?></td>
                <td><span class="tag tag-<?= $statusTag[$t['status']] ?? 'disabled' ?>"><?= $statusLabel[$t['status']] ?? $t['status'] ?></span></td>
                <td><?= time_ago($t['created_at']) ?></td>
                <td><?php if ($t['status'] === 'pending'): ?><a href="<?= BASE_URL ?>/topup_qr?id=<?= $t['id'] ?>" class="btn btn-outline btn-sm">Xem QR</a><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>

        <div class="section-head"><h2>Lịch sử giao dịch ví</h2></div>
        <?php if (!$tx): ?><div class="empty">Chưa có giao dịch nào.</div><?php else: ?>
        <table>
            <tr><th>Loại</th><th>Nội dung</th><th>Số tiền</th><th>Thời gian</th></tr>
            <?php foreach ($tx as $t): ?>
            <tr>
                <td>
                    <?php $isPlus = in_array($t['type'], ['credit','topup']); ?>
                    <span class="tag <?= $isPlus ? 'tag-active' : 'tag-sold_hidden' ?>"><?= $isPlus ? '+ Nhận' : '- Chi' ?></span>
                </td>
                <td><?= e($t['description']) ?></td>
                <td class="mono" style="color:<?= $isPlus ? 'var(--ok)' : 'var(--danger)' ?>"><?= $isPlus?'+':'-' ?><?= money($t['amount']) ?></td>
                <td><?= time_ago($t['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>