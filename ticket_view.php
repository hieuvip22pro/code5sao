<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_login();
$uid = current_user_id();
$id = (int)($_GET['id'] ?? 0);

$t = $pdo->prepare('SELECT * FROM support_tickets WHERE id=?');
$t->execute([$id]);
$ticket = $t->fetch();
if (!$ticket || (int)$ticket['user_id'] !== (int)$uid) { http_response_code(404); die('Không tìm thấy yêu cầu.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply'])) {
    csrf_verify();
    $msg = trim($_POST['message'] ?? '');
    if ($ticket['status'] === 'closed') {
        flash_set('error', 'Yêu cầu đã đóng, không thể trả lời.');
    } elseif (mb_strlen($msg) >= 1) {
        $pdo->prepare('INSERT INTO ticket_messages (ticket_id, sender_id, is_admin, message) VALUES (?,?,0,?)')
            ->execute([$id, $uid, $msg]);
        $pdo->prepare("UPDATE support_tickets SET status='open', updated_at=NOW() WHERE id=?")->execute([$id]);
    }
    redirect('/ticket_view?id=' . $id);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close'])) {
    csrf_verify();
    $pdo->prepare("UPDATE support_tickets SET status='closed', updated_at=NOW() WHERE id=?")->execute([$id]);
    redirect('/ticket_view?id=' . $id);
}

$msgs = $pdo->prepare('SELECT m.*, u.username FROM ticket_messages m LEFT JOIN users u ON u.id=m.sender_id WHERE m.ticket_id=? ORDER BY m.created_at ASC');
$msgs->execute([$id]);
$msgs = $msgs->fetchAll();

$page_title = 'Yêu cầu #' . $id;
require_once __DIR__ . '/includes/header.php';
?>
<div class="section"><div class="container" style="max-width:820px;">
  <a href="<?= BASE_URL ?>/support" class="hint">← Về danh sách hỗ trợ</a>
  <div class="section-head" style="margin-top:8px;"><h2>#<?= $ticket['id'] ?> · <?= e($ticket['subject']) ?></h2></div>
  <p class="hint">Trạng thái: <strong><?= e($ticket['status']) ?></strong></p>
  <div class="cm-thread">
    <?php foreach ($msgs as $m): ?>
      <div class="cm-msg <?= $m['is_admin'] ? 'cm-msg-admin' : 'cm-msg-user' ?>">
        <div class="cm-msg-head"><strong><?= $m['is_admin'] ? '🛡️ Hỗ trợ viên' : e($m['username']) ?></strong> <span class="hint"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></span></div>
        <div style="white-space:pre-wrap;"><?= e($m['message']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($ticket['status'] !== 'closed'): ?>
  <form method="post" class="form-card" style="margin-top:16px;">
    <?= csrf_input() ?>
    <div class="form-group"><label>Trả lời</label>
      <textarea name="message" rows="4" required></textarea></div>
    <div style="display:flex;gap:8px;">
      <button class="btn btn-primary" name="reply" value="1">Gửi trả lời</button>
      <button class="btn btn-outline" name="close" value="1" onclick="return confirm('Đóng yêu cầu này?')">Đóng yêu cầu</button>
    </div>
  </form>
  <?php else: ?>
    <div class="alert" style="margin-top:16px;">Yêu cầu đã đóng.</div>
  <?php endif; ?>
</div></div>
<style>
.cm-thread{display:flex;flex-direction:column;gap:12px;margin-top:16px}
.cm-msg{padding:14px 16px;border-radius:12px;border:1px solid var(--border)}
.cm-msg-admin{background:#eff6ff;border-color:#bfdbfe}
.cm-msg-user{background:#fff}
.cm-msg-head{display:flex;justify-content:space-between;gap:10px;margin-bottom:6px}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
