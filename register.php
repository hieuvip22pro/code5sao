<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/oauth_functions.php';
require_once __DIR__ . '/includes/business_functions.php';

$oauthEnabled = oauth_enabled_map($pdo);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Xác thực CSRF
    csrf_verify();

    // Rate limiting: tối đa 3 lần đăng ký trong 10 phút (chống spam account)
    try {
        rate_limit('register', 3, 600);
    } catch (Exception $ex) {
        $errors[] = $ex->getMessage();
    }

    if (!$errors) {
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';

        if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            $errors[] = 'Tên đăng nhập chỉ gồm chữ, số, gạch dưới, từ 3-30 ký tự.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email không hợp lệ.';
        }
        // Yêu cầu mật khẩu tối thiểu 8 ký tự, có chữ và có số
        // MỚI: Chỉ yêu cầu tối thiểu 6 ký tự, không bắt buộc chữ/số
if (strlen($password) < 6) {
    $errors[] = 'Mật khẩu phải có ít nhất 6 ký tự.';
}
        if ($password !== $password2) {
            $errors[] = 'Mật khẩu nhập lại không khớp.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $errors[] = 'Tên đăng nhập hoặc email đã được sử dụng.';
        }
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (?,?,?)');
        $stmt->execute([$username, $email, $hash]);
        $uid = $pdo->lastInsertId();

        // Affiliate: gan nguoi gioi thieu (neu co ma ref) + tao ma gioi thieu cho user moi
        $refCode = trim($_POST['ref'] ?? ($_COOKIE['cm_ref'] ?? ''));
        if ($refCode !== '') {
            try {
                $refUser = get_user_by_affiliate_code($pdo, $refCode);
                if ($refUser && (int)$refUser['id'] !== (int)$uid) {
                    $pdo->prepare('UPDATE users SET referred_by=? WHERE id=?')->execute([$refUser['id'], $uid]);
                }
            } catch (Exception $e) {}
        }
        try { ensure_affiliate_code($pdo, $uid); } catch (Exception $e) {}

        // Tái tạo Session ID sau khi đăng ký để chống Session Fixation
        session_regenerate_id(true);

        $_SESSION['user_id'] = $uid;
        $_SESSION['role']    = 'user';

        // Gửi email xác thực (không bắt buộc để mua, chỉ cần để đăng bán code)
        $newUser = get_user($pdo, $uid);
        if ($newUser) { @send_verification_email($pdo, $newUser); }

        flash_set('success', 'Chào mừng bạn đến với CodeMarket! Chúng tôi đã gửi email xác thực — hãy xác thực email để có thể đăng bán code.');
        redirect('/index');
    }
}

$page_title = 'Đăng ký';
require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-wrap">
    <div class="form-card">
        <h2>Tạo tài khoản</h2>
        <p class="hint" style="margin-bottom:24px;">Đăng ký để bắt đầu mua bán code và cho thuê web trên CodeMarket.</p>

        <?php if ($oauthEnabled['google'] || $oauthEnabled['github'] || $oauthEnabled['apple']): ?>
        <div class="oauth-buttons" style="display:flex; flex-direction:column; gap:10px; margin-bottom:20px;">
            <?php if ($oauthEnabled['google']): ?>
            <a href="<?= BASE_URL ?>/oauth_start?provider=google" class="btn btn-outline btn-block" style="display:flex; align-items:center; justify-content:center; gap:10px;">
                <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true"><path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84c-.21 1.13-.84 2.09-1.8 2.73v2.27h2.91c1.7-1.57 2.69-3.88 2.69-6.64z"/><path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.91-2.27c-.8.54-1.83.86-3.05.86-2.35 0-4.34-1.59-5.05-3.72H.96v2.34C2.44 15.98 5.48 18 9 18z"/><path fill="#FBBC05" d="M3.95 10.69A5.4 5.4 0 013.68 9c0-.59.1-1.16.27-1.69V4.97H.96A9 9 0 000 9c0 1.45.35 2.83.96 4.03l2.99-2.34z"/><path fill="#EA4335" d="M9 3.58c1.32 0 2.51.45 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0 5.48 0 2.44 2.02.96 4.97l2.99 2.34C4.66 5.17 6.65 3.58 9 3.58z"/></svg>
                Đăng ký với Google
            </a>
            <?php endif; ?>
            <?php if ($oauthEnabled['github']): ?>
            <a href="<?= BASE_URL ?>/oauth_start?provider=github" class="btn btn-outline btn-block" style="display:flex; align-items:center; justify-content:center; gap:10px;">
                <svg width="18" height="18" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>
                Đăng ký với GitHub
            </a>
            <?php endif; ?>
            <?php if ($oauthEnabled['apple']): ?>
            <a href="<?= BASE_URL ?>/oauth_start?provider=apple" class="btn btn-block" style="display:flex; align-items:center; justify-content:center; gap:10px; background:#000; color:#fff; border:1px solid #000;">
                <svg width="16" height="18" viewBox="0 0 14 17" fill="currentColor" aria-hidden="true"><path d="M11.62 9.02c-.02-1.9 1.55-2.81 1.62-2.86-.88-1.29-2.26-1.47-2.75-1.49-1.17-.12-2.29.69-2.88.69-.6 0-1.5-.67-2.47-.65-1.27.02-2.44.74-3.09 1.87-1.32 2.28-.34 5.66.95 7.51.63.9 1.38 1.92 2.36 1.88.95-.04 1.31-.61 2.46-.61 1.14 0 1.47.61 2.47.59 1.02-.02 1.67-.92 2.29-1.83.72-1.05 1.02-2.06 1.03-2.11-.02-.01-1.98-.76-2-3zM9.7 3.3c.52-.63.87-1.5.77-2.37-.75.03-1.65.5-2.19 1.12-.48.55-.9 1.44-.79 2.28.83.06 1.68-.42 2.21-1.03z"/></svg>
                Đăng ký với Apple
            </a>
            <?php endif; ?>
        </div>
        <div style="display:flex; align-items:center; gap:12px; margin:18px 0; color:var(--muted); font-size:13px;">
            <div style="flex:1; height:1px; background:var(--border);"></div>HOẶC<div style="flex:1; height:1px; background:var(--border);"></div>
        </div>
        <?php endif; ?>

        <?php foreach ($errors as $err): ?>
            <div class="alert alert-error"><?= e($err) ?></div>
        <?php endforeach; ?>

        <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="ref" value="<?= e($_GET['ref'] ?? ($_COOKIE['cm_ref'] ?? '')) ?>">
            <div class="form-group">
                <label>Tên đăng nhập</label>
                <input type="text" name="username" value="<?= e($_POST['username'] ?? '') ?>" required autocomplete="username" placeholder="Nhập tên tài khoản">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required autocomplete="email" placeholder="Nhập địa chỉ email">
            </div>
            <div class="row-2">
                <div class="form-group">
                    <label>Mật khẩu</label>
                    <input type="password" name="password" required autocomplete="new-password" minlength="6" placeholder="Tối thiểu 6 ký tự">
                </div>
                <div class="form-group">
                    <label>Nhập lại mật khẩu</label>
                    <input type="password" name="password2" required autocomplete="new-password" placeholder="Xác nhận mật khẩu">
                </div>
            </div>
            <button class="btn btn-primary btn-block" type="submit">Đăng ký</button>
        </form>
        <p class="hint" style="margin-top:18px;">Đã có tài khoản? <a href="<?= BASE_URL ?>/login" style="color:var(--accent);">Đăng nhập</a></p>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>