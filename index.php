<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

$type = $_GET['type'] ?? 'all';
$q = trim($_GET['q'] ?? '');

// Map slug danh muc (tu menu header) -> ten the loai thuc trong DB
$CODE_CAT_MAP = [
    'game' => 'Game', 'ung-dung' => 'Ứng dụng', 'phan-mem' => 'Phần mềm', 'website' => 'Website',
    'php-mysql' => 'PHP & MySQL', 'java-jsp' => 'Java/JSP', 'asp-aspx' => 'Asp/Asp.Net',
    'javascript' => 'Javascript', 'html-template' => 'Html & Template', 'android' => 'Android',
    'ios' => 'iOS', 'unity' => 'Unity', 'wordpress' => 'WordPress', 'joomla' => 'Joomla',
    'visual-csharp' => 'Visual C#', 'visual-cpp' => 'Visual C++', 'visual-basic' => 'Visual Basic',
    'cocos2d' => 'Cocos2D', 'windows-phone' => 'Windows phone', 'khac' => 'Khác',
];
$catName = ($q !== '' && isset($CODE_CAT_MAP[$q])) ? $CODE_CAT_MAP[$q] : null;

// Thêm đoạn code chuẩn hóa canonical và meta robots vào đây:
if ($catName !== null) {    
    // Trang danh mục hợp lệ (q=game, q=website...) -> cho phép index bình thường
    $canonical = rtrim(BASE_URL, '/') . '/index?q=' . urlencode($q);
} elseif ($q !== '') {    
    // Trang kết quả tìm kiếm tự do (người dùng tự gõ từ khóa) -> không cho index    
    // để tránh hàng loạt trang mỏng/trùng lặp do vô số từ khóa khác nhau
    $meta_robots = 'noindex,follow';
    $canonical = rtrim(BASE_URL, '/') . '/index';
} else {
    $canonical = rtrim(BASE_URL, '/') . '/index';
}
// Map tab dùng chung cho các khối sản phẩm trên trang chủ
$TAB_CATS = [
    'all'      => ['label' => 'Tất cả',    'cat' => null],
    'website'  => ['label' => 'Website',   'cat' => $CODE_CAT_MAP['website']],
    'game'     => ['label' => 'Game',      'cat' => $CODE_CAT_MAP['game']],
    'ung-dung' => ['label' => 'Ứng dụng',  'cat' => $CODE_CAT_MAP['ung-dung']],
    'phan-mem' => ['label' => 'Phần mềm',  'cat' => $CODE_CAT_MAP['phan-mem']],
    'khac'     => ['label' => 'Khác',      'cat' => $CODE_CAT_MAP['khac']],
];

// Lấy dữ liệu cho từng tab của 1 khối, tận dụng data "all" đã query sẵn để đỡ query lại
function get_items_for_tabs($pdo, $whereCondition, $orderBy, $limit, $q, $tabCats, $allItems) {
    $result = [];
    foreach ($tabCats as $key => $info) {
        $result[$key] = ($key === 'all')
            ? $allItems
            : get_code_listings($pdo, $whereCondition, $orderBy, $limit, $q, $info['cat']);
    }
    return $result;
}

$totalCode = $pdo->query("SELECT COUNT(*) c FROM code_listings WHERE status='active'")->fetch()['c'];
$totalHosting = $pdo->query("SELECT COUNT(*) c FROM hosting_plans WHERE status='active'")->fetch()['c'];
$totalUsers = $pdo->query("SELECT COUNT(*) c FROM users")->fetch()['c'];
$totalOrders = $pdo->query("SELECT COUNT(*) c FROM orders")->fetch()['c'];

function get_code_listings($pdo, $whereCondition = '1=1', $orderBy = 'cl.created_at DESC', $limit = 8, $q = '', $catName = null) {
    $params = [];
   $sql = "SELECT cl.*, u.username, u.avatar,
                (SELECT COALESCE(AVG(rating),0) FROM reviews WHERE code_listing_id = cl.id) as avg_rating,
                (SELECT COUNT(*) FROM reviews WHERE code_listing_id = cl.id) as review_count,
                (SELECT COUNT(*) FROM orders WHERE item_type='code' AND item_id = cl.id AND status='completed') as purchase_count
            FROM code_listings cl 
            JOIN users u ON u.id = cl.user_id 
            WHERE cl.status = 'active' AND ($whereCondition)";

    if ($catName !== null && $catName !== '') {
        $sql .= " AND cl.category LIKE ?";
        $params[] = "%$catName%";
    } elseif ($q !== '') {
        $sql .= " AND (cl.title LIKE ? OR cl.category LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }

    $sql .= " ORDER BY $orderBy LIMIT " . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    if (empty($items)) return [];

    $codeIds = array_column($items, 'id');
    $inClause = implode(',', array_fill(0, count($codeIds), '?'));
    $imgSql = "SELECT item_id, image_path FROM listing_images WHERE item_type='code' AND item_id IN ($inClause) ORDER BY sort_order ASC";
    $imgStmt = $pdo->prepare($imgSql);
    $imgStmt->execute($codeIds);
    $images = $imgStmt->fetchAll();

    $itemImages = [];
    foreach ($images as $img) {
        $itemImages[$img['item_id']][] = $img['image_path'];
    }

    foreach ($items as &$item) {
        $item['carousel_images'] = $itemImages[$item['id']] ?? [];
        if (empty($item['carousel_images']) && !empty($item['demo_image'])) {
            $item['carousel_images'] = [$item['demo_image']];
        }
    }

    return $items;
}

// TĂNG LIMIT LÊN 8
$newItems        = get_code_listings($pdo, "1=1", "cl.created_at DESC", 8, $q, $catName);
$freeItems       = get_code_listings($pdo, "cl.price = 0 OR cl.price IS NULL", "cl.created_at DESC", 8, $q, $catName);
$paidItems       = get_code_listings($pdo, "cl.price > 0", "cl.created_at DESC", 8, $q, $catName);
$bestSellerItems = get_code_listings($pdo, "1=1", "purchase_count DESC, cl.views DESC", 8, $q, $catName);

// Dữ liệu theo tab (Tất cả/Game/Ứng dụng/Phần mềm/Website) cho từng khối
$newItemsByTab        = get_items_for_tabs($pdo, "1=1", "cl.created_at DESC", 8, $q, $TAB_CATS, $newItems);
$freeItemsByTab       = get_items_for_tabs($pdo, "cl.price = 0 OR cl.price IS NULL", "cl.created_at DESC", 8, $q, $TAB_CATS, $freeItems);
$paidItemsByTab       = get_items_for_tabs($pdo, "cl.price > 0", "cl.created_at DESC", 8, $q, $TAB_CATS, $paidItems);
$bestSellerItemsByTab = get_items_for_tabs($pdo, "1=1", "purchase_count DESC, cl.views DESC", 8, $q, $TAB_CATS, $bestSellerItems);

$hostingPlans = [];
$hostingGroups = [];
if ($type === 'all' || $type === 'hosting') {
    $sql = "SELECT hp.*, hs.name AS server_name, hs.sort_order AS server_sort FROM hosting_plans hp LEFT JOIN hosting_servers hs ON hs.id = hp.server_id WHERE hp.status='active'";
    $params = [];
    if ($q !== '') { $sql .= " AND hp.name LIKE ?"; $params[] = "%$q%"; }
    $sql .= " ORDER BY hp.sort_order ASC, hp.id ASC LIMIT 24";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $hostingPlans = $stmt->fetchAll();

    // Nhóm gói theo server để hiển thị thành các tab (giống trang hosting.php)
    foreach ($hostingPlans as $hp) {
        $gid = !empty($hp['server_id']) ? (int)$hp['server_id'] : 0;
        if (!isset($hostingGroups[$gid])) {
            $hostingGroups[$gid] = [
                'name'  => $hp['server_name'] ?: 'Khác',
                'sort'  => isset($hp['server_sort']) ? (int)$hp['server_sort'] : 999,
                'plans' => [],
            ];
        }
        $hostingGroups[$gid]['plans'][] = $hp;
    }
    uasort($hostingGroups, function ($a, $b) { return $a['sort'] <=> $b['sort']; });
}

function build_product_jsonld($allItemSets) {
    // Gộp và loại trùng theo id (một sản phẩm có thể xuất hiện ở nhiều khối: mới/free/paid/bestseller)
    $unique = [];
    foreach ($allItemSets as $set) {
        foreach ($set as $item) {
            $unique[$item['id']] = $item;
        }
    }
    if (empty($unique)) return;

    $graph = [];
    foreach ($unique as $item) {
        $image = null;
        if (!empty($item['carousel_images'])) {
            $image = array_map(function ($img) {
                return UPLOAD_URL_CODE . $img;
            }, $item['carousel_images']);
        }

        $product = [
            '@type'       => 'Product',
            '@id'         => code_url($item['id'], $item['title']) . '#product',
            'name'        => $item['title'],
            'url'         => code_url($item['id'], $item['title']),
            'category'    => $item['category'] ?? null,
            'brand'       => [
                '@type' => 'Brand',
                'name'  => $item['username'] ?? 'CodeMarket',
            ],
            'offers'      => [
                '@type'         => 'Offer',
                'url'           => code_url($item['id'], $item['title']),
                'priceCurrency' => 'VND',
                'price'         => (string)(int)$item['price'],
                'availability'  => 'https://schema.org/InStock',
            ],
        ];

        if ($image) {
            $product['image'] = $image;
        }

        // Chỉ thêm AggregateRating nếu có review, tránh lỗi thiếu dữ liệu bắt buộc của Google
        if (!empty($item['review_count']) && $item['review_count'] > 0) {
            $product['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => number_format((float)$item['avg_rating'], 1),
                'reviewCount' => (int)$item['review_count'],
            ];
        }

        // Loại bỏ field null để JSON gọn
        $product = array_filter($product, function ($v) { return $v !== null && $v !== ''; });

        $graph[] = $product;
    }

    $jsonld = [
        '@context' => 'https://schema.org',
        '@graph'   => $graph,
    ];

    echo '<script type="application/ld+json">'
        . json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        . '</script>' . "\n";
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- ===== HERO (ThemeWagon light) ===== -->
<section class="hero-modern">
    <div class="hero-bg-grid"></div>
    <div class="hero-grid-cells" id="heroGridCells" aria-hidden="true"></div>
    <div class="hero-aurora">
        <span class="aurora-blob blob-1"></span>
        <span class="aurora-blob blob-2"></span>
        <span class="aurora-blob blob-3"></span>
    </div>
    <div class="hero-particles">
        <span style="left:8%;  animation-duration:9s;  animation-delay:0s;   width:5px; height:5px;"></span>
        <span style="left:18%; animation-duration:11s; animation-delay:1.5s; width:7px; height:7px;"></span>
        <span style="left:27%; animation-duration:8s;  animation-delay:3s;   width:4px; height:4px;"></span>
        <span style="left:36%; animation-duration:13s; animation-delay:.8s;  width:6px; height:6px;"></span>
        <span style="left:45%; animation-duration:10s; animation-delay:2.2s; width:8px; height:8px;"></span>
        <span style="left:54%; animation-duration:12s; animation-delay:4s;   width:5px; height:5px;"></span>
        <span style="left:63%; animation-duration:9.5s;animation-delay:1s;   width:6px; height:6px;"></span>
        <span style="left:72%; animation-duration:14s; animation-delay:2.8s; width:4px; height:4px;"></span>
        <span style="left:81%; animation-duration:10.5s;animation-delay:.4s; width:7px; height:7px;"></span>
        <span style="left:90%; animation-duration:12.5s;animation-delay:3.6s;width:5px; height:5px;"></span>
    </div>
    <div class="hero-shapes" aria-hidden="true">
        <div class="hero-stack hero-stack-left">
            <span class="hs hs-4"></span>
            <span class="hs hs-3"></span>
            <span class="hs hs-2"></span>
            <span class="hs hs-1"></span>
        </div>
        <div class="hero-stack hero-stack-right">
            <span class="hs hs-4"></span>
            <span class="hs hs-3"></span>
            <span class="hs hs-2"></span>
            <span class="hs hs-1"></span>
        </div>
    </div>
    <div class="container hero-content">
        <div class="cmwave-box">
      <h1 class="hero-title cmwave-main">
    Nơi trao đổi <span>source code</span> an toàn và bảo mật.
</h1>
<span aria-hidden="true" class="hero-title cmwave-copy cw1">Nơi trao đổi <span>source code</span> an toàn và bảo mật.</span>
<span aria-hidden="true" class="hero-title cmwave-copy cw2">Nơi trao đổi <span>source code</span> an toàn và bảo mật.</span>
<span aria-hidden="true" class="hero-title cmwave-copy cw3">Nơi trao đổi <span>source code</span> an toàn và bảo mật.</span>
<span aria-hidden="true" class="hero-title cmwave-copy cw4">Nơi trao đổi <span>source code</span> an toàn và bảo mật.</span>
</div>

        <p class="hero-desc animate-fade-up delay-1">
            <b>Kho mã nguồn &amp; template</b> chất lượng. Thanh toán bảo mật đa tầng, cơ chế hoàn tiền Escrow, bảo vệ cả người mua lẫn người bán.
        </p>

    </div>
    
     <br> <br>
<div class="position-absolute z-index-1 w-100 logo-carousel" bis_skin_checked="1">
            

            <div class="overflow-hidden d-flex w-100" bis_skin_checked="1">
                <!-- Additional required wrapper -->
                <div aria-hidden="false" class="marquee d-flex gap-4 align-items-center logo-marquee" bis_skin_checked="1">
                    <!-- Slides -->
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/IBM.png" height="44" alt="" loading="eager" title="Used by people from IBM">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/SAP.png" height="44" alt="" loading="eager" title="Used by people from SAP">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/shopify.png" height="44" alt="" loading="eager" title="Used by people from Shopify">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/cloudflare.png" height="44" alt="" loading="eager" title="Used by people from Cloudflare">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/uber.png" height="44" alt="" loading="eager" title="Used by people from Uber">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/microsoft.png" height="44" alt="" loading="eager" title="Used by people from Microsoft">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/google.png" height="44" alt="" loading="eager" title="Used by people from Google">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/amazon.png" height="44" alt="" loading="eager" title="Used by people from Amazon">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/salesforce.png" height="44" alt="" loading="eager" title="Used by people from Salesforce">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/adobe.png" height="44" alt="" loading="eager" title="Used by people from Adobe">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/linkedin.png" height="44" alt="" loading="eager" title="Used by people from LinkedIn">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/MIT.png" height="44" alt="" loading="eager" title="Used by people from MIT">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/georgia.png" height="44" alt="" loading="eager" title="Used by people from UGA">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/mcgill.png" height="44" alt="" loading="eager" title="Used by people from McGill">
                        </div>
                                    </div>
                <div aria-hidden="true" class="marquee d-flex gap-4 align-items-center logo-marquee" bis_skin_checked="1">
                    <!-- Slides -->
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/IBM.png" height="44" alt="" loading="eager" title="Used by people from IBM">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/SAP.png" height="44" alt="" loading="eager" title="Used by people from SAP">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/shopify.png" height="44" alt="" loading="eager" title="Used by people from Shopify">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/cloudflare.png" height="44" alt="" loading="eager" title="Used by people from Cloudflare">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/uber.png" height="44" alt="" loading="eager" title="Used by people from Uber">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/microsoft.png" height="44" alt="" loading="eager" title="Used by people from Microsoft">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/google.png" height="44" alt="" loading="eager" title="Used by people from Google">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/amazon.png" height="44" alt="" loading="eager" title="Used by people from Amazon">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/salesforce.png" height="44" alt="" loading="eager" title="Used by people from Salesforce">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/adobe.png" height="44" alt="" loading="eager" title="Used by people from Adobe">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/linkedin.png" height="44" alt="" loading="eager" title="Used by people from LinkedIn">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/MIT.png" height="44" alt="" loading="eager" title="Used by people from MIT">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/georgia.png" height="44" alt="" loading="eager" title="Used by people from UGA">
                        </div>
                                            <div bis_skin_checked="1">
                            <img src="https://themewagon.com/wp-content/themes/themewagon/dist/images/mcgill.png" height="44" alt="" loading="eager" title="Used by people from McGill">
                        </div>
                                    </div>
            </div>
        </div>
</section>

<script>
(function () {
    var hero = document.querySelector('.hero-modern');
    if (!hero) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var aurora  = hero.querySelector('.hero-aurora');
    var grid    = hero.querySelector('.hero-bg-grid');
    var content = hero.querySelector('.hero-content');
    var raf = null;
    hero.addEventListener('mousemove', function (e) {
        if (raf) return;
        raf = requestAnimationFrame(function () {
            var r = hero.getBoundingClientRect();
            var x = (e.clientX - r.left) / r.width  - 0.5;
            var y = (e.clientY - r.top)  / r.height - 0.5;
            /* aurora parallax da tat theo yeu cau */
            /* grid parallax da tat theo yeu cau */
            /* parallax nen theo chuot da tat theo yeu cau: chi de chu waves tuong tac */
            raf = null;
        });
    });
    hero.addEventListener('mouseleave', function () {
        if (aurora)  aurora.style.transform  = '';
        if (grid)    grid.style.transform    = '';
        if (content) content.style.transform = '';
    });
})();
</script>
</section>

<script>
(function () {
    var hero = document.querySelector('.hero-modern');
    if (!hero) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var aurora  = hero.querySelector('.hero-aurora');
    var grid    = hero.querySelector('.hero-bg-grid');
    var content = hero.querySelector('.hero-content');
    var raf = null;
    hero.addEventListener('mousemove', function (e) {
        if (raf) return;
        raf = requestAnimationFrame(function () {
            var r = hero.getBoundingClientRect();
            var x = (e.clientX - r.left) / r.width  - 0.5;
            var y = (e.clientY - r.top)  / r.height - 0.5;
            /* aurora parallax da tat theo yeu cau */
            /* grid parallax da tat theo yeu cau */
            /* parallax nen theo chuot da tat theo yeu cau: chi de chu waves tuong tac */
            raf = null;
        });
    });
    hero.addEventListener('mouseleave', function () {
        if (aurora)  aurora.style.transform  = '';
        if (grid)    grid.style.transform    = '';
        if (content) content.style.transform = '';
    });
})();
</script>

<script>
(function () {
    var hero = document.querySelector('.hero-modern');
    if (!hero) return;
    var cells = document.getElementById('heroGridCells');
    if (!cells) return;
    var CELL = 56;
    function build() {
        var w = hero.offsetWidth, h = hero.offsetHeight;
        var cols = Math.ceil(w / CELL) + 1;
        var rows = Math.ceil(h / CELL) + 1;
        var need = cols * rows;
        cells.style.gridTemplateColumns = 'repeat(' + cols + ', ' + CELL + 'px)';
        cells.style.gridAutoRows = CELL + 'px';
        var have = cells.childElementCount;
        if (have === need) return;
        if (have > need) {
            while (cells.childElementCount > need) cells.removeChild(cells.lastChild);
        } else {
            var frag = document.createDocumentFragment();
            for (var i = have; i < need; i++) {
                var c = document.createElement('span');
                c.className = 'hero-cell';
                frag.appendChild(c);
            }
            cells.appendChild(frag);
        }
    }
    build();
    var rt = null;
    window.addEventListener('resize', function () {
        clearTimeout(rt);
        rt = setTimeout(build, 150);
    });
    // Đồng bộ hiệu ứng trôi (parallax) để ô sáng luôn khớp với lưới mờ nền
    if (!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) {
        var raf = null;
        hero.addEventListener('mousemove', function (e) {
            if (raf) return;
            raf = requestAnimationFrame(function () {
                var r = hero.getBoundingClientRect();
                var x = (e.clientX - r.left) / r.width  - 0.5;
                var y = (e.clientY - r.top)  / r.height - 0.5;
                /* parallax luoi nen theo chuot da tat theo yeu cau */
                raf = null;
            });
        });
        hero.addEventListener('mouseleave', function () {
            cells.style.transform = '';
        });
    }
})();
</script>

<script>
(function () {
    var title = document.querySelector('.hero-scramble');
    if (!title) return;
    function graphemes(str) {
        if (typeof Intl !== 'undefined' && Intl.Segmenter) {
            try {
                var seg = new Intl.Segmenter('vi', { granularity: 'grapheme' });
                return Array.from(seg.segment(str), function (s) { return s.segment; });
            } catch (e) {}
        }
        return Array.from(str);
    }
    var chars = [];
    function wrapText(text, grad, parent) {
        graphemes(text).forEach(function (ch) {
            if (ch === ' ') { parent.appendChild(document.createTextNode(' ')); return; }
            if (ch === '\n' || ch === '\t' || ch === '\r') { return; }
            var s = document.createElement('span');
            s.className = 'hc' + (grad ? ' hc-grad' : '');
            s.textContent = ch;
            parent.appendChild(s);
            chars.push(s);
        });
    }
    var nodes = Array.prototype.slice.call(title.childNodes);
    var frag = document.createDocumentFragment();
    nodes.forEach(function (node) {
        if (node.nodeType === 3) { wrapText(node.textContent, false, frag); }
        else if (node.nodeName === 'BR') { frag.appendChild(document.createElement('br')); }
        else if (node.nodeType === 1) {
            var sp = document.createElement('span');
            if (node.className) sp.className = node.className;
            wrapText(node.textContent, true, sp);
            frag.appendChild(sp);
        }
    });
    title.innerHTML = '';
    title.appendChild(frag);

    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) { title.classList.add('assembled'); return; }

    function rnd(min, max) { return Math.random() * (max - min) + min; }
    chars.forEach(function (s) {
        s.style.setProperty('--tx', rnd(-220, 220).toFixed(1) + 'px');
        s.style.setProperty('--ty', rnd(-170, 170).toFixed(1) + 'px');
        s.style.setProperty('--rot', rnd(-140, 140).toFixed(1) + 'deg');
        s.style.setProperty('--sc', rnd(0.2, 1.7).toFixed(2));
        s.style.setProperty('--d', (Math.random() * 0.45).toFixed(2) + 's');
    });

    requestAnimationFrame(function () {
        title.classList.add('scramble');
        setTimeout(function () { title.classList.add('assembled'); }, 750);
    });
})();
</script>


<style>
/* ===== Hero waves: tieu de 3D nhieu lop, parallax theo chuot ===== */
/* Xoa het hieu ung nen trang tri (bong bay / banner) va tuong tac chuot theo yeu cau */
.hero-bg-grid,.hero-grid-cells,.hero-aurora,.hero-particles,.hero-shapes{display:none !important;}
/* An gach ngang (border-bottom) duoi tieu de muc, giu nguyen nav */
.cm-section-head{border-bottom:none !important; padding-bottom:0 !important;}
.cmwave-box{position:relative; display:block;}
.cmwave-main{position:relative; z-index:6;}
.cmwave-copy{position:absolute; top:0; left:0; width:100%; margin:0 !important; will-change:transform; pointer-events:none; user-select:none;}
.cmwave-box .cmwave-copy,
.cmwave-box .cmwave-copy span{-webkit-text-fill-color:initial !important; background:none !important; animation:none !important;}
.cmwave-box .cw1, .cmwave-box .cw1 span{color:#6d28d9 !important;}
.cmwave-box .cw2, .cmwave-box .cw2 span{color:#9333ea !important;}
.cmwave-box .cw3, .cmwave-box .cw3 span{color:#06b6d4 !important;}
.cmwave-box .cw4, .cmwave-box .cw4 span{color:#f97316 !important;}
.cw1{z-index:5; transform:perspective(600px) translate3d(0,0,-12px);}
.cw2{z-index:4; transform:perspective(600px) translate3d(0,0,-24px);}
.cw3{z-index:3; transform:perspective(600px) translate3d(0,0,-36px);}
.cw4{z-index:2; transform:perspective(600px) translate3d(0,0,-48px);}
@media (prefers-reduced-motion: reduce){.cmwave-copy{display:none;}}
</style>
<script>
(function(){
  var box = document.querySelector('.cmwave-box');
  if(!box) return;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if(reduce) return;
  var copies = box.getElementsByClassName('cmwave-copy');
  if(!copies.length) return;
  var center={x:0,y:0}, dist={x:0,y:0}, lerp={x:0,y:0}, sim=true;
  var PERSP=600, TZ=-12, ROT=2, SKEW=1.5;
  function updateCenter(){ var r=box.getBoundingClientRect(); center.x=r.left+r.width/2; center.y=r.top+r.height/2; }
  function track(e){ sim=false; dist.x=center.x-e.clientX; dist.y=center.y-e.clientY; }
  function fake(t){ dist.x=Math.sin(t/1000)*window.innerWidth*0.28; dist.y=Math.cos(t/1000)*window.innerWidth*0.10; }
  function tf(y,z,rot,sk){ return 'perspective('+PERSP+'px) translate3d(0px,'+y+'px,'+z+'px) rotate('+rot+'deg) skew('+sk+'deg)'; }
  function frame(t){
    if(sim) fake(t);
    lerp.x+=(dist.x-lerp.x)*0.15; lerp.y+=(dist.y-lerp.y)*0.15;
    for(var i=1;i<=copies.length;i++){
      copies[i-1].style.transform = tf(i*lerp.y*0.02, i*TZ, i*ROT*(lerp.x*0.003), i*SKEW*(lerp.x*0.003));
    }
    requestAnimationFrame(frame);
  }
  updateCenter();
  document.addEventListener('mousemove', track);
  window.addEventListener('resize', updateCenter);
  window.addEventListener('scroll', updateCenter, {passive:true});
  requestAnimationFrame(frame);
})();
</script>

<section class="section" id="listings">
    <div class="container">
        <form class="filterbar" method="get">
            <input type="text" name="q" placeholder="Tìm kiếm theo tên sản phẩm..." value="<?= e($catName ?? $q) ?>">
            <button class="btn btn-primary" type="submit">Lọc</button>
        </form>

        <?php if ($type === 'all' || $type === 'code'): ?>
        <div class="cm-global-tabs" role="tablist">
            <?php $__gi = 0; foreach ($TAB_CATS as $key => $info): ?>
                <button type="button" class="cm-cat-tab<?= $__gi === 0 ? ' active' : '' ?>" data-tabkey="<?= e($key) ?>">
                    <span><?= e($info['label']) ?></span>
                </button>
            <?php $__gi++; endforeach; ?>
        </div>
        <?php endif; ?>

     <?php if ($type === 'all' || $type === 'code'): ?>

    <?php function render_product_grid($items, $seeMoreUrl = null) { ?>
        <div class="grid cm-code-grid-alt">
            <?php foreach ($items as$item): ?>
            <a class="cm-alt-card" href="<?= code_url($item['id'],$item['title']) ?>">
                <div class="cm-alt-thumb">
                    <?php if (!empty($item['carousel_images'])): ?>
                        <div class="cm-carousel" data-index="0">
                            <div class="cm-carousel-inner">
                                <?php foreach ($item['carousel_images'] as$img): ?>
                                    <img src="<?= UPLOAD_URL_CODE . e($img) ?>" alt="<?= e($item['title']) ?> - ảnh preview". loading="lazy">
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
                        <?php if (!empty($item['verified']) &&$item['price'] != 0): ?>
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
                        <?php foreach (array_filter(array_map('trim', explode(',', $item['category'] ?? ''))) as $cat): ?>
                            <span class="cm-alt-cat-chip" style="font-size:12px;color:#3730a3;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;padding:2px 8px;line-height:1.5"><?= e($cat) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <br>
                    
                    <div class="cm-alt-stats">
                        <span>🛒 <?= number_format($item['purchase_count'] ?? 0) ?> lượt mua</span>
                        <?php if (!empty($item['review_count'])): ?>
                            <span class="rating">★ <?= round($item['avg_rating'], 1) ?> (<?=$item['review_count'] ?>)</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($item['category'])): ?>
                   
                    <?php endif; ?>

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
        <?php if ($seeMoreUrl !== null && count($items) >= 8): ?>
            <div class="cm-loadmore-hint">
                <a href="<?= $seeMoreUrl ?>">
                    Còn nữa <span class="cm-loadmore-arrow">— ấn để xem tiếp →</span>
                </a>
            </div>
        <?php endif; ?>
    <?php } ?>

    <?php function render_tab_block($blockId, $title, $seeMoreUrl, $tabCats, $itemsByTab, $emptyText) { ?>
        <div class="cm-category-block">
            <div class="section-head cm-section-head">
                <h2><?= $title ?></h2>
                <span class="desc"><a href="<?= $seeMoreUrl ?>">Xem thêm →</a></span>
            </div>
            <div class="cm-cat-panel-group" data-block="<?= e($blockId) ?>">
                <?php $__i = 0; foreach ($tabCats as $key => $info): ?>
                    <div class="cm-cat-panel<?= $__i === 0 ? ' active' : '' ?>" data-tabkey="<?= e($key) ?>">
                        <?php if (empty($itemsByTab[$key])): ?>
                            <div class="empty"><div class="icon">{ }</div><?= e($emptyText) ?></div>
                        <?php else: ?>
                            <?php render_product_grid($itemsByTab[$key], $seeMoreUrl); ?>
                        <?php endif; ?>
                    </div>
                <?php $__i++; endforeach; ?>
            </div>
        </div>
    <?php } ?>

    <?php
build_product_jsonld([$newItems, $freeItems, $paidItems, $bestSellerItems]);

render_tab_block('free', '📦 Các mẫu mã nguồn miễn phí', BASE_URL . '/code_list?filter=free', $TAB_CATS, $freeItemsByTab, 'Hiện tại chưa có mẫu miễn phí nào.');
render_tab_block('paid', '💰 Các mẫu mã nguồn có phí', BASE_URL . '/code_list?filter=paid', $TAB_CATS, $paidItemsByTab, 'Hiện tại chưa có mẫu có phí nào.');
render_tab_block('new', '✨ Bản phát hành mới nhất', BASE_URL . '/code_list?filter=new', $TAB_CATS, $newItemsByTab, 'Chưa có sản phẩm nào.');
render_tab_block('best', '🔥 Sản phẩm bán chạy nhất', BASE_URL . '/code_list?filter=views', $TAB_CATS, $bestSellerItemsByTab, 'Chưa có dữ liệu.');
?>

<script>
    document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll('.cm-alt-thumb').forEach(thumb => {
        const img = thumb.querySelector('img');
        if (img) {
            thumb.style.setProperty('--bg-blur-img', `url('${img.src}')`);
        }
    });
});
</script>
<?php endif; ?>

       <?php if ($type === 'all' || $type === 'hosting'): ?>
<section class="cm-hosting-section" style="margin-top: 70px;">
    <div class="section-head cm-section-head">
        <div class="cm-sh-left">
            <span class="cm-sh-badge">🚀 HIGH-PERFORMANCE CLOUD</span>
            <h2>Gói Hosting & Máy Chủ Tốc Độ Cao</h2>
        </div>
        <span class="desc"><a href="<?= BASE_URL ?>/hosting">Khám phá cấu hình chi tiết →</a></span>
    </div>

    <?php if (!$hostingPlans): ?>
        <div class="empty-card">
            <div class="icon">☁</div>
            <p>Hệ thống đang cập nhật các gói hosting mới nhất.</p>
        </div>
    <?php else: ?>
        <?php $__multi = count($hostingGroups) > 1; ?>
        
        <?php if ($__multi): ?>
        <div class="hosting-tabs-wrapper">
            <div class="hosting-tabs" role="tablist">
                <?php $__ti = 0; foreach ($hostingGroups as $__gid => $__g): ?>
                <button type="button" class="hosting-tab<?= $__ti === 0 ? ' active' : '' ?>" data-target="idx-srv-<?= (int)$__gid ?>">
                    <span><?= e($__g['name']) ?></span>
                </button>
                <?php $__ti++; endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php $__pi = 0; foreach ($hostingGroups as $__gid => $__g): ?>
        <div class="hosting-panel<?= (!$__multi || $__pi === 0) ? ' active' : '' ?>" id="idx-srv-<?= (int)$__gid ?>">
            <div class="hosting-grid">
                <?php foreach ($__g['plans'] as $item):
                    $features = array_filter(array_map('trim', explode("\n", $item['features'] ?? '')));
                    $isPopular = !empty($item['is_popular']);
                ?>
                <div class="hosting-card <?= $isPopular ? 'popular' : '' ?>">
                    <?php if ($isPopular): ?>
                        <div class="hosting-popular-badge">
                            <span>⭐ LỰA CHỌN TỐT NHẤT</span>
                        </div>
                    <?php endif; ?>

                    <div class="hosting-card-header">
                        <h3 class="hosting-title"><?= e($item['name']) ?></h3>
                        <p class="hosting-desc"><?= e($item['description']) ?></p>
                    </div>

                    <div class="hosting-pricing">
                        <div class="hosting-price-wrap">
                            <span class="hosting-currency">₫</span>
                            <span class="hosting-price mono"><?= number_format($item['price_month'], 0, ',', '.') ?></span>
                        </div>
                        <span class="hosting-period">/ tháng</span>
                    </div>

                    <div class="hosting-specs">
                        <div class="hosting-spec-item">
                            <span>Dung lượng</span>
                            <b><?= e($item['disk_quota_display'] ?: '-') ?></b>
                        </div>
                        <div class="hosting-spec-item">
                            <span>Băng thông tốc độ cao</span>
                            <b><?= e($item['bandwidth_display'] ?: '-') ?></b>
                        </div>
                        <div class="hosting-spec-item">
                            <span>Số website tối đa</span>
                            <b><?= e($item['max_domains'] ?? '1') ?> Domain</b>
                        </div>
                        <div class="hosting-spec-item">
                            <span>Bảo mật SSL</span>
                            <b class="ssl-active"><?= $item['free_ssl'] ? 'Miễn phí AutoSSL' : '—' ?></b>
                        </div>
                    </div>

                    <?php if ($features): ?>
                    <ul class="hosting-features-list">
                        <?php foreach ($features as $f): ?>
                            <li>
                                <div class="check-icon">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                </div>
                                <span><?= e($f) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>

                    <div class="hosting-card-footer">
                        <a href="<?= BASE_URL ?>/hosting_buy?plan=<?= $item['id'] ?>" class="btn <?= $isPopular ? 'btn-primary' : 'btn-outline' ?> btn-block hosting-cta-btn">
                            <span>Đăng ký ngay</span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php $__pi++; endforeach; ?>
    <?php endif; ?>
</section>
<?php endif; ?>
    </div>


<style>
.hosting-tabs{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin:22px 0 26px;}
.hosting-tab{padding:9px 20px;border:1px solid var(--border);background:#fff;color:var(--muted);border-radius:999px;font-weight:600;font-size:14px;cursor:pointer;transition:.2s;}
.hosting-tab:hover{border-color:var(--accent);color:var(--accent);}
.hosting-tab.active{background:var(--accent);border-color:var(--accent);color:#fff;}
.hosting-panel{display:none;animation:hostingFade .35s ease;}
.hosting-panel.active{display:block;}
@keyframes hostingFade{from{opacity:0;transform:translateY(6px);}to{opacity:1;transform:none;}}

/* ===== Thanh tab dùng chung (Tất cả / Game / Ứng dụng / Phần mềm / Website) ===== */
/* Điều khiển đồng thời tất cả các khối: Miễn phí, Có phí, Mới nhất, Bán chạy */
.cm-global-tabs{display:flex;flex-wrap:wrap;gap:10px;margin:18px 0 30px;}
.cm-cat-tab{padding:8px 18px;border:1px solid var(--border);background:#fff;color:var(--muted);border-radius:999px;font-weight:600;font-size:14px;cursor:pointer;transition:.2s;}
.cm-cat-tab:hover{border-color:var(--accent);color:var(--accent);}
.cm-cat-tab.active{background:var(--accent);border-color:var(--accent);color:#fff;}
.cm-cat-panel{display:none;animation:cmCatFade .3s ease;}
.cm-cat-panel.active{display:block;}
@keyframes cmCatFade{from{opacity:0;transform:translateY(6px);}to{opacity:1;transform:none;}}

/* ===== "Còn nữa - ấn để xem tiếp" ===== */
.cm-loadmore-hint{
    text-align:center;
    margin-top:18px;
}
.cm-loadmore-hint a{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:10px 22px;
    border:1px dashed var(--border);
    border-radius:999px;
    color:var(--accent);
    font-weight:600;
    font-size:14px;
    text-decoration:none;
    transition:.2s;
}
.cm-loadmore-hint a:hover{
    background:var(--accent);
    border-color:var(--accent);
    color:#fff;
}
.cm-loadmore-arrow{
    font-weight:500;
    opacity:.85;
}
</style>
<script>
(function(){
    var tabs = document.querySelectorAll('.hosting-tab');
    if (!tabs.length) return;
    tabs.forEach(function(tab){
        tab.addEventListener('click', function(){
            var id = tab.getAttribute('data-target');
            tabs.forEach(function(t){ t.classList.remove('active'); });
            tab.classList.add('active');
            document.querySelectorAll('.hosting-panel').forEach(function(p){
                p.classList.toggle('active', p.id === id);
            });
        });
    });
})();

(function(){
    // Thanh tab dùng chung: 1 click -> đổi tất cả các khối cùng lúc
    var tabs = document.querySelectorAll('.cm-global-tabs .cm-cat-tab');
    if (!tabs.length) return;
    tabs.forEach(function(tab){
        tab.addEventListener('click', function(){
            var key = tab.getAttribute('data-tabkey');
            tabs.forEach(function(t){ t.classList.remove('active'); });
            tab.classList.add('active');
            document.querySelectorAll('.cm-cat-panel').forEach(function(p){
                p.classList.toggle('active', p.getAttribute('data-tabkey') === key);
            });
        });
    });
})();
</script>

<!-- ===== Features Section (Cabinet Slide) ===== -->
<section class="features-section">
<div class="container">
<div class="features-badge">Cam kết từ CodeMarket</div>
<h2 class="features-title">Mua bán source code an toàn, không lo mất tiền</h2>
<p class="features-subtitle">Tiền của bạn được giữ an toàn cho tới khi nhận đúng sản phẩm. Mọi mã nguồn đều được kiểm duyệt trước khi lên sàn.</p>

<div class="features-grid">
<!-- Card 1 -->
<div class="cabinet-card">
<div class="cabinet-front">
<div class="feature-icon">🛡️</div>
<h3>Giữ tiền ký quỹ – Hoàn tiền nếu lỗi</h3>
<p>Tiền chỉ được chuyển cho người bán khi bạn xác nhận đã nhận đủ và đúng sản phẩm. Chưa hài lòng, chưa giải ngân.</p>
<span class="cabinet-hint">Xem chi tiết ▾</span>
</div>
<div class="cabinet-drawer">
<h4>Cơ chế ký quỹ an toàn</h4>
<p>Hệ thống giữ hộ toàn bộ số tiền trong suốt giao dịch. Nếu sản phẩm không đúng mô tả hoặc bị lỗi, bạn được mở tranh chấp và hoàn tiền — quyền lợi của cả người mua lẫn người bán đều được bảo vệ.</p>
<a href="#listings" class="feature-link">Khám phá ngay →</a>
</div>
</div>

<!-- Card 2 -->
<div class="cabinet-card">
<div class="cabinet-front">
<div class="feature-icon">✅</div>
<h3>Source sạch – Đã kiểm duyệt</h3>
<p>Mọi mã nguồn được đội ngũ rà soát trước khi đăng bán, đảm bảo hoạt động đúng mô tả và không chứa mã độc.</p>
<span class="cabinet-hint">Xem chi tiết ▾</span>
</div>
<div class="cabinet-drawer">
<h4>Kiểm duyệt trước khi lên sàn</h4>
<p>Sản phẩm phải khớp 100% với hình ảnh và mô tả, có đầy đủ file chạy và hướng dẫn cài đặt. Mọi trường hợp vi phạm bản quyền hoặc chèn mã độc đều bị gỡ bỏ và xử lý ngay.</p>
<a href="#listings" class="feature-link">Khám phá ngay →</a>
</div>
</div>

<!-- Card 3 -->
<div class="cabinet-card">
<div class="cabinet-front">
<div class="feature-icon">💳</div>
<h3>Thanh toán an toàn, minh bạch</h3>
<p>Nạp/rút 24/7 qua ví điện tử và ngân hàng, lịch sử giao dịch rõ ràng, thông tin được mã hóa bảo mật.</p>
<span class="cabinet-hint">Xem chi tiết ▾</span>
</div>
<div class="cabinet-drawer">
<h4>Giao dịch rõ ràng từng đồng</h4>
<p>Hỗ trợ ví điện tử, thẻ ngân hàng nội địa và quốc tế. Mọi khoản nạp, mua, rút đều được ghi lại chi tiết trong ví của bạn — không phí ẩn, không lo thất thoát.</p>
<a href="#listings" class="feature-link">Khám phá ngay →</a>
</div>
</div>

<!-- Card 4 -->
<div class="cabinet-card">
<div class="cabinet-front">
<div class="feature-icon">🤝</div>
<h3>Hỗ trợ & bảo hành sau mua</h3>
<p>Kết nối trực tiếp với người bán để được hỗ trợ cài đặt, sửa lỗi và bảo hành đúng cam kết.</p>
<span class="cabinet-hint">Xem chi tiết ▾</span>
</div>
<div class="cabinet-drawer">
<h4>Đồng hành sau khi mua</h4>
<p>Phí tải đã bao gồm chi phí bảo hành: người bán có nghĩa vụ hỗ trợ khắc phục lỗi khi bạn liên hệ. Cần cài đặt tận nơi? Nhiều sản phẩm có sẵn dịch vụ cài đặt lên hosting/VPS.</p>
<a href="#listings" class="feature-link">Khám phá ngay →</a>
</div>
</div>
</div>
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
    if (currentIndex < 0) {
        currentIndex = imagesCount - 1;
    } else if (currentIndex >= imagesCount) {
        currentIndex = 0;
    }

    carousel.setAttribute('data-index', currentIndex);
    inner.style.transform = `translateX(-${currentIndex * 100}%)`;
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>