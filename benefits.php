<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/business_functions.php';

$sellerPct  = defined('SELLER_PERCENT') ? (int)SELLER_PERCENT : 80;
$adminPct   = defined('ADMIN_FEE_PERCENT') ? (int)ADMIN_FEE_PERCENT : 20;
$affPct     = defined('AFFILIATE_PERCENT') ? (int)AFFILIATE_PERCENT : 5;
$escrowDays = defined('ESCROW_AUTO_RELEASE_DAYS') ? (int)ESCROW_AUTO_RELEASE_DAYS : 3;

$page_title = 'Quyền lợi người mua & người bán';
$meta_description = 'Quyền lợi và cam kết dành cho người mua và người bán trên CodeMarket: tạm giữ tiền (escrow), tranh chấp, bảo hành, hỗ trợ cài đặt, hoa hồng giới thiệu.';
require_once __DIR__ . '/includes/header.php';
?>
<div class="section"><div class="container cm-legal">
  <h1>🛡️ Quyền lợi người mua &amp; người bán</h1>
  <p class="hint">Cập nhật lần cuối: <?= date('d/m/Y') ?></p>
  <p>Tại CodeMarket, mọi giao dịch đều được bảo vệ bằng cơ chế <b>tạm giữ tiền (Escrow)</b> và hệ thống <b>tranh chấp</b> minh bạch. Dưới đây là các quyền lợi cụ thể dành cho cả hai bên.</p>

  <div class="cm-benefit-grid">
    <div class="cm-benefit-card cm-buyer">
      <h2>🛒 Quyền lợi Người MUA</h2>
      <ul>
        <li><b>Tạm giữ tiền an toàn (Escrow):</b> Tiền chỉ được chuyển cho người bán sau khi bạn bấm “Đã nhận hàng”, hoặc tự động sau <?= $escrowDays ?> ngày nếu bạn không phản hồi. Trong thời gian này tiền được giữ an toàn bởi nền tảng.</li>
        <li><b>Quyền mở tranh chấp:</b> Nếu sản phẩm thiếu file, không chạy, sai mô tả hoặc chứa mã độc, bạn có thể mở tranh chấp ngay trong mục “Đơn đã mua”. Đội ngũ quản trị sẽ xem xét và có thể <b>hoàn tiền 100%</b>.</li>
        <li><b>Hoàn tiền:</b> Được hoàn tiền theo <a href="<?= BASE_URL ?>/refund">Chính sách hoàn tiền</a> khi khiếu nại hợp lệ.</li>
        <li><b>Bảo hành sản phẩm:</b> Mỗi đơn đi kèm thời hạn bảo hành (tiêu chuẩn 7 ngày, hoặc mở rộng tới 6 tháng nếu người bán có gói).</li>
        <li><b>Thông tin liên hệ người bán:</b> Sau khi thanh toán, bạn nhận được thông tin liên hệ trực tiếp (Zalo / Messenger / SĐT) để được hỗ trợ cài đặt &amp; bảo hành.</li>
        <li><b>Hỗ trợ cài đặt:</b> Nếu người bán cung cấp, bạn có thể chọn thêm dịch vụ cài đặt lên Hosting/VPS.</li>
        <li><b>Tải lại vĩnh viễn:</b> Truy cập và tải lại sản phẩm đã mua bất cứ lúc nào trong “Đơn đã mua”.</li>
        <li><b>Minh bạch chi phí:</b> Xem rõ tổng tiền, phụ phí và mã giảm giá trước khi thanh toán — không phí ẩn.</li>
        <li><b>Bảo mật giao dịch:</b> Thanh toán qua ví nội bộ, mọi giao dịch được ghi lại rõ ràng trong lịch sử ví.</li>
      </ul>
    </div>

    <div class="cm-benefit-card cm-seller">
      <h2>💼 Quyền lợi Người BÁN</h2>
      <ul>
        <li><b>Nhận <?= $sellerPct ?>% giá bán:</b> Nền tảng chỉ thu <?= $adminPct ?>% phí dịch vụ; phần còn lại thuộc về bạn.</li>
        <li><b>Phí hỗ trợ cài đặt 100% cho bạn:</b> Nếu bạn cung cấp dịch vụ cài đặt, toàn bộ phí này được cộng trực tiếp vào ví của bạn (không bị trừ phí nền tảng).</li>
        <li><b>Tự do định giá:</b> Đặt giá tiêu chuẩn, gói <b>Full quyền phân phối lại</b>, gói <b>bảo hành mở rộng</b> và <b>phí cài đặt</b> tuỳ ý.</li>
        <li><b>Huy hiệu “Đã xác minh” (KYC):</b> <a href="<?= BASE_URL ?>/kyc">Xác minh danh tính</a> để nhận huy hiệu uy tín, tăng tỉ lệ chốt đơn.</li>
        <li><b>Hoa hồng giới thiệu (Affiliate):</b> Nhận <?= $affPct ?>% hoa hồng khi giới thiệu người dùng mới phát sinh giao dịch.</li>
        <li><b>Rút tiền linh hoạt:</b> Rút số dư ví về tài khoản của bạn, được bảo vệ bằng xác thực 2 lớp (2FA).</li>
        <li><b>Bảo vệ bản quyền (DMCA):</b> Yêu cầu <a href="<?= BASE_URL ?>/dmca">gỡ nội dung vi phạm</a> nếu sản phẩm của bạn bị sao chép trái phép.</li>
        <li><b>Thanh toán đảm bảo:</b> Khi người mua xác nhận (hoặc hết thời gian tạm giữ), tiền tự động chuyển về ví bạn.</li>
        <li><b>Quản lý toàn diện:</b> Đăng/sửa/ẩn sản phẩm, theo dõi lượt xem và doanh thu trong Bảng điều khiển.</li>
      </ul>
    </div>
  </div>

  <h2>🤝 Cam kết chung của hai bên</h2>
  <ul>
    <li><b>Người bán</b> cam kết sản phẩm hợp pháp, đúng mô tả, không chứa mã độc/backdoor và hỗ trợ người mua theo đúng cam kết.</li>
    <li><b>Người mua</b> cam kết sử dụng sản phẩm đúng phạm vi giấy phép đã mua, không chia sẻ/phát tán lại trái phép.</li>
    <li>Cả hai bên tôn trọng nhau, phản hồi tranh chấp trung thực và thiện chí.</li>
  </ul>

  <h2>⚖️ Quy trình bảo vệ giao dịch (Escrow)</h2>
  <ol>
    <li>Người mua thanh toán → tiền được <b>tạm giữ</b> trên hệ thống.</li>
    <li>Người bán bàn giao sản phẩm &amp; hỗ trợ (nếu có).</li>
    <li>Người mua kiểm tra và bấm <b>“Đã nhận hàng (nhả tiền)”</b> → tiền về ví người bán. Nếu không phản hồi, hệ thống tự nhả sau <?= $escrowDays ?> ngày.</li>
    <li>Nếu có vấn đề, người mua <b>mở tranh chấp</b> → quản trị viên xem xét và quyết định hoàn tiền hoặc nhả tiền.</li>
  </ol>

  <p class="hint" style="margin-top:22px;">Xem thêm: <a href="<?= BASE_URL ?>/terms">Điều khoản sử dụng</a> · <a href="<?= BASE_URL ?>/refund">Chính sách hoàn tiền</a> · <a href="<?= BASE_URL ?>/dmca">DMCA</a>.</p>
</div></div>
<style>
.cm-legal{max-width:920px;margin:0 auto;padding:20px 18px;line-height:1.75}
.cm-legal h1{font-size:28px;margin-bottom:6px}
.cm-legal h2{font-size:20px;margin:26px 0 10px}
.cm-legal ul,.cm-legal ol{padding-left:22px}
.cm-legal li{margin-bottom:8px}
.cm-legal a{color:var(--accent)}
.cm-benefit-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:22px 0}
.cm-benefit-card{border:1px solid var(--border);border-radius:14px;padding:18px 20px;background:#fff}
.cm-benefit-card h2{margin-top:0}
.cm-buyer{border-top:4px solid var(--accent)}
.cm-seller{border-top:4px solid var(--accent-2)}
@media (max-width:768px){.cm-benefit-grid{grid-template-columns:1fr}}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
