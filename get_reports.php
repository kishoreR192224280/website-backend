<?php
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'session_helpers.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$authPayload = require_admin_auth();
$adminId = (int) $authPayload['admin_id'];

try {
    $overviewStmt = $pdo->prepare(
        'SELECT
            COUNT(DISTINCT qs.id) AS total_sessions,
            COUNT(DISTINCT sp.student_id) AS total_students,
            COUNT(pa.id) AS total_answers,
            COALESCE(SUM(CASE WHEN pa.is_correct = TRUE THEN 1 ELSE 0 END), 0) AS correct_answers
         FROM quiz_sessions qs
         LEFT JOIN session_participants sp ON sp.session_id = qs.id
         LEFT JOIN participant_answers pa ON pa.session_id = qs.id
         WHERE qs.admin_id = ?'
    );
    $overviewStmt->execute([$adminId]);
    $overview = $overviewStmt->fetch() ?: [
        'total_sessions' => 0,
        'total_students' => 0,
        'total_answers' => 0,
        'correct_answers' => 0,
    ];

    $questionAccuracyStmt = $pdo->prepare(
        'SELECT
            sq.id,
            sq.display_order,
            sq.question_text,
            qs.title AS session_title,
            COUNT(pa.id) AS attempts,
            COALESCE(SUM(CASE WHEN pa.is_correct = TRUE THEN 1 ELSE 0 END), 0) AS correct_answers
         FROM session_questions sq
         INNER JOIN quiz_sessions qs ON qs.id = sq.session_id
         LEFT JOIN participant_answers pa ON pa.question_id = sq.id
         WHERE qs.admin_id = ?
         GROUP BY sq.id, sq.display_order, sq.question_text, qs.title, qs.created_at
         ORDER BY qs.created_at DESC, sq.display_order ASC
         LIMIT 6'
    );
    $questionAccuracyStmt->execute([$adminId]);
    $questionRows = $questionAccuracyStmt->fetchAll();

    $recentSessionsStmt = $pdo->prepare(
        'SELECT
            qs.id,
            qs.title,
            qs.public_code,
            qs.status,
            qs.participant_count,
            qs.created_at,
            COALESCE(CAST(session_accuracy.accuracy_pct AS FLOAT), 0) AS accuracy_pct
         FROM quiz_sessions qs
         LEFT JOIN (
            SELECT
                pa.session_id,
                ROUND(CAST(100.0 * SUM(CASE WHEN pa.is_correct = TRUE THEN 1 ELSE 0 END) AS NUMERIC) / NULLIF(COUNT(pa.id), 0), 1) AS accuracy_pct
            FROM participant_answers pa
            GROUP BY pa.session_id
         ) AS session_accuracy ON session_accuracy.session_id = qs.id
         WHERE qs.admin_id = ?
         ORDER BY qs.created_at DESC
         LIMIT 8'
    );
    $recentSessionsStmt->execute([$adminId]);
    $recentSessions = $recentSessionsStmt->fetchAll();

    $reports = [
        'overview' => [
            'totalSessions' => (int) $overview['total_sessions'],
            'totalStudents' => (int) $overview['total_students'],
            'totalAnswers' => (int) $overview['total_answers'],
            'correctAnswers' => (int) $overview['correct_answers'],
            'overallAccuracy' => (int) $overview['total_answers'] > 0
                ? round(((int) $overview['correct_answers'] / (int) $overview['total_answers']) * 100, 1)
                : 0,
        ],
        'questionAccuracy' => array_map(
            static function (array $row): array {
                $attempts = (int) $row['attempts'];
                $correctAnswers = (int) $row['correct_answers'];

                return [
                    'id' => (int) $row['id'],
                    'label' => 'Q' . (int) $row['display_order'],
                    'sessionTitle' => $row['session_title'],
                    'questionText' => $row['question_text'],
                    'attempts' => $attempts,
                    'accuracy' => $attempts > 0 ? round(($correctAnswers / $attempts) * 100, 1) : 0,
                ];
            },
            $questionRows
        ),
        'sessions' => array_map(
            static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'name' => $row['title'],
                    'code' => $row['public_code'],
                    'date' => format_datetime_for_client($row['created_at']),
                    'participants' => (int) $row['participant_count'],
                    'avgAccuracy' => (float) $row['accuracy_pct'],
                    'status' => $row['status'],
                ];
            },
            $recentSessions
        ),
    ];

    echo json_encode([
        'success' => true,
        'reports' => $reports,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load reports', 'debug_error' => $e->getMessage()]);
}
