<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/totp.php';
require_once __DIR__ . '/includes/security_functions.php';
require_login();

$uid = current_user_id();
$me  = get_user($pdo, $uid);
$errors = [];
$verified = is_email_verified($me);

function _sec_method($me) {
    $m = $me['two_factor_method'] ?? '';
    if ($m === '' || $m === null) $m = !empty($me['totp_enabled']) ? 'totp' : 'none';
    return $m;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $method = _sec_method($me);

    if (isset($_POST['resend_verify'])) {
        if (!$verified) { send_verification_email($pdo, $me); flash_set('success', 'Đã gửi lại email xác thực. Kiểm tra hộp thư của bạn.'); }
        redirect('/security');
    }

    // ===== Google Authenticator (TOTP) =====
    if (isset($_POST['gen_totp'])) {
        if (!$verified) $errors[] = 'Bạn cần xác thực email trước.';
        elseif ($method !== 'none') $errors[] = 'Bạn chỉ được bật 1 phương thức bảo mật. Hãy tắt phương thức hiện tại trước.';
        else { $_SESSION['pending_totp_secret'] = totp_generate_secret(); redirect('/security'); }
    }
    if (isset($_POST['enable_totp'])) {
        $secret = $_SESSION['pending_totp_secret'] ?? null;
        $code = trim($_POST['otp_code'] ?? '');
        if (!$verified) $errors[] = 'Bạn cần xác thực email trước.';
        elseif ($method !== 'none') $errors[] = 'Bạn chỉ được bật 1 phương thức bảo mật.';
        elseif (!$secret) $errors[] = 'Phiên thiết lập đã hết hạn, vui lòng tạo mã QR lại.';
        elseif (!totp_verify($secret, $code)) $errors[] = 'Mã xác thực không đúng. Kiểm tra lại giờ trên điện thoại.';
        else {
            $pdo->prepare("UPDATE users SET totp_secret=?, totp_enabled=1, two_factor_method='totp' WHERE id=?")->execute([$secret, $uid]);
            unset($_SESSION['pending_totp_secret']);
            flash_set('success', 'Đã bật Google Authenticator (TOTP).');
            redirect('/security');
        }
    }
    if (isset($_POST['disable_totp'])) {
        $pw = $_POST['confirm_password'] ?? '';
        if (!empty($me['password_hash']) && !password_verify($pw, $me['password_hash'])) $errors[] = 'Sai mật khẩu, không thể tắt.';
        else {
            $pdo->prepare("UPDATE users SET totp_secret=NULL, totp_enabled=0, two_factor_method='none' WHERE id=?")->execute([$uid]);
            flash_set('success', 'Đã tắt Google Authenticator.');
            redirect('/security');
        }
    }

    // ===== Email MFA =====
    if (isset($_POST['start_email_mfa'])) {
        if (!$verified) $errors[] = 'Bạn cần xác thực email trước.';
        elseif ($method !== 'none') $errors[] = 'Bạn chỉ được bật 1 phương thức bảo mật.';
        else {
            $code = security_generate_code(6);
            $pdo->prepare('UPDATE users SET email_otp_code=?, email_otp_expires=DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id=?')->execute([$code, $uid]);
            send_otp_email($me['email'], $code, 'Bật bảo mật Email MFA');
            $_SESSION['emailmfa_setup'] = 1;
            flash_set('success', 'Đã gửi mã tới email của bạn.');
            redirect('/security');
        }
    }
    if (isset($_POST['enable_email_mfa'])) {
        $code = trim($_POST['otp_code'] ?? '');
        if (!$verified) $errors[] = 'Bạn cần xác thực email trước.';
        elseif ($method !== 'none') $errors[] = 'Bạn chỉ được bật 1 phương thức bảo mật.';
        elseif (empty($me['email_otp_code']) || empty($me['email_otp_expires']) || strtotime($me['email_otp_expires']) < time()) $errors[] = 'Mã đã hết hạn, hãy gửi lại.';
        elseif (!hash_equals((string)$me['email_otp_code'], $code)) $errors[] = 'Mã xác thực không đúng.';
        else {
            $pdo->prepare("UPDATE users SET two_factor_method='email', email_otp_code=NULL, email_otp_expires=NULL WHERE id=?")->execute([$uid]);
            unset($_SESSION['emailmfa_setup']);
            flash_set('success', 'Đã bật bảo mật Email MFA.');
            redirect('/security');
        }
    }
    if (isset($_POST['disable_email_mfa'])) {
        $pw = $_POST['confirm_password'] ?? '';
        if (!empty($me['password_hash']) && !password_verify($pw, $me['password_hash'])) $errors[] = 'Sai mật khẩu, không thể tắt.';
        else {
            $pdo->prepare("UPDATE users SET two_factor_method='none' WHERE id=?")->execute([$uid]);
            flash_set('success', 'Đã tắt Email MFA.');
            redirect('/security');
        }
    }

    // ===== IP whitelist =====
    if (isset($_POST['add_ip'])) {
        $rule = trim($_POST['ip_rule'] ?? '');
        $note = trim($_POST['ip_note'] ?? '');
        if (!$verified) $errors[] = 'Bạn cần xác thực email trước.';
        elseif (!ip_rule_valid($rule)) $errors[] = 'Định dạng IP/dải IP không hợp lệ.';
        else {
            $pdo->prepare('INSERT INTO user_allowed_ips (user_id, ip_rule, note) VALUES (?,?,?)')->execute([$uid, $rule, ($note !== '' ? mb_substr($note, 0, 120) : null)]);
            flash_set('success', 'Đã thêm IP được phép truy cập.');
            redirect('/security');
        }
    }
    if (isset($_POST['delete_ip'])) {
        $pdo->prepare('DELETE FROM user_allowed_ips WHERE id=? AND user_id=?')->execute([(int)$_POST['delete_ip'], $uid]);
        flash_set('success', 'Đã xoá IP.');
        redirect('/security');
    }

    // ===== SSH keys =====
    if (isset($_POST['add_ssh'])) {
        $name = trim($_POST['ssh_name'] ?? '');
        $key  = trim($_POST['ssh_key'] ?? '');
        if (!$verified) $errors[] = 'Bạn cần xác thực email trước.';
        elseif ($name === '') $errors[] = 'Vui lòng nhập tên cho SSH key.';
        elseif (!ssh_key_valid($key)) $errors[] = 'Public key không đúng định dạng OpenSSH (ssh-rsa / ssh-ed25519 ...).';
        else {
            $fp = ssh_key_fingerprint($key);
            $pdo->prepare('INSERT INTO user_ssh_keys (user_id, name, public_key, fingerprint) VALUES (?,?,?,?)')->execute([$uid, mb_substr($name, 0, 100), $key, $fp]);
            flash_set('success', 'Đã thêm SSH key.');
            redirect('/security');
        }
    }
    if (isset($_POST['delete_ssh'])) {
        $pdo->prepare('DELETE FROM user_ssh_keys WHERE id=? AND user_id=?')->execute([(int)$_POST['delete_ssh'], $uid]);
        flash_set('success', 'Đã xoá SSH key.');
        redirect('/security');
    }
}

$me = get_user($pdo, $uid);
$method = _sec_method($me);

$ips = [];
$sshKeys = [];
try { $s = $pdo->prepare('SELECT * FROM user_allowed_ips WHERE user_id=? ORDER BY id DESC'); $s->execute([$uid]); $ips = $s->fetchAll(); } catch (Exception $e) {}
try { $s = $pdo->prepare('SELECT * FROM user_ssh_keys WHERE user_id=? ORDER BY id DESC'); $s->execute([$uid]); $sshKeys = $s->fetchAll(); } catch (Exception $e) {}

$pendingSecret = $_SESSION['pending_totp_secret'] ?? null;
$qrUrl = null;
if ($pendingSecret) {
    $otpauth = totp_provisioning_uri($pendingSecret, $me['username'], 'Code5sao');
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($otpauth);
}
$emailSetup = !empty($_SESSION['emailmfa_setup']);

$page_title = 'Bảo mật';
require_once __DIR__ . '/includes/header.php';
?>
<div class="section">
  <div class="container">
    <div class="dash-layout">
      <div class="dash-nav">
                <a href="?tab=overview" class="<?= $tab==='overview'?'active':'' ?>">📊 Tổng quan</a>
                <a href="?tab=code" class="<?= $tab==='code'?'active':'' ?>">📦 Code của tôi</a>
                <a href="?tab=reviews" class="<?= $tab==='reviews'?'active':'' ?>">💬 Bình luận & Đánh giá</a>
                <a href="?tab=hosting" class="<?= $tab==='hosting'?'active':'' ?>">🖥 Hosting của tôi</a>
                <a href="?tab=purchases" class="<?= $tab==='purchases'?'active':'' ?>">🛒 Đơn đã mua</a>
                <a href="?tab=sales" class="<?= $tab==='sales'?'active':'' ?>">💵 Đơn đã bán</a>
                <a href="?tab=settings" class="<?= $tab==='settings'?'active':'' ?>">⚙️ Cài đặt hồ sơ</a>
                <a href="<?= BASE_URL ?>/security">🔐 Bảo mật</a>
                <a href="<?= BASE_URL ?>/wallet">👛 Ví &amp; giao dịch</a>
      </div>

      <div>
        <div class="section-head"><h2>🔐 Bảo mật tài khoản (áp dụng cho rút tiền + đăng nhập)</h2></div>
        <style>
          .sec-grid { display:grid; grid-template-columns: minmax(0,1.5fr) minmax(0,1fr); gap:18px; align-items:start; }
          .sec-col { display:flex; flex-direction:column; gap:18px; }
          .sec-methods { display:grid; grid-template-columns: repeat(auto-fit, minmax(185px,1fr)); gap:14px; }
          @media (max-width: 992px){ .sec-grid { grid-template-columns:1fr; } }
        </style>
        <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

        <?php if (!$verified): ?>
        <div class="alert alert-error" style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
          <span>⚠️ Bạn cần <b>xác thực email</b> trước khi bật các tính năng bảo mật.</span>
          <form method="post" style="margin:0;"><?= csrf_input() ?><button class="btn btn-outline btn-sm" name="resend_verify" value="1">Gửi lại email xác thực</button></form>
        </div>
        <?php endif; ?>

        <div class="sec-grid">
        <div class="form-card" style="margin:0;">
          <h3>Bảo mật 2 lớp (2FA)</h3>
          <?php if ($method === 'none'): ?>
            <div class="alert alert-info">Bảo mật 2 lớp chưa được bật! Bạn chỉ được bật <b>một</b> trong hai phương thức bên dưới.</div>
            <div class="sec-methods">
              <div class="form-group" style="border:1px solid var(--border);border-radius:10px;padding:16px;">
                <h4 style="margin:0 0 8px;">Google Authenticator</h4>
                <?php if ($pendingSecret): ?>
                  <p class="hint">Quét mã QR bằng app Authenticator rồi nhập mã 6 số.</p>
                  <img src="<?= e($qrUrl) ?>" alt="QR" style="display:block;margin:10px auto;border-radius:8px;max-width:100%;height:auto;">
                  <p class="hint mono" style="text-align:center;word-break:break-all;">Mã thủ công: <?= e($pendingSecret) ?></p>
                  <form method="post"><?= csrf_input() ?>
                    <div class="form-group"><input type="text" name="otp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required></div>
                    <button class="btn btn-primary btn-block" name="enable_totp" value="1" <?= !$verified?'disabled':'' ?>>Xác nhận &amp; Bật</button>
                  </form>
                <?php else: ?>
                  <p class="hint">Dùng app Google Authenticator / Authy / Microsoft Authenticator sinh mã 6 số theo thời gian.</p>
                  <form method="post"><?= csrf_input() ?><button class="btn btn-primary btn-block" name="gen_totp" value="1" <?= !$verified?'disabled':'' ?>>Bật</button></form>
                <?php endif; ?>
              </div>
              <div class="form-group" style="border:1px solid var(--border);border-radius:10px;padding:16px;">
                <h4 style="margin:0 0 8px;">Email MFA</h4>
                <?php if ($emailSetup): ?>
                  <p class="hint">Nhập mã 6 số vừa gửi tới email <b><?= e($me['email']) ?></b>.</p>
                  <form method="post"><?= csrf_input() ?>
                    <div class="form-group"><input type="text" name="otp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required></div>
                    <button class="btn btn-primary btn-block" name="enable_email_mfa" value="1">Xác nhận &amp; Bật</button>
                  </form>
                  <form method="post" style="margin-top:8px;"><?= csrf_input() ?><button class="btn btn-outline btn-block btn-sm" name="start_email_mfa" value="1">Gửi lại mã</button></form>
                <?php else: ?>
                  <p class="hint">Mỗi lần đăng nhập sẽ nhận mã 6 số qua email để xác thực.</p>
                  <form method="post"><?= csrf_input() ?><button class="btn btn-primary btn-block" name="start_email_mfa" value="1" <?= !$verified?'disabled':'' ?>>Bật</button></form>
                <?php endif; ?>
              </div>
            </div>
          <?php elseif ($method === 'totp'): ?>
            <div class="alert alert-success">✅ Đang bật <b>Google Authenticator (TOTP)</b>. Mỗi lần đăng nhập cần mã 6 số từ app.</div>
            <form method="post" style="max-width:360px;"><?= csrf_input() ?>
              <div class="form-group"><label>Mật khẩu hiện tại (để tắt)</label><input type="password" name="confirm_password" <?= !empty($me['password_hash'])?'required':'' ?>></div>
              <button class="btn btn-danger" name="disable_totp" value="1">Tắt 2FA</button>
            </form>
          <?php else: ?>
            <div class="alert alert-success">✅ Đang bật <b>Email MFA</b>. Mỗi lần đăng nhập sẽ nhận mã 6 số qua email <b><?= e($me['email']) ?></b>.</div>
            <form method="post" style="max-width:360px;"><?= csrf_input() ?>
              <div class="form-group"><label>Mật khẩu hiện tại (để tắt)</label><input type="password" name="confirm_password" <?= !empty($me['password_hash'])?'required':'' ?>></div>
              <button class="btn btn-danger" name="disable_email_mfa" value="1">Tắt Email MFA</button>
            </form>
          <?php endif; ?>
        </div>

        <div class="sec-col">
        <div class="form-card" style="margin:0;">
          <h3>IP được phép truy cập</h3>
          <p class="hint">IP hiện tại của bạn: <b class="mono"><?= e(client_ip()) ?></b></p>
          <?php if (!$ips): ?>
            <div class="alert alert-info">Chưa cấu hình rule nào — hiện đang cho phép đăng nhập từ tất cả IP. Chỉ dùng nếu mạng của bạn có IP tĩnh, nếu không có thể tự khoá chính mình.</div>
          <?php else: ?>
            <table>
              <tr><th>Rule</th><th>Ghi chú</th><th></th></tr>
              <?php foreach ($ips as $ip): ?>
              <tr>
                <td class="mono"><?= e($ip['ip_rule']) ?></td>
                <td><?= e($ip['note'] ?? '') ?></td>
                <td><form method="post" onsubmit="return confirm('Xoá rule này?');"><?= csrf_input() ?><button class="btn btn-outline btn-sm" name="delete_ip" value="<?= $ip['id'] ?>">Xoá</button></form></td>
              </tr>
              <?php endforeach; ?>
            </table>
          <?php endif; ?>
          <form method="post" style="margin-top:12px;"><?= csrf_input() ?>
            <div class="row-2">
              <div class="form-group"><label>IP hoặc dải IP</label><input type="text" name="ip_rule" placeholder="VD: 115.76.48.146 hoặc 120.123.0.0/16 hoặc all" <?= !$verified?'disabled':'' ?>></div>
              <div class="form-group"><label>Ghi chú (tuỳ chọn)</label><input type="text" name="ip_note" placeholder="VD: Mạng công ty" <?= !$verified?'disabled':'' ?>></div>
            </div>
            <button class="btn btn-primary" name="add_ip" value="1" <?= !$verified?'disabled':'' ?>>Thêm IP được phép truy cập</button>
          </form>
          <details style="margin-top:12px;"><summary class="hint">Định dạng hỗ trợ</summary>
            <ul class="hint" style="line-height:1.8;">
              <li><b>all</b> — khớp toàn bộ IP</li>
              <li><b>xxx.xxx.xxx.xxx</b> — IP đơn</li>
              <li><b>xxx.xxx.xxx.xxx/M</b> — IP kèm mask dạng CIDR</li>
              <li><b>xxx.xxx.xxx.xxx/mmm.mmm.mmm.mmm</b> — IP kèm mask dạng dotted</li>
            </ul>
          </details>
        </div>

        <div class="form-card" style="margin:0;">
          <h3>SSH Keys</h3>
          <?php if (!$sshKeys): ?>
            <div class="alert alert-info">Không có SSH key nào.</div>
          <?php else: ?>
            <table>
              <tr><th>Tên</th><th>Fingerprint</th><th></th></tr>
              <?php foreach ($sshKeys as $k): ?>
              <tr>
                <td><?= e($k['name']) ?></td>
                <td class="mono" style="word-break:break-all;font-size:12px;"><?= e($k['fingerprint'] ?? '') ?></td>
                <td><form method="post" onsubmit="return confirm('Xoá SSH key này?');"><?= csrf_input() ?><button class="btn btn-outline btn-sm" name="delete_ssh" value="<?= $k['id'] ?>">Xoá</button></form></td>
              </tr>
              <?php endforeach; ?>
            </table>
          <?php endif; ?>
          <form method="post" style="margin-top:12px;"><?= csrf_input() ?>
            <div class="form-group"><label>Tên</label><input type="text" name="ssh_name" placeholder="VD: Laptop cá nhân" <?= !$verified?'disabled':'' ?>></div>
            <div class="form-group"><label>Public SSH key (định dạng OpenSSH)</label><textarea name="ssh_key" rows="4" placeholder="ssh-ed25519 AAAA... user@host" <?= !$verified?'disabled':'' ?>></textarea></div>
            <button class="btn btn-primary" name="add_ssh" value="1" <?= !$verified?'disabled':'' ?>>Thêm mới SSH Key</button>
          </form>
        </div>
        </div><!-- /sec-col -->
        </div><!-- /sec-grid -->

      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
