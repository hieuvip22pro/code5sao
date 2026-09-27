<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_login();
$uid = current_user_id();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_ticket'])) {
    csrf_verify();
    $subject = trim($_POST['subject'] ?? '');
    $category = $_POST['category'] ?? 'other';
    $priority = $_POST['priority'] ?? 'normal';
    $message = trim($_POST['message'] ?? '');
    if (!in_array($category, ['payment','account','product','other'], true)) $category = 'other';
    if (!in_array($priority, ['low','normal','high'], true)) $priority = 'normal';
    if (mb_strlen($subject) < 5) $errors[] = 'Tiêu đề tối thiểu 5 ký tự.';
    if (mb_strlen($message) < 10) $errors[] = 'Nội dung tối thiểu 10 ký tự.';
    if (!$errors) {
        $pdo->prepare('INSERT INTO support_tickets (user_id, subject, category, priority) VALUES (?,?,?,?)')
            ->execute([$uid, $subject, $category, $priority]);
        $tid = $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO ticket_messages (ticket_id, sender_id, is_admin, message) VALUES (?,?,0,?)')
            ->execute([$tid, $uid, $message]);
        flash_set('success', 'Đã tạo yêu cầu hỗ trợ #' . $tid);
        redirect('/ticket_view?id=' . $tid);
    }
}

$tickets = $pdo->prepare('SELECT * FROM support_tickets WHERE user_id=? ORDER BY updated_at DESC, created_at DESC');
$tickets->execute([$uid]);
$tickets = $tickets->fetchAll();

$catLabels = ['payment'=>'Thanh toán/Nạp rút','account'=>'Tài khoản','product'=>'Sản phẩm','other'=>'Khác'];
$stLabels = ['open'=>'Đang mở','answered'=>'Đã trả lời','closed'=>'Đã đóng'];
$page_title = 'Trung tâm hỗ trợ';
require_once __DIR__ . '/includes/header.php';
?>
<div class="section"><div class="container">
  <div class="section-head"><h2>🆘 Trung tâm hỗ trợ</h2></div>
  <div class="cm-support-grid">
    <div class="form-card">
      <h3 style="font-size:16px;margin-bottom:12px;">Tạo yêu cầu mới</h3>
      <?php foreach ($errors as $e): ?><div class="alert alert-error"><?= e($e) ?></div><?php endforeach; ?>
      <form method="post">
        <?= csrf_input() ?>
        <div class="form-group"><label>Tiêu đề</label>
          <input type="text" name="subject" required placeholder="Vấn đề bạn gặp phải"></div>
        <div class="row-2">
          <div class="form-group"><label>Chủ đề</label>
            <select name="category">
              <option value="payment">Thanh toán/Nạp rút</option>
              <option value="account">Tài khoản</option>
              <option value="product">Sản phẩm</option>
              <option value="other" selected>Khác</option>
            </select></div>
          <div class="form-group"><label>Độ ưu tiên</label>
            <select name="priority">
              <option value="low">Thấp</option>
              <option value="normal" selected>Bình thường</option>
              <option value="high">Cao</option>
            </select></div>
        </div>
        <div class="form-group"><label>Nội dung</label>
          <textarea name="message" rows="5" required placeholder="Mô tả chi tiết..."></textarea></div>
        <button class="btn btn-primary btn-block" name="create_ticket" value="1">Gửi yêu cầu</button>
      </form>
    </div>
    <div>
      <h3 style="font-size:16px;margin-bottom:12px;">Yêu cầu của bạn</h3>
      <?php if (!$tickets): ?><div class="empty">Bạn chưa có yêu cầu nào.</div><?php else: ?>
        <?php foreach ($tickets as $t): ?>
          <a href="<?= BASE_URL ?>/ticket_view?id=<?= $t['id'] ?>" class="cm-ticket-row">
            <div>
              <strong>#<?= $t['id'] ?> · <?= e($t['subject']) ?></strong><br>
              <span class="hint"><?= e($catLabels[$t['category']] ?? $t['category']) ?> · <?= date('d/m/Y H:i', strtotime($t['updated_at'])) ?></span>
            </div>
            <span class="badge badge-<?= $t['status']==='open'?'warn':($t['status']==='closed'?'muted':'ok') ?>"><?= e($stLabels[$t['status']] ?? $t['status']) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div></div>
<style>
.cm-support-grid{display:grid;grid-template-columns:1fr 1.2fr;gap:24px;align-items:start}
.cm-ticket-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:14px 16px;border:1px solid var(--border);border-radius:12px;margin-bottom:10px;text-decoration:none;color:inherit;transition:.15s}
.cm-ticket-row:hover{border-color:var(--accent);transform:translateY(-1px)}
.badge{padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600}
.badge-ok{background:#dcfce7;color:#166534}.badge-warn{background:#fef3c7;color:#92400e}.badge-muted{background:#e5e7eb;color:#374151}
@media(max-width:768px){.cm-support-grid{grid-template-columns:1fr}}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
