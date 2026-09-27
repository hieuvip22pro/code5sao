<?php
// Sitemap dong: trang chu, danh muc code, hosting + toan bo code dang ban.
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/seo_functions.php';
header('Content-Type: application/xml; charset=UTF-8');

// Chuẩn hóa lại việc ghép domain để tránh bị lặp (vd: https://code5sao.comhttps://code5sao.com)
$base = rtrim(BASE_URL, '/');
if (strpos($base, 'http') === 0) {
    $root = $base;
} else {
    $origin = rtrim(seo_site_origin(), '/');
    $root = $origin . ($base ? '/' . ltrim($base, '/') : '');
}

$urls = [];
$urls[] = ['loc' => $root . '/',               'priority' => '1.0', 'changefreq' => 'daily'];
$urls[] = ['loc' => $root . '/index?type=code', 'priority' => '0.9', 'changefreq' => 'daily'];
$urls[] = ['loc' => $root . '/hosting',         'priority' => '0.7', 'changefreq' => 'weekly'];
$urls[] = ['loc' => $root . '/code_list?filter=new',  'priority' => '0.6', 'changefreq' => 'daily'];
$urls[] = ['loc' => $root . '/code_list?filter=free', 'priority' => '0.6', 'changefreq' => 'daily'];
$urls[] = ['loc' => $root . '/code_list?filter=paid', 'priority' => '0.6', 'changefreq' => 'daily'];
$urls[] = ['loc' => $root . '/code_list?filter=views','priority' => '0.6', 'changefreq' => 'daily'];

try {
    $rows = $pdo->query(
        "SELECT id, title, created_at FROM code_listings WHERE status = 'active' ORDER BY id DESC LIMIT 5000"
    )->fetchAll();

    foreach ($rows as $r) {
        // Dùng đúng hàm code_url() giống toàn bộ site
        $loc = code_url($r['id'], $r['title']);
        
        // Đảm bảo là URL tuyệt đối — nếu chưa có http thì nối với $root
        if (strpos($loc, 'http') !== 0) {
            $loc = $root . (strpos($loc, '/') === 0 ? $loc : '/' . $loc);
        }

        $urls[] = [
            'loc'        => $loc,
            'lastmod'    => date('Y-m-d', strtotime($r['created_at'])),
            'priority'   => '0.8',
            'changefreq' => 'weekly',
        ];
    }
} catch (Exception $e) { /* bang chua ton tai -> bo qua */ }

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($u['loc'], ENT_QUOTES) . "</loc>\n";
    if (!empty($u['lastmod']))    echo '    <lastmod>' . $u['lastmod'] . "</lastmod>\n";
    if (!empty($u['changefreq'])) echo '    <changefreq>' . $u['changefreq'] . "</changefreq>\n";
    if (!empty($u['priority']))   echo '    <priority>' . $u['priority'] . "</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>' . "\n";