<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/oauth_functions.php';
require_once __DIR__ . '/includes/totp.php';
require_once __DIR__ . '/includes/security_functions.php';
// Gửi security headers cho mọi trang
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: https://api.dicebear.com https://api.qrserver.com; connect-src 'self';");
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

$oauthEnabled = oauth_enabled_map($pdo);

$error = null;
$show2fa = false;

// ===== Bước 2: xác nhận mã OTP (chỉ áp dụng cho admin đã bật 2FA) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_2fa'])) {
    csrf_verify();
    $code = trim($_POST['otp_code'] ?? '');
    $pendingId = $_SESSION['pending_2fa_user_id'] ?? null;

    if (!$pendingId) {
        $error = 'Phiên xác thực đã hết hạn, vui lòng đăng nhập lại.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$pendingId]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'Phiên xác thực đã hết hạn, vui lòng đăng nhập lại.';
        } else {
            $twofaType = $_SESSION['pending_2fa_type'] ?? 'totp';
            $ok = false;
            if ($twofaType === 'email') {
                $ok = !empty($user['email_otp_code']) && !empty($user['email_otp_expires'])
                    && strtotime($user['email_otp_expires']) > time()
                    && hash_equals((string)$user['email_otp_code'], $code);
                if ($ok) {
                    $pdo->prepare('UPDATE users SET email_otp_code=NULL, email_otp_expires=NULL WHERE id=?')->execute([$user['id']]);
                }
            } else {
                $ok = !empty($user['totp_enabled']) && totp_verify($user['totp_secret'], $code);
            }
            if (!$ok) {
                $error = 'Mã xác thực không đúng hoặc đã hết hạn.';
                $show2fa = true;
            } else {
                unset($_SESSION['pending_2fa_user_id']);
                unset($_SESSION['pending_2fa_type']);
                unset($_SESSION['_rl_login']);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role']    = $user['role'];
                redirect($user['role'] === 'admin' ? '/admin/index' : '/index');
            }
        }
    }
}

// Nếu đang chờ nhập OTP (do trước đó đã qua bước 1 thành công) -> luôn hiện form OTP khi tải lại trang
if (!empty($_SESSION['pending_2fa_user_id'])) {
    $show2fa = true;
}

// Cho phép huỷ và đăng nhập lại từ đầu
if (isset($_GET['cancel_2fa'])) {
    unset($_SESSION['pending_2fa_user_id']);
    unset($_SESSION['pending_2fa_type']);
    redirect('/login');
}

// Gửi lại mã OTP qua email khi dùng Email MFA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_email_otp'])) {
    csrf_verify();
    $pid = $_SESSION['pending_2fa_user_id'] ?? null;
    if ($pid && ($_SESSION['pending_2fa_type'] ?? '') === 'email') {
        $ru = $pdo->prepare('SELECT * FROM users WHERE id=?'); $ru->execute([$pid]); $ru = $ru->fetch();
        if ($ru) {
            $rcode = security_generate_code(6);
            $pdo->prepare('UPDATE users SET email_otp_code=?, email_otp_expires=DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id=?')->execute([$rcode, $pid]);
            send_otp_email($ru['email'], $rcode, 'Mã đăng nhập 2 lớp');
            flash_set('success', 'Đã gửi lại mã tới email của bạn.');
        }
    }
    $show2fa = true;
}

// ===== Bước 1: đăng nhập bằng username/mật khẩu =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_login'])) {
    csrf_verify();
    $login    = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    // Rate limiting: tối đa 5 lần thử trong 5 phút theo session
    try {
        rate_limit('login', 5, 300);
    } catch (Exception $ex) {
        $error = $ex->getMessage();
        goto render;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$login, $login]);
    $user = $stmt->fetch();

    // Tài khoản đang bị tạm khoá do sai mật khẩu quá nhiều lần trước đó
    if ($user && !empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        $remain = (int)ceil((strtotime($user['locked_until']) - time()) / 60);
        $error = 'Tài khoản tạm khoá do đăng nhập sai nhiều lần. Vui lòng thử lại sau ' . $remain . ' phút.';
    }
    // empty($user['password_hash']): tài khoản tạo qua Google/GitHub/Apple không có mật khẩu nội bộ
    elseif (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
        if ($user) {
            $attempts = (int)$user['failed_login_attempts'] + 1;
            if ($attempts >= 5) {
                $pdo->prepare('UPDATE users SET failed_login_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?')
                    ->execute([$attempts, $user['id']]);
                $error = 'Sai mật khẩu quá 5 lần. Tài khoản bị tạm khoá 15 phút để bảo vệ.';
            } else {
                $pdo->prepare('UPDATE users SET failed_login_attempts = ? WHERE id = ?')->execute([$attempts, $user['id']]);
                // Thông báo chung — không tiết lộ username có tồn tại hay không
                $error = 'Tên đăng nhập/email hoặc mật khẩu không đúng.';
            }
        } else {
            $error = 'Tên đăng nhập/email hoặc mật khẩu không đúng.';
        }
    } elseif ($user['status'] === 'banned') {
        $error = 'Tài khoản của bạn đã bị khoá. Vui lòng liên hệ admin.';
    } else {
        // Đăng nhập đúng mật khẩu -> reset bộ đếm sai
        $pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$user['id']]);
        unset($_SESSION['_rl_login']);

        if (!ip_allowed($pdo, $user['id'], client_ip())) {
            $error = 'Địa chỉ IP của bạn (' . e(client_ip()) . ') không nằm trong danh sách IP được phép truy cập tài khoản này.';
        } else {
            $twofaMethod = $user['two_factor_method'] ?? (!empty($user['totp_enabled']) ? 'totp' : 'none');
            if ($twofaMethod === 'totp') {
                $_SESSION['pending_2fa_user_id'] = $user['id'];
                $_SESSION['pending_2fa_type'] = 'totp';
                $show2fa = true;
            } elseif ($twofaMethod === 'email') {
                $otp = security_generate_code(6);
                $pdo->prepare('UPDATE users SET email_otp_code=?, email_otp_expires=DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id=?')->execute([$otp, $user['id']]);
                send_otp_email($user['email'], $otp, 'Mã đăng nhập 2 lớp');
                $_SESSION['pending_2fa_user_id'] = $user['id'];
                $_SESSION['pending_2fa_type'] = 'email';
                $show2fa = true;
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role']    = $user['role'];
                redirect($user['role'] === 'admin' ? '/admin/index' : '/index');
            }
        }
    }
}
render:
$page_title = 'Đăng nhập';
require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-wrap">
    <div class="form-card">

        <?php if ($show2fa): ?>
            <h2>Xác thực 2 lớp</h2>
            <?php $twofaType = $_SESSION['pending_2fa_type'] ?? 'totp'; ?>
            <p class="hint" style="margin-bottom:24px;"><?php if ($twofaType === 'email'): ?>Chúng tôi đã gửi mã 6 số tới email của bạn. Nhập mã để hoàn tất đăng nhập.<?php else: ?>Mở app Authenticator trên điện thoại, nhập mã 6 số hiện tại.<?php endif; ?></p>
            <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
            <form method="post">
                <?= csrf_input() ?>
                <div class="form-group">
                    <label>Mã xác thực (OTP)</label>
                    <input type="text" name="otp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus placeholder="000000">
                </div>
                <button class="btn btn-primary btn-block" type="submit" name="verify_2fa" value="1">Xác nhận</button>
            </form>
            <?php if (($_SESSION['pending_2fa_type'] ?? 'totp') === 'email'): ?>
            <form method="post" style="margin-top:12px;">
                <?= csrf_input() ?>
                <button class="btn btn-outline btn-block" type="submit" name="resend_email_otp" value="1">Gửi lại mã qua email</button>
            </form>
            <?php endif; ?>
            <p class="hint" style="margin-top:18px;"><a href="?cancel_2fa=1" style="color:var(--accent);">← Huỷ, đăng nhập lại từ đầu</a></p>

        <?php else: ?>
            <h2>Đăng nhập</h2>
            <p class="hint" style="margin-bottom:24px;">Chào mừng quay lại CodeMarket.</p>

            <?php if ($oauthEnabled['google'] || $oauthEnabled['github'] || $oauthEnabled['apple']): ?>
            <div class="oauth-buttons" style="display:flex; flex-direction:column; gap:10px; margin-bottom:20px;">
                <?php if ($oauthEnabled['google']): ?>
                <a href="<?= BASE_URL ?>/oauth_start?provider=google" class="btn btn-outline btn-block" style="display:flex; align-items:center; justify-content:center; gap:10px;">
                    <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true"><path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84c-.21 1.13-.84 2.09-1.8 2.73v2.27h2.91c1.7-1.57 2.69-3.88 2.69-6.64z"/><path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.91-2.27c-.8.54-1.83.86-3.05.86-2.35 0-4.34-1.59-5.05-3.72H.96v2.34C2.44 15.98 5.48 18 9 18z"/><path fill="#FBBC05" d="M3.95 10.69A5.4 5.4 0 013.68 9c0-.59.1-1.16.27-1.69V4.97H.96A9 9 0 000 9c0 1.45.35 2.83.96 4.03l2.99-2.34z"/><path fill="#EA4335" d="M9 3.58c1.32 0 2.51.45 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0 5.48 0 2.44 2.02.96 4.97l2.99 2.34C4.66 5.17 6.65 3.58 9 3.58z"/></svg>
                    Đăng nhập với Google
                </a>
                <?php endif; ?>
                <?php if ($oauthEnabled['github']): ?>
                <a href="<?= BASE_URL ?>/oauth_start?provider=github" class="btn btn-outline btn-block" style="display:flex; align-items:center; justify-content:center; gap:10px;">
                    <svg width="18" height="18" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>
                    Đăng nhập với GitHub
                </a>
                <?php endif; ?>
                <?php if ($oauthEnabled['apple']): ?>
                <a href="<?= BASE_URL ?>/oauth_start?provider=apple" class="btn btn-block" style="display:flex; align-items:center; justify-content:center; gap:10px; background:#000; color:#fff; border:1px solid #000;">
                    <svg width="16" height="18" viewBox="0 0 14 17" fill="currentColor" aria-hidden="true"><path d="M11.62 9.02c-.02-1.9 1.55-2.81 1.62-2.86-.88-1.29-2.26-1.47-2.75-1.49-1.17-.12-2.29.69-2.88.69-.6 0-1.5-.67-2.47-.65-1.27.02-2.44.74-3.09 1.87-1.32 2.28-.34 5.66.95 7.51.63.9 1.38 1.92 2.36 1.88.95-.04 1.31-.61 2.46-.61 1.14 0 1.47.61 2.47.59 1.02-.02 1.67-.92 2.29-1.83.72-1.05 1.02-2.06 1.03-2.11-.02-.01-1.98-.76-2-3zM9.7 3.3c.52-.63.87-1.5.77-2.37-.75.03-1.65.5-2.19 1.12-.48.55-.9 1.44-.79 2.28.83.06 1.68-.42 2.21-1.03z"/></svg>
                    Đăng nhập với Apple
                </a>
                <?php endif; ?>
            </div>
            <div style="display:flex; align-items:center; gap:12px; margin:18px 0; color:var(--muted); font-size:13px;">
                <div style="flex:1; height:1px; background:var(--border);"></div>HOẶC<div style="flex:1; height:1px; background:var(--border);"></div>
            </div>
            <?php endif; ?>

<style>
/* Tùy chỉnh mở rộng form đăng nhập */
.auth-wrap .form-card {
    max-width: 520px !important; /* Tăng độ rộng của form (bạn có thể tăng giảm số này) */
    width: 100%;
    padding: 50px !important; /* Tăng khoảng cách bên trong để form trông cao và thoáng hơn */
}

/* Phóng to nhẹ các nút OAuth để cân đối với form mới */
.auth-wrap .form-card .oauth-buttons .btn {
    padding: 14px 20px;
    font-size: 15px;
}

/* Phóng to ô input và nút đăng nhập */
.auth-wrap .form-card input {
    padding: 14px 16px;
    font-size: 15px;
}

.auth-wrap .form-card button[type="submit"] {
    padding: 16px;
    font-size: 16px;
    margin-top: 10px;
}
</style>

            <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
            <form method="post">
                <?= csrf_input() ?>
                <div class="form-group">
                    <label>Tên đăng nhập hoặc email</label>
                    <input type="text" name="login" required autofocus autocomplete="username" placeholder="Nhập tên tài khoản hoặc email">
                </div>
                <div class="form-group">
                    <label>Mật khẩu</label>
                    <input type="password" name="password" required autocomplete="current-password" placeholder="Nhập mật khẩu">
                </div>
                <p class="hint" style="margin:-6px 0 16px; text-align:right;"><a href="<?= BASE_URL ?>/forgot_password" style="color:var(--accent);">Quên mật khẩu?</a></p>
                <button class="btn btn-primary btn-block" type="submit" name="do_login" value="1">Đăng nhập</button>
            </form>
            <p class="hint" style="margin-top:18px;">Chưa có tài khoản? <a href="<?= BASE_URL ?>/register" style="color:var(--accent);">Đăng ký ngay</a></p>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>