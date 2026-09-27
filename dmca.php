<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

$errors = [];
$ok = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $url   = trim($_POST['target_url'] ?? '');
    $codeId = (int)($_POST['code_id'] ?? 0);
    $desc  = trim($_POST['description'] ?? '');
    if ($name === '' || mb_strlen($name) < 2) $errors[] = 'Vui lòng nhập họ tên.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email không hợp lệ.';
    if (mb_strlen($desc) < 20) $errors[] = 'Mô tả vi phạm tối thiểu 20 ký tự.';
    if (!$errors) {
        $pdo->prepare('INSERT INTO dmca_requests (complainant_name, complainant_email, code_id, target_url, description) VALUES (?,?,?,?,?)')
            ->execute([$name, $email, $codeId ?: null, $url ?: null, $desc]);
        $ok = true;
    }
}
$page_title = 'Báo cáo vi phạm bản quyền (DMCA)';
$meta_description = 'Gửi yêu cầu gỡ bỏ nội dung vi phạm bản quyền trên CodeMarket theo quỹ trình DMCA.';
require_once __DIR__ . '/includes/header.php';
?>
<div class="section"><div class="container cm-legal">
  <h1>Báo cáo vi phạm bản quyền (DMCA)</h1>
  <p>Nếu bạn là chủ sở hữu hợp pháp và phát hiện nội dung trên CodeMarket vi phạm bản quyền của mình, vui lòng điền biểu mẫu dưới đây. Chúng tôi sẽ xem xét và gỡ bỏ trong thời gian sớm nhất.</p>
  <?php if ($ok): ?>
    <div class="alert alert-success">Đã gửi yêu cầu DMCA. Chúng tôi sẽ liên hệ qua email nếu cần thêm thông tin. Cảm ơn bạn!</div>
  <?php else: ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-error"><?= e($e) ?></div><?php endforeach; ?>
    <form method="post" class="form-card" style="max-width:640px;margin-top:16px;">
      <?= csrf_input() ?>
      <div class="form-group"><label>Họ tên chủ sở hữu / người đại diện *</label>
        <input type="text" name="name" value="<?= e($_POST['name'] ?? '') ?>" required></div>
      <div class="form-group"><label>Email liên hệ *</label>
        <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required></div>
      <div class="form-group"><label>Link sản phẩm vi phạm (nếu có)</label>
        <input type="text" name="target_url" value="<?= e($_POST['target_url'] ?? '') ?>" placeholder="https://..."></div>
      <div class="form-group"><label>Ma san pham (ID, nếu biết)</label>
        <input type="number" name="code_id" value="<?= e($_POST['code_id'] ?? '') ?>"></div>
      <div class="form-group"><label>Mô tả vi phạm & bằng chứng sở hữu *</label>
        <textarea name="description" rows="5" required placeholder="Mô tả nội dung vi phạm, link bản gốc, bằng chứng bản quyền..."><?= e($_POST['description'] ?? '') ?></textarea></div>
      <button class="btn btn-primary" type="submit">Gửi yêu cầu gỡ bỏ</button>
    </form>
  <?php endif; ?>
</div></div>
<style>
.cm-legal{max-width:820px;margin:0 auto;padding:20px 18px;line-height:1.7}
.cm-legal h1{font-size:28px;margin-bottom:10px}
.cm-legal a{color:var(--accent)}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
