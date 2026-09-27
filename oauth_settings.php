<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/oauth_functions.php';
require_admin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_provider'])) {
    $provider = $_POST['save_provider'];
    if (!in_array($provider, ['google', 'github', 'apple'], true)) {
        $errors[] = 'Nhà cung cấp không hợp lệ.';
    } else {
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $clientId = trim($_POST['client_id'] ?? '');

        if ($provider === 'apple') {
            $teamId = trim($_POST['apple_team_id'] ?? '');
            $keyId = trim($_POST['apple_key_id'] ?? '');
            $privateKey = trim($_POST['apple_private_key'] ?? '');

            $existing = $pdo->prepare('SELECT apple_private_key FROM oauth_settings WHERE provider=?');
            $existing->execute([$provider]);
            $oldKey = $existing->fetch()['apple_private_key'] ?? null;
            if ($privateKey === '' && $oldKey) $privateKey = $oldKey;

            $pdo->prepare('UPDATE oauth_settings SET client_id=?, apple_team_id=?, apple_key_id=?, apple_private_key=?, enabled=? WHERE provider=?')
                ->execute([$clientId, $teamId, $keyId, $privateKey, $enabled, $provider]);
        } else {
            $clientSecret = trim($_POST['client_secret'] ?? '');
            $existing = $pdo->prepare('SELECT client_secret FROM oauth_settings WHERE provider=?');
            $existing->execute([$provider]);
            $oldSecret = $existing->fetch()['client_secret'] ?? null;
            if ($clientSecret === '' && $oldSecret) $clientSecret = $oldSecret;

            $pdo->prepare('UPDATE oauth_settings SET client_id=?, client_secret=?, enabled=? WHERE provider=?')
                ->execute([$clientId, $clientSecret, $enabled, $provider]);
        }
        flash_set('success', 'Đã lưu cấu hình ' . strtoupper($provider) . '.');
        redirect('/admin/oauth_settings');
    }
}

$settings = [];
foreach (['google', 'github', 'apple'] as $p) {
    $settings[$p] = oauth_get_settings($pdo, $p);
}

$page_title = 'Admin - Đăng nhập mạng xã hội';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="section">
    <div class="container">
        <div class="dash-layout">
            <?php include __DIR__ . '/_nav.php'; ?>
            <div>
                <div class="section-head"><h2>🔑 Đăng nhập qua Google / GitHub / Apple</h2></div>
                <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

                <!-- GOOGLE -->
                <div class="form-card wide" style="margin-bottom:24px;">
                    <h3>🔵 Google</h3>
                    <p class="hint">Tạo OAuth Client tại <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a> → APIs &amp; Services → Credentials → loại "Web application".</p>
                    <p class="hint">Authorized redirect URI cần khai báo: <code class="mono"><?= e(oauth_redirect_uri('google')) ?></code></p>
                    <form method="post">
                        <div class="row-2">
                            <div class="form-group"><label>Client ID</label><input type="text" name="client_id" value="<?= e($settings['google']['client_id'] ?? '') ?>"></div>
                            <div class="form-group"><label>Client Secret</label><input type="password" name="client_secret" placeholder="<?= !empty($settings['google']['client_secret']) ? 'Để trống nếu giữ nguyên' : '' ?>"></div>
                        </div>
                        <div class="form-group" style="display:flex; align-items:center; gap:8px;">
                            <input type="checkbox" name="enabled" id="en_google" <?= !empty($settings['google']['enabled']) ? 'checked' : '' ?>>
                            <label for="en_google" style="font-weight:400;">Bật đăng nhập Google</label>
                        </div>
                        <button class="btn btn-primary btn-sm" type="submit" name="save_provider" value="google">Lưu Google</button>
                    </form>
                </div>

                <!-- GITHUB -->
                <div class="form-card wide" style="margin-bottom:24px;">
                    <h3>⚫ GitHub</h3>
                    <p class="hint">Tạo OAuth App tại <a href="https://github.com/settings/developers" target="_blank">GitHub Developer Settings</a> → OAuth Apps → New OAuth App.</p>
                    <p class="hint">Authorization callback URL cần khai báo: <code class="mono"><?= e(oauth_redirect_uri('github')) ?></code></p>
                    <form method="post">
                        <div class="row-2">
                            <div class="form-group"><label>Client ID</label><input type="text" name="client_id" value="<?= e($settings['github']['client_id'] ?? '') ?>"></div>
                            <div class="form-group"><label>Client Secret</label><input type="password" name="client_secret" placeholder="<?= !empty($settings['github']['client_secret']) ? 'Để trống nếu giữ nguyên' : '' ?>"></div>
                        </div>
                        <div class="form-group" style="display:flex; align-items:center; gap:8px;">
                            <input type="checkbox" name="enabled" id="en_github" <?= !empty($settings['github']['enabled']) ? 'checked' : '' ?>>
                            <label for="en_github" style="font-weight:400;">Bật đăng nhập GitHub</label>
                        </div>
                        <button class="btn btn-primary btn-sm" type="submit" name="save_provider" value="github">Lưu GitHub</button>
                    </form>
                </div>

                <!-- APPLE -->
                <div class="form-card wide" style="margin-bottom:24px;">
                    <h3>⚪ Apple</h3>
                    <p class="hint">Cần tài khoản Apple Developer (trả phí 99$/năm). Tạo <b>Services ID</b> tại
                        <a href="https://developer.apple.com/account/resources/identifiers/list/serviceId" target="_blank">Apple Developer</a>,
                        bật "Sign in with Apple", cấu hình Domain + Return URL, rồi tạo Key (.p8) tại mục Keys.</p>
                    <p class="hint">Return URL cần khai báo: <code class="mono"><?= e(oauth_redirect_uri('apple')) ?></code></p>
                    <form method="post">
                        <div class="form-group"><label>Client ID (Services ID, VD: com.yourdomain.web)</label><input type="text" name="client_id" value="<?= e($settings['apple']['client_id'] ?? '') ?>"></div>
                        <div class="row-2">
                            <div class="form-group"><label>Team ID</label><input type="text" name="apple_team_id" value="<?= e($settings['apple']['apple_team_id'] ?? '') ?>"></div>
                            <div class="form-group"><label>Key ID</label><input type="text" name="apple_key_id" value="<?= e($settings['apple']['apple_key_id'] ?? '') ?>"></div>
                        </div>
                        <div class="form-group">
                            <label>Private Key (dán toàn bộ nội dung file .p8)</label>
                            <textarea name="apple_private_key" rows="6" placeholder="<?= !empty($settings['apple']['apple_private_key']) ? 'Để trống nếu giữ nguyên key cũ' : '-----BEGIN PRIVATE KEY-----...' ?>" style="font-family:monospace; font-size:12px;"></textarea>
                        </div>
                        <div class="form-group" style="display:flex; align-items:center; gap:8px;">
                            <input type="checkbox" name="enabled" id="en_apple" <?= !empty($settings['apple']['enabled']) ? 'checked' : '' ?>>
                            <label for="en_apple" style="font-weight:400;">Bật đăng nhập Apple</label>
                        </div>
                        <button class="btn btn-primary btn-sm" type="submit" name="save_provider" value="apple">Lưu Apple</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
