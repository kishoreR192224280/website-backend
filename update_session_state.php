<?php
session_start();
require_once 'config.php';
require_once 'session_helpers.php';
require_once 'notify_socket.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
$adminId = (int) $_SESSION['admin_id'];
session_write_close();

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}
$sessionId = (int) ($data['sessionId'] ?? $_POST['sessionId'] ?? 0);
$action = trim((string) ($data['action'] ?? $_POST['action'] ?? ''));

if ($sessionId <= 0 || $action === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Session id and action are required']);
    exit;
}

$allowedActions = ['launch_next', 'reveal_results', 'show_leaderboard', 'resume', 'end'];
if (!in_array($action, $allowedActions, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

$sessionRow = fetch_session_record_by_id($pdo, $sessionId);
if (!$sessionRow) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Session not found']);
    exit;
}

if ((int) $sessionRow['admin_id'] !== $adminId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$questions = fetch_session_questions($pdo, $sessionId);
if (!$questions) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Session has no questions']);
    exit;
}

$currentIndex = get_effective_current_question_index($sessionRow, $questions);

try {
    switch ($action) {
        case 'launch_next':
            if ($sessionRow['status'] === 'paused') {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'Resume the paused session before launching another question']);
                exit;
            }

            if ($sessionRow['status'] === 'waiting') {
                $nextIndex = 0;
            } else {
                $nextIndex = $currentIndex + 1;
            }

            if ($nextIndex >= count($questions)) {
                $updateStmt = $pdo->prepare(
                    'UPDATE quiz_sessions
                     SET status = ?, question_started_at = NULL
                     WHERE id = ?'
                );
                $updateStmt->execute(['ended', $sessionId]);
            } else {
                $nextQuestionId = (int) $questions[$nextIndex]['id'];
                $updateStmt = $pdo->prepare(
                    'UPDATE quiz_sessions
                     SET status = ?,
                         current_question_id = ?,
                         question_started_at = clock_timestamp(),
                         paused_at = NULL,
                         pause_reason = NULL,
                         accumulated_pause_ms = 0,
                         host_connection_status = ?
                     WHERE id = ?'
                );
                $updateStmt->execute(['active', $nextQuestionId, 'connected', $sessionId]);
            }
            break;

        case 'reveal_results':
            $updateStmt = $pdo->prepare(
                'UPDATE quiz_sessions
                 SET status = ?
                 WHERE id = ?'
            );
            $updateStmt->execute(['results', $sessionId]);
            break;

        case 'show_leaderboard':
            $updateStmt = $pdo->prepare(
                'UPDATE quiz_sessions
                 SET status = ?
                 WHERE id = ?'
            );
            $updateStmt->execute(['leaderboard', $sessionId]);
            break;

        case 'resume':
            if ($sessionRow['status'] !== 'paused') {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'This session is not paused']);
                exit;
            }

            $updateStmt = $pdo->prepare(
                'UPDATE quiz_sessions
                 SET status = ?,
                     host_connection_status = ?,
                     host_last_seen_at = clock_timestamp(),
                     accumulated_pause_ms = accumulated_pause_ms + CASE
                        WHEN paused_at IS NULL THEN 0
                        ELSE CAST(EXTRACT(EPOCH FROM (clock_timestamp() - paused_at)) * 1000 AS INTEGER)
                     END,
                     paused_at = NULL,
                     pause_reason = NULL
                 WHERE id = ?'
            );
            $updateStmt->execute(['active', 'connected', $sessionId]);
            break;

        case 'end':
            $updateStmt = $pdo->prepare(
                'UPDATE quiz_sessions
                 SET status = ?, question_started_at = NULL
                 WHERE id = ?'
            );
            $updateStmt->execute(['ended', $sessionId]);
            break;
    }

    $updatedSessionRow = fetch_session_record_by_id($pdo, $sessionId);
    $updatedQuestions = fetch_session_questions($pdo, $sessionId);
    $session = hydrate_admin_session($pdo, $updatedSessionRow, $updatedQuestions);

    // Build the compact state payload (no question data, no leaderboard).
    // This is sent to all clients first so the status transition is instant.
    $publicPayload = build_lightweight_state_payload($updatedSessionRow, $updatedQuestions);
    notify_socket_server('/broadcast-state-change', $publicPayload);

    // For leaderboard/ended transitions, push leaderboard data as a SEPARATE
    // event. This lets clients render the status change immediately (< 1KB
    // arrives first) and then hydrate the leaderboard asynchronously once the
    // (potentially larger) payload arrives via session:leaderboard.
    if (in_array($updatedSessionRow['status'], ['leaderboard', 'ended'], true)) {
        $leaderboard = fetch_public_leaderboard($pdo, $sessionId);
        notify_socket_server('/broadcast-leaderboard', [
            'sessionId' => $sessionId,
            'leaderboard' => $leaderboard,
        ]);
    }

    echo json_encode([
        'success' => true,
        'session' => $session,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to update session state']);
}
