<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

$me = is_logged_in() ? get_user($pdo, current_user_id()) : null;

$status = null; // ok | invalid | expired | already

// ===== Xác thực bằng token từ link email =====
$token = trim($_GET['token'] ?? '');
if ($token !== '') {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email_verify_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $u = $stmt->fetch();

    if (!$u) {
        // Token không tìm thấy: có thể đã dùng rồi
        if ($me && is_email_verified($me)) { $status = 'already'; }
        else { $status = 'invalid'; }
    } elseif ((int)$u['email_verified'] === 1) {
        $status = 'already';
    } elseif (!empty($u['email_verify_sent_at']) && (time() - strtotime($u['email_verify_sent_at'])) > 48 * 3600) {
        $status = 'expired';
    } else {
        $pdo->prepare('UPDATE users SET email_verified = 1, email_verify_token = NULL WHERE id = ?')->execute([$u['id']]);
        $status = 'ok';
    }
}

// ===== Gửi lại email xác thực =====
if (isset($_GET['resend'])) {
    if (!$me) { redirect('/login'); }
    if (is_email_verified($me)) {
        flash_set('success', 'Email của bạn đã được xác thực.');
        redirect('/index');
    }
    try {
        rate_limit('verify_resend', 3, 600);
        if (send_verification_email($pdo, $me)) {
            flash_set('success', 'Đã gửi email xác thực tới ' . e($me['email']) . '. Vui lòng kiểm tra hộp thư (kể cả mục Spam).');
        } else {
            flash_set('error', 'Không gửi được email lúc này. Vui lòng thử lại sau ít phút.');
        }
    } catch (Exception $ex) {
        flash_set('error', $ex->getMessage());
    }
    redirect('/verify_email');
}

$page_title = 'Xác thực email';
require_once __DIR__ . '/includes/header.php';
// $__me trong header.php đã lấy trạng thái mới nhất từ DB
$me = is_logged_in() ? get_user($pdo, current_user_id()) : $me;
?>
<div class="auth-wrap">
    <div class="form-card">
        <h2>Xác thực email</h2>

        <?php if ($status === 'ok'): ?>
            <div class="alert alert-success">🎉 Xác thực email thành công! Giờ bạn đã có thể đăng bán code.</div>
            <a href="<?= BASE_URL ?>/code_create" class="btn btn-primary btn-block" style="margin-top:14px;">Đăng bán code ngay</a>

        <?php elseif ($status === 'already'): ?>
            <div class="alert alert-success">Email này đã được xác thực trước đó.</div>
            <a href="<?= BASE_URL ?>/index" class="btn btn-outline btn-block" style="margin-top:14px;">Về trang chủ</a>

        <?php elseif ($status === 'expired'): ?>
            <div class="alert alert-error">Link xác thực đã hết hạn (quá 48 giờ). Vui lòng gửi lại email mới.</div>
            <?php if ($me && !is_email_verified($me)): ?>
                <a href="<?= BASE_URL ?>/verify_email?resend=1" class="btn btn-primary btn-block" style="margin-top:14px;">Gửi lại email xác thực</a>
            <?php endif; ?>

        <?php elseif ($status === 'invalid'): ?>
            <div class="alert alert-error">Link xác thực không hợp lệ hoặc đã được sử dụng.</div>
            <?php if ($me && !is_email_verified($me)): ?>
                <a href="<?= BASE_URL ?>/verify_email?resend=1" class="btn btn-primary btn-block" style="margin-top:14px;">Gửi lại email xác thực</a>
            <?php endif; ?>

        <?php else: ?>
            <?php if ($me && is_email_verified($me)): ?>
                <div class="alert alert-success">Email của bạn đã được xác thực. Bạn có thể đăng bán code.</div>
                <a href="<?= BASE_URL ?>/code_create" class="btn btn-primary btn-block" style="margin-top:14px;">Đăng bán code</a>
            <?php elseif ($me): ?>
                <p class="hint" style="margin-bottom:18px;">Tài khoản <b><?= e($me['email']) ?></b> chưa xác thực email. Xác thực để có thể <b>đăng bán code</b> — không bắt buộc nếu bạn chỉ mua code.</p>
                <a href="<?= BASE_URL ?>/verify_email?resend=1" class="btn btn-primary btn-block">Gửi email xác thực</a>
            <?php else: ?>
                <p class="hint">Vui lòng <a href="<?= BASE_URL ?>/login" style="color:var(--accent);">đăng nhập</a> để xác thực email.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
