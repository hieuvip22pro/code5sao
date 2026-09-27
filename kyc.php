<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/business_functions.php';
require_login();
$uid = current_user_id();

$k = $pdo->prepare('SELECT * FROM seller_kyc WHERE user_id=?');
$k->execute([$uid]);
$kyc = $k->fetch();

$errors = [];
$canSubmit = !$kyc || $kyc['status'] === 'rejected';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canSubmit) {
    csrf_verify();
    $fullName = trim($_POST['full_name'] ?? '');
    $idNumber = trim($_POST['id_number'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $bankName = trim($_POST['bank_name'] ?? '');
    $bankAcc  = trim($_POST['bank_account'] ?? '');
    if (mb_strlen($fullName) < 2) $errors[] = 'Vui lòng nhập họ tên đầy đủ.';
    if (mb_strlen($idNumber) < 6) $errors[] = 'Số CCCD/CMND không hợp lệ.';
    if (mb_strlen($phone) < 8) $errors[] = 'Số điện thoại không hợp lệ.';

    $frontFile = null; $backFile = null;
    if (!$errors) {
        $kycDir = __DIR__ . '/uploads/kyc/';
        if (!is_dir($kycDir)) @mkdir($kycDir, 0755, true);
        try {
            if (!empty($_FILES['id_front']['name'])) $frontFile = upload_image('id_front', $kycDir, 'kyc');
            if (!empty($_FILES['id_back']['name']))  $backFile  = upload_image('id_back', $kycDir, 'kyc');
        } catch (Exception $ex) {
            $errors[] = 'Ảnh giấy tờ: ' . $ex->getMessage();
        }
    }

    if (!$errors) {
        if ($kyc) {
            $pdo->prepare("UPDATE seller_kyc SET full_name=?, id_number=?, phone=?, bank_name=?, bank_account=?,
                id_front=COALESCE(?, id_front), id_back=COALESCE(?, id_back), status='pending', admin_note=NULL, created_at=NOW(), reviewed_at=NULL WHERE user_id=?")
                ->execute([$fullName, $idNumber, $phone, $bankName ?: null, $bankAcc ?: null, $frontFile, $backFile, $uid]);
        } else {
            $pdo->prepare('INSERT INTO seller_kyc (user_id, full_name, id_number, phone, bank_name, bank_account, id_front, id_back) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$uid, $fullName, $idNumber, $phone, $bankName ?: null, $bankAcc ?: null, $frontFile, $backFile]);
        }
        flash_set('success', 'Đã gửi hồ sơ xác minh. Chúng tôi sẽ duyệt trong 1-2 ngày làm việc.');
        redirect('/kyc');
    }
}

$page_title = 'Xác minh người bán (KYC)';
require_once __DIR__ . '/includes/header.php';
?>
<div class="section"><div class="container" style="max-width:640px;">
  <div class="section-head"><h2>🔐 Xác minh người bán (KYC)</h2></div>
  <p class="hint">Xác minh danh tính để nhận huy hiệu <?= verified_badge(true) ?> giúp tăng độ tin cậy và doanh số. Thông tin được bảo mật, chỉ dùng để xác minh.</p>

  <?php if ($kyc && $kyc['status'] === 'approved'): ?>
    <div class="alert alert-success">✅ Tài khoản của bạn đã được xác minh. Cảm ơn bạn!</div>
  <?php elseif ($kyc && $kyc['status'] === 'pending'): ?>
    <div class="alert">⏳ Hồ sơ đang chờ duyệt. Chúng tôi sẽ thông báo khi có kết quả.</div>
  <?php elseif ($kyc && $kyc['status'] === 'rejected'): ?>
    <div class="alert alert-error">❌ Hồ sơ bị từ chối.<?php if ($kyc['admin_note']): ?> Lý do: <?= e($kyc['admin_note']) ?><?php endif; ?> Vui lòng gửi lại.</div>
  <?php endif; ?>

  <?php foreach ($errors as $e): ?><div class="alert alert-error"><?= e($e) ?></div><?php endforeach; ?>

  <?php if ($canSubmit): ?>
  <form method="post" enctype="multipart/form-data" class="form-card">
    <?= csrf_input() ?>
    <div class="form-group"><label>Họ và tên (theo CCCD) *</label>
      <input type="text" name="full_name" value="<?= e($kyc['full_name'] ?? '') ?>" required></div>
    <div class="form-group"><label>Số CCCD/CMND *</label>
      <input type="text" name="id_number" value="<?= e($kyc['id_number'] ?? '') ?>" required></div>
    <div class="form-group"><label>Số điện thoại *</label>
      <input type="text" name="phone" value="<?= e($kyc['phone'] ?? '') ?>" required></div>
    <div class="row-2">
      <div class="form-group"><label>Ngân hàng</label>
        <input type="text" name="bank_name" value="<?= e($kyc['bank_name'] ?? '') ?>" placeholder="VD: Vietcombank"></div>
      <div class="form-group"><label>Số tài khoản</label>
        <input type="text" name="bank_account" value="<?= e($kyc['bank_account'] ?? '') ?>"></div>
    </div>
    <div class="row-2">
      <div class="form-group"><label>Ảnh CCCD mặt trước</label>
        <input type="file" name="id_front" accept="image/*"></div>
      <div class="form-group"><label>Ảnh CCCD mặt sau</label>
        <input type="file" name="id_back" accept="image/*"></div>
    </div>
    <button class="btn btn-primary btn-block" type="submit">Gửi hồ sơ xác minh</button>
  </form>
  <?php endif; ?>
</div></div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
