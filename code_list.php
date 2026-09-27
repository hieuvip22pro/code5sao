<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

$filterMap = [
    'free'  => ['title' => 'Các mẫu miễn phí',         'where' => '(cl.price = 0 OR cl.price IS NULL)', 'order' => 'cl.created_at DESC'],
    'paid'  => ['title' => 'Các mẫu có phí',           'where' => '(cl.price > 0)',                      'order' => 'cl.created_at DESC'],
    'new'   => ['title' => 'Bản phát hành mới',        'where' => '1=1',                                 'order' => 'cl.created_at DESC'],
    'views' => ['title' => '🔥 Sản phẩm bán chạy nhất', 'where' => '1=1',                                 'order' => 'purchase_count DESC, cl.views DESC'],
];

$filter = $_GET['filter'] ?? 'new';
if (!isset($filterMap[$filter])) $filter = 'new';
$conf = $filterMap[$filter];

// Map slug danh mục -> tên thể loại thực trong DB (giống trang chủ)
$CODE_CAT_MAP = [
    'game' => 'Game', 'ung-dung' => 'Ứng dụng', 'phan-mem' => 'Phần mềm', 'website' => 'Website',
    'khac' => 'Khác',
];
$TAB_CATS = [
    'all'      => ['label' => 'Tất cả',    'cat' => null],
    'website'  => ['label' => 'Website',   'cat' => $CODE_CAT_MAP['website']],
    'game'     => ['label' => 'Game',      'cat' => $CODE_CAT_MAP['game']],
    'ung-dung' => ['label' => 'Ứng dụng',  'cat' => $CODE_CAT_MAP['ung-dung']],
    'phan-mem' => ['label' => 'Phần mềm',  'cat' => $CODE_CAT_MAP['phan-mem']],
    'khac'     => ['label' => 'Khác',      'cat' => $CODE_CAT_MAP['khac']],
];

$cat = $_GET['cat'] ?? 'all';
if (!isset($TAB_CATS[$cat])) $cat = 'all';
$catName = $TAB_CATS[$cat]['cat'];

$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$params = [];
$baseSql = "FROM code_listings cl JOIN users u ON u.id = cl.user_id WHERE cl.status='active' AND ({$conf['where']})";
if ($catName !== null) { $baseSql .= " AND cl.category LIKE ?"; $params[] = "%$catName%"; }
if ($q !== '') { $baseSql .= " AND cl.title LIKE ?"; $params[] = "%$q%"; }

$countStmt = $pdo->prepare("SELECT COUNT(*) c $baseSql");
$countStmt->execute($params);
$total = $countStmt->fetch()['c'];

$sql = "SELECT cl.*, u.username, u.avatar,
            (SELECT COALESCE(AVG(rating),0) FROM reviews WHERE code_listing_id = cl.id) as avg_rating,
            (SELECT COUNT(*) FROM reviews WHERE code_listing_id = cl.id) as review_count,
            (SELECT COUNT(*) FROM orders WHERE item_type='code' AND item_id = cl.id AND status='completed') as purchase_count
        $baseSql ORDER BY {$conf['order']} LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

if ($items) {
    $codeIds = array_column($items, 'id');
    $inClause = implode(',', array_fill(0, count($codeIds), '?'));
    $imgStmt = $pdo->prepare("SELECT item_id, image_path FROM listing_images WHERE item_type='code' AND item_id IN ($inClause) ORDER BY sort_order ASC");
    $imgStmt->execute($codeIds);
    $itemImages = [];
    foreach ($imgStmt->fetchAll() as $img) {
        $itemImages[$img['item_id']][] = $img['image_path'];
    }
    foreach ($items as &$item) {
        $item['carousel_images'] = $itemImages[$item['id']] ?? [];
        if (empty($item['carousel_images']) && !empty($item['demo_image'])) {
            $item['carousel_images'] = [$item['demo_image']];
        }
    }
    unset($item);
}
function build_itemlist_jsonld($items, $page, $perPage) {
    if (empty($items)) return;

    $listItems = [];
    $position = ($page - 1) * $perPage;
    foreach ($items as $item) {
        $position++;

        $product = [
            '@type' => 'Product',
            'name'  => $item['title'],
            'url'   => code_url($item['id'], $item['title']),
            'offers' => [
                '@type'         => 'Offer',
                'url'           => code_url($item['id'], $item['title']),
                'priceCurrency' => 'VND',
                'price'         => (string)(int)$item['price'],
                'availability'  => 'https://schema.org/InStock',
            ],
        ];

        if (!empty($item['carousel_images'])) {
            $product['image'] = UPLOAD_URL_CODE . $item['carousel_images'][0];
        }

        if (!empty($item['review_count']) && $item['review_count'] > 0) {
            $product['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => number_format((float)$item['avg_rating'], 1),
                'reviewCount' => (int)$item['review_count'],
            ];
        }

        $listItems[] = [
            '@type'    => 'ListItem',
            'position' => $position,
            'item'     => $product,
        ];
    }

    $jsonld = [
        '@context'        => 'https://schema.org',
        '@type'           => 'ItemList',
        'itemListElement' => $listItems,
    ];

    echo '<script type="application/ld+json">'
        . json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        . '</script>' . "\n";
}

build_itemlist_jsonld($items, $page, $perPage);
$totalPages = max(1, ceil($total / $perPage));
$canonical = rtrim(BASE_URL, '/') . '/code_list?filter=' . urlencode($filter)
           . ($cat !== 'all' ? '&cat=' . urlencode($cat) : '');

if ($page > 1) {
    // Các trang phân trang sau trang 1: giữ cho Google vẫn thấy được (follow)
    // nhưng không tính là nội dung riêng để tránh loãng SEO
    $canonical .= '&page=' . $page;
    $meta_robots = 'noindex,follow';
}

if ($q !== '') {
    // Có từ khóa tìm kiếm riêng trong danh sách -> không index trang này
    $meta_robots = 'noindex,follow';
}

$page_title = $conf['title'] . ($cat !== 'all' ? ' - ' . $TAB_CATS[$cat]['label'] : '');
$page_title = $conf['title'];
require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<style>
.cm-global-tabs{display:flex;flex-wrap:wrap;gap:10px;}
.cm-cat-tab{padding:8px 18px;border:1px solid var(--border);background:#fff;color:var(--muted);border-radius:999px;font-weight:600;font-size:14px;cursor:pointer;transition:.2s;text-decoration:none;display:inline-block;}
.cm-cat-tab:hover{border-color:var(--accent);color:var(--accent);}
.cm-cat-tab.active{background:var(--accent);border-color:var(--accent);color:#fff;}
</style>
<section class="section">
    <div class="container">
        <div class="section-head cm-section-head" style="margin-bottom:10px;">
            <h2><?= e($conf['title']) ?> (<?= $total ?>)</h2>
            <a href="<?= BASE_URL ?>/index" style="color:var(--accent)">← Về trang chủ</a>
        </div>

        <form class="filterbar" method="get" style="margin-bottom:24px;">
            <input type="hidden" name="filter" value="<?= e($filter) ?>">
            <input type="hidden" name="cat" value="<?= e($cat) ?>">
            <input type="text" name="q" placeholder="Tìm kiếm theo tên sản phẩm..." value="<?= e($q) ?>">
            <button class="btn btn-outline" type="submit">Lọc</button>
        </form>

        <div class="cm-global-tabs" role="tablist" style="margin-bottom:24px;">
            <?php foreach ($TAB_CATS as $key => $info): ?>
                <a href="?filter=<?= e($filter) ?>&cat=<?= e($key) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
                   class="cm-cat-tab<?= $key === $cat ? ' active' : '' ?>">
                    <span><?= e($info['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (!$items): ?>
            <div class="empty"><div class="icon">{ }</div>Không có sản phẩm nào phù hợp.</div>
        <?php else: ?>
        <div class="grid cm-code-grid-alt">
            <?php foreach ($items as $item): ?>
            <a class="cm-alt-card" href="<?= code_url($item['id'], $item['title']) ?>">
                <div class="cm-alt-thumb">
                    <?php if (!empty($item['carousel_images'])): ?>
                        <div class="cm-carousel" data-index="0">
                            <div class="cm-carousel-inner">
                                <?php foreach ($item['carousel_images'] as $img): ?>
                                    <img src="<?= UPLOAD_URL_CODE . e($img) ?>" alt="Preview" loading="lazy">
                                <?php endforeach; ?>
                            </div>
                            <?php if (count($item['carousel_images']) > 1): ?>
                                <button class="cm-nav-btn cm-prev" onclick="moveCarousel(event, -1)">‹</button>
                                <button class="cm-nav-btn cm-next" onclick="moveCarousel(event, 1)">›</button>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="cm-alt-noimg">
                            <span>// source code</span>
                        </div>
                    <?php endif; ?>

                    <div class="cm-alt-badge-group">
                        <?php if (!empty($item['verified']) && $item['price'] != 0): ?>
                            <span class="cm-alt-badge verified">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"><polyline points="20 6 9 17 4 12"></polyline></svg> Verified
                            </span>
                        <?php endif; ?>
                        <span class="cm-alt-badge <?= $item['price'] == 0 ? 'free' : 'type' ?>">
                            <?= $item['price'] == 0 ? 'FREE' : ($item['price'] >= 1000000 ? 'PRO' : 'CODE') ?>
                        </span>
                    </div>
                </div>

                <div class="cm-alt-body">
                    <div class="cm-alt-title"><?= e($item['title']) ?></div>
                    <div class="cm-alt-cat" style="margin-top:6px;display:flex;flex-wrap:wrap;gap:6px;align-items:center">
                        <?php foreach (array_filter(array_map('trim', explode(',', $item['category']))) as $cat): ?>
                            <span class="cm-alt-cat-chip" style="font-size:12px;color:#3730a3;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;padding:2px 8px;line-height:1.5"><?= e($cat) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <br>

                    <div class="cm-alt-stats">
                        <span>🛒 <?= number_format($item['purchase_count'] ?? 0) ?> lượt mua</span>
                        <?php if (!empty($item['review_count'])): ?>
                            <span class="rating">★ <?= round($item['avg_rating'], 1) ?> (<?= $item['review_count'] ?>)</span>
                        <?php endif; ?>
                    </div>

                    <div class="cm-alt-footer">
                        <div class="cm-alt-seller">
                            <img src="<?= $item['avatar'] ? UPLOAD_URL_AVATAR . e($item['avatar']) : 'https://api.dicebear.com/7.x/identicon/svg?seed=' . urlencode($item['username']) ?>" alt="">
                            <span><?= e($item['username']) ?></span>
                        </div>

                        <?php if ($item['price'] == 0): ?>
                            <span class="cm-alt-price free">Miễn phí</span>
                        <?php else: ?>
                            <span class="cm-alt-price"><?= number_format($item['price'], 0, ',', '.') ?>₫</span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
        <div style="display:flex; gap:8px; margin-top:28px; flex-wrap:wrap;">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a href="?filter=<?= e($filter) ?>&cat=<?= e($cat) ?>&page=<?= $p ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="btn btn-sm <?= $p == $page ? 'btn-primary' : 'btn-outline' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
<script>
function moveCarousel(event, direction) {
    event.preventDefault();
    event.stopPropagation();
    const carousel = event.target.closest('.cm-carousel');
    const inner = carousel.querySelector('.cm-carousel-inner');
    const imagesCount = inner.children.length;
    let currentIndex = parseInt(carousel.getAttribute('data-index') || 0);
    currentIndex += direction;
    if (currentIndex < 0) currentIndex = imagesCount - 1;
    else if (currentIndex >= imagesCount) currentIndex = 0;
    carousel.setAttribute('data-index', currentIndex);
    inner.style.transform = `translateX(-${currentIndex * 100}%)`;
}
</script>
<style>
    @media (max-width: 992px) {
    .cm-catbar-inner {
        gap: 12px;
        overflow: visible;
        padding: 9px 16px;
    }
}
.cm-catbar-inner {
    display: flex;
    align-items: center;
    gap: 22px;
    padding: 11px 16px;
    font-size: 13.5px;
    font-weight: 500;
    max-width: 1350px;
    margin: 0 auto;
    overflow-x: auto;
    white-space: nowrap;
}

</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>