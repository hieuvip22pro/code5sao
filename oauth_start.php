<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/oauth_functions.php';

$provider = $_GET['provider'] ?? '';
if (!in_array($provider, ['google', 'github', 'apple'], true)) {
    http_response_code(400);
    die('Nhà cung cấp đăng nhập không hợp lệ.');
}

try {
    $url = oauth_build_authorize_url($pdo, $provider);
    header('Location: ' . $url);
    exit;
} catch (Exception $e) {
    flash_set('error', $e->getMessage());
    redirect('/login');
}