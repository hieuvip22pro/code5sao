<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id && isset($_GET['slug'])) {
    // fallback: lay id o cuoi slug dang ten-bai-12
    if (preg_match('/(\d+)$/', $_GET['slug'], $mm)) $id = (int)$mm[1];
}
$s = $pdo->prepare("SELECT b.*, u.username FROM blog_posts b LEFT JOIN users u ON u.id=b.author_id WHERE b.id=?");
$s->execute([$id]);
$post = $s->fetch();
if (!$post || $post['status'] !== 'published') { http_response_code(404); die('Không tìm thấy bài viết.'); }

// URL than thien 301
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $wantPath = '/blog/' . code_slug($post['title']) . '-' . $id;
    $basePath = parse_url(BASE_URL, PHP_URL_PATH);
    if ($basePath) $wantPath = rtrim($basePath, '/') . $wantPath;
    $reqPath = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    if (rtrim($reqPath, '/') !== rtrim($wantPath, '/')) { header('Location: ' . $wantPath, true, 301); exit; }
}

$pdo->prepare('UPDATE blog_posts SET views = views + 1 WHERE id=?')->execute([$id]);

$origin = seo_site_origin();
$canonical = $origin . rtrim(BASE_URL, '/') . '/blog/' . code_slug($post['title']) . '-' . $id;
$page_title = $post['title'];
$meta_description = seo_desc($post['excerpt'] ?: strip_tags($post['content']));
$og_type = 'article';
if ($post['cover_image']) $og_image = $post['cover_image'];

$related = $pdo->prepare("SELECT id,title FROM blog_posts WHERE status='published' AND id<>? ORDER BY created_at DESC LIMIT 5");
$related->execute([$id]); $related = $related->fetchAll();
$sidebar_related = $related;
$sidebar_current_id = $id;
require_once __DIR__ . '/includes/header.php';
?>
<div class="section"><div class="container">
  <div class="cm-blog-layout">
    <aside class="cm-blog-side"><?php require __DIR__ . '/includes/blog_sidebar.php'; ?></aside>
    <div class="cm-blog-main cm-article">
  <a href="<?= BASE_URL ?>/blog" class="hint">← Về Blog</a>
  <h1><?= e($post['title']) ?></h1>
  <p class="hint">Đăng bởi <?= e($post['username'] ?: 'CodeMarket') ?> · <?= date('d/m/Y', strtotime($post['created_at'])) ?> · <?= (int)$post['views'] ?> lượt xem</p>
  <?php if ($post['cover_image']): ?><img class="cm-article-cover" src="<?= e($post['cover_image']) ?>" alt="<?= e($post['title']) ?>"><?php endif; ?>
  <div class="cm-article-content"><?= render_rich($post['content']) ?></div>
    </div><!-- /cm-blog-main -->
  </div><!-- /cm-blog-layout -->
</div></div>
<style>
.cm-article{max-width:780px;margin:0 auto}
.cm-article h1{font-size:30px;margin:10px 0 6px;line-height:1.25}
.cm-article-cover{width:100%;border-radius:14px;margin:16px 0}
.cm-article-content{font-size:16px;line-height:1.8;color:#1f2937}
.cm-article-content img{max-width:100%;height:auto;border-radius:10px;margin:12px 0}
.cm-article-content h2,.cm-article-content h3{margin:22px 0 10px;line-height:1.3}
.cm-article-content ul,.cm-article-content ol{padding-left:24px;margin:12px 0}
.cm-article-content blockquote{border-left:4px solid var(--accent);padding:6px 16px;margin:14px 0;color:#4b5563;background:#f9fafb;border-radius:0 8px 8px 0}
.cm-article-content pre{background:#0d1117;color:#e6edf3;padding:14px;border-radius:10px;overflow:auto}
.cm-related{margin-top:36px;border-top:1px solid var(--border);padding-top:20px}
.cm-related a{color:var(--accent)}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
