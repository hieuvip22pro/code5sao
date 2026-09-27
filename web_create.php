<?php
// Tính năng "Đăng Web cho thuê" đã được thay thế bằng "Bán gói Hosting" (WHM/cPanel).
// Chuyển hướng những liên kết cũ sang trang quản lý gói hosting của admin.
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_login();
flash_set('info', 'Tính năng "Đăng Web cho thuê" đã được thay bằng hệ thống Hosting tự động. Vui lòng xem các gói Hosting hiện có.');
redirect('/hosting');