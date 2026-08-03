<?php
require_once 'config.php';
require_once 'session_helpers.php';
require_once 'notify_socket.php';
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
$code = strtoupper(trim((string) ($data['code'] ?? $_POST['code'] ?? '')));
$name = trim((string) ($data['name'] ?? $_POST['name'] ?? ''));
$phoneNumber = trim((string) ($data['phoneNumber'] ?? $data['phone'] ?? $_POST['phoneNumber'] ?? $_POST['phone'] ?? ''));

if ($code === '' || $name === '' || $phoneNumber === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Session code, participant name, and phone number are required']);
    exit;
}

$normalizedPhoneNumber = preg_replace('/\s+/', '', $phoneNumber);
if (!preg_match('/^\+\d{7,15}$/', $normalizedPhoneNumber)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Enter a valid phone number with country code']);
    exit;
}

$normalizedName = preg_replace('/\s+/', ' ', mb_strtolower($name, 'UTF-8'));
$normalizedName = trim((string) $normalizedName);

$sessionRow = fetch_session_record_by_code($pdo, $code);
if (!$sessionRow) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Session not found']);
    exit;
}

assert_session_status_allowed($sessionRow, ['waiting', 'active', 'paused', 'results', 'leaderboard'], 'This session is not accepting participants.');

try {
    $pdo->beginTransaction();

    $studentStmt = $pdo->prepare(
        'SELECT id, full_name, phone_number
         FROM students
         WHERE phone_number = ?
         LIMIT 1'
    );
    $studentStmt->execute([$normalizedPhoneNumber]);
    $student = $studentStmt->fetch() ?: null;

    if ($student) {
        $existingNormalizedName = preg_replace('/\s+/', ' ', mb_strtolower((string) $student['full_name'], 'UTF-8'));
        $existingNormalizedName = trim((string) $existingNormalizedName);

        if ($existingNormalizedName !== $normalizedName) {
            throw new InvalidArgumentException('Mobile number already exists');
        }
    } else {
        $insertStudent = $pdo->prepare(
            'INSERT INTO students (full_name, phone_number)
             VALUES (?, ?)
             RETURNING id'
        );
        $insertStudent->execute([$name, $normalizedPhoneNumber]);
        $student = [
            'id' => (int) $insertStudent->fetchColumn(),
            'full_name' => $name,
            'phone_number' => $normalizedPhoneNumber,
        ];
    }

    $participantStmt = $pdo->prepare(
        'SELECT id, session_id, student_id, join_token
         FROM session_participants
         WHERE session_id = ? AND student_id = ?
         LIMIT 1'
    );
    $participantStmt->execute([(int) $sessionRow['id'], (int) $student['id']]);
    $participant = $participantStmt->fetch() ?: null;
    $isNewParticipant = ($participant === null);

    if ($participant) {
        $updateParticipant = $pdo->prepare(
            'UPDATE session_participants
             SET status = ?, last_seen_at = clock_timestamp()
             WHERE id = ?'
        );
        $updateParticipant->execute(['active', (int) $participant['id']]);
    } else {
        $joinToken = generate_join_token();
        $insertParticipant = $pdo->prepare(
            'INSERT INTO session_participants (
                session_id,
                student_id,
                join_token,
                status
            ) VALUES (?, ?, ?, ?)
            RETURNING id'
        );
        $insertParticipant->execute([
            (int) $sessionRow['id'],
            (int) $student['id'],
            $joinToken,
            'joined',
        ]);

        $participant = [
            'id' => (int) $insertParticipant->fetchColumn(),
            'student_id' => (int) $student['id'],
            'join_token' => $joinToken,
        ];
    }

    if (!$isNewParticipant) {
        $participantCount = (int) $sessionRow['participant_count'];
    } else {
        $participantCount = increment_participant_count($pdo, (int) $sessionRow['id']);
    }
    $pdo->commit();

    notify_socket_server('/broadcast-participant-joined', [
        'sessionId' => (int) $sessionRow['id'],
        'participants' => $participantCount,
    ]);

    $sessionRow['participant_count'] = $participantCount;
    $questions = fetch_session_questions($pdo, (int) $sessionRow['id']);
    $session = hydrate_public_session($sessionRow, $questions);
    $session = apply_participant_option_order_to_public_session(
        $pdo,
        $session,
        $questions,
        (int) $participant['id']
    );

    echo json_encode([
        'success' => true,
        'participant' => [
            'id' => (int) $participant['id'],
            'studentId' => (int) $student['id'],
            'name' => $student['full_name'],
            'phoneNumber' => $student['phone_number'] ?? null,
            'token' => $participant['join_token'],
        ],
        'session' => $session,
    ]);
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(409);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to join session']);
}
