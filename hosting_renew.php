<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/hosting_functions.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT ha.*, hp.name as plan_name, hp.price_month FROM hosting_accounts ha JOIN hosting_plans hp ON hp.id = ha.plan_id WHERE ha.id = ? AND ha.user_id = ?');
$stmt->execute([$id, current_user_id()]);
$acc = $stmt->fetch();
if (!$acc) { http_response_code(404); die('Không tìm thấy gói hosting.'); }

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['renew'])) {
    csrf_verify();
    $months = (int)($_POST['months'] ?? 1);
    try {
        process_hosting_renewal($pdo, $id, current_user_id(), $months);
        flash_set('success', 'Gia hạn thành công thêm ' . $months . ' tháng cho ' . $acc['domain']);
        redirect('/dashboard?tab=hosting');
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$page_title = 'Gia hạn Hosting';
require_once __DIR__ . '/includes/header.php';
?>
<div class="section">
    <div class="container">
        <div class="form-card" style="max-width:480px;">
            <h2>Gia hạn gói <?= e($acc['plan_name']) ?></h2>
            <p class="hint">Domain: <b class="mono"><?= e($acc['domain']) ?></b> — Hết hạn hiện tại: <b><?= date('d/m/Y', strtotime($acc['expires_at'])) ?></b></p>
            <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
            <form method="post">
                <?= csrf_input() ?>
                <div class="form-group">
                    <label>Số tháng gia hạn</label>
                    <select name="months" id="monthsSelect" required>
                        <option value="1">1 tháng</option>
                        <option value="3">3 tháng</option>
                        <option value="6">6 tháng</option>
                        <option value="12">12 tháng</option>
                    </select>
                </div>
                <div class="kv"><span>Giá / tháng</span><b class="mono"><?= money($acc['price_month']) ?></b></div>
                <div class="kv" style="font-size:18px;"><span>Tổng tiền</span><b class="mono" id="grandTotal"><?= money($acc['price_month']) ?></b></div>
                <button class="btn btn-primary btn-block" type="submit" name="renew" value="1" style="margin-top:16px;">Xác nhận gia hạn</button>
            </form>
        </div>
    </div>
</div>
<script>
(function(){
    const price = <?= (float)$acc['price_month'] ?>;
    const sel = document.getElementById('monthsSelect');
    const total = document.getElementById('grandTotal');
    function fmt(n){ return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' đ'; }
    sel.addEventListener('change', function(){ total.textContent = fmt(price * parseInt(sel.value,10)); });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>