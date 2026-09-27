<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_login();
csrf_verify();

$codeId = (int)($_POST['code_id'] ?? 0);
$back   = (string)($_POST['back'] ?? '');

if ($codeId > 0) {
    $st = $pdo->prepare('SELECT id FROM code_listings WHERE id = ?');
    $st->execute([$codeId]);
    if ($st->fetch()) {
        $nowFav = toggle_favorite($pdo, current_user_id(), $codeId);
        flash_set('success', $nowFav ? '❤️ Đã lưu vào Code yêu thích.' : 'Đã bỏ khỏi Code yêu thích.');
    }
}

// Chỉ cho phép chuyển hướng nội bộ để tránh open-redirect
if ($back === '' || !preg_match('~^/[^/]~', $back)) {
    $back = '/code_view.php?id=' . $codeId;
}
redirect($back);
