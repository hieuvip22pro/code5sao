<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
$page_title = 'Chính sách bảo mật';
$meta_description = 'Chính sách bảo mật Code5sao - cách chúng tôi thu thập, sử dụng và bảo vệ dữ liệu cá nhân của bạn.';
require_once __DIR__ . '/includes/header.php';
?>
<div class="section"><div class="container cm-legal">
  <h1>Chính sách bảo mật</h1>
  <p class="hint">Cập nhật lần cuối: <?= date('d/m/Y') ?></p>

  <h2>1. Thông tin chúng tôi thu thập</h2>
  <ul>
    <li>Thông tin tài khoản: tên đăng nhập, email, mật khẩu (đã mã hoá).</li>
    <li>Thông tin giao dịch: lịch sử nạp/rút/mua bán.</li>
    <li>Thông tin KYC (nếu đăng ký người bán): họ tên, số giấy tờ, số điện thoại.</li>
    <li>Dữ liệu kỹ thuật: IP, trình duyệt, cookie.</li>
  </ul>

  <h2>2. Mục đích sử dụng</h2>
  <p>Để vận hành dịch vụ, xử lý giao dịch, chống gian lận, hỗ trợ khách hàng và cải thiện sản phẩm.</p>

  <h2>3. Chia sẻ dữ liệu</h2>
  <p>Chúng tôi KHÔNG bán dữ liệu cá nhân. Chỉ chia sẻ khi có yêu cầu hợp pháp từ cơ quan chức năng hoặc để xử lý thanh toán qua đối tác cổng thanh toán.</p>

  <h2>4. Bảo mật</h2>
  <p>Mật khẩu được mã hoá bcrypt; hỗ trợ xác thực 2 lớp (2FA), giới hạn IP và nhật ký hoạt động.</p>

  <h2>5. Quyền của bạn</h2>
  <p>Bạn có thể yêu cầu xem, chỉnh sửa hoặc xoá dữ liệu cá nhân bằng cách liên hệ <a href="<?= BASE_URL ?>/support">Trung tâm hỗ trợ</a>.</p>
</div></div>
<style>
.cm-legal{max-width:820px;margin:0 auto;padding:20px 18px;line-height:1.75}
.cm-legal h1{font-size:28px;margin-bottom:6px}
.cm-legal h2{font-size:19px;margin:26px 0 8px}
.cm-legal ul{padding-left:22px}
.cm-legal a{color:var(--accent)}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
