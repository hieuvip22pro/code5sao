<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/topup_functions.php';
header('Content-Type: application/json');

if (!is_logged_in()) { http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit; }

$id = (int)($_GET['id'] ?? 0);
$topup = get_topup_request($pdo, $id, current_user_id());
if (!$topup) { http_response_code(404); echo json_encode(['error' => 'not_found']); exit; }

try {
    $topup = check_and_credit_topup($pdo, $id);
    echo json_encode(['status' => $topup['status']]);
} catch (Exception $e) {
    echo json_encode(['status' => $topup['status'], 'error' => $e->getMessage()]);
}
