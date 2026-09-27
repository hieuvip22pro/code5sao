<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/oauth_functions.php';

$provider = $_GET['provider'] ?? '';
if (!in_array($provider, ['google', 'github', 'apple'], true)) {
    http_response_code(400);
    die('Nhà cung cấp đăng nhập không hợp lệ.');
}

// Google/GitHub redirect về bằng GET, Apple bắt buộc POST (response_mode=form_post)
$params = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

if (!empty($params['error'])) {
    flash_set('error', 'Đăng nhập bị huỷ hoặc bị từ chối bởi ' . strtoupper($provider) . '.');
    redirect('/login');
}

$state = $params['state'] ?? '';
if (!oauth_verify_state($provider, $state)) {
    flash_set('error', 'Phiên đăng nhập không hợp lệ hoặc đã hết hạn, vui lòng thử lại.');
    redirect('/login');
}

$code = $params['code'] ?? '';
if ($code === '') {
    flash_set('error', 'Thiếu mã xác thực từ ' . strtoupper($provider) . '.');
    redirect('/login');
}

try {
    $profile = oauth_exchange_and_get_profile($pdo, $provider, $code);

    // Apple chỉ gửi họ tên (nếu người dùng đồng ý chia sẻ) DUY NHẤT 1 LẦN,
    // dạng JSON trong $_POST['user'] ở lần đăng nhập đầu tiên.
    $appleName = null;
    if ($provider === 'apple' && !empty($params['user'])) {
        $u = json_decode($params['user'], true);
        if (!empty($u['name'])) {
            $appleName = trim(($u['name']['firstName'] ?? '') . ' ' . ($u['name']['lastName'] ?? ''));
        }
    }

    $userId = oauth_find_or_create_user($pdo, $provider, $profile, $appleName);

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) throw new Exception('Không thể tạo hoặc tìm tài khoản.');

    // Email đã được nhà cung cấp (Google/GitHub/Apple) xác thực -> đánh dấu đã xác thực
    try { $pdo->prepare('UPDATE users SET email_verified = 1 WHERE id = ?')->execute([$user['id']]); } catch (Exception $e) {}
    if ($user['status'] === 'banned') {
        flash_set('error', 'Tài khoản của bạn đã bị khoá. Vui lòng liên hệ admin.');
        redirect('/login');
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];

    flash_set('success', 'Đăng nhập thành công qua ' . strtoupper($provider) . '!');

    $destination = BASE_URL . ($user['role'] === 'admin' ? '/admin/index.php' : '/index.php');

    // QUAN TRỌNG: KHÔNG dùng header('Location: ...') ở đây.
    // Vì request này vẫn nằm trong chuỗi điều hướng bắt đầu từ bên ngoài
    // (accounts.google.com / github.com / appleid.apple.com), một số trình
    // duyệt áp dụng SameSite=Strict cho CẢ CHUỖI redirect đó, khiến cookie
    // session vừa tạo bị chặn không gửi đi ở bước redirect tự động ngay sau.
    // Trang trung chuyển bên dưới tạo ra một điều hướng MỚI hoàn toàn do
    // chính trình duyệt/JS khởi tạo (không còn dính chuỗi từ nơi khác),
    // nên cookie sẽ được gửi đúng ở lần tải trang tiếp theo.
    oauth_finish_login($destination);
} catch (Exception $e) {
    flash_set('error', 'Đăng nhập ' . strtoupper($provider) . ' thất bại: ' . $e->getMessage());
    redirect('/login');
}