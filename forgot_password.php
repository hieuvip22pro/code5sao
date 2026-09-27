<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) { redirect('/index'); }

$error = null;
$sent  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_forgot'])) {
    try {
        csrf_verify();
        $email = trim($_POST['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Vui lòng nhập địa chỉ email hợp lệ.');
        }
        rate_limit('forgot_pw', 3, 600);
        $st = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch();
        // Chỉ gửi cho tài khoản có mật khẩu nội bộ (không phải đăng nhập Google/GitHub/Apple)
        if ($u && !empty($u['password_hash'])) {
            @send_password_reset_email($pdo, $u);
        }
        // Luôn báo thành công để không lộ email nào có tồn tại
        $sent = true;
    } catch (Exception $ex) {
        $error = $ex->getMessage();
    }
}

$page_title = 'Quên mật khẩu';
require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-wrap">
    <div class="form-card">
        <h2>Quên mật khẩu</h2>
        <?php if ($sent): ?>
            <div class="alert alert-success">Nếu email này tồn tại trong hệ thống, chúng tôi đã gửi link đặt lại mật khẩu tới hộp thư của bạn. Vui lòng kiểm tra (kể cả mục Spam). Link có hiệu lực trong 1 giờ.</div>
            <p class="hint" style="margin-top:16px;"><a href="<?= BASE_URL ?>/login" style="color:var(--accent);">← Về trang đăng nhập</a></p>
        <?php else: ?>
            <p class="hint" style="margin-bottom:24px;">Nhập email đã đăng ký. Chúng tôi sẽ gửi link để bạn đặt lại mật khẩu.</p>
            <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
            <form method="post">
                <?= csrf_input() ?>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" required autofocus placeholder="you@example.com" value="<?= e($_POST['email'] ?? '') ?>">
                </div>
                <button class="btn btn-primary btn-block" type="submit" name="do_forgot" value="1">Gửi link đặt lại mật khẩu</button>
            </form>
            <p class="hint" style="margin-top:18px;">Nhớ mật khẩu rồi? <a href="<?= BASE_URL ?>/login" style="color:var(--accent);">Đăng nhập</a></p>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
