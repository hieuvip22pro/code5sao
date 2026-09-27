<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/hosting_functions.php';

$planId = (int)($_GET['plan'] ?? $_POST['plan_id'] ?? 0);
$plan   = get_hosting_plan($pdo, $planId);
if (!$plan) { http_response_code(404); die('Gói hosting không tồn tại hoặc đã ngừng bán.'); }

$error  = null;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buy'])) {
    require_login();
    // Xác thực CSRF
    csrf_verify();
    // Rate limiting: tối đa 3 lần mua hosting trong 5 phút
    try {
        rate_limit('hosting_buy', 3, 300);
    } catch (Exception $ex) {
        $error = $ex->getMessage();
        goto render;
    }

    $domain = trim($_POST['domain'] ?? '');
    $months = (int)($_POST['months'] ?? 1);

    if (empty($_POST['agree'])) {
        $error = 'Vui lòng đồng ý điều khoản sử dụng.';
    } else {
        try {
            $result = process_hosting_purchase($pdo, current_user_id(), $planId, $domain, $months);
            flash_set('success', 'Tạo hosting thành công cho domain ' . e($domain) . '!');
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

render:
$page_title = 'Đăng ký ' . $plan['name'];
require_once __DIR__ . '/includes/header.php';
?>
<div class="section">
    <div class="container">
        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

        <?php if ($result): ?>
            <div class="alert alert-success">🎉 Tài khoản hosting đã được tạo thành công trên hệ thống!</div>
            <div class="form-card" style="max-width:520px;">
                <h2>Thông tin đăng nhập cPanel</h2>
                <p class="hint">Vui lòng lưu lại thông tin này — mật khẩu chỉ hiển thị 1 lần duy nhất.</p>
                <div class="kv"><span>Domain</span><b class="mono"><?= e($_POST['domain'] ?? '') ?></b></div>
                <div class="kv"><span>cPanel Username</span><b class="mono"><?= e($result['cpanel_username']) ?></b></div>
                <div class="kv"><span>Mật khẩu</span><b class="mono"><?= e($result['cpanel_password']) ?></b></div>
                <a href="<?= BASE_URL ?>/dashboard?tab=hosting" class="btn btn-primary btn-block" style="margin-top:20px;">Đi tới Quản lý Hosting của tôi</a>
            </div>
        <?php else: ?>

        <div class="detail-grid">
            <div>
                <h1 style="font-size:26px; margin-bottom:20px;">Đăng ký gói <?= e($plan['name']) ?></h1>

                <?php if (!is_logged_in()): ?>
                    <div class="alert alert-info">Vui lòng <a href="<?= BASE_URL ?>/login">đăng nhập</a> để đăng ký hosting.</div>
                <?php else: ?>
                    <form method="post" id="buyForm" onsubmit="return confirm('Xác nhận đăng ký hosting với tổng chi phí đã tính bên dưới? Hệ thống sẽ tạo tài khoản cPanel ngay lập tức.');">
                        <?= csrf_input() ?>
                        <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                        <div class="form-group">
                            <label>Tên miền của bạn</label>
                            <input type="text" name="domain" id="domainInput" placeholder="VD: example.com" maxlength="253" required>
                            <p class="hint">Nhập tên miền bạn đã sở hữu sẵn. Sau khi tạo hosting, hãy trỏ DNS/Nameserver của domain về server hosting.</p>
                        </div>
                        <div class="form-group">
                            <label>Chu kỳ thanh toán</label>
                            <select name="months" id="monthsSelect" required>
                                <option value="1">1 tháng</option>
                                <option value="3">3 tháng</option>
                                <option value="6">6 tháng</option>
                                <option value="12">12 tháng (tiết kiệm hơn)</option>
                            </select>
                        </div>
                        <div class="form-group" style="display:flex; align-items:flex-start; gap:8px;">
                            <input type="checkbox" name="agree" id="agree" required style="margin-top:4px;">
                            <label for="agree" style="font-weight:400;">Tôi đồng ý với điều khoản sử dụng dịch vụ hosting.</label>
                        </div>
                        <button class="btn btn-primary btn-block" type="submit" name="buy" value="1">Thanh toán &amp; Kích hoạt ngay - <span id="totalBtn">0đ</span></button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="buybox">
                <h3 style="margin-top:0;">Chi tiết gói</h3>
                <div class="kv"><span>Dung lượng</span><b><?= e($plan['disk_quota_display'] ?: '-') ?></b></div>
                <div class="kv"><span>Băng thông</span><b><?= e($plan['bandwidth_display'] ?: '-') ?></b></div>
                <div class="kv"><span>Số website</span><b><?= (int)$plan['max_domains'] ?></b></div>
                <div class="kv"><span>SSL</span><b><?= $plan['free_ssl'] ? '✅ Miễn phí' : '—' ?></b></div>
                <hr style="border-color:var(--border); margin:14px 0;">
                <div class="kv"><span>Giá / tháng</span><b class="mono"><?= number_format($plan['price_month'],0,',','.') ?>đ</b></div>
                <div class="kv" style="font-size:18px;"><span>Tổng tiền</span><b class="mono" id="grandTotal">0đ</b></div>
            </div>
        </div>

        <script>
        (function () {
            const price = <?= (float)$plan['price_month'] ?>;
            const monthsSelect = document.getElementById('monthsSelect');
            const grandTotalEl = document.getElementById('grandTotal');
            const totalBtnEl   = document.getElementById('totalBtn');
            function fmt(n) { return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.') + 'đ'; }
            function recalc() {
                const months = parseInt(monthsSelect.value || '1', 10);
                const total  = price * months;
                grandTotalEl.textContent = fmt(total);
                totalBtnEl.textContent   = fmt(total);
            }
            if (monthsSelect) { monthsSelect.addEventListener('change', recalc); recalc(); }
        })();
        </script>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>