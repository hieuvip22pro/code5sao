<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/business_functions.php';
require_login();
$uid = current_user_id();

$code = ensure_affiliate_code($pdo, $uid);
$origin = function_exists('seo_site_origin') ? seo_site_origin() : '';
$refLink = $origin . rtrim(BASE_URL, '/') . '/register?ref=' . $code;

$refCount = $pdo->prepare('SELECT COUNT(*) FROM users WHERE referred_by = ?');
$refCount->execute([$uid]);
$refCount = (int)$refCount->fetchColumn();

$stat = $pdo->prepare("SELECT COALESCE(SUM(amount),0) total, COUNT(*) cnt FROM affiliate_commissions WHERE referrer_id = ? AND status='paid'");
$stat->execute([$uid]);
$stat = $stat->fetch();

$rows = $pdo->prepare("SELECT ac.*, u.username AS referred_name FROM affiliate_commissions ac LEFT JOIN users u ON u.id=ac.referred_id WHERE ac.referrer_id=? ORDER BY ac.created_at DESC LIMIT 50");
$rows->execute([$uid]);
$rows = $rows->fetchAll();

$page_title = 'Chương trình giới thiệu (Affiliate)';
require_once __DIR__ . '/includes/header.php';
?>
<div class="section"><div class="container">
  <div class="section-head"><h2>🤝 Giới thiệu bạn bè - Nhận hoa hồng</h2></div>
  <p class="hint">Chia sẻ link giới thiệu. Khi người được giới thiệu mua hàng<?= AFFILIATE_FIRST_ORDER_ONLY ? ' (đơn đầu tiên)' : '' ?>, bạn nhận <strong><?= (float)AFFILIATE_PERCENT ?>%</strong> hoa hồng vào ví.</p>

  <div class="cm-aff-stats">
    <div class="cm-stat"><div class="cm-stat-num"><?= $refCount ?></div><div class="cm-stat-lbl">Người đã giới thiệu</div></div>
    <div class="cm-stat"><div class="cm-stat-num"><?= (int)$stat['cnt'] ?></div><div class="cm-stat-lbl">Đơn có hoa hồng</div></div>
    <div class="cm-stat"><div class="cm-stat-num"><?= money($stat['total']) ?></div><div class="cm-stat-lbl">Tổng hoa hồng</div></div>
  </div>

  <div class="form-card" style="margin-top:20px;max-width:640px;">
    <label style="font-weight:600;">Link giới thiệu của bạn</label>
    <div style="display:flex;gap:8px;margin-top:8px;">
      <input type="text" id="refLink" value="<?= e($refLink) ?>" readonly style="flex:1;">
      <button class="btn btn-primary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('refLink').value);this.textContent='Đã copy!'">Copy</button>
    </div>
    <p class="hint" style="margin-top:8px;">Mã giới thiệu: <strong><?= e($code) ?></strong></p>
  </div>

  <h3 style="margin-top:28px;font-size:17px;">Lịch sử hoa hồng</h3>
  <?php if (!$rows): ?><div class="empty">Chưa có hoa hồng nào.</div><?php else: ?>
    <table class="table"><thead><tr><th>Ngày</th><th>Người được giới thiệu</th><th>Tỷ lệ</th><th>Hoa hồng</th><th>Trạng thái</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="hint"><?= date('d/m/Y', strtotime($r['created_at'])) ?></td>
        <td><?= e($r['referred_name'] ?: '—') ?></td>
        <td><?= (float)$r['rate'] ?>%</td>
        <td><?= money($r['amount']) ?></td>
        <td><?= $r['status']==='paid' ? '✅ Đã trả' : ($r['status']==='reversed' ? '↩️ Đã đảo' : '⏳ Chờ') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
</div></div>
<style>
.cm-aff-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;max-width:640px}
.cm-stat{border:1px solid var(--border);border-radius:14px;padding:18px;text-align:center}
.cm-stat-num{font-size:26px;font-weight:800;color:var(--accent)}
.cm-stat-lbl{font-size:13px;color:var(--muted);margin-top:4px}
@media(max-width:600px){.cm-aff-stats{grid-template-columns:1fr}}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
