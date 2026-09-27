<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) { redirect('/index'); }

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error = null;
$user  = null;

if ($token !== '') {
    $st = $pdo->prepare('SELECT * FROM users WHERE password_reset_token = ? LIMIT 1');
    $st->execute([$token]);
    $user = $st->fetch();
    if (!$user) {
        $error = 'Link đặt lại mật khẩu không hợp lệ hoặc đã được sử dụng.';
    } elseif (empty($user['password_reset_expires']) || strtotime($user['password_reset_expires']) < time()) {
        $error = 'Link đặt lại mật khẩu đã hết hạn. Vui lòng yêu cầu lại.';
        $user  = null;
    }
} else {
    $error = 'Thiếu mã đặt lại mật khẩu.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_reset']) && $user) {
    csrf_verify();
    $pw  = $_POST['password'] ?? '';
    $pw2 = $_POST['password_confirm'] ?? '';
    if (strlen($pw) < 6) {
        $error = 'Mật khẩu phải có ít nhất 6 ký tự.';
    } elseif ($pw !== $pw2) {
        $error = 'Mật khẩu nhập lại không khớp.';
    } else {
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash = ?, password_reset_token = NULL, password_reset_expires = NULL, failed_login_attempts = 0, locked_until = NULL WHERE id = ?')
            ->execute([$hash, $user['id']]);
        flash_set('success', 'Đặt lại mật khẩu thành công! Vui lòng đăng nhập bằng mật khẩu mới.');
        redirect('/login');
    }
}

$page_title = 'Đặt lại mật khẩu';
require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-wrap">
    <div class="form-card">
        <h2>Đặt lại mật khẩu</h2>
        <?php if (!$user): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
            <p class="hint" style="margin-top:16px;"><a href="<?= BASE_URL ?>/forgot_password" style="color:var(--accent);">← Yêu cầu link mới</a></p>
        <?php else: ?>
            <p class="hint" style="margin-bottom:24px;">Nhập mật khẩu mới cho tài khoản <b><?= e($user['username']) ?></b>.</p>
            <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
            <form method="post">
                <?= csrf_input() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="form-group">
                    <label>Mật khẩu mới</label>
                    <input type="password" name="password" required autocomplete="new-password" placeholder="Ít nhất 6 ký tự">
                </div>
                <div class="form-group">
                    <label>Nhập lại mật khẩu mới</label>
                    <input type="password" name="password_confirm" required autocomplete="new-password" placeholder="Nhập lại mật khẩu">
                </div>
                <button class="btn btn-primary btn-block" type="submit" name="do_reset" value="1">Đặt lại mật khẩu</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
