<?php
session_start();
require_once 'config.php';
require_once 'session_helpers.php';
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
$sessionId = (int) ($data['sessionId'] ?? $_POST['sessionId'] ?? 0);
$connectionStatus = trim((string) ($data['connectionStatus'] ?? $_POST['connectionStatus'] ?? ''));
$action = trim((string) ($data['action'] ?? $_POST['action'] ?? 'heartbeat'));
$reason = trim((string) ($data['reason'] ?? $_POST['reason'] ?? ''));

if ($sessionId <= 0 || !in_array($connectionStatus, ['connected', 'reconnecting', 'disconnected'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid host connection payload']);
    exit;
}

$sessionRow = fetch_session_record_by_id($pdo, $sessionId);
if (!$sessionRow) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Session not found']);
    exit;
}

try {
    if ($action === 'pause') {
        $stmt = $pdo->prepare(
            'UPDATE quiz_sessions
             SET status = CASE WHEN status = :active_status THEN :paused_status ELSE status END,
                 host_connection_status = :conn_status,
                 host_last_seen_at = clock_timestamp(),
                 paused_at = CASE WHEN status = :active_status2 AND paused_at IS NULL THEN clock_timestamp() ELSE paused_at END,
                 pause_reason = CASE WHEN status = :active_status3 THEN :reason ELSE pause_reason END
             WHERE id = :session_id
               AND status IN (:active_status4, :paused_status2)'
        );
        $stmt->execute([
            ':active_status'  => 'active',
            ':paused_status'  => 'paused',
            ':conn_status'    => $connectionStatus,
            ':active_status2' => 'active',
            ':active_status3' => 'active',
            ':reason'         => $reason !== '' ? $reason : 'Host disconnected',
            ':session_id'     => $sessionId,
            ':active_status4' => 'active',
            ':paused_status2' => 'paused',
        ]);
    } else {
        $stmt = $pdo->prepare(
            'UPDATE quiz_sessions
             SET host_connection_status = ?,
                 host_last_seen_at = clock_timestamp()
             WHERE id = ?'
        );
        $stmt->execute([$connectionStatus, $sessionId]);
    }

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to update host connection']);
}
