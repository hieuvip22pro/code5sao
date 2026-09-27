<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
$page_title = 'Chính sách hoàn tiền & tranh chấp';
$meta_description = 'Chính sách hoàn tiền, tạm giữ tiền (escrow) và giải quyết tranh chấp trên Code5sao.';
require_once __DIR__ . '/includes/header.php';
?>
<div class="section"><div class="container cm-legal">
  <h1>Chính sách hoàn tiền & tranh chấp</h1>
  <p class="hint">Cập nhật lần cuối: <?= date('d/m/Y') ?></p>

  <h2>1. Cơ chế tạm giữ tiền (Escrow)</h2>
  <p>Khi bạn mua một sản phẩm, tiền thanh toán của người bán sẽ được <strong>tạm giữ</strong> bởi hệ thống. Người bán chỉ nhận được tiền khi:</p>
  <ul>
    <li>Bạn bấm “Đã nhận được hàng” để xác nhận, hoặc</li>
    <li>Sau <strong><?= (int)(defined('ESCROW_AUTO_RELEASE_DAYS') ? ESCROW_AUTO_RELEASE_DAYS : 3) ?> ngày</strong> kể từ khi mua mà không phát sinh khiếu nại, tiền sẽ tự động được nhả.</li>
  </ul>

  <h2>2. Khi nào được hoàn tiền?</h2>
  <ul>
    <li>Sản phẩm không đúng mô tả, thiếu file, không chạy được.</li>
    <li>Sản phẩm chứa mã độc hoặc vi phạm bản quyền.</li>
    <li>Người bán không bàn giao hoặc không hỗ trợ như cam kết.</li>
  </ul>

  <h2>3. Quy trình khiếu nại</h2>
  <ol>
    <li>Vào mục <a href="<?= BASE_URL ?>/my_orders">Đơn đã mua</a>, chọn đơn cần khiếu nại.</li>
    <li>Bấm “Mở tranh chấp” và mô tả vấn đề (kèm bằng chứng nếu có).</li>
    <li>Quản trị viên sẽ xem xét và quyết định hoàn tiền hoặc nhả tiền cho người bán.</li>
  </ol>
  <p>Thời gian xử lý tranh chấp thông thường 1-3 ngày làm việc.</p>

  <h2>4. Trường hợp không hoàn tiền</h2>
  <p>Đã xác nhận nhận hàng, hoặc đã tải sản phẩm và sử dụng bình thường nhưng đổi ý. Việc lạm dụng khiếu nại có thể dẫn đến khoá tài khoản.</p>
</div></div>
<style>
.cm-legal{max-width:820px;margin:0 auto;padding:20px 18px;line-height:1.75}
.cm-legal h1{font-size:28px;margin-bottom:6px}
.cm-legal h2{font-size:19px;margin:26px 0 8px}
.cm-legal ul,.cm-legal ol{padding-left:22px}
.cm-legal a{color:var(--accent)}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
