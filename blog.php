<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $stmt = $pdo->prepare("SELECT * FROM blog_posts WHERE status='published' AND (title LIKE ? OR excerpt LIKE ?) ORDER BY created_at DESC LIMIT 60");
    $stmt->execute(['%' . $q . '%', '%' . $q . '%']);
} else {
    $stmt = $pdo->query("SELECT * FROM blog_posts WHERE status='published' ORDER BY created_at DESC LIMIT 60");
}
$posts = $stmt->fetchAll();

$page_title = 'Blog & Thủ thuật';
$meta_description = 'Blog CodeMarket - hướng dẫn lập trình, thủ thuật source code, kinh nghiệm mua bán mã nguồn, tin tức công nghệ.';
require_once __DIR__ . '/includes/header.php';
?>
<div class="section"><div class="container">
  <div class="section-head"><h2>📝 Blog & Thủ thuật</h2></div>
  <div class="cm-blog-layout">
    <aside class="cm-blog-side"><?php require __DIR__ . '/includes/blog_sidebar.php'; ?></aside>
    <div class="cm-blog-main">
  <form method="get" style="margin-bottom:20px;max-width:420px;display:flex;gap:8px;">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Tìm bài viết..." style="flex:1;">
    <button class="btn btn-primary">Tìm</button>
  </form>
  <?php if (!$posts): ?><div class="empty">Chưa có bài viết nào.</div><?php else: ?>
    <div class="cm-blog-grid">
      <?php foreach ($posts as $p): $url = BASE_URL . '/blog/' . code_slug($p['title']) . '-' . $p['id']; ?>
        <a class="cm-blog-card" href="<?= $url ?>">
          <?php if ($p['cover_image']): ?>
            <div class="cm-blog-cover"><img src="<?= e($p['cover_image']) ?>" alt="<?= e($p['title']) ?>" loading="lazy"></div>
          <?php else: ?>
            <div class="cm-blog-cover cm-blog-cover-ph">&lt;/&gt;</div>
          <?php endif; ?>
          <div class="cm-blog-body">
            <h3><?= e($p['title']) ?></h3>
            <p><?= e($p['excerpt'] ?: mb_substr(strip_tags($p['content']), 0, 120) . '...') ?></p>
            <span class="hint"><?= date('d/m/Y', strtotime($p['created_at'])) ?> · <?= (int)$p['views'] ?> lượt xem</span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
    </div><!-- /cm-blog-main -->
  </div><!-- /cm-blog-layout -->
</div></div>
<style>
.cm-blog-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px}
.cm-blog-card{display:flex;flex-direction:column;border:1px solid var(--border);border-radius:14px;overflow:hidden;text-decoration:none;color:inherit;background:#fff;transition:.15s}
.cm-blog-card:hover{transform:translateY(-3px);box-shadow:0 10px 30px rgba(0,0,0,.08);border-color:var(--accent)}
.cm-blog-cover{height:160px;overflow:hidden}
.cm-blog-cover img{width:100%;height:100%;object-fit:cover}
.cm-blog-cover-ph{display:flex;align-items:center;justify-content:center;font-family:monospace;font-size:38px;color:#cbd5e1;background:linear-gradient(135deg,#f1f5f9,#e2e8f0)}
.cm-blog-body{padding:16px}
.cm-blog-body h3{font-size:17px;margin:0 0 8px;line-height:1.35}
.cm-blog-body p{font-size:14px;color:var(--muted);margin:0 0 10px;line-height:1.5}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
