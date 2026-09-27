<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
$page_title = 'Điều khoản sử dụng';
$meta_description = 'Điều khoản sử dụng dịch vụ Code5sao - quy định mua bán source code, quyền & nghĩa vụ của người dùng.';
require_once __DIR__ . '/includes/header.php';
?>
<div class="section"><div class="container cm-legal">
  <h1>Điều khoản sử dụng</h1>
  <p class="hint">Cập nhật lần cuối: <?= date('d/m/Y') ?></p>

  <h2>1. Chấp thuận điều khoản</h2>
  <p>Khi truy cập và sử dụng Code5sao, bạn đồng ý tuân thủ các điều khoản dưới đây. Nếu không đồng ý, vui lòng ngừng sử dụng dịch vụ.</p>

  <h2>2. Tài khoản</h2>
  <p>Người dùng chịu trách nhiệm bảo mật thông tin đăng nhập và mọi hoạt động dưới tài khoản của mình. Nghiêm cấm mạo danh, gian lận hoặc sử dụng tài khoản cho mục đích phi pháp.</p>

  <h2>3. Mua bán source code</h2>
  <ul>
    <li>Người bán cam kết sản phẩm do mình sở hữu hoặc có quyền phân phối hợp pháp, không vi phạm bản quyền bên thứ ba.</li>
    <li>Sản phẩm không được chứa mã độc, backdoor, hoặc chức năng đánh cắp dữ liệu.</li>
    <li>Người mua được cấp quyền sử dụng theo loại giấy phép đã chọn khi thanh toán.</li>
  </ul>

  <h2>4. Thanh toán & tạm giữ tiền (Escrow)</h2>
  <p>Tiền thanh toán có thể được tạm giữ cho đến khi người mua xác nhận nhận được sản phẩm đúng mô tả, hoặc tự động nhả sau thời hạn quy định. Xem thêm <a href="<?= BASE_URL ?>/refund">Chính sách hoàn tiền</a>.</p>

  <h2>5. Hành vi bị cấm</h2>
  <p>Nghiêm cấm đăng bán code vi phạm bản quyền, phát tán mã độc, lừa đảo, spam. Vi phạm có thể bị khoá tài khoản và tịch thu số dư.</p>

  <h2>6. Giới hạn trách nhiệm</h2>
  <p>Code5sao là nền tảng trung gian. Chúng tôi nỗ lực kiểm duyệt nhưng không bảo đảm tuyệt đối về chất lượng sản phẩm của bên thứ ba.</p>

  <h2>7. Liên hệ</h2>
  <p>Mọi thắc mắc vui lòng gửi qua <a href="<?= BASE_URL ?>/support">Trung tâm hỗ trợ</a>.</p>
</div></div>
<style>
.cm-legal{max-width:820px;margin:0 auto;padding:20px 18px;line-height:1.75}
.cm-legal h1{font-size:28px;margin-bottom:6px}
.cm-legal h2{font-size:19px;margin:26px 0 8px}
.cm-legal ul{padding-left:22px}
.cm-legal a{color:var(--accent)}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
