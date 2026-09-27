<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

$plans = $pdo->query("SELECT hp.*, hs.name AS server_name FROM hosting_plans hp LEFT JOIN hosting_servers hs ON hs.id = hp.server_id WHERE hp.status='active' ORDER BY hp.sort_order ASC, hp.id ASC")->fetchAll();
$servers = $pdo->query("SELECT id, name FROM hosting_servers WHERE status='active' ORDER BY sort_order ASC, id ASC")->fetchAll();

// Gom goi theo server (moi server = 1 tab)
$groups = [];
foreach ($servers as $s) { $groups[(int)$s['id']] = ['name' => $s['name'], 'plans' => []]; }
foreach ($plans as $p) {
    $sid = ($p['server_id'] !== null) ? (int)$p['server_id'] : 0;
    if ($sid && isset($groups[$sid])) {
        $groups[$sid]['plans'][] = $p;
    } else {
        if (!isset($groups[0])) $groups[0] = ['name' => ($p['server_name'] ?: 'Khac'), 'plans' => []];
        $groups[0]['plans'][] = $p;
    }
}
$groups = array_filter($groups, function ($g) { return count($g['plans']) > 0; });

$page_title = 'Bang gia Hosting';
require_once __DIR__ . '/includes/header.php';
?>
<section class="hero">
    <div class="container">
        <div class="hero-eyebrow">● hosting powered by WHM/cPanel</div>
        <h1>Chọn gói <span>Hosting</span><br>phù hợp với website của bạn.</h1>
        <p class="lead">Tài khoản hosting được cấp <b>tự động ngay sau khi thanh toán</b>.</p>
    </div>
</section>

<section class="cm-hosting-section section" style="padding: 50px 0 80px;">
    <div class="container">
        <?php if (!$groups): ?>
            <div class="empty"><div class="icon">☁</div>Hiện chưa có gói hosting nào. Vui lòng quay lại sau.</div>
        <?php else: ?>
        <?php $tabKeys = array_keys($groups); $multi = count($groups) > 1; ?>
        
        <?php if ($multi): ?>
        <div class="hosting-tabs-wrapper">
            <div class="hosting-tabs" role="tablist">
                <?php foreach ($tabKeys as $i => $k): ?>
                <button type="button" class="hosting-tab<?= $i === 0 ? ' active' : '' ?>" data-target="srv-<?= $k ?>">
                    <span><?= e($groups[$k]['name']) ?></span>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php foreach ($tabKeys as $i => $k): ?>
        <div class="hosting-panel<?= $i === 0 ? ' active' : '' ?>" id="srv-<?= $k ?>">
            <div class="hosting-grid">
                <?php foreach ($groups[$k]['plans'] as $p):
                    $features = array_filter(array_map('trim', explode("\n", $p['features'] ?? '')));
                    $isPopular = !empty($p['is_popular']);
                ?>
                <div class="hosting-card <?= $isPopular ? 'popular' : '' ?>">
                    <?php if ($isPopular): ?>
                        <div class="hosting-popular-badge">
                            <span>⭐ LỰA CHỌN TỐT NHẤT</span>
                        </div>
                    <?php endif; ?>

                    <div class="hosting-card-header">
                        <h3 class="hosting-title"><?= e($p['name']) ?></h3>
                        <p class="hosting-desc"><?= e($p['description']) ?></p>
                    </div>

                    <div class="hosting-pricing">
                        <div class="hosting-price-wrap">
                            <span class="hosting-currency">₫</span>
                            <span class="hosting-price mono"><?= number_format($p['price_month'], 0, ',', '.') ?></span>
                        </div>
                        <span class="hosting-period">/ tháng</span>
                    </div>

                    <div class="hosting-specs">
                        <div class="hosting-spec-item">
                            <span>Dung lượng</span>
                            <b><?= e($p['disk_quota_display'] ?: '-') ?></b>
                        </div>
                        <div class="hosting-spec-item">
                            <span>Băng thông tốc độ cao</span>
                            <b><?= e($p['bandwidth_display'] ?: '-') ?></b>
                        </div>
                        <div class="hosting-spec-item">
                            <span>Số website tối đa</span>
                            <b><?= e($p['max_domains'] ?? '1') ?> Domain</b>
                        </div>
                        <div class="hosting-spec-item">
                            <span>Bảo mật SSL</span>
                            <b class="ssl-active"><?= $p['free_ssl'] ? 'Miễn phí AutoSSL' : '—' ?></b>
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
                        <a href="<?= BASE_URL ?>/hosting_buy?plan=<?= $p['id'] ?>" class="btn <?= $isPopular ? 'btn-primary' : 'btn-outline' ?> btn-block hosting-cta-btn">
                            <span>Đăng ký ngay</span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<script>
(function(){
    var tabs = document.querySelectorAll('.hosting-tab');
    tabs.forEach(function(t){
        t.addEventListener('click', function(){
            document.querySelectorAll('.hosting-tab').forEach(function(x){x.classList.remove('active');});
            document.querySelectorAll('.hosting-panel').forEach(function(x){x.classList.remove('active');});
            t.classList.add('active');
            var el = document.getElementById(t.getAttribute('data-target'));
            if(el) el.classList.add('active');
        });
    });
})();
</script>

<style>
.hosting-tabs{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-bottom:26px;}
.hosting-tab{padding:10px 22px;border:1px solid var(--border);background:#fff;border-radius:999px;font-weight:600;font-size:15px;color:var(--text);cursor:pointer;transition:.18s;}
.hosting-tab:hover{border-color:var(--accent);color:var(--accent);}
.hosting-tab.active{background:var(--accent);border-color:var(--accent);color:#fff;}
.hosting-panel{display:none;}
.hosting-panel.active{display:block;animation:hostingFade .25s ease;}
@keyframes hostingFade{from{opacity:0;transform:translateY(6px);}to{opacity:1;transform:none;}}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
