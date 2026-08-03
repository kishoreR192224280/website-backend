<?php
require_once 'config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}
$participantToken = trim((string) ($data['participantToken'] ?? $_POST['participantToken'] ?? ''));
$socketId = trim((string) ($data['socketId'] ?? $_POST['socketId'] ?? ''));
$connectionStatus = trim((string) ($data['connectionStatus'] ?? $_POST['connectionStatus'] ?? 'reconnecting'));
$lastSocketError = trim((string) ($data['lastSocketError'] ?? $_POST['lastSocketError'] ?? ''));

if ($participantToken === '' || $socketId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid socket disconnect payload']);
    exit;
}

if (!in_array($connectionStatus, ['connected', 'reconnecting', 'disconnected'], true)) {
    $connectionStatus = 'reconnecting';
}

$stmt = $pdo->prepare(
    'UPDATE session_participants
     SET disconnected_at = clock_timestamp(),
         connection_status = ?,
         last_socket_error = ?,
         last_seen_at = clock_timestamp()
     WHERE join_token = ?
       AND active_socket_id = ?'
);
$stmt->execute([
    $connectionStatus,
    $lastSocketError !== '' ? $lastSocketError : null,
    $participantToken,
    $socketId,
]);

echo json_encode(['success' => true]);
