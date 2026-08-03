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
$sessionId = (int) ($data['sessionId'] ?? $_POST['sessionId'] ?? 0);
$studentId = (int) ($data['studentId'] ?? $_POST['studentId'] ?? 0);

if ($participantToken === '' || $socketId === '' || $sessionId <= 0 || $studentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid socket registration payload']);
    exit;
}

$stmt = $pdo->prepare(
    'UPDATE session_participants
     SET active_socket_id = ?,
         active_socket_connected_at = clock_timestamp(),
         connection_status = ?,
         last_socket_error = NULL,
         disconnected_at = NULL,
         last_seen_at = clock_timestamp(),
         status = ?
     WHERE join_token = ?
       AND session_id = ?
       AND student_id = ?'
);
$stmt->execute([
    $socketId,
    'connected',
    'active',
    $participantToken,
    $sessionId,
    $studentId,
]);

if ($stmt->rowCount() === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Participant not found']);
    exit;
}

echo json_encode(['success' => true]);
