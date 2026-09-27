<?php
// Tính năng "Web cho thuê" đã được thay thế bằng "Bán gói Hosting" (WHM/cPanel).
// Liên kết cũ (web_view.php?id=...) được chuyển hướng sang trang bảng giá hosting mới.
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
flash_set('info', 'Trang này đã ngừng hoạt động. Xem các gói Hosting hiện có bên dưới.');
redirect('/hosting');