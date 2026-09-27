<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$user = get_user($pdo, $id);
if (!$user) { http_response_code(404); die('Không tìm thấy người dùng.'); }

$tab = $_GET['tab'] ?? 'code';

$codeItems = $pdo->prepare("SELECT * FROM code_listings WHERE user_id = ? ORDER BY created_at DESC");
$codeItems->execute([$id]);
$codeItems = $codeItems->fetchAll();

$soldCount = $pdo->prepare("SELECT COUNT(*) c FROM orders WHERE seller_id = ?");
$soldCount->execute([$id]);
$soldCount = $soldCount->fetch()['c'];

// ===== Dữ liệu riêng cho chủ trang: Code yêu thích + Doanh thu bán code =====
$isOwner = is_logged_in() && current_user_id() == $id;
$favItems = [];
$salesByDay = [];
$revTotal = 0; $recvTotal = 0; $dlTotal = 0;
if ($isOwner) {
    try {
        $fav = $pdo->prepare("SELECT cl.*, f.created_at AS fav_at,
            (SELECT COUNT(*) FROM orders o WHERE o.item_type='code' AND o.item_id=cl.id AND o.status='completed') AS dl
            FROM favorites f JOIN code_listings cl ON cl.id = f.code_id
            WHERE f.user_id = ? ORDER BY f.created_at DESC");
        $fav->execute([$id]);
        $favItems = $fav->fetchAll();
    } catch (Exception $e) { $favItems = []; }

    $sal = $pdo->prepare("SELECT DATE(created_at) AS d, COUNT(*) AS dl, SUM(price) AS revenue, SUM(seller_amount) AS received
        FROM orders WHERE seller_id = ? AND item_type='code' AND status='completed'
        GROUP BY DATE(created_at) ORDER BY d DESC");
    $sal->execute([$id]);
    $salesByDay = $sal->fetchAll();
    foreach ($salesByDay as $r) { $revTotal += $r['revenue']; $recvTotal += $r['received']; $dlTotal += $r['dl']; }
}

$page_title = $user['username'] . ' - Hồ sơ';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container">
    <div class="profile-head">
        <img class="avatar avatar-lg" src="<?= $user['avatar'] ? UPLOAD_URL_AVATAR.e($user['avatar']) : 'https://api.dicebear.com/7.x/identicon/svg?seed='.urlencode($user['username']) ?>" alt="">
        <div>
            <div class="profile-name"><?= e($user['full_name'] ?: $user['username']) ?> ☑️</div>
            <?php if ($user['bio']): ?><div class="profile-bio"><?= e($user['bio']) ?></div><?php endif; ?>
            <div class="profile-stats">
                <span>📦 <b><?= count($codeItems) ?></b> code</span>
                <span>✅ <b><?= $soldCount ?></b> đã bán thành công</span>
                <span>🗓 Tham gia <b><?= date('m/Y', strtotime($user['created_at'])) ?></b></span>
            </div>
            <?php
            $__socials = [
                ['k'=>'facebook','ic'=>'📘','lb'=>'Facebook'],
                ['k'=>'zalo','ic'=>'💬','lb'=>'Zalo'],
                ['k'=>'telegram','ic'=>'✈️','lb'=>'Telegram'],
                ['k'=>'website','ic'=>'🔗','lb'=>'Website'],
            ];
            $__has = false;
            foreach ($__socials as $__s) { if (!empty($user[$__s['k']])) { $__has = true; break; } }
            ?>
            <?php if ($__has): ?>
            <div class="profile-socials">
            <?php foreach ($__socials as $__s): $__v = trim($user[$__s['k']] ?? ''); if ($__v === '') continue;
                if (preg_match('~^https?://~i', $__v)) { $__href = $__v; }
                elseif ($__s['k']==='zalo') { $__href = 'https://zalo.me/' . preg_replace('~[^0-9]~','',$__v); }
                elseif ($__s['k']==='telegram') { $__href = 'https://t.me/' . ltrim($__v,'@'); }
                else { $__href = 'https://' . $__v; }
            ?>
                <a class="social-btn social-<?= $__s['k'] ?>" href="<?= e($__href) ?>" target="_blank" rel="noopener"><?= $__s['ic'] ?> <?= $__s['lb'] ?></a>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php if (is_logged_in() && current_user_id() == $id): ?>
            <div style="margin-left:auto; display:flex; gap:8px; flex-wrap:wrap;">
                <a href="<?= BASE_URL ?>/dashboard?tab=settings" class="btn btn-outline">Chỉnh sửa hồ sơ</a>
                <a href="<?= BASE_URL ?>/wallet" class="btn btn-primary">👛 Ví &amp; Rút tiền</a>
            </div>
        <?php endif; ?>
    </div>

    <div class="section">
        <div class="section-head"><h2>Danh mục Code (<?= count($codeItems) ?>)</h2></div>
        <?php if (!$codeItems): ?>
            <div class="empty"><div class="icon">{ }</div>Người dùng này chưa đăng bán code nào.</div>
        <?php else: ?>
        <div class="grid">
            <?php foreach ($codeItems as $item): ?>
            <a class="card" href="<?= BASE_URL ?>/code_view?id=<?= $item['id'] ?>">
               
                <div class="card-thumb">
                    <?php if ($item['demo_image']): ?><img src="<?= UPLOAD_URL_CODE.e($item['demo_image']) ?>" alt=""><?php else: ?><div class="noimg">// no preview</div><?php endif; ?>
                    <span class="badge <?= $item['status']==='active' ? 'badge-code':'badge-sold' ?>"><?= $item['status']==='active' ? 'CODE' : 'ĐÃ BÁN' ?></span>
                </div>
                <div class="card-body">
                    <div class="card-title"><?= e($item['title']) ?></div>
                    <div class="card-meta"><span></span><span class="price-tag mono"><?= number_format($item['price'],0,',','.') ?>đ</span></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($isOwner): ?>
    <div class="section" id="favorites">
        <div class="section-head"><h2>❤️ Code yêu thích đã lưu (<?= count($favItems) ?>)</h2></div>
        <p class="hint" style="margin-bottom:14px;">Danh sách code yêu thích bạn đã lưu lại.</p>
        <?php if (!$favItems): ?>
            <div class="empty">Bạn chưa lưu code nào. Bấm nút 🤍 <b>Lưu yêu thích</b> ở trang sản phẩm để lưu lại.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <tr><th>Ngày lưu</th><th>Mã code</th><th>Ảnh</th><th>Source code</th><th>Phí download</th><th>Lượt tải</th><th></th></tr>
            <?php foreach ($favItems as $f): ?>
            <tr>
                <td class="mono"><?= date('d/m/Y', strtotime($f['fav_at'])) ?></td>
                <td><a href="<?= BASE_URL ?>/code_view?id=<?= (int)$f['id'] ?>" style="color:var(--text)">#<?= (int)$f['id'] ?> · <?= e($f['title']) ?></a></td>
                <td><?php if ($f['demo_image']): ?><img src="<?= UPLOAD_URL_CODE.e($f['demo_image']) ?>" alt="" style="width:64px;height:42px;object-fit:cover;border-radius:6px;"><?php else: ?><span class="hint">—</span><?php endif; ?></td>
                <td><a href="<?= BASE_URL ?>/code_view?id=<?= (int)$f['id'] ?>" class="btn btn-outline btn-sm">Xem</a></td>
                <td class="mono"><?= money($f['price']) ?></td>
                <td><?= (int)$f['dl'] ?></td>
                <td>
                    <form method="post" action="<?= BASE_URL ?>/favorite_toggle" style="display:inline;">
                        <?= csrf_input() ?>
                        <input type="hidden" name="code_id" value="<?= (int)$f['id'] ?>">
                        <input type="hidden" name="back" value="/profile.php?id=<?= (int)$id ?>#favorites">
                        <button class="cm-fav-btn active" title="Bỏ lưu">❤️</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="section" id="revenue">
        <div class="section-head"><h2>💵 Doanh thu bán code</h2></div>
        <p class="hint" style="margin-bottom:14px;">Lịch sử và thống kê doanh thu bán code của bạn.</p>
        <div class="stat-cards" style="margin-bottom:16px;">
            <div class="stat-card"><div class="label">TỔNG LƯỢT TẢI/BÁN</div><div class="value mono"><?= number_format($dlTotal) ?></div></div>
            <div class="stat-card"><div class="label">TỔNG DOANH THU</div><div class="value mono"><?= money($revTotal) ?></div></div>
            <div class="stat-card"><div class="label">THỰC NHẬN</div><div class="value mono"><?= money($recvTotal) ?></div></div>
        </div>
        <?php if (!$salesByDay): ?>
            <div class="empty">Chưa có doanh thu bán code.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <tr><th>STT</th><th>Thời gian</th><th>Lượt download</th><th>Doanh thu</th><th>Thực nhận</th></tr>
            <?php foreach ($salesByDay as $i => $r): ?>
            <tr>
                <td><?= $i+1 ?></td>
                <td class="mono"><?= date('d/m/Y', strtotime($r['d'])) ?></td>
                <td><?= (int)$r['dl'] ?></td>
                <td class="mono"><?= money($r['revenue']) ?></td>
                <td class="mono"><?= money($r['received']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>