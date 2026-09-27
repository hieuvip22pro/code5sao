<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/hosting_functions.php';
require_once __DIR__ . '/includes/security_functions.php';
require_once __DIR__ . '/includes/review_functions.php';
require_once __DIR__ . '/includes/seo_functions.php';
require_login();

$uid  = current_user_id();
$tab  = $_GET['tab'] ?? 'overview';
$me   = get_user($pdo, $uid);
$errors = [];

// Cập nhật hồ sơ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    csrf_verify();
    $full_name = trim($_POST['full_name'] ?? '');
    $bio       = trim($_POST['bio'] ?? '');
    $facebook = trim($_POST['facebook'] ?? '');
    $zalo     = trim($_POST['zalo'] ?? '');
    $telegram = trim($_POST['telegram'] ?? '');
    $website  = trim($_POST['website'] ?? '');
    // Giới hạn độ dài để chống spam
    $full_name = mb_substr($full_name, 0, 100);
    $bio       = mb_substr($bio, 0, 500);
    try {
        $avatar = upload_image('avatar', UPLOAD_PATH_AVATAR, 'avt');
        if ($avatar) {
            $pdo->prepare('UPDATE users SET full_name=?, bio=?, facebook=?, zalo=?, telegram=?, website=?, avatar=? WHERE id=?')->execute([$full_name, $bio, $facebook, $zalo, $telegram, $website, $avatar, $uid]);
        } else {
            $pdo->prepare('UPDATE users SET full_name=?, bio=?, facebook=?, zalo=?, telegram=?, website=? WHERE id=?')->execute([$full_name, $bio, $facebook, $zalo, $telegram, $website, $uid]);
        }
        flash_set('success', 'Đã cập nhật hồ sơ.');
        redirect('/dashboard?tab=settings');
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

// Đổi mật khẩu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    csrf_verify();
    $cur = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $cf  = $_POST['confirm_password'] ?? '';
    if (!empty($me['password_hash']) && !password_verify($cur, $me['password_hash'])) {
        $errors[] = 'Mật khẩu hiện tại không đúng.';
    } elseif (strlen($new) < 8) {
        $errors[] = 'Mật khẩu mới phải có ít nhất 8 ký tự.';
    } elseif ($new !== $cf) {
        $errors[] = 'Xác nhận mật khẩu mới không khớp.';
    } else {
        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
        flash_set('success', 'Đã đổi mật khẩu thành công.');
        redirect('/dashboard?tab=settings');
    }
}

// Đổi email - bước 1: gửi mã xác thực tới email mới
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_email_change'])) {
    csrf_verify();
    $newEmail = trim($_POST['new_email'] ?? '');
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email mới không hợp lệ.';
    } elseif (strcasecmp($newEmail, $me['email']) === 0) {
        $errors[] = 'Email mới trùng với email hiện tại.';
    } else {
        $chk = $pdo->prepare('SELECT id FROM users WHERE email=? AND id<>?');
        $chk->execute([$newEmail, $uid]);
        if ($chk->fetch()) {
            $errors[] = 'Email này đã được sử dụng bởi tài khoản khác.';
        } else {
            $code = security_generate_code(6);
            $pdo->prepare('UPDATE users SET pending_email=?, pending_email_code=?, pending_email_expires=DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id=?')->execute([$newEmail, $code, $uid]);
            send_otp_email($newEmail, $code, 'Xác nhận đổi email');
            flash_set('success', 'Đã gửi mã xác thực tới ' . $newEmail . '. Nhập mã để hoàn tất đổi email.');
            redirect('/dashboard?tab=settings');
        }
    }
}

// Đổi email - bước 2: xác nhận mã
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_email_change'])) {
    csrf_verify();
    $code = trim($_POST['email_code'] ?? '');
    if (empty($me['pending_email'])) {
        $errors[] = 'Không có yêu cầu đổi email nào đang chờ.';
    } elseif (empty($me['pending_email_expires']) || strtotime($me['pending_email_expires']) < time()) {
        $errors[] = 'Mã xác thực đã hết hạn, vui lòng gửi lại.';
    } elseif (!hash_equals((string)$me['pending_email_code'], $code)) {
        $errors[] = 'Mã xác thực không đúng.';
    } else {
        $chk = $pdo->prepare('SELECT id FROM users WHERE email=? AND id<>?');
        $chk->execute([$me['pending_email'], $uid]);
        if ($chk->fetch()) {
            $errors[] = 'Email này vừa được người khác sử dụng. Vui lòng chọn email khác.';
        } else {
            $pdo->prepare('UPDATE users SET email=?, email_verified=1, pending_email=NULL, pending_email_code=NULL, pending_email_expires=NULL WHERE id=?')->execute([$me['pending_email'], $uid]);
            flash_set('success', 'Đã đổi email thành công.');
            redirect('/dashboard?tab=settings');
        }
    }
}

// Vô hiệu hoá / kích hoạt lại sản phẩm code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_code'])) {
    csrf_verify();
    $cid = (int)$_POST['toggle_code'];
    $stmt = $pdo->prepare('SELECT * FROM code_listings WHERE id=? AND user_id=?');
    $stmt->execute([$cid, $uid]);
    if ($row = $stmt->fetch()) {
        $new = $row['status'] === 'disabled' ? 'active' : 'disabled';
        if (in_array($row['status'], ['active','disabled'], true)) {
            $pdo->prepare('UPDATE code_listings SET status=? WHERE id=?')->execute([$new, $cid]);
        }
    }
    redirect('/dashboard?tab=code');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_web'])) {
    csrf_verify();
    $wid = (int)$_POST['toggle_web'];
    $stmt = $pdo->prepare('SELECT * FROM web_listings WHERE id=? AND user_id=?');
    $stmt->execute([$wid, $uid]);
    if ($row = $stmt->fetch()) {
        $new = $row['status'] === 'disabled' ? 'active' : 'disabled';
        if ($row['status'] !== 'rented') {
            $pdo->prepare('UPDATE web_listings SET status=? WHERE id=?')->execute([$new, $wid]);
        }
    }
    redirect('/dashboard?tab=web');
}

// Xóa bình luận/đánh giá của chính mình
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_review'])) {
    csrf_verify();
    delete_review($pdo, (int)$_POST['delete_review'], $uid);
    flash_set('success', 'Đã xóa bình luận/đánh giá.');
    redirect('/dashboard?tab=reviews&sub=mine');
}

// Bật/tắt tự động gia hạn cho 1 gói hosting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_auto_renew'])) {
    csrf_verify();
    $hid = (int)$_POST['toggle_auto_renew'];
    $stmt = $pdo->prepare('SELECT * FROM hosting_accounts WHERE id=? AND user_id=?');
    $stmt->execute([$hid, $uid]);
    if ($row = $stmt->fetch()) {
        $new = $row['auto_renew'] ? 0 : 1;
        $pdo->prepare('UPDATE hosting_accounts SET auto_renew=? WHERE id=?')->execute([$new, $hid]);
    }
    redirect('/dashboard?tab=hosting');
}

$myCode = $pdo->prepare('SELECT * FROM code_listings WHERE user_id=? ORDER BY created_at DESC');
$myCode->execute([$uid]); $myCode = $myCode->fetchAll();

$myHosting = $pdo->prepare('SELECT ha.*, hp.name as plan_name, hp.price_month FROM hosting_accounts ha JOIN hosting_plans hp ON hp.id = ha.plan_id WHERE ha.user_id=? ORDER BY ha.created_at DESC');
$myHosting->execute([$uid]); $myHosting = $myHosting->fetchAll();

$myPurchases = $pdo->prepare('SELECT o.*, u.username as seller_name FROM orders o JOIN users u ON u.id = o.seller_id WHERE o.buyer_id=? ORDER BY o.created_at DESC');
$myPurchases->execute([$uid]); $myPurchases = $myPurchases->fetchAll();

$mySales = $pdo->prepare('SELECT o.*, u.username as buyer_name FROM orders o JOIN users u ON u.id = o.buyer_id WHERE o.seller_id=? ORDER BY o.created_at DESC');
$mySales->execute([$uid]); $mySales = $mySales->fetchAll();

// Bình luận & đánh giá
$reviewsReceived = $pdo->prepare('SELECT r.*, u.username, u.avatar, c.title AS code_title FROM reviews r JOIN code_listings c ON c.id = r.code_listing_id JOIN users u ON u.id = r.buyer_id WHERE c.user_id = ? ORDER BY r.created_at DESC');
$reviewsReceived->execute([$uid]); $reviewsReceived = $reviewsReceived->fetchAll();
$reviewsMine = $pdo->prepare('SELECT r.*, c.title AS code_title FROM reviews r JOIN code_listings c ON c.id = r.code_listing_id WHERE r.buyer_id = ? ORDER BY r.created_at DESC');
$reviewsMine->execute([$uid]); $reviewsMine = $reviewsMine->fetchAll();

$totalEarned = array_sum(array_column($mySales, 'seller_amount'));
$totalSpent  = array_sum(array_column($myPurchases, 'price'));

$page_title = 'Bảng điều khiển';
require_once __DIR__ . '/includes/header.php';
?>
<div class="section">
    <div class="container">
        <div class="dash-layout">
            <div class="dash-nav">
                <a href="?tab=overview" class="<?= $tab==='overview'?'active':'' ?>">📊 Tổng quan</a>
                <a href="?tab=code" class="<?= $tab==='code'?'active':'' ?>">📦 Code của tôi</a>
                <a href="?tab=reviews" class="<?= $tab==='reviews'?'active':'' ?>">💬 Bình luận & Đánh giá</a>
                <a href="?tab=hosting" class="<?= $tab==='hosting'?'active':'' ?>">🖥 Hosting của tôi</a>
                <a href="?tab=purchases" class="<?= $tab==='purchases'?'active':'' ?>">🛒 Đơn đã mua</a>
                <a href="?tab=sales" class="<?= $tab==='sales'?'active':'' ?>">💵 Đơn đã bán</a>
                <a href="?tab=settings" class="<?= $tab==='settings'?'active':'' ?>">⚙️ Cài đặt hồ sơ</a>
                <a href="<?= BASE_URL ?>/security">🔐 Bảo mật</a>
                <a href="<?= BASE_URL ?>/wallet">👛 Ví &amp; giao dịch</a>
            </div>

            <div>
            <?php if ($tab === 'overview'): ?>
                <div class="stat-cards">
                    <div class="stat-card"><div class="label">SỐ DƯ VÍ</div><div class="value mono"><?= money($me['wallet_balance']) ?></div></div>
                    <div class="stat-card"><div class="label">ĐÃ KIẾM ĐƯỢC</div><div class="value mono"><?= money($totalEarned) ?></div></div>
                    <div class="stat-card"><div class="label">ĐÃ CHI TIÊU</div><div class="value mono"><?= money($totalSpent) ?></div></div>
                    <div class="stat-card"><div class="label">SẢN PHẨM CODE ĐANG ĐĂNG</div><div class="value mono"><?= count($myCode) ?></div></div>
                    <div class="stat-card"><div class="label">GÓI HOSTING ĐANG DÙNG</div><div class="value mono"><?= count(array_filter($myHosting, fn($h) => $h['status'] !== 'terminated')) ?></div></div>
                </div>
                <div style="display:flex; gap:12px;">
                    <a href="<?= BASE_URL ?>/code_create" class="btn btn-primary">+ Đăng bán Code</a>
                    <a href="<?= BASE_URL ?>/hosting" class="btn btn-accent">+ Đăng ký Hosting</a>
                </div>

            <?php elseif ($tab === 'code'): ?>
                <div class="section-head"><h2>Code của tôi</h2><a href="<?= BASE_URL ?>/code_create" class="btn btn-primary btn-sm">+ Đăng mới</a></div>
                <?php if (!$myCode): ?><div class="empty">Bạn chưa đăng sản phẩm code nào.</div><?php else: ?>
                <table>
                    <tr><th>Sản phẩm</th><th>Giá</th><th>Lượt xem</th><th>Trạng thái</th><th></th></tr>
                    <?php foreach ($myCode as $c): ?>
                    <tr>
                        <td><a href="<?= code_url($c['id'], $c['title']) ?>" style="color:var(--text)"><?= e($c['title']) ?></a></td>
                        <td class="mono"><?= money($c['price']) ?></td>
                        <td><?= (int)$c['views'] ?></td>
                        <td>
                            <?php
                                $__stMap = ['pending'=>['⏳ Chờ duyệt','sold_hidden'],'active'=>['✅ Đang bán','active'],'disabled'=>['⏸ Đang ẩn','disabled'],'rejected'=>['❌ Bị từ chối','disabled'],'sold_hidden'=>['💰 Đã bán','sold_hidden']];
                                $__st = $__stMap[$c['status']] ?? [$c['status'], $c['status']];
                            ?>
                            <span class="tag tag-<?= e($__st[1]) ?>"><?= e($__st[0]) ?></span>
                        </td>
                        <td>
                            <a href="<?= BASE_URL ?>/code_edit?id=<?= $c['id'] ?>" class="btn btn-outline btn-sm">Sửa</a>
                            <?php if (in_array($c['status'], ['active','disabled'], true)): ?>
                            <form method="post" style="display:inline;">
                                <button class="btn btn-outline btn-sm" name="toggle_code" value="<?= $c['id'] ?>"><?= $c['status']==='disabled'?'Bật lại':'Ẩn' ?></button>
                            </form>
                            <?php elseif ($c['status'] === 'rejected'): ?>
                            <span class="hint" style="font-size:12px;">Bị từ chối — sửa lại để gửi duyệt lại</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php endif; ?>

            <?php elseif ($tab === 'reviews'): ?>
                <?php $rsub = $_GET['sub'] ?? 'received'; ?>
                <div class="section-head"><h2>💬 Bình luận & Đánh giá</h2></div>
                <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
                    <a href="?tab=reviews&sub=received" class="btn btn-sm <?= $rsub==='received'?'btn-primary':'btn-outline' ?>">📥 Đánh giá cho code của tôi (<?= count($reviewsReceived) ?>)</a>
                    <a href="?tab=reviews&sub=mine" class="btn btn-sm <?= $rsub==='mine'?'btn-primary':'btn-outline' ?>">📤 Bình luận của tôi (<?= count($reviewsMine) ?>)</a>
                </div>
                <?php if ($rsub === 'mine'): ?>
                    <?php if (!$reviewsMine): ?><div class="empty">Bạn chưa viết bình luận/đánh giá nào.</div><?php else: ?>
                    <div style="overflow-x:auto;">
                    <table>
                        <tr><th>Mã</th><th>Thời gian</th><th>Source code</th><th>Đánh giá</th><th>Bình luận</th><th></th></tr>
                        <?php foreach ($reviewsMine as $r): ?>
                        <tr>
                            <td class="mono">#<?= (int)$r['id'] ?></td>
                            <td class="hint"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                            <td><a href="<?= code_url($r['code_listing_id'], $r['code_title']) ?>" style="color:var(--text)"><?= e($r['code_title']) ?></a></td>
                            <td style="color:var(--accent-2)"><?= render_stars($r['rating']) ?></td>
                            <td><?= $r['comment'] ? nl2br(e($r['comment'])) : '<span class="hint">—</span>' ?></td>
                            <td style="display:flex;gap:6px;">
                                <a href="<?= BASE_URL ?>/code_view?id=<?= (int)$r['code_listing_id'] ?>&edit_review=<?= (int)$r['id'] ?>#reviews" class="btn btn-outline btn-sm">Sửa</a>
                                <form method="post" onsubmit="return confirm('Xóa bình luận này?')" style="display:inline;">
                                    <?= csrf_input() ?>
                                    <button class="btn btn-outline btn-sm" name="delete_review" value="<?= (int)$r['id'] ?>">Xóa</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if (!$reviewsReceived): ?><div class="empty">Chưa có ai đánh giá sản phẩm code của bạn.</div><?php else: ?>
                    <div style="overflow-x:auto;">
                    <table>
                        <tr><th>Mã</th><th>Thời gian</th><th>Source code</th><th>Thành viên</th><th>Đánh giá</th><th>Bình luận</th><th>Link</th></tr>
                        <?php foreach ($reviewsReceived as $r): ?>
                        <tr>
                            <td class="mono">#<?= (int)$r['id'] ?></td>
                            <td class="hint"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                            <td><a href="<?= code_url($r['code_listing_id'], $r['code_title']) ?>" style="color:var(--text)"><?= e($r['code_title']) ?></a></td>
                            <td><?= e($r['username']) ?></td>
                            <td style="color:var(--accent-2)"><?= render_stars($r['rating']) ?></td>
                            <td><?= $r['comment'] ? nl2br(e($r['comment'])) : '<span class="hint">—</span>' ?></td>
                            <td><a href="<?= code_url($r['code_listing_id'], $r['code_title']) ?>#reviews" class="btn btn-outline btn-sm">Xem</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>

            <?php elseif ($tab === 'hosting'): ?>
                <div class="section-head"><h2>Hosting của tôi</h2><a href="<?= BASE_URL ?>/hosting" class="btn btn-primary btn-sm">+ Đăng ký gói mới</a></div>
                <?php if (!$myHosting): ?><div class="empty">Bạn chưa đăng ký gói hosting nào.</div><?php else: ?>
                <div style="overflow-x:auto;">
                <table>
                    <tr><th>Domain</th><th>Gói</th><th>cPanel</th><th>Hết hạn</th><th>Trạng thái</th><th>Tự động gia hạn</th><th></th></tr>
                    <?php foreach ($myHosting as $h): $d = days_until($h['expires_at']); ?>
                    <tr>
                        <td class="mono"><?= e($h['domain']) ?></td>
                        <td><?= e($h['plan_name']) ?> <div class="hint mono"><?= money($h['price_month']) ?>/th</div></td>
                        <td>
                            <div class="mono">👤 <?= e($h['cpanel_username']) ?></div>
                            <?php if ($h['status'] !== 'terminated'): ?>
                            <div>
                                🔑 <span class="pw-value" data-pw="<?= e(decrypt_secret($h['cpanel_password'])) ?>">••••••••</span>
                                <button type="button" class="btn-link toggle-pw" style="font-size:12px;">Hiện</button>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $h['expires_at'] ? date('d/m/Y', strtotime($h['expires_at'])) : '-' ?>
                            <?php if ($d !== null && $h['status'] !== 'terminated'): ?>
                                <div class="hint" style="color:<?= $d < 0 ? 'var(--danger)' : ($d <= 5 ? 'var(--accent-2)' : 'var(--muted)') ?>">
                                    <?= $d < 0 ? 'Đã hết hạn' : $d . ' ngày nữa' ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><span class="tag tag-<?= $h['status']==='active'?'active':($h['status']==='suspended'?'sold_hidden':'disabled') ?>"><?= e($h['status']) ?></span></td>
                        <td>
                            <?php if ($h['status'] !== 'terminated'): ?>
                            <form method="post">
                                <?= csrf_input() ?>
                                <button class="btn btn-outline btn-sm" name="toggle_auto_renew" value="<?= (int)$h['id'] ?>"><?= $h['auto_renew'] ? '✅ Đang bật' : '⭕ Đang tắt' ?></button>
                            </form>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($h['status'] !== 'terminated'): ?>
                                <a href="<?= BASE_URL ?>/hosting_manage?id=<?= (int)$h['id'] ?>" class="btn btn-primary btn-sm">Quản lý</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                </div>
                <script>
                document.querySelectorAll('.toggle-pw').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const span = this.previousElementSibling;
                        if (span.textContent === '••••••••') { span.textContent = span.dataset.pw; this.textContent = 'Ẩn'; }
                        else { span.textContent = '••••••••'; this.textContent = 'Hiện'; }
                    });
                });
                </script>
                <?php endif; ?>

        <?php elseif ($tab === 'purchases'): ?>
                <div class="section-head"><h2>Đơn đã mua/thuê</h2></div>
                <?php if (!$myPurchases): ?><div class="empty">Chưa có giao dịch nào.</div><?php else: ?>
                <div style="overflow-x:auto;">
                <table>
                    <tr>
                        <th>Sản phẩm</th>
                        <th>Người bán</th>
                        <th>Chi tiết</th>
                        <th>Giá</th>
                        <th>Ngày</th>
                        <th></th>
                    </tr>
                    <?php foreach ($myPurchases as $o): ?>
                    <tr>
                        <td>
                            <?= e($o['item_title']) ?>
                            <?php if ($o['item_type']==='web' && !empty($o['months'])): ?>
                                <div class="hint">Thuê <?= (int)$o['months'] ?> tháng</div>
                            <?php endif; ?>
                        </td>
                        <td><?= e($o['seller_name']) ?></td>
                        <td>
                            <?php if ($o['item_type']==='web'): ?>
                                <?php if (!empty($o['domain_name'])): ?><div class="mono">🌐 <?= e($o['domain_name']) ?></div><?php endif; ?>
                                <?php if (!empty($o['account_username'])): ?><div>👤 <?= e($o['account_username']) ?></div><?php endif; ?>
                                <?php if (!empty($o['account_password'])): ?>
                                    <div>
                                        🔑 <span class="pw-value" data-pw="<?= e(base64_decode($o['account_password'])) ?>">••••••••</span>
                                        <button type="button" class="btn-link toggle-pw" style="font-size:12px;">Hiện</button>
                                    </div>
                                <?php endif; ?>
                            <?php elseif ($o['item_type']==='hosting'): ?>
                                <span class="hint">Xem chi tiết & thông tin cPanel ở tab "Hosting của tôi"</span>
                            <?php else: ?>
                                <a href="<?= code_url($o['item_id'], $o['item_title'] ?? '') ?>" style="color:var(--accent)">Xem sản phẩm →</a>
                                <?php if (!empty($o['license_type']) && $o['license_type'] === 'reseller'): ?>
                                    <div class="hint">🏷 Full quyền phân phối</div>
                                <?php endif; ?>
                                
                                <?php if (!empty($o['addon_install'])): ?>
                                    <div class="hint">🛠 Đã đăng ký hỗ trợ cài đặt</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="mono"><?= money($o['price']) ?></td>
                        <td><?= time_ago($o['created_at']) ?></td>
                        <td>
                            <?php if ($o['item_type']==='web'): ?>
                                <a href="<?= BASE_URL ?>/web_view?id=<?= (int)$o['item_id'] ?>" style="color:var(--accent)">Xem →</a>
                            <?php elseif ($o['item_type']==='hosting'): ?>
                                <a href="<?= BASE_URL ?>/dashboard?tab=hosting" style="color:var(--accent)">Xem →</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                </div>
                <script>
                document.querySelectorAll('.toggle-pw').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const span = this.previousElementSibling;
                        if (span.textContent === '••••••••') { span.textContent = span.dataset.pw; this.textContent = 'Ẩn'; }
                        else { span.textContent = '••••••••'; this.textContent = 'Hiện'; }
                    });
                });
                </script>
                <?php endif; ?>

            <?php elseif ($tab === 'sales'): ?>
                <div class="section-head"><h2>Đơn đã bán/cho thuê</h2></div>
                <?php if (!$mySales): ?><div class="empty">Chưa có giao dịch nào.</div><?php else: ?>
                <table>
                    <tr><th>Sản phẩm</th><th>Loại</th><th>Người mua</th><th>Giá</th><th>Bạn nhận (80%)</th><th>Ngày</th></tr>
                    <?php foreach ($mySales as $o): ?>
                    <tr>
                        <td><?= e($o['item_title']) ?></td>
                        <td><span class="tag tag-active"><?= $o['item_type']==='code'?'Code':($o['item_type']==='hosting'?'Hosting':'Web') ?></span></td>
                        <td><?= e($o['buyer_name']) ?></td>
                        <td class="mono"><?= money($o['price']) ?></td>
                        <td class="mono" style="color:var(--ok)"><?= money($o['seller_amount']) ?></td>
                        <td><?= time_ago($o['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php endif; ?>

            <?php elseif ($tab === 'settings'): ?>
                <style>
                  .set-grid { display:grid; grid-template-columns: minmax(0,1.5fr) minmax(0,1fr); gap:18px; align-items:start; }
                  .set-col { display:flex; flex-direction:column; gap:18px; }
                  @media (max-width: 992px){ .set-grid { grid-template-columns:1fr; } }
                </style>
                <div class="set-grid">
                <div class="form-card" style="margin:0;">
                    <h2>Cài đặt hồ sơ</h2>
                    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
                    <form method="post" enctype="multipart/form-data">
                        <?= csrf_input() ?>
                        <input type="hidden" name="update_profile" value="1">
                        <div class="form-group">
                            <label>Ảnh đại diện</label>
                            <img class="avatar" style="width:60px;height:60px;margin-bottom:10px;" src="<?= $me['avatar'] ? UPLOAD_URL_AVATAR.e($me['avatar']) : 'https://api.dicebear.com/7.x/identicon/svg?seed='.urlencode($me['username']) ?>">
                            <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp">
                        </div>
                        <div class="form-group">
                            <label>Họ tên hiển thị</label>
                            <input type="text" name="full_name" value="<?= e($me['full_name']) ?>" maxlength="100">
                        </div>
                       
                        
                        <button class="btn btn-primary" type="submit">Lưu thay đổi</button>
                    </form>
                </div>

                <div class="set-col">
                <div class="form-card" style="margin:0;">
                    <h2>Đổi email</h2>
                    <p class="hint">Email hiện tại: <b><?= e($me['email']) ?></b> <?= is_email_verified($me) ? '<span style="color:#16a34a;">(đã xác thực)</span>' : '<span style="color:#dc2626;">(chưa xác thực)</span>' ?></p>
                    <form method="post" style="margin-top:10px;">
                        <?= csrf_input() ?>
                        <div class="form-group">
                            <label>Email mới</label>
                            <input type="email" name="new_email" placeholder="you@example.com" required>
                        </div>
                        <button class="btn btn-outline" type="submit" name="request_email_change" value="1">Gửi mã xác thực</button>
                    </form>
                    <?php if (!empty($me['pending_email'])): ?>
                    <form method="post" style="margin-top:14px; padding-top:14px; border-top:1px solid var(--border);">
                        <?= csrf_input() ?>
                        <p class="hint">Đang chờ xác thực cho: <b><?= e($me['pending_email']) ?></b>. Nhập mã 6 số đã gửi tới email đó.</p>
                        <div class="form-group">
                            <label>Mã xác thực</label>
                            <input type="text" name="email_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required>
                        </div>
                        <button class="btn btn-primary" type="submit" name="confirm_email_change" value="1">Xác nhận đổi email</button>
                    </form>
                    <?php endif; ?>
                </div>

                <div class="form-card" style="margin:0;">
                    <h2>Đổi mật khẩu</h2>
                    <form method="post" style="margin-top:10px;">
                        <?= csrf_input() ?>
                        <?php if (!empty($me['password_hash'])): ?>
                        <div class="form-group">
                            <label>Mật khẩu hiện tại</label>
                            <input type="password" name="current_password" required>
                        </div>
                        <?php else: ?>
                        <p class="hint">Tài khoản của bạn đăng nhập qua mạng xã hội và chưa có mật khẩu. Bạn có thể đặt mật khẩu mới bên dưới.</p>
                        <?php endif; ?>
                        <div class="row-2">
                            <div class="form-group">
                                <label>Mật khẩu mới</label>
                                <input type="password" name="new_password" minlength="8" required>
                            </div>
                            <div class="form-group">
                                <label>Nhập lại mật khẩu mới</label>
                                <input type="password" name="confirm_password" minlength="8" required>
                            </div>
                        </div>
                        <button class="btn btn-primary" type="submit" name="change_password" value="1">Đổi mật khẩu</button>
                    </form>
                </div>
                </div><!-- /set-col -->
                </div><!-- /set-grid -->
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>