<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/business_functions.php';
require_once __DIR__ . '/includes/code_purchase_functions.php';
require_login();
$uid = current_user_id();
escrow_auto_release_due($pdo);

function cart_items_of($pdo, $uid) {
    $s = $pdo->prepare("SELECT c.id AS cart_id, l.* FROM cart_items c
        JOIN code_listings l ON l.id = c.item_id
        WHERE c.user_id = ? AND c.item_type = 'code' ORDER BY c.created_at DESC");
    $s->execute([$uid]);
    return $s->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    // Them vao gio
    if (isset($_POST['add_to_cart'])) {
        $itemId = (int)$_POST['add_to_cart'];
        $l = $pdo->prepare("SELECT * FROM code_listings WHERE id = ? AND status = 'active'");
        $l->execute([$itemId]);
        $listing = $l->fetch();
        if (!$listing) {
            flash_set('error', 'Sản phẩm không khả dụng.');
        } elseif ((int)$listing['user_id'] === (int)$uid) {
            flash_set('error', 'Không thể thêm sản phẩm của chính bạn.');
        } else {
            $own = $pdo->prepare("SELECT id FROM orders WHERE buyer_id=? AND item_type='code' AND item_id=? AND status='completed' LIMIT 1");
            $own->execute([$uid, $itemId]);
            if ($own->fetch()) {
                flash_set('error', 'Bạn đã mua sản phẩm này rồi.');
            } else {
                $pdo->prepare("INSERT IGNORE INTO cart_items (user_id, item_type, item_id) VALUES (?, 'code', ?)")->execute([$uid, $itemId]);
                flash_set('success', 'Đã thêm vào giỏ hàng.');
            }
        }
        redirect('/cart');
    }
    // Xoa khoi gio
    if (isset($_POST['remove'])) {
        $pdo->prepare('DELETE FROM cart_items WHERE id=? AND user_id=?')->execute([(int)$_POST['remove'], $uid]);
        redirect('/cart');
    }
    // Ap ma giam gia
    if (isset($_POST['apply_coupon'])) {
        $code = trim($_POST['coupon_code'] ?? '');
        $items = cart_items_of($pdo, $uid);
        $subtotal = array_sum(array_map(fn($i) => (float)$i['price'], $items));
        try {
            $res = validate_coupon($pdo, $code, $uid, $subtotal);
            $_SESSION['cart_coupon'] = ['id' => $res['coupon']['id'], 'code' => $res['coupon']['code']];
            flash_set('success', 'Đã áp dụng mã giảm giá: giảm ' . money($res['discount']));
        } catch (Exception $ex) {
            unset($_SESSION['cart_coupon']);
            flash_set('error', $ex->getMessage());
        }
        redirect('/cart');
    }
    if (isset($_POST['remove_coupon'])) {
        unset($_SESSION['cart_coupon']);
        redirect('/cart');
    }
    // Thanh toan
    if (isset($_POST['checkout'])) {
        $items = cart_items_of($pdo, $uid);
        if (!$items) { flash_set('error', 'Giỏ hàng trống.'); redirect('/cart'); }
        $subtotal = array_sum(array_map(fn($i) => (float)$i['price'], $items));
        // Tinh giam gia (neu co ma)
        $couponId = 0; $couponCode = null; $discount = 0.0;
        if (!empty($_SESSION['cart_coupon'])) {
            try {
                $res = validate_coupon($pdo, $_SESSION['cart_coupon']['code'], $uid, $subtotal);
                $couponId = (int)$res['coupon']['id'];
                $couponCode = $res['coupon']['code'];
                $discount = (float)$res['discount'];
            } catch (Exception $ex) {
                unset($_SESSION['cart_coupon']);
                flash_set('error', 'Mã giảm giá: ' . $ex->getMessage());
                redirect('/cart');
            }
        }
        $ok = 0; $fail = 0; $firstDone = false; $errMsg = '';
        foreach ($items as $it) {
            $options = ['license_type' => 'standard', 'warranty_type' => 'standard', 'install_addon' => false];
            // Ap ma giam gia vao don dau tien
            if (!$firstDone && $couponId > 0 && $discount > 0) {
                $options['coupon_id'] = $couponId;
                $options['coupon_discount'] = $discount;
                $options['coupon_code'] = $couponCode;
            }
            try {
                process_code_purchase($pdo, $uid, $it, $options);
                $pdo->prepare('DELETE FROM cart_items WHERE id=? AND user_id=?')->execute([$it['cart_id'], $uid]);
                $ok++;
                if (!$firstDone && $couponId > 0 && $discount > 0) $firstDone = true;
            } catch (Exception $ex) {
                $fail++;
                $errMsg = $ex->getMessage();
            }
        }
        unset($_SESSION['cart_coupon']);
        if ($ok > 0 && $fail === 0) flash_set('success', "Thanh toán thành công $ok sản phẩm! Xem trong Đơn đã mua.");
        elseif ($ok > 0) flash_set('success', "Mua thành công $ok sản phẩm, $fail thất bại ($errMsg).");
        else flash_set('error', 'Thanh toán thất bại: ' . $errMsg);
        redirect($ok > 0 ? '/my_orders' : '/cart');
    }
}

$items = cart_items_of($pdo, $uid);
$subtotal = array_sum(array_map(fn($i) => (float)$i['price'], $items));
$discount = 0.0; $couponCode = null;
if (!empty($_SESSION['cart_coupon'])) {
    try {
        $res = validate_coupon($pdo, $_SESSION['cart_coupon']['code'], $uid, $subtotal);
        $discount = (float)$res['discount'];
        $couponCode = $res['coupon']['code'];
    } catch (Exception $ex) { unset($_SESSION['cart_coupon']); }
}
$total = max(0, $subtotal - $discount);
$me = get_user($pdo, $uid);

$page_title = 'Giỏ hàng';
require_once __DIR__ . '/includes/header.php';
?>
<div class="section"><div class="container">
  <div class="section-head"><h2>🛒 Giỏ hàng</h2></div>
  <?php if (!$items): ?>
    <div class="empty">Giỏ hàng trống. <a href="<?= BASE_URL ?>/index">Khám phá sản phẩm →</a></div>
  <?php else: ?>
  <div class="cm-cart-grid">
    <div>
      <?php foreach ($items as $it): ?>
        <div class="cm-cart-item">
          <?php if (!empty($it['demo_image'])): ?><?php else: ?><div class="cm-cart-ph">&lt;/&gt;</div><?php endif; ?>
          <div class="cm-cart-info">
            <a href="<?= BASE_URL ?>/code_view?id=<?= $it['id'] ?>"><strong><?= e($it['title']) ?></strong></a>
            <div class="hint"><?= e($it['category']) ?></div>
          </div>
          <div class="cm-cart-price"><?= money($it['price']) ?></div>
          <form method="post"><?= csrf_input() ?><input type="hidden" name="remove" value="<?= $it['cart_id'] ?>"><button class="cm-cart-x" title="Xoá">×</button></form>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="cm-cart-summary">
      <h3>Tóm tắt đơn</h3>
      <div class="cm-sum-row"><span>Tạm tính (<?= count($items) ?> sp)</span><span><?= money($subtotal) ?></span></div>
      <?php if ($discount > 0): ?><div class="cm-sum-row" style="color:#16a34a"><span>Giảm giá (<?= e($couponCode) ?>)</span><span>-<?= money($discount) ?></span></div><?php endif; ?>
      <div class="cm-sum-row cm-sum-total"><span>Tổng cộng</span><span><?= money($total) ?></span></div>
      <p class="hint">Số dư ví: <?= money($me['wallet_balance']) ?></p>
      <?php if (!empty($_SESSION['cart_coupon'])): ?>
        <form method="post" style="margin:10px 0;"><?= csrf_input() ?>
          <button class="btn btn-sm btn-outline btn-block" name="remove_coupon" value="1">Xoá mã "<?= e($couponCode) ?>"</button></form>
      <?php else: ?>
        <form method="post" style="display:flex;gap:6px;margin:10px 0;"><?= csrf_input() ?>
          <input type="text" name="coupon_code" placeholder="Mã giảm giá" style="flex:1;text-transform:uppercase;">
          <button class="btn btn-sm btn-outline" name="apply_coupon" value="1">Áp dụng</button></form>
      <?php endif; ?>
      <form method="post"><?= csrf_input() ?>
        <button class="btn btn-primary btn-block" name="checkout" value="1" <?= $me['wallet_balance'] < $total ? '' : '' ?>>Thanh toán bằng ví</button>
      </form>
      <?php if ($me['wallet_balance'] < $total): ?>
        <p class="hint" style="color:#dc2626;margin-top:8px;">Số dư không đủ. <a href="<?= BASE_URL ?>/wallet">Nạp tiền →</a></p>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div></div>
<style>
.cm-cart-grid{display:grid;grid-template-columns:1fr 320px;gap:24px;align-items:start}
.cm-cart-item{display:flex;align-items:center;gap:14px;padding:12px;border:1px solid var(--border);border-radius:12px;margin-bottom:10px}
.cm-cart-item img,.cm-cart-ph{width:70px;height:52px;border-radius:8px;object-fit:cover;flex:none}
.cm-cart-ph{display:flex;align-items:center;justify-content:center;background:#f1f5f9;color:#cbd5e1;font-family:monospace}
.cm-cart-info{flex:1}.cm-cart-info a{text-decoration:none;color:inherit}
.cm-cart-price{font-weight:700;color:var(--accent)}
.cm-cart-x{border:none;background:#f3f4f6;width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:16px;line-height:1}
.cm-cart-summary{border:1px solid var(--border);border-radius:14px;padding:18px;position:sticky;top:90px}
.cm-cart-summary h3{font-size:16px;margin:0 0 12px}
.cm-sum-row{display:flex;justify-content:space-between;margin:8px 0;font-size:14px}
.cm-sum-total{font-size:18px;font-weight:700;border-top:1px solid var(--border);padding-top:10px;margin-top:10px}
@media(max-width:768px){.cm-cart-grid{grid-template-columns:1fr}.cm-cart-summary{position:static}}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
