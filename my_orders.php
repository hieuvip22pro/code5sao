<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/business_functions.php';
require_login();
$uid = current_user_id();
escrow_auto_release_due($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $orderId = (int)($_POST['order_id'] ?? 0);
    // Kiem tra don thuoc ve buyer
    $o = $pdo->prepare('SELECT * FROM orders WHERE id=? AND buyer_id=?');
    $o->execute([$orderId, $uid]);
    $order = $o->fetch();
    if (!$order) { flash_set('error', 'Không tìm thấy đơn hàng.'); redirect('/my_orders'); }

    if (isset($_POST['confirm_receipt'])) {
        if ($order['escrow_status'] === 'held') {
            try {
                escrow_release_order($pdo, $orderId, false);
                $pdo->prepare('UPDATE orders SET buyer_confirmed_at=NOW() WHERE id=?')->execute([$orderId]);
                flash_set('success', 'Đã xác nhận nhận hàng. Cảm ơn bạn!');
            } catch (Exception $ex) { flash_set('error', $ex->getMessage()); }
        }
        redirect('/my_orders');
    }
    if (isset($_POST['open_dispute'])) {
        $reason = trim($_POST['reason'] ?? '');
        if ($order['escrow_status'] !== 'held') {
            flash_set('error', 'Chỉ có thể mở tranh chấp khi tiền đang được tạm giữ.');
        } elseif (mb_strlen($reason) < 10) {
            flash_set('error', 'Vui lòng mô tả lý do (tối thiểu 10 ký tự).');
        } else {
            $ex = $pdo->prepare('SELECT id FROM disputes WHERE order_id=?');
            $ex->execute([$orderId]);
            if ($ex->fetch()) {
                flash_set('error', 'Đơn này đã có tranh chấp.');
            } else {
                $pdo->prepare('INSERT INTO disputes (order_id, opener_id, reason) VALUES (?,?,?)')->execute([$orderId, $uid, $reason]);
                $did = $pdo->lastInsertId();
                $pdo->prepare('INSERT INTO dispute_messages (dispute_id, sender_id, is_admin, message) VALUES (?,?,0,?)')->execute([$did, $uid, $reason]);
                $pdo->prepare("UPDATE orders SET escrow_status='disputed' WHERE id=?")->execute([$orderId]);
                flash_set('success', 'Đã mở tranh chấp. Quản trị viên sẽ xem xét sớm.');
            }
        }
        redirect('/my_orders');
    }
    if (isset($_POST['dispute_reply'])) {
        $msg = trim($_POST['message'] ?? '');
        $d = $pdo->prepare('SELECT * FROM disputes WHERE order_id=?');
        $d->execute([$orderId]);
        $dispute = $d->fetch();
        if ($dispute && $dispute['status'] === 'open' && mb_strlen($msg) >= 1) {
            $pdo->prepare('INSERT INTO dispute_messages (dispute_id, sender_id, is_admin, message) VALUES (?,?,0,?)')->execute([$dispute['id'], $uid, $msg]);
        }
        redirect('/my_orders');
    }
}

$orders = $pdo->prepare('SELECT * FROM orders WHERE buyer_id=? ORDER BY created_at DESC');
$orders->execute([$uid]);
$orders = $orders->fetchAll();
// Lay disputes theo order
// Lay du lieu file code de hien thi nut tai truc tiep tren trang nay
$codeFiles = [];
if ($orders) {
    $codeIds = array_values(array_unique(array_map(
        fn($o) => (int)$o['item_id'],
        array_filter($orders, fn($o) => $o['item_type'] === 'code')
    )));
    if ($codeIds) {
        $in3 = implode(',', array_fill(0, count($codeIds), '?'));
        $cfq = $pdo->prepare("SELECT id, code_file FROM code_listings WHERE id IN ($in3)");
        $cfq->execute($codeIds);
        foreach ($cfq->fetchAll() as $row) {
            $codeFiles[$row['id']] = $row['code_file'];
        }
    }
}
$disputes = [];
$dispMsgs = [];
if ($orders) {
    $ids = array_map(fn($o) => (int)$o['id'], $orders);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $dq = $pdo->prepare("SELECT * FROM disputes WHERE order_id IN ($in)");
    $dq->execute($ids);
    foreach ($dq->fetchAll() as $d) { $disputes[$d['order_id']] = $d; }
    if ($disputes) {
        $dids = array_map(fn($d) => (int)$d['id'], $disputes);
        $in2 = implode(',', array_fill(0, count($dids), '?'));
        $mq = $pdo->prepare("SELECT m.*, u.username FROM dispute_messages m LEFT JOIN users u ON u.id=m.sender_id WHERE m.dispute_id IN ($in2) ORDER BY m.created_at ASC");
        $mq->execute($dids);
        foreach ($mq->fetchAll() as $m) { $dispMsgs[$m['dispute_id']][] = $m; }
    }
}

$escrowLbl = ['none'=>'','held'=>'⏳ Chưa nhận code','released'=>'✅ Đã hoàn tất','refunded'=>'↩️ Đã hoàn tiền','disputed'=>'⚠️ Đang tranh chấp'];
$page_title = 'Đơn đã mua';
require_once __DIR__ . '/includes/header.php';
?>
<div class="section"><div class="container">
  <div class="section-head"><h2>📦 Đơn đã mua</h2></div>
  <?php if (!$orders): ?><div class="empty">Bạn chưa mua sản phẩm nào.</div><?php else: ?>
    <?php foreach ($orders as $o): $d = $disputes[$o['id']] ?? null; ?>
      <div class="form-card" style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;">
          <div>
            <strong>#<?= $o['id'] ?> · <?= e($o['item_title']) ?></strong><br>
            <span class="hint"><?= date('d/m/Y ', strtotime($o['created_at'])) ?> · <?= money($o['price']) ?><?php if ($o['discount_code']): ?> · Mã: <?= e($o['discount_code']) ?><?php endif; ?></span>
          </div>
          <div style="text-align:right;">
            <?php if (!empty($escrowLbl[$o['escrow_status']])): ?><span style="display:inline-block;position:static;font-family:var(--font-mono);font-size:11px;font-weight:700;padding:6px 11px;border-radius:999px;background:rgba(79,70,229,.1);color:var(--accent);border:1px solid rgba(79,70,229,.28);"><?= $escrowLbl[$o['escrow_status']] ?></span><br><?php endif; ?>
            <a href="<?= BASE_URL ?>/code_view?id=<?= (int)$o['item_id'] ?>" class="btn btn-sm btn-outline" style="margin-top:6px;">Xem / Tải</a>
          </div>
        </div>
      <?php
$__file = ($o['item_type'] === 'code') ? ($codeFiles[$o['item_id']] ?? null) : null;
?>
<?php if ($__file && $o['escrow_status'] !== 'held'): ?>
    <?php if (filter_var($__file, FILTER_VALIDATE_URL)): ?>
        <a class="btn btn-sm btn-accent" href="<?= e($__file) ?>" target="_blank" rel="noopener" style="margin-top:10px;">⬇ Tải source code (link ngoài)</a>
    <?php else: ?>
        <a class="btn btn-sm btn-accent" href="<?= UPLOAD_URL_CODE . 'files/' . e($__file) ?>" download style="margin-top:10px;">⬇ Tải source code</a>
    <?php endif; ?>
<?php elseif ($o['item_type'] === 'code' && $o['escrow_status'] === 'held'): ?>
    <p class="hint" style="margin-top:8px;">Link tải sẽ hiện sau khi bạn xác nhận đã nhận hàng.</p>
<?php elseif ($o['item_type'] === 'code'): ?>
    <p class="hint" style="margin-top:8px;">Người bán chưa cập nhật link tải. Vui lòng vào <a href="<?= BASE_URL ?>/code_view?id=<?= (int)$o['item_id'] ?>" style="color:var(--accent)">trang sản phẩm</a> để liên hệ.</p>
<?php endif; ?>
        <?php if ($o['escrow_status'] === 'held'): ?>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;align-items:center;">
            <form method="post" onsubmit="return confirm('Hãy báo cáo admin nếu code lỗi ở dưới nhé! Ấn OK để nhận code')">
              <?= csrf_input() ?><input type="hidden" name="order_id" value="<?= $o['id'] ?>">
             <button class="btn btn-sm btn-primary" name="confirm_receipt" value="1">Ấn vào để lấy code</form>
            <button class="btn btn-sm btn-outline" type="button" onclick="document.getElementById('disp-<?= $o['id'] ?>').style.display='block'">⚠️ Code lỗi (Báo cáo admin)</button>
          </div>
          <?php if ($o['auto_release_at']): ?><p class="hint" style="margin-top:6px;">Ban quản lý không hỗ trợ tranh chấp (code lỗi) vào: <?= date('d/m/Y H:i', strtotime($o['auto_release_at'])) ?></p><?php endif; ?>
          <form method="post" id="disp-<?= $o['id'] ?>" style="display:none;margin-top:10px;">
            <?= csrf_input() ?><input type="hidden" name="order_id" value="<?= $o['id'] ?>">
            <textarea name="reason" rows="3" placeholder="Mô tả vấn đề: thiếu file, không chạy, sai mô tả..." required></textarea>
            <button class="btn btn-sm btn-primary" name="open_dispute" value="1" style="margin-top:8px;">Gửi tranh chấp</button>
          </form>
        <?php endif; ?>
        <?php if ($d): ?>
          <div class="cm-dispute">
            <strong>Tranh chấp #<?= $d['id'] ?> — <?= e($d['status']) ?></strong>
            <?php foreach (($dispMsgs[$d['id']] ?? []) as $m): ?>
              <div class="cm-dmsg <?= $m['is_admin']?'cm-dmsg-admin':'' ?>"><strong><?= $m['is_admin']?'🛡️ Admin':e($m['username']) ?>:</strong> <?= e($m['message']) ?> <span class="hint"><?= date('d/m H:i', strtotime($m['created_at'])) ?></span></div>
            <?php endforeach; ?>
            <?php if ($d['status'] === 'open'): ?>
              <form method="post" style="display:flex;gap:6px;margin-top:8px;">
                <?= csrf_input() ?><input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                <input type="text" name="message" placeholder="Phản hồi thêm..." style="flex:1;" required>
                <button class="btn btn-sm btn-outline" name="dispute_reply" value="1">Gửi</button>
              </form>
            <?php elseif ($d['admin_note']): ?>
              <p class="hint">Kết luận admin: <?= e($d['admin_note']) ?></p>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div></div>
<style>
.badge{padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;background:#eef2ff;color:#3730a3}
.cm-dispute{margin-top:12px;padding:12px;border:1px dashed var(--border);border-radius:10px;background:#fafafa}
.cm-dmsg{padding:6px 0;font-size:14px}
.cm-dmsg-admin{color:#1d4ed8}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
