<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/topup_functions.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$topup = get_topup_request($pdo, $id, current_user_id());
if (!$topup) { http_response_code(404); die('Không tìm thấy yêu cầu nạp tiền.'); }

$page_title = 'Quét mã QR nạp tiền';
require_once __DIR__ . '/includes/header.php';
?>
<div class="section">
    <div class="container">
        <div class="form-card" style="max-width:420px; text-align:center; margin:0 auto;">
            <h2>Quét mã để nạp tiền</h2>
            <p class="hint">Mở app ngân hàng bất kỳ, quét mã QR bên dưới. Nội dung chuyển khoản đã có sẵn mã đối soát — vui lòng KHÔNG chỉnh sửa nội dung.</p>
            <img src="<?= e($topup['qr_url']) ?>" alt="QR nạp tiền" style="max-width:280px; width:100%; margin:16px auto; border-radius:12px; border:1px solid var(--border); display:block;">
            <div class="kv"><span>Số tiền</span><b class="mono"><?= money($topup['amount']) ?></b></div>
            <div class="kv"><span>Mã nội dung</span><b class="mono"><?= e($topup['code']) ?></b></div>
            <div class="kv"><span>Trạng thái</span><b id="statusLabel"><?= e($topup['status']) ?></b></div>
            <div id="waitBox" style="margin-top:16px;">
                <div class="hint">⏳ Đang chờ giao dịch... trang sẽ tự động cập nhật, không cần tải lại.</div>
            </div>
            <button class="btn btn-outline" id="checkNowBtn" style="margin-top:12px;">Tôi đã chuyển khoản, kiểm tra ngay</button>
            <a href="<?= BASE_URL ?>/wallet" class="btn btn-primary btn-block" style="margin-top:12px;">Quay lại Ví</a>
        </div>
    </div>
</div>
<script>
(function () {
    const topupId = <?= (int)$topup['id'] ?>;
    const statusLabel = document.getElementById('statusLabel');
    const waitBox = document.getElementById('waitBox');
    let polling = <?= $topup['status'] === 'pending' ? 'true' : 'false' ?>;

    async function checkStatus() {
        try {
            const res = await fetch('<?= BASE_URL ?>/topup_check.php?id=' + topupId);
            const data = await res.json();
            statusLabel.textContent = data.status;
            if (data.status === 'completed') {
                polling = false;
                waitBox.innerHTML = '<div class="alert alert-success">🎉 Nạp tiền thành công! Đang chuyển về ví...</div>';
                setTimeout(function () { window.location.href = '<?= BASE_URL ?>/wallet'; }, 1500);
            } else if (data.status === 'expired') {
                polling = false;
                waitBox.innerHTML = '<div class="alert alert-error">Mã QR đã hết hạn hiển thị. Nếu bạn đã chuyển khoản, hãy bấm "Kiểm tra ngay".</div>';
            }
        } catch (e) { /* lỗi mạng tạm thời, bỏ qua và thử lại lần sau */ }
    }

    document.getElementById('checkNowBtn').addEventListener('click', checkStatus);

    if (polling) {
        const interval = setInterval(function () {
            if (!polling) { clearInterval(interval); return; }
            checkStatus();
        }, 4000);
    }
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
