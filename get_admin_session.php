<?php
require_once 'config.php';
session_start();
require_once 'session_helpers.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$sessionId = (int) ($_GET['id'] ?? 0);
if ($sessionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Session id is required']);
    exit;
}

$sessionRow = fetch_session_record_by_id($pdo, $sessionId);
if (!$sessionRow) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Session not found']);
    exit;
}

if ((int) $sessionRow['admin_id'] !== (int) $_SESSION['admin_id']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$questions = fetch_session_questions($pdo, $sessionId);
$session = hydrate_admin_session($pdo, $sessionRow, $questions);

echo json_encode([
    'success' => true,
    'session' => $session,
]);
