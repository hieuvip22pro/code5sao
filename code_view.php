<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/review_functions.php';
require_once __DIR__ . '/includes/code_purchase_functions.php';

// Định nghĩa hàm has_purchased nếu chưa có để tránh lỗi sập trang
if (!function_exists('has_purchased')) {
    function has_purchased($pdo, $userId, $listingId) {
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE buyer_id = ? AND item_type = 'code' AND item_id = ? AND status = 'completed' LIMIT 1");
        $stmt->execute([$userId, $listingId]);
        return (bool)$stmt->fetch();
    }
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT cl.*, u.username, u.avatar, u.id as seller_id FROM code_listings cl JOIN users u ON u.id = cl.user_id WHERE cl.id = ?');
$stmt->execute([$id]);
$item = $stmt->fetch();
if (!$item) { http_response_code(404); die('Không tìm thấy sản phẩm.'); }

// Chuyen huong URL cu (?id=) sang URL than thien chuan SEO (301)
require_once __DIR__ . '/includes/seo_functions.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $__slug = code_slug($item['title']);
    $__wantPath = '/code/' . $id . ($__slug !== '' ? '-' . $__slug : '');
    $__basePath = parse_url(BASE_URL, PHP_URL_PATH);
    if ($__basePath) { $__wantPath = rtrim($__basePath, '/') . $__wantPath; }
    $__reqPath = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    if (rtrim($__reqPath, '/') !== rtrim($__wantPath, '/')) {
        // Giữ lại các tham số khác (edit_review, delete_review, csrf_token, page, ...)
        // chỉ bỏ 'id' vì đã được đưa vào path đẹp phía trên.
        $__extraParams = $_GET;
        unset($__extraParams['id']);
        $__qs = http_build_query($__extraParams);
        $__location = $__wantPath . ($__qs !== '' ? '?' . $__qs : '');
        header('Location: ' . $__location, true, 301);
        exit;
    }
}

$pdo->prepare('UPDATE code_listings SET views = views + 1 WHERE id = ?')->execute([$id]);

// Dem so luot mua (don hang hoan tat) cua san pham nay
$pcStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM orders WHERE item_type='code' AND item_id=? AND status='completed'");
$pcStmt->execute([$id]);
$purchaseCount = (int)($pcStmt->fetch()['c'] ?? 0);

$imgStmt = $pdo->prepare("SELECT image_path FROM listing_images WHERE item_type = 'code' AND item_id = ? ORDER BY sort_order ASC");
$imgStmt->execute([$id]);
$images = array_column($imgStmt->fetchAll(), 'image_path');
if (!$images && $item['demo_image']) $images = [$item['demo_image']];

$error = null; $success = null; $unlockedFile = null; $ownedOrder = null;
$reviewError = null;

if (is_logged_in()) {
    $chk = $pdo->prepare("SELECT * FROM orders WHERE buyer_id=? AND item_type='code' AND item_id=? ORDER BY created_at DESC LIMIT 1");
    $chk->execute([current_user_id(), $id]);
    $ownedOrder = $chk->fetch();
    if ($ownedOrder) $unlockedFile = $item['code_file'];
}

// Mua sản phẩm
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buy'])) {
    require_login();
    csrf_verify();
    try {
        $orderId = process_code_purchase($pdo, current_user_id(), $item, [
            'license_type'  => $_POST['license_type'] ?? 'standard',
            'warranty_type' => $_POST['warranty_type'] ?? 'standard',
            'install_addon' => isset($_POST['install_addon']),
        ]);
        flash_set('success', 'Mua thành công! Bạn có thể tải source code ngay bên dưới.');
        redirect('/code_view?id=' . $id);
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Gửi báo cáo (vi phạm bản quyền / báo lỗi) cho admin
$reportError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report'])) {
    require_login();
    try {
        csrf_verify();
        $rtype = ($_POST['report_type'] ?? 'error') === 'copyright' ? 'copyright' : 'error';
        $rmsg  = trim($_POST['report_message'] ?? '');
        if (mb_strlen($rmsg) < 10) throw new Exception('Vui lòng mô tả chi tiết (ít nhất 10 ký tự).');
        rate_limit('report_' . $id, 3, 600);
        $pdo->prepare('INSERT INTO reports (code_id, reporter_id, type, message) VALUES (?,?,?,?)')
            ->execute([$id, current_user_id(), $rtype, $rmsg]);
        $adminEmail = function_exists('get_admin_email') ? get_admin_email($pdo) : null;
        if ($adminEmail) {
            $label = $rtype === 'copyright' ? 'Vi phạm bản quyền' : 'Báo lỗi sản phẩm';
            $rlink = BASE_URL . '/code_view.php?id=' . $id;
            $rbody = '<div style="font-family:Arial,sans-serif;">'
                   . '<h3>[' . $label . '] ' . e($item['title']) . '</h3>'
                   . '<p><b>Mã sản phẩm:</b> #' . $id . ' — <a href="' . e($rlink) . '">' . e($rlink) . '</a></p>'
                   . '<p><b>Người báo cáo:</b> #' . current_user_id() . '</p>'
                   . '<p><b>Nội dung:</b><br>' . nl2br(e($rmsg)) . '</p></div>';
            @send_email($adminEmail, '[CodeMarket] ' . $label . ' - ' . $item['title'], $rbody);
        }
        flash_set('success', 'Đã gửi báo cáo tới admin. Cảm ơn bạn!');
        redirect('/code_view?id=' . $id);
    } catch (Exception $e) {
        $reportError = $e->getMessage();
    }
}

// Gửi đánh giá mới (Tối đa 3 lần)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    require_login();
    csrf_verify();
    try {
        $ratingVal = (int)($_POST['rating'] ?? 5);
        $comment = trim($_POST['comment'] ?? '');
        
        submit_review($pdo, $id, current_user_id(), $ratingVal, $comment);
        
        flash_set('success', 'Cảm ơn bạn đã đánh giá!');
        redirect('/code_view?id=' . $id . '#reviews');
    } catch (Exception $e) {
        $reviewError = $e->getMessage();
    }
}

// Xử lý Sửa đánh giá
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_review'])) {
    require_login();
    csrf_verify();
    try {
        $reviewId = (int)$_POST['review_id'];
        $ratingVal = (int)($_POST['rating'] ?? 5);
        $comment = trim($_POST['comment'] ?? '');
        
        $rev = get_review_by_id($pdo, $reviewId, current_user_id());
        if (!$rev) throw new Exception('Không tìm thấy đánh giá hoặc bạn không có quyền sửa.');

        update_review($pdo, $reviewId, current_user_id(), $ratingVal, $comment);
        flash_set('success', 'Cập nhật đánh giá thành công!');
        redirect('/code_view?id=' . $id . '#reviews');
    } catch (Exception $e) {
        $reviewError = $e->getMessage();
    }
}

// Xử lý Xóa đánh giá
if (isset($_GET['delete_review']) && is_logged_in()) {
    if (!hash_equals(csrf_token(), (string)($_GET['csrf_token'] ?? ''))) { http_response_code(403); die('Yêu cầu không hợp lệ (CSRF token mismatch).'); }
    $reviewId = (int)$_GET['delete_review'];
    try {
        $rev = get_review_by_id($pdo, $reviewId, current_user_id());
        if ($rev) {
            delete_review($pdo, $reviewId, current_user_id());
            flash_set('success', 'Đã xóa đánh giá thành công.');
        }
        redirect('/code_view?id=' . $id . '#reviews');
    } catch (Exception $e) {
        $reviewError = $e->getMessage();
    }
}

$rating = get_listing_rating($pdo, $id);
$reviews = get_listing_reviews($pdo, $id);

// Kiểm tra xem user hiện tại đang muốn sửa đánh giá nào không
$editReviewId = isset($_GET['edit_review']) ? (int)$_GET['edit_review'] : 0;
$editingReviewData = null;
if ($editReviewId && is_logged_in()) {
    $editingReviewData = get_review_by_id($pdo, $editReviewId, current_user_id());
}

$canReviewNow = is_logged_in() && can_review($pdo, current_user_id(), $id);

// ===== SEO cho trang chi tiet code =====
require_once __DIR__ . '/includes/seo_functions.php';
$__base   = rtrim(BASE_URL, '/');
$canonical = $__base . '/code/' . $id . '-' . code_slug($item['title']);
$meta_description = seo_desc($item['description'], 160);
$__kw = array_values(array_filter(array_map('trim', preg_split('/[,\/]+/', (string)$item['category']))));
$meta_keywords = trim($item['title'] . ($__kw ? ', ' . implode(', ', $__kw) : '') . ', source code, mã nguồn, download code, mua code');
$og_type = 'product';
if ($images) {
    $__firstImg = $images[0];
    if (preg_match('#^https?://#', $__firstImg)) {
        $og_image = $__firstImg;
    } else {
        // Loại bỏ tên miền ra khỏi UPLOAD_URL_CODE nếu lỡ có sẵn để chống lặp
        $__cleanUploadUrl = preg_replace('#^https?://[^/]+#', '', UPLOAD_URL_CODE);
        $og_image = $__base . '/' . trim($__cleanUploadUrl, '/') . '/' . ltrim($__firstImg, '/');
    }
}

// Du lieu co cau truc Product + Breadcrumb cho Google
$__ld = [
    '@context'    => 'https://schema.org',
    '@type'       => 'Product',
    'name'        => $item['title'],
    'description' => $meta_description,
    'sku'         => 'CODE-' . $id,
    'category'    => $item['category'] ?: 'Source code',
    'url'         => $canonical,
    'brand'       => ['@type' => 'Brand', 'name' => 'CodeMarket'],
];
if (!empty($og_image)) $__ld['image'] = [$og_image];
$__ld['offers'] = [
    '@type'         => 'Offer',
    'price'         => (string)(int)$item['price'],
    'priceCurrency' => 'VND',
    'availability'  => 'https://schema.org/InStock',
    'url'           => $canonical,
];
if (!empty($rating) && $rating['count'] > 0) {
    $__ld['aggregateRating'] = [
        '@type'       => 'AggregateRating',
        'ratingValue' => (string)$rating['avg'],
        'reviewCount' => (int)$rating['count'],
        'bestRating'  => '5',
        'worstRating' => '1',
    ];
}
$__breadcrumb = [
    '@context' => 'https://schema.org',
    '@type'    => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Trang chủ',   'item' => $__base . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Source code', 'item' => $__base . '/index?type=code'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $item['title'], 'item' => $canonical],
    ],
];
$json_ld = json_encode([$__ld, $__breadcrumb], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$page_title = $item['title'];
require_once __DIR__ . '/includes/header.php';
?>
<div class="section">
    <div class="container">
        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <?php if ($reviewError): ?><div class="alert alert-error"><?= e($reviewError) ?></div><?php endif; ?>
        <?php if ($msg = flash_get('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>

        <div class="detail-grid">
            <div>
                <div class="detail-thumb" id="mainImageWrap">
                    <?php if ($images): ?>
                        <img id="mainImage" src="<?= UPLOAD_URL_CODE . e($images[0]) ?>" alt="<?= e($item['title']) ?>">
                        <?php if (count($images) > 1): ?>
                        <button type="button" class="img-nav img-prev" id="imgPrev" aria-label="Ảnh trước">‹</button>
                        <button type="button" class="img-nav img-next" id="imgNext" aria-label="Ảnh sau">›</button>
                        <span class="img-counter" id="imgCounter">1 / <?= count($images) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="card-thumb"><div class="noimg">// no preview available</div></div>
                    <?php endif; ?>
                </div>
                <?php if (count($images) > 1): ?>
                <div class="thumb-row">
                    <?php foreach ($images as $i => $img): ?>
                        <img src="<?= UPLOAD_URL_CODE . e($img) ?>" class="thumb-item<?= $i===0 ? ' active' : '' ?>" data-index="<?= $i ?>" data-src="<?= UPLOAD_URL_CODE . e($img) ?>" alt="<?= e($item['title']) ?> - ảnh <?= $i + 1 ?>">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-top:24px;">
                    <h1 style="font-size:26px; margin:0;"><?= e($item['title']) ?></h1>
                    <?php if ($item['verified']): ?>
                        <span class="tag tag-active" title="<?= e($item['verified_note'] ?: 'Đã kiểm duyệt thủ công bởi admin') ?>">✅ Code Sạch - Đã Kiểm Duyệt</span>
                    <?php endif; ?>
                </div>

                <?php $__isFav = (function_exists('is_favorited') && is_logged_in()) ? is_favorited($pdo, current_user_id(), $id) : false; ?>
                <div style="margin-top:12px;">
                <?php if (is_logged_in()): ?>
                
                    <form method="post" action="<?= BASE_URL ?>/favorite_toggle" style="display:inline;">
                        <?= csrf_input() ?>
                        <input type="hidden" name="code_id" value="<?= $id ?>">
                        <input type="hidden" name="back" value="/code_view.php?id=<?= $id ?>">
                        <button type="submit" class="cm-fav-btn<?= $__isFav ? ' active' : '' ?>"><?= $__isFav ? '❤️ Đã lưu yêu thích' : '🤍 Lưu yêu thích' ?></button>
                    </form>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>/login" class="cm-fav-btn">🤍 Lưu yêu thích</a>
                <?php endif; ?>
                </div>
<br>
<script>
    document.addEventListener("DOMContentLoaded", () => {
    const detailThumb = document.querySelector('.detail-thumb');
    if (detailThumb) {
        const img = detailThumb.querySelector('img');
        if (img) {
            detailThumb.style.setProperty('--detail-blur-bg', `url('${img.src}')`);
        }
    }
});
</script>
                <?php if ($rating['count'] > 0): ?>
                <div style="margin:6px 0 14px; color:var(--accent-2);">
                    <span style="font-size:15px;"><?= render_stars($rating['avg']) ?></span>
                    <span class="hint mono" style="margin-left:6px;"><?= $rating['avg'] ?>/5 (<?= $rating['count'] ?> đánh giá)</span>
                </div>
                <?php endif; ?>

                <div class="card-seller" style="margin-bottom:18px;">
                    <img src="<?= $item['avatar'] ? UPLOAD_URL_AVATAR.e($item['avatar']) : 'https://api.dicebear.com/7.x/identicon/svg?seed='.urlencode($item['username']) ?>" style="width:26px;height:26px;border-radius:50%;">
                    Đăng bởi <a href="<?= BASE_URL ?>/profile?id=<?= $item['seller_id'] ?>" style="color:var(--accent)"><?= e($item['username']) ?></a>
                    · <?= time_ago($item['created_at']) ?> · 🛒 <?= number_format($purchaseCount) ?> lượt mua
                </div>

                <?php if ($item['demo_client_url']): ?>
                <div class="form-card" style="max-width:none; padding:18px; margin-bottom:20px;">
                    <h3 style="font-size:15px; margin-bottom:12px;">🔗 Live Demo</h3>
                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        <div style="flex:1; min-width:220px;">
                            <a href="<?= e($item['demo_client_url']) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-block">🖥 Xem Demo Client</a>
                            <?php if ($item['demo_client_account']): ?><p class="hint mono" style="margin-top:6px;"><?= e($item['demo_client_account']) ?></p><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <h3>Mô tả</h3>
                <div style="color: var(--muted);">
    <?= $item['description'] ?>
</div>
                <?php if ($item['category']): ?><span class="tag tag-active">📁 <?= e($item['category']) ?></span><?php endif; ?>

                <div class="cm-report-box" style="margin-top:22px;">
                    <details>
                        <summary style="cursor:pointer; color:var(--muted); font-size:13px;">🚩 Báo vi phạm bản quyền / báo lỗi sản phẩm cho admin</summary>
                        <?php if (!empty($reportError)): ?><div class="alert alert-error" style="margin-top:10px;"><?= e($reportError) ?></div><?php endif; ?>
                        <?php if (is_logged_in()): ?>
                        <form method="post" style="margin-top:12px; max-width:520px;">
                            <?= csrf_input() ?>
                            <div class="form-group">
                                <label>Loại báo cáo</label>
                                <select name="report_type">
                                    <option value="copyright">🚩 Vi phạm bản quyền</option>
                                    <option value="error">⚠️ Báo lỗi / nội dung sai</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Nội dung chi tiết</label>
                                <textarea name="report_message" placeholder="Mô tả vi phạm bản quyền hoặc lỗi bạn gặp..." required></textarea>
                            </div>
                            <button type="submit" name="report" value="1" class="btn btn-outline btn-sm">Gửi báo cáo cho admin</button>
                        </form>
                        <?php else: ?>
                        <p class="hint" style="margin-top:10px;">Vui lòng <a href="<?= BASE_URL ?>/login" style="color:var(--accent)">đăng nhập</a> để gửi báo cáo.</p>
                        <?php endif; ?>
                    </details>
                </div>

                <div id="reviews" style="margin-top:36px;">
                    <h3>Đánh giá (<?= $rating['count'] ?>)</h3>

                    <?php if (!is_logged_in()): ?>
                        <div class="alert alert-info" style="margin-bottom:20px;">
                            Vui lòng <a href="<?= BASE_URL ?>/login" style="color:var(--accent); font-weight:bold;">đăng nhập</a> để gửi đánh giá và bình luận sản phẩm.
                        </div>
                    <?php elseif ($editingReviewData): ?>
                        <!-- Form Sửa Đánh Giá -->
                        <div class="form-card" style="max-width:none; padding:18px; margin-bottom:20px; border: 1px solid var(--accent);">
                            <h4 style="margin:0 0 10px; font-size:14px; color:var(--accent);">Chỉnh sửa đánh giá của bạn</h4>
                            <form method="post">
                                <?= csrf_input() ?>
                                <input type="hidden" name="review_id" value="<?= $editingReviewData['id'] ?>">
                                <div class="form-group">
                                    <label>Số sao</label>
                                    <select name="rating" required>
                                        <option value="5" <?= $editingReviewData['rating'] == 5 ? 'selected' : '' ?>>⭐⭐⭐⭐⭐ Xuất sắc</option>
                                        <option value="4" <?= $editingReviewData['rating'] == 4 ? 'selected' : '' ?>>⭐⭐⭐⭐ Tốt</option>
                                        <option value="3" <?= $editingReviewData['rating'] == 3 ? 'selected' : '' ?>>⭐⭐⭐ Bình thường</option>
                                        <option value="2" <?= $editingReviewData['rating'] == 2 ? 'selected' : '' ?>>⭐⭐ Chưa tốt</option>
                                        <option value="1" <?= $editingReviewData['rating'] == 1 ? 'selected' : '' ?>>⭐ Tệ</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Nhận xét</label>
                                    <textarea name="comment" rows="3"><?= e($editingReviewData['comment']) ?></textarea>
                                </div>
                                <div style="display:flex; gap:10px;">
                                    <button class="btn btn-primary" type="submit" name="update_review" value="1">Lưu thay đổi</button>
                                    <a href="/code_view?id=<?= $id ?>#reviews" class="btn btn-outline">Hủy</a>
                                </div>
                            </form>
                        </div>
                    <?php elseif ($canReviewNow): ?>
                        <!-- Form Đánh Giá Mới -->
                        <div class="form-card" style="max-width:none; padding:18px; margin-bottom:20px;">
                            <h4 style="margin:0 0 10px; font-size:14px;">Chia sẻ đánh giá hoặc bình luận của bạn (Tối đa 3 lần)</h4>
                            <form method="post">
                                <?= csrf_input() ?>
                                <div class="form-group">
                                    <label>Số sao</label>
                                    <select name="rating" required>
                                        <option value="5">⭐⭐⭐⭐⭐ Xuất sắc</option>
                                        <option value="4">⭐⭐⭐⭐ Tốt</option>
                                        <option value="3">⭐⭐⭐ Bình thường</option>
                                        <option value="2">⭐⭐ Chưa tốt</option>
                                        <option value="1">⭐ Tệ</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Nhận xét</label>
                                    <textarea name="comment" rows="3" placeholder="Chia sẻ trải nghiệm của bạn..."></textarea>
                                </div>
                                <button class="btn btn-primary" type="submit" name="submit_review" value="1">Gửi đánh giá</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info" style="margin-bottom:20px;">
                            Bạn đã đạt giới hạn tối đa 3 lần bình luận/đánh giá cho sản phẩm này.
                        </div>
                    <?php endif; ?>

                    <?php if (!$reviews): ?>
                        <div class="empty">Chưa có đánh giá nào cho sản phẩm này.</div>
                    <?php else: ?>
                        <?php foreach ($reviews as $r): ?>
                        <div style="padding:14px 0; border-bottom:1px dashed var(--border);">
                            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                <img src="<?= $r['avatar'] ? UPLOAD_URL_AVATAR.e($r['avatar']) : 'https://api.dicebear.com/7.x/identicon/svg?seed='.urlencode($r['username']) ?>" style="width:24px;height:24px;border-radius:50%;">
                                <b style="font-size:13.5px;"><?= e($r['username']) ?></b>
                                <?php if (has_purchased($pdo, $r['buyer_id'], $id)): ?>
                                    <span class="tag tag-active" style="font-size:10px; padding:2px 6px;">✓ Đã mua</span>
                                <?php endif; ?>
                                <span style="color:var(--accent-2); font-size:13px;"><?= render_stars($r['rating']) ?></span>
                                <span class="hint" style="margin-left:auto;"><?= time_ago($r['created_at']) ?></span>

                                <!-- Nút Sửa / Xóa nếu là chủ nhân của đánh giá -->
                                <?php if (is_logged_in() && current_user_id() == $r['buyer_id']): ?>
                                    <div style="margin-left: 10px; font-size: 12px; display:flex; gap:6px;">
                                        <a href="/code_view?id=<?= $id ?>&delete_review=<?= $r['id'] ?>&csrf_token=<?= e(csrf_token()) ?>" onclick="return confirm('Bạn có chắc chắn muốn xóa đánh giá này không?');" style="color: var(--danger);">🗑️ Xóa</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($r['comment']): ?><p style="margin:8px 0 0; color:var(--muted); font-size:14px;"><?= nl2br(e($r['comment'])) ?></p><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="buybox">
                <div class="price-tag mono" id="displayPrice"><?= number_format($item['price'],0,',','.') ?>đ</div>
                <p class="hint" style="margin:6px 0 18px;">Nhận file source code ngay sau khi thanh toán.</p>

                <?php if ($item['status'] !== 'active' && !$ownedOrder): ?>
                    <div class="alert alert-info">Sản phẩm này hiện không khả dụng.</div>
                <?php endif; ?>
                <?php if ($ownedOrder): ?>
                    <div class="alert alert-success">
                        Bạn đã sở hữu sản phẩm này
                        <?php if ($ownedOrder['license_type'] === 'reseller'): ?> — <b>Bản Full quyền phân phối lại</b><?php endif; ?>.
                    </div>
                    
                    <?php if ($ownedOrder['addon_install']): ?>
                        <div class="kv"><span>Hỗ trợ cài đặt</span><b>Đã đăng ký - xem tiến độ trong Bảng điều khiển</b></div>
                    <?php endif; ?>
                    <?php if ($unlockedFile && filter_var($unlockedFile, FILTER_VALIDATE_URL)): ?>
                        <a class="btn btn-accent btn-block" href="/my_orders" style="margin-top:14px;">Truy cập đơn đã mua</a>
                    <?php elseif ($unlockedFile): ?>
                        <a class="btn btn-accent btn-block" href="<?= UPLOAD_URL_CODE . 'files/' . e($unlockedFile) ?>" download style="margin-top:14px;">⬇ Tải source code</a>
                    <?php else: ?>
                        <p class="hint">Người bán chưa cập nhật link tải. Vui lòng liên hệ <a href="<?= BASE_URL ?>/profile?id=<?= $item['seller_id'] ?>" style="color:var(--accent)">@<?= e($item['username']) ?></a>.</p>
                    <?php endif; ?>
                    <?php if (!empty($item['seller_contact'])): ?>
                        <div class="btn btn-outline btn-block" style="margin-top:14px; white-space:pre-wrap;">📞 <b>Thông tin liên hệ người bán</b>: <?= nl2br(e($item['seller_contact'])) ?></div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (!is_logged_in()): ?>
                    <a class="btn btn-primary btn-block" href="<?= BASE_URL ?>/login">Đăng nhập để mua</a>
                <?php elseif (current_user_id() == $item['seller_id']): ?>
                    <div class="alert alert-info">Đây là sản phẩm của bạn.</div>
                    <a class="btn btn-outline btn-block" href="<?= BASE_URL ?>/dashboard?tab=code">Quản lý sản phẩm</a>
                    <a class="btn btn-outline btn-block" href="<?= BASE_URL ?>/code_edit?id=<?= $item['id'] ?>" style="margin-top:8px;">✏️ Sửa sản phẩm</a>
                <?php else: ?>
                    <?php if ($ownedOrder): ?><div style="border-top:1px solid var(--border);margin:16px 0 12px;"></div><p class="hint" style="margin-bottom:10px;">Bạn đã sở hữu sản phẩm này nhưng vẫn có thể <b>mua lại bao nhiêu lần tùy ý</b>.</p><?php endif; ?>
                    <form method="post" id="buyForm" onsubmit="return confirm('Xác nhận mua với tổng số tiền đã tính bên dưới?');">
                        <?= csrf_input() ?>

                        <?php if ($item['reseller_price']): ?>
                        <div class="form-group">
                            <label>Loại giấy phép</label>
                            <select name="license_type" id="licenseType">
                                <option value="standard" data-price="<?= (float)$item['price'] ?>">Tiêu chuẩn (1 domain) - <?= number_format($item['price'],0,',','.') ?>đ</option>
                                <option value="reseller" data-price="<?= (float)$item['reseller_price'] ?>">Full quyền phân phối lại - <?= number_format($item['reseller_price'],0,',','.') ?>đ</option>
                            </select>
                        </div>
                        <?php else: ?>
                            <input type="hidden" name="license_type" value="standard">
                        <?php endif; ?>

                        <input type="hidden" name="warranty_type" value="standard">

                        <?php if (($item['install_support_price'] ?? null) !== null): ?>
                        <div class="form-group" style="display:flex; align-items:flex-start; gap:8px;">
                            <input type="checkbox" name="install_addon" id="installAddon" value="1" data-fee="<?= (float)$item['install_support_price'] ?>" style="margin-top:4px;">
                            <label for="installAddon" style="font-weight:400;">🛠️ Hỗ trợ cài đặt lên Hosting/VPS <?= (float)$item['install_support_price'] > 0 ? '(+' . number_format((float)$item['install_support_price'],0,',','.') . 'đ)' : '(Miễn phí)' ?></label>
                        </div>
                        <?php endif; ?>

                        <div class="kv" style="font-size:18px; border-top:1px solid var(--border); padding-top:10px;">
                            <span>Tổng tiền</span><b class="mono" id="totalPrice"><?= number_format($item['price'],0,',','.') ?>đ</b>
                        </div>

                        <button type="submit" name="buy" value="1" class="btn btn-primary btn-block" style="margin-top:12px;">🛒 Mua ngay</button>
                    </form>
                    <form method="post" action="<?= BASE_URL ?>/cart" style="margin-top:8px;">
                        <?= csrf_input() ?>
                        <input type="hidden" name="add_to_cart" value="<?= $item['id'] ?>">
                        <button type="submit" class="btn btn-outline btn-block">➕ Thêm vào giỏ hàng</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var mainImg = document.getElementById('mainImage');
    if (!mainImg) return;
    var thumbs = Array.prototype.slice.call(document.querySelectorAll('.thumb-item'));
    var srcs = thumbs.length ? thumbs.map(function (t) { return t.dataset.src; }) : [mainImg.getAttribute('src')];
    var counter = document.getElementById('imgCounter');
    var idx = 0;
    function show(i) {
        if (!srcs.length) return;
        idx = (i + srcs.length) % srcs.length;
        mainImg.src = srcs[idx];
        thumbs.forEach(function (t, j) { t.classList.toggle('active', j === idx); });
        if (counter) counter.textContent = (idx + 1) + ' / ' + srcs.length;
    }
    thumbs.forEach(function (t) { t.addEventListener('click', function () { show(parseInt(this.dataset.index, 10)); }); });
    var prev = document.getElementById('imgPrev'), next = document.getElementById('imgNext');
    if (prev) prev.addEventListener('click', function () { show(idx - 1); });
    if (next) next.addEventListener('click', function () { show(idx + 1); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowLeft') show(idx - 1);
        else if (e.key === 'ArrowRight') show(idx + 1);
    });
})();

(function () {
    const licenseSel = document.getElementById('licenseType');
    const warrantySel = document.getElementById('warrantyType');
    const installChk = document.getElementById('installAddon');
    const totalEl = document.getElementById('totalPrice');
    if (!totalEl) return;

    function fmt(n) { return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.') + 'đ'; }

    function recalc() {
        let base = licenseSel ? parseFloat(licenseSel.selectedOptions[0].dataset.price) : <?= (float)$item['price'] ?>;
        let extra = warrantySel ? parseFloat(warrantySel.selectedOptions[0].dataset.extra) : 0;
        let install = (installChk && installChk.checked) ? parseFloat(installChk.dataset.fee) : 0;
        totalEl.textContent = fmt(base + extra + install);
    }

    [licenseSel, warrantySel, installChk].forEach(function (el) {
        if (el) el.addEventListener('change', recalc);
    });
    recalc();
})();
</script>
<!-- ===== Zoom ảnh (độc lập, không đụng vào carousel gốc) ===== -->
<style>
.cm-zoom-btn{
    position:absolute; top:12px; right:12px; z-index:5;
    width:36px; height:36px; border-radius:50%;
    background:rgba(0,0,0,.55); color:#fff; border:none;
    font-size:16px; cursor:pointer; display:flex; align-items:center; justify-content:center;
    transition:.2s;
}
.cm-zoom-btn:hover{ background:rgba(0,0,0,.8); transform:scale(1.06); }

.cm-lightbox{
    display:none; position:fixed; inset:0; z-index:9999;
    background:rgba(0,0,0,.9);
    align-items:center; justify-content:center;
}
.cm-lightbox.active{ display:flex; }
.cm-lightbox img{
    max-width:90vw; max-height:88vh; object-fit:contain;
    border-radius:6px; box-shadow:0 10px 40px rgba(0,0,0,.5);
}
.cm-lightbox-close{
    position:absolute; top:20px; right:24px;
    width:42px; height:42px; border-radius:50%;
    background:rgba(255,255,255,.12); color:#fff; border:none;
    font-size:20px; cursor:pointer; transition:.2s;
}
.cm-lightbox-close:hover{ background:rgba(255,255,255,.25); }
.cm-lightbox-nav{
    position:absolute; top:50%; transform:translateY(-50%);
    width:48px; height:48px; border-radius:50%;
    background:rgba(255,255,255,.12); color:#fff; border:none;
    font-size:26px; cursor:pointer; transition:.2s;
}
.cm-lightbox-nav:hover{ background:rgba(255,255,255,.25); }
.cm-lightbox-prev{ left:20px; }
.cm-lightbox-next{ right:20px; }
.cm-lightbox-counter{
    position:absolute; bottom:22px; left:50%; transform:translateX(-50%);
    color:#fff; font-size:13px; background:rgba(0,0,0,.5);
    padding:4px 12px; border-radius:999px;
}
@media (max-width:600px){
    .cm-lightbox-nav{ width:40px; height:40px; font-size:22px; }
    .cm-lightbox-prev{ left:8px; }
    .cm-lightbox-next{ right:8px; }
}
</style>

<script>
(function () {
    var wrap = document.getElementById('mainImageWrap');
    var mainImg = document.getElementById('mainImage');
    if (!wrap || !mainImg) return;

    // Tạo nút zoom, chèn vào wrap có sẵn (wrap đã position:relative sẵn vì đang chứa nav buttons)
    var zoomBtn = document.createElement('button');
    zoomBtn.type = 'button';
    zoomBtn.className = 'cm-zoom-btn';
    zoomBtn.setAttribute('aria-label', 'Phóng to ảnh');
    zoomBtn.textContent = '🔍';
    wrap.appendChild(zoomBtn);

    // Tạo lightbox, chèn vào cuối body
    var lightbox = document.createElement('div');
    lightbox.className = 'cm-lightbox';
    lightbox.id = 'cmLightbox';
    lightbox.innerHTML =
        '<button type="button" class="cm-lightbox-close" aria-label="Đóng">✕</button>' +
        '<button type="button" class="cm-lightbox-nav cm-lightbox-prev" aria-label="Ảnh trước">‹</button>' +
        '<img src="" alt="">' +
        '<button type="button" class="cm-lightbox-nav cm-lightbox-next" aria-label="Ảnh sau">›</button>' +
        '<span class="cm-lightbox-counter"></span>';
    document.body.appendChild(lightbox);

    var lbImg = lightbox.querySelector('img');
    var lbCounter = lightbox.querySelector('.cm-lightbox-counter');
    var lbClose = lightbox.querySelector('.cm-lightbox-close');
    var lbPrev = lightbox.querySelector('.cm-lightbox-prev');
    var lbNext = lightbox.querySelector('.cm-lightbox-next');

    // Tự đọc danh sách ảnh + ảnh đang active từ DOM có sẵn, không phụ thuộc script carousel gốc
    function getSrcs() {
        var thumbs = Array.prototype.slice.call(document.querySelectorAll('.thumb-item'));
        return thumbs.length ? thumbs.map(function (t) { return t.dataset.src; }) : [mainImg.getAttribute('src')];
    }
    function getCurrentIndex(srcs) {
        var i = srcs.indexOf(mainImg.getAttribute('src'));
        return i >= 0 ? i : 0;
    }

    var srcs = [], idx = 0;

    function render() {
        lbImg.src = srcs[idx];
        lbCounter.textContent = (idx + 1) + ' / ' + srcs.length;
        lbPrev.style.display = lbNext.style.display = srcs.length > 1 ? '' : 'none';
    }
    function open() {
        srcs = getSrcs();
        idx = getCurrentIndex(srcs);
        render();
        lightbox.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function close() {
        lightbox.classList.remove('active');
        document.body.style.overflow = '';
    }
    function go(step) {
        idx = (idx + step + srcs.length) % srcs.length;
        // Đồng bộ luôn ảnh chính + thumbnail đang active (không sửa script gốc, chỉ set src/class)
        mainImg.src = srcs[idx];
        document.querySelectorAll('.thumb-item').forEach(function (t, j) {
            t.classList.toggle('active', j === idx);
        });
        var counterEl = document.getElementById('imgCounter');
        if (counterEl) counterEl.textContent = (idx + 1) + ' / ' + srcs.length;
        render();
    }

    zoomBtn.addEventListener('click', open);
    mainImg.addEventListener('click', open);
    lbClose.addEventListener('click', close);
    lbPrev.addEventListener('click', function (e) { e.stopPropagation(); go(-1); });
    lbNext.addEventListener('click', function (e) { e.stopPropagation(); go(1); });
    lightbox.addEventListener('click', function (e) { if (e.target === lightbox) close(); });
    document.addEventListener('keydown', function (e) {
        if (!lightbox.classList.contains('active')) return;
        if (e.key === 'Escape') close();
        else if (e.key === 'ArrowLeft') go(-1);
        else if (e.key === 'ArrowRight') go(1);
    });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>