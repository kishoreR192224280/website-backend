<?php
require_once 'config.php';
require_once 'session_helpers.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$code = strtoupper(trim((string) ($_GET['code'] ?? '')));
$scope = (string) ($_GET['scope'] ?? 'public');
$participantToken = trim((string) ($_GET['participantToken'] ?? ''));
$allowedScopes = ['public', 'display'];

if ($code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Session code is required']);
    exit;
}

if (!in_array($scope, $allowedScopes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid scope']);
    exit;
}

$sessionRow = fetch_session_record_by_code($pdo, $code);
if (!$sessionRow) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Session not found']);
    exit;
}

$participant = null;
if ($scope === 'display') {
    assert_session_status_allowed($sessionRow, ['waiting', 'active', 'paused', 'results', 'leaderboard', 'ended'], 'This session is not available for display.');
} elseif ($participantToken !== '') {
    assert_session_status_allowed($sessionRow, ['waiting', 'active', 'paused', 'results', 'leaderboard', 'ended'], 'This session is not available.');
    $participant = assert_participant_belongs_to_session($pdo, (int) $sessionRow['id'], $participantToken);
} else {
    assert_session_status_allowed($sessionRow, ['waiting', 'active', 'paused', 'results', 'leaderboard'], 'This session is not open for joining.');
}

$questions = fetch_session_questions($pdo, (int) $sessionRow['id']);
$session = hydrate_public_session($sessionRow, $questions);

// For the display/BigScreen scope, include full question data in the bootstrap
// so the projector can derive currentQuestion from lightweight socket events
// without needing the full session re-sent on every state transition.
// Reuse $questions already fetched above — avoid a redundant DB roundtrip.
if ($scope === 'display') {
    $session['questions'] = array_map(
        static function (array $question): array {
            return [
                'id' => $question['id'],
                'questionType' => $question['questionType'],
                'text' => $question['text'],
                'instructions' => $question['instructions'],
                'mediaUrl' => $question['mediaUrl'],
                'options' => $question['options'],
                'correctAnswer' => $question['correctAnswer'],
                'items' => $question['items'],
                'correctOrder' => $question['correctOrder'],
                'labels' => $question['labels'],
                'matchingPairs' => $question['matchingPairs'],
                'timer' => $question['timer'],
                'showLeaderboardAfter' => $question['showLeaderboardAfter'],
            ];
        },
        $questions
    );
}

if ($participant !== null) {
    $session['participantSummary'] = fetch_participant_summary(
        $pdo,
        (int) $sessionRow['id'],
        $participant,
        (string) $sessionRow['status']
    );

    if ($session['participantSummary']) {
        $session = apply_participant_option_order_to_public_session(
            $pdo,
            $session,
            $questions,
            (int) $session['participantSummary']['id']
        );
    }

    $session['currentQuestionResponse'] = fetch_participant_current_question_response(
        $pdo,
        (int) $sessionRow['id'],
        $participantToken,
        $sessionRow['current_question_id'] !== null ? (int) $sessionRow['current_question_id'] : null
    );
}

if (in_array($sessionRow['status'], ['leaderboard', 'ended'], true)) {
    $session['leaderboard'] = fetch_public_leaderboard($pdo, (int) $sessionRow['id']);
}

echo json_encode([
    'success' => true,
    'session' => $session,
]);
