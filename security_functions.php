<?php
// Hàm hỗ trợ tính năng bảo mật: OTP email, IP whitelist, SSH key.

if (!function_exists('security_generate_code')) {
function security_generate_code($len = 6) {
    $s = '';
    for ($i = 0; $i < $len; $i++) { $s .= (string)random_int(0, 9); }
    return $s;
}
}

if (!function_exists('send_otp_email')) {
function send_otp_email($to, $code, $purpose = 'Mã xác thực') {
    $body  = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:0 auto;padding:24px;color:#0f172a;">';
    $body .= '<h2 style="margin:0 0 12px;">' . e($purpose) . '</h2>';
    $body .= '<p>Mã xác thực của bạn là:</p>';
    $body .= '<p style="font-size:30px;font-weight:700;letter-spacing:6px;color:#4f46e5;margin:10px 0;">' . e($code) . '</p>';
    $body .= '<p style="color:#64748b;">Mã có hiệu lực trong 10 phút. Tuyệt đối không chia sẻ mã này cho bất kỳ ai.</p>';
    $body .= '</div>';
    return send_email($to, $purpose . ' - CodeMarket', $body);
}
}

if (!function_exists('client_ip')) {
function client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
}

if (!function_exists('ip_rule_valid')) {
function ip_rule_valid($rule) {
    $rule = trim($rule);
    if ($rule === '') return false;
    if (strtolower($rule) === 'all') return true;
    if (strpos($rule, '/') !== false) {
        list($s, $m) = explode('/', $rule, 2);
        if (!filter_var(trim($s), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        $m = trim($m);
        if (ctype_digit($m)) return ((int)$m >= 0 && (int)$m <= 32);
        return (bool)filter_var($m, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    }
    return (bool)filter_var($rule, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
}
}

if (!function_exists('ip_in_rule')) {
function ip_in_rule($ip, $rule) {
    $rule = trim($rule);
    if ($rule === '') return false;
    if (strtolower($rule) === 'all') return true;
    $ipL = ip2long($ip);
    if ($ipL === false) return false;
    if (strpos($rule, '/') !== false) {
        list($subnet, $mask) = explode('/', $rule, 2);
        $subL = ip2long(trim($subnet));
        if ($subL === false) return false;
        $mask = trim($mask);
        if (ctype_digit($mask)) {
            $bits = (int)$mask;
            if ($bits < 0 || $bits > 32) return false;
            $maskL = $bits === 0 ? 0 : ((~((1 << (32 - $bits)) - 1)) & 0xFFFFFFFF);
        } else {
            $maskL = ip2long($mask);
            if ($maskL === false) return false;
        }
        return (($ipL & $maskL) === ($subL & $maskL));
    }
    $rL = ip2long($rule);
    if ($rL === false) return false;
    return $ipL === $rL;
}
}

if (!function_exists('ip_allowed')) {
function ip_allowed($pdo, $userId, $ip) {
    try {
        $st = $pdo->prepare('SELECT ip_rule FROM user_allowed_ips WHERE user_id=?');
        $st->execute([$userId]);
        $rules = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return true; // bảng chưa tồn tại -> không chặn
    }
    if (!$rules) return true;
    foreach ($rules as $r) { if (ip_in_rule($ip, $r)) return true; }
    return false;
}
}

if (!function_exists('ssh_key_valid')) {
function ssh_key_valid($key) {
    return (bool)preg_match('/^(ssh-rsa|ssh-ed25519|ssh-dss|ecdsa-sha2-[a-z0-9-]+)\s+AAAA[0-9A-Za-z+\/=]+/', trim($key));
}
}

if (!function_exists('ssh_key_fingerprint')) {
function ssh_key_fingerprint($key) {
    $parts = preg_split('/\s+/', trim($key));
    $blob = null;
    foreach ($parts as $p) { if (strpos($p, 'AAAA') === 0) { $blob = $p; break; } }
    if (!$blob) return null;
    $bin = base64_decode($blob, true);
    if ($bin === false) return null;
    return 'SHA256:' . rtrim(base64_encode(hash('sha256', $bin, true)), '=');
}
}
