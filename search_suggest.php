<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/seo_functions.php';

header('Content-Type: application/json; charset=utf-8');

$q     = trim($_GET['q'] ?? '');
$debug = isset($_GET['debug']);
$items = [];
$err   = null;

function run_suggest($pdo, $q, $withKeywords, $withImg) {
    $imgExpr = $withImg
        ? "COALESCE((SELECT li.image_path FROM listing_images li
                     WHERE li.item_type='code' AND li.item_id = cl.id
                     ORDER BY li.sort_order ASC LIMIT 1), cl.demo_image)"
        : "NULL";

    if ($q === '') {
        $sql = "SELECT cl.id, cl.title, cl.category, cl.price, $imgExpr AS img
                FROM code_listings cl
                WHERE cl.status='active'
                ORDER BY cl.views DESC, cl.created_at DESC LIMIT 6";
        $st = $pdo->prepare($sql);
        $st->execute();
    } else {
        $like = '%' . $q . '%';
        $cond = "cl.title LIKE ? OR cl.category LIKE ?" . ($withKeywords ? " OR cl.keywords LIKE ?" : "");
        $params = $withKeywords ? [$like, $like, $like, $q . '%'] : [$like, $like, $q . '%'];
        $sql = "SELECT cl.id, cl.title, cl.category, cl.price, $imgExpr AS img
                FROM code_listings cl
                WHERE cl.status='active' AND ($cond)
                ORDER BY CASE WHEN cl.title LIKE ? THEN 0 ELSE 1 END,
                         cl.views DESC, cl.created_at DESC LIMIT 8";
        $st = $pdo->prepare($sql);
        $st->execute($params);
    }
    return $st->fetchAll();
}

$rows = [];
try {
    $rows = run_suggest($pdo, $q, true, true);
} catch (Throwable $e1) {
    try { $rows = run_suggest($pdo, $q, false, true); }
    catch (Throwable $e2) {
        try { $rows = run_suggest($pdo, $q, false, false); }
        catch (Throwable $e3) { $err = $e3->getMessage(); $rows = []; }
    }
}

foreach ($rows as $r) {
    $items[] = [
        'title'    => $r['title'],
        'url'      => code_url($r['id'], $r['title']),
        'image'    => !empty($r['img']) ? UPLOAD_URL_CODE . $r['img'] : '',
    ];
}

$out = ['q' => $q, 'items' => $items];
if ($debug && $err) $out['error'] = $err;
echo json_encode($out, JSON_UNESCAPED_UNICODE);
