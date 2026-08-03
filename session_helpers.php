<?php

function parse_database_datetime(?string $value): ?DateTimeImmutable
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $utc = new DateTimeZone('UTC');

    return DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, $utc)
        ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $utc)
        ?: null;
}

function format_datetime_for_client(?string $value): ?string
{
    $dateTime = parse_database_datetime($value);
    if (!$dateTime) {
        return $value;
    }

    return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
}

function get_server_now(): DateTimeImmutable
{
    return DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)), new DateTimeZone('UTC'))
        ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function format_server_now_for_client(): string
{
    return get_server_now()->format('Y-m-d\TH:i:s.u\Z');
}

function calculate_elapsed_time_ms(?string $startedAt): ?int
{
    $startedAtDate = parse_database_datetime($startedAt);
    if (!$startedAtDate) {
        return null;
    }

    $elapsedSeconds = (float) get_server_now()->format('U.u') - (float) $startedAtDate->format('U.u');

    return max(0, (int) round($elapsedSeconds * 1000));
}

function calculate_effective_elapsed_time_ms(
    ?string $startedAt,
    int $accumulatedPauseMs = 0,
    ?string $pausedAt = null
): ?int
{
    $startedAtDate = parse_database_datetime($startedAt);
    if (!$startedAtDate) {
        return null;
    }

    $endDate = parse_database_datetime($pausedAt) ?: get_server_now();
    $elapsedSeconds = (float) $endDate->format('U.u') - (float) $startedAtDate->format('U.u');

    return max(0, (int) round($elapsedSeconds * 1000) - max(0, $accumulatedPauseMs));
}

function calculate_response_time_ms(
    ?string $startedAt,
    int $timeLimitSeconds,
    int $accumulatedPauseMs = 0,
    ?string $pausedAt = null
): ?int
{
    $elapsedMs = calculate_effective_elapsed_time_ms($startedAt, $accumulatedPauseMs, $pausedAt);
    if ($elapsedMs === null) {
        return null;
    }
    $maxAllowedMs = max(0, $timeLimitSeconds * 1000);

    return min($elapsedMs, $maxAllowedMs);
}

function calculate_time_remaining_seconds(
    ?string $startedAt,
    int $timeLimitSeconds,
    int $accumulatedPauseMs = 0,
    ?string $pausedAt = null
): int
{
    $responseTimeMs = calculate_response_time_ms($startedAt, $timeLimitSeconds, $accumulatedPauseMs, $pausedAt);

    if ($responseTimeMs === null) {
        return $timeLimitSeconds;
    }

    return max(0, (int) ceil(($timeLimitSeconds * 1000 - $responseTimeMs) / 1000));
}

function calculate_question_score(bool $isCorrect, ?int $responseTimeMs, int $timeLimitSeconds): int
{
    if (!$isCorrect || $responseTimeMs === null || $timeLimitSeconds <= 0) {
        return 0;
    }

    $timeLimitMs = $timeLimitSeconds * 1000;
    $clampedResponseMs = max(0, min($responseTimeMs, $timeLimitMs));
    $speedRatio = max(0, ($timeLimitMs - $clampedResponseMs) / $timeLimitMs);
    $baseScore = 600;
    $speedBonus = (int) round($speedRatio * 400);

    return $baseScore + $speedBonus;
}

function generate_join_token(): string
{
    $bytes = bin2hex(random_bytes(16));

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($bytes, 0, 8),
        substr($bytes, 8, 4),
        substr($bytes, 12, 4),
        substr($bytes, 16, 4),
        substr($bytes, 20, 12)
    );
}

function normalize_free_text_answer(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = preg_replace('/[^\p{L}\p{N}\s]/u', '', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    return $value;
}

function decode_json_array(?string $value): array
{
    if ($value === null || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);

    return is_array($decoded) ? $decoded : [];
}

function build_matching_option_label(array $pair, string $fallbackLabel = 'Image option'): string
{
    $text = trim((string) ($pair['rightText'] ?? ''));
    if ($text !== '') {
        return $text;
    }

    return $fallbackLabel;
}

function send_json_error(int $httpStatus, string $message, string $code, array $extra = []): void
{
    http_response_code($httpStatus);
    echo json_encode(array_merge([
        'success' => false,
        'error' => $message,
        'code' => $code,
    ], $extra));
    exit;
}

function assert_session_status_allowed(array $sessionRow, array $allowedStatuses, string $message = 'This session is not available.'): void
{
    $status = (string) ($sessionRow['status'] ?? '');
    if (in_array($status, $allowedStatuses, true)) {
        return;
    }

    $statusMessages = [
        'draft' => 'This quiz has not been opened by the host yet.',
        'archived' => 'This quiz link is no longer available.',
        'ended' => 'This quiz session has ended.',
        'paused' => 'This quiz is paused while the host reconnects.',
        'waiting' => 'This quiz is still in the waiting room.',
        'results' => 'This quiz is showing results and is not accepting answers.',
        'leaderboard' => 'This quiz is showing the leaderboard and is not accepting answers.',
    ];

    send_json_error(
        409,
        $statusMessages[$status] ?? $message,
        'SESSION_STATUS_NOT_ALLOWED',
        ['status' => $status]
    );
}

function assert_participant_belongs_to_session(PDO $pdo, int $sessionId, string $participantToken): ?array
{
    if ($participantToken === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT
            sp.id,
            sp.session_id,
            sp.student_id,
            sp.status,
            sp.total_score,
            sp.joined_at,
            s.full_name,
            s.phone_number
         FROM session_participants sp
         INNER JOIN students s ON s.id = sp.student_id
         WHERE sp.session_id = ? AND sp.join_token = ?
         LIMIT 1'
    );
    $stmt->execute([$sessionId, $participantToken]);
    $participant = $stmt->fetch();

    if (!$participant) {
        send_json_error(401, 'Your session access has expired. Please rejoin the quiz.', 'PARTICIPANT_SESSION_INVALID');
    }

    return $participant;
}

function find_question_by_id(array $questions, int $questionId): ?array
{
    foreach ($questions as $question) {
        if ((int) ($question['id'] ?? 0) === $questionId) {
            return $question;
        }
    }

    return null;
}

function normalize_option_order_ids(array $optionIds, array $validOptionIds): ?array
{
    $optionIds = array_values(array_map('intval', $optionIds));
    $validOptionIds = array_values(array_map('intval', $validOptionIds));
    sort($optionIds);
    sort($validOptionIds);

    return $optionIds === $validOptionIds ? array_values(array_map('intval', $optionIds)) : null;
}

function ensure_participant_option_order(PDO $pdo, int $participantId, int $questionId, array $optionRecords): array
{
    $validOptionIds = array_values(array_map(
        static fn(array $option): int => (int) $option['id'],
        $optionRecords
    ));

    if (count($validOptionIds) === 0) {
        return [];
    }

    try {
        $orderStmt = $pdo->prepare(
            'SELECT option_order_json
             FROM participant_question_option_orders
             WHERE participant_id = ? AND question_id = ?
             LIMIT 1'
        );
        $orderStmt->execute([$participantId, $questionId]);
        $existingOrderJson = $orderStmt->fetchColumn();

        if ($existingOrderJson) {
            $existingOrder = decode_json_array((string) $existingOrderJson);
            $normalizedExistingOrder = normalize_option_order_ids($existingOrder, $validOptionIds);
            if ($normalizedExistingOrder !== null) {
                return array_values(array_map('intval', $existingOrder));
            }
        }

        $nextOrder = $validOptionIds;
        shuffle($nextOrder);

        $insertStmt = $pdo->prepare(
            'INSERT INTO participant_question_option_orders (
                participant_id,
                question_id,
                option_order_json
             ) VALUES (?, ?, ?)
             ON CONFLICT (participant_id, question_id) DO UPDATE SET option_order_json = EXCLUDED.option_order_json'
        );
        $insertStmt->execute([
            $participantId,
            $questionId,
            json_encode($nextOrder, JSON_UNESCAPED_UNICODE),
        ]);

        return $nextOrder;
    } catch (PDOException $e) {
        // If the migration has not been executed yet, keep the original order instead of breaking the live quiz.
        return $validOptionIds;
    }
}

function participant_option_id_is_allowed(PDO $pdo, int $participantId, int $questionId, int $optionId): bool
{
    try {
        $orderStmt = $pdo->prepare(
            'SELECT option_order_json
             FROM participant_question_option_orders
             WHERE participant_id = ? AND question_id = ?
             LIMIT 1'
        );
        $orderStmt->execute([$participantId, $questionId]);
        $orderJson = $orderStmt->fetchColumn();

        if (!$orderJson) {
            return true;
        }

        $order = array_values(array_map('intval', decode_json_array((string) $orderJson)));

        return in_array($optionId, $order, true);
    } catch (PDOException $e) {
        return true;
    }
}

function apply_participant_option_order_to_public_session(PDO $pdo, array $session, array $questions, int $participantId): array
{
    $currentQuestionId = isset($session['currentQuestion']['id']) ? (int) $session['currentQuestion']['id'] : 0;
    if ($currentQuestionId <= 0 || ($session['currentQuestion']['questionType'] ?? '') !== 'multiple_choice') {
        return $session;
    }

    $sourceQuestion = find_question_by_id($questions, $currentQuestionId);
    if (!$sourceQuestion || empty($sourceQuestion['optionRecords'])) {
        return $session;
    }

    $optionRecordsById = [];
    foreach ($sourceQuestion['optionRecords'] as $optionRecord) {
        $optionRecordsById[(int) $optionRecord['id']] = $optionRecord;
    }

    $orderedOptionIds = ensure_participant_option_order(
        $pdo,
        $participantId,
        $currentQuestionId,
        $sourceQuestion['optionRecords']
    );

    $orderedOptions = [];
    $orderedOptionIdsForClient = [];
    $correctAnswerIndex = null;

    foreach ($orderedOptionIds as $index => $optionId) {
        if (!isset($optionRecordsById[$optionId])) {
            continue;
        }

        $optionRecord = $optionRecordsById[$optionId];
        $orderedOptions[] = (string) $optionRecord['text'];
        $orderedOptionIdsForClient[] = $optionId;

        if (!empty($optionRecord['isCorrect'])) {
            $correctAnswerIndex = $index;
        }
    }

    if (count($orderedOptions) === count($sourceQuestion['optionRecords'])) {
        $session['currentQuestion']['options'] = $orderedOptions;
        $session['currentQuestion']['optionIds'] = $orderedOptionIdsForClient;

        if (($session['status'] ?? '') !== 'active') {
            $session['currentQuestion']['correctAnswer'] = $correctAnswerIndex;
        }
    }

    return $session;
}

function fetch_session_record_by_id(PDO $pdo, int $sessionId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT
            qs.id,
            qs.admin_id,
            qs.public_code,
            qs.title,
            qs.description,
            qs.youtube_url,
            qs.thumbnail_url,
            qs.intro_video_url,
            qs.status,
            qs.current_question_id,
            qs.question_started_at,
            qs.host_connection_status,
            qs.host_last_seen_at,
            qs.paused_at,
            qs.pause_reason,
            qs.accumulated_pause_ms,
            qs.participant_count,
            qs.created_at,
            qs.updated_at
        FROM quiz_sessions qs
        WHERE qs.id = ?
        LIMIT 1'
    );
    $stmt->execute([$sessionId]);

    $session = $stmt->fetch();

    return $session ?: null;
}

function fetch_session_record_by_code(PDO $pdo, string $code): ?array
{
    $stmt = $pdo->prepare(
        'SELECT
            qs.id,
            qs.admin_id,
            qs.public_code,
            qs.title,
            qs.description,
            qs.youtube_url,
            qs.thumbnail_url,
            qs.intro_video_url,
            qs.status,
            qs.current_question_id,
            qs.question_started_at,
            qs.host_connection_status,
            qs.host_last_seen_at,
            qs.paused_at,
            qs.pause_reason,
            qs.accumulated_pause_ms,
            qs.participant_count,
            qs.created_at,
            qs.updated_at
        FROM quiz_sessions qs
        WHERE qs.public_code = ?
        LIMIT 1'
    );
    $stmt->execute([$code]);

    $session = $stmt->fetch();

    return $session ?: null;
}

function fetch_session_questions_optimized(PDO $pdo, int $sessionId, ?int $currentQuestionId = null): array
{
    $headerStmt = $pdo->prepare(
        'SELECT sq.id, sq.display_order, sq.question_type
         FROM session_questions sq
         WHERE sq.session_id = ?
         ORDER BY sq.display_order ASC'
    );
    $headerStmt->execute([$sessionId]);
    $headers = $headerStmt->fetchAll();

    if (!$headers) {
        return [];
    }

    $activeQuestionRow = null;
    if ($currentQuestionId !== null) {
        $detailStmt = $pdo->prepare(
            'SELECT
                sq.id,
                sq.display_order,
                sq.question_type,
                sq.question_text,
                sq.instructions,
                sq.media_url,
                sq.content_json,
                sq.answer_key_json,
                sq.time_limit_seconds,
                sq.show_leaderboard_after
             FROM session_questions sq
             WHERE sq.id = ?
             LIMIT 1'
        );
        $detailStmt->execute([$currentQuestionId]);
        $activeQuestionRow = $detailStmt->fetch() ?: null;
    }

    $options = [];
    if ($activeQuestionRow && ($activeQuestionRow['question_type'] ?: 'multiple_choice') === 'multiple_choice') {
        $optionStmt = $pdo->prepare(
            'SELECT qo.id, qo.question_id, qo.display_order, qo.option_text, qo.is_correct
             FROM question_options qo
             WHERE qo.question_id = ?
             ORDER BY qo.display_order ASC'
        );
        $optionStmt->execute([$currentQuestionId]);
        $options = $optionStmt->fetchAll();
    }

    $questions = [];
    foreach ($headers as $header) {
        $qId = (int) $header['id'];

        if ($activeQuestionRow && (int) $activeQuestionRow['id'] === $qId) {
            $questionType = $activeQuestionRow['question_type'] ?: 'multiple_choice';
            $content = decode_json_array($activeQuestionRow['content_json'] ?? null);
            $answerKey = decode_json_array($activeQuestionRow['answer_key_json'] ?? null);
            $optTexts = [];
            $correctAnswer = null;
            $optionRecords = [];

            if ($questionType === 'multiple_choice') {
                foreach ($options as $optionIndex => $optionRow) {
                    $optTexts[] = $optionRow['option_text'];
                    $optionRecords[] = [
                        'id' => (int) $optionRow['id'],
                        'text' => $optionRow['option_text'],
                        'displayOrder' => (int) $optionRow['display_order'],
                        'isCorrect' => (bool) $optionRow['is_correct'],
                    ];

                    if ((int) $optionRow['is_correct'] === 1) {
                        $correctAnswer = $optionIndex;
                    }
                }

                if (isset($content['options']) && is_array($content['options']) && count($optTexts) === 0) {
                    $optTexts = array_values(array_map('strval', $content['options']));
                }

                if ($correctAnswer === null && isset($answerKey['correctAnswer'])) {
                    $correctAnswer = (int) $answerKey['correctAnswer'];
                }
            }

            $questions[] = [
                'id' => $qId,
                'questionType' => $questionType,
                'text' => $activeQuestionRow['question_text'],
                'instructions' => $activeQuestionRow['instructions'] ?? '',
                'mediaUrl' => $activeQuestionRow['media_url'] ?? '',
                'options' => $optTexts,
                'correctAnswer' => $correctAnswer,
                'items' => isset($content['items']) && is_array($content['items'])
                    ? array_values(array_map('strval', $content['items']))
                    : [],
                'correctOrder' => isset($answerKey['correctOrder']) && is_array($answerKey['correctOrder'])
                    ? array_values(array_map('strval', $answerKey['correctOrder']))
                    : [],
                'labels' => isset($content['labels']) && is_array($content['labels'])
                    ? array_values($content['labels'])
                    : [],
                'matchingPairs' => isset($content['pairs']) && is_array($content['pairs'])
                    ? array_values($content['pairs'])
                    : [],
                'timer' => (int) $activeQuestionRow['time_limit_seconds'],
                'showLeaderboardAfter' => (bool) $activeQuestionRow['show_leaderboard_after'],
                'displayOrder' => (int) $activeQuestionRow['display_order'],
                'optionRecords' => $optionRecords,
                'content' => $content,
                'answerKey' => $answerKey,
            ];
        } else {
            $questions[] = [
                'id' => $qId,
                'questionType' => $header['question_type'] ?: 'multiple_choice',
                'text' => '',
                'instructions' => '',
                'mediaUrl' => '',
                'options' => [],
                'correctAnswer' => null,
                'items' => [],
                'correctOrder' => [],
                'labels' => [],
                'matchingPairs' => [],
                'timer' => 0,
                'showLeaderboardAfter' => true,
                'displayOrder' => (int) $header['display_order'],
                'optionRecords' => [],
                'content' => [],
                'answerKey' => [],
            ];
        }
    }

    return $questions;
}

function fetch_session_questions(PDO $pdo, int $sessionId): array
{
    $questionStmt = $pdo->prepare(
        'SELECT
            sq.id,
            sq.display_order,
            sq.question_type,
            sq.question_text,
            sq.instructions,
            sq.media_url,
            sq.content_json,
            sq.answer_key_json,
            sq.time_limit_seconds,
            sq.show_leaderboard_after
        FROM session_questions sq
        WHERE sq.session_id = ?
        ORDER BY sq.display_order ASC'
    );
    $questionStmt->execute([$sessionId]);
    $questionRows = $questionStmt->fetchAll();

    if (!$questionRows) {
        return [];
    }

    // Batch-load all options in a single query (fixes N+1 problem)
    $questionIds = array_column($questionRows, 'id');
    $optionsByQuestion = [];

    if (count($questionIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $optionStmt = $pdo->prepare(
            "SELECT
                qo.id,
                qo.question_id,
                qo.display_order,
                qo.option_text,
                qo.is_correct
            FROM question_options qo
            WHERE qo.question_id IN ($placeholders)
            ORDER BY qo.question_id, qo.display_order ASC"
        );
        $optionStmt->execute($questionIds);
        $allOptionRows = $optionStmt->fetchAll();

        foreach ($allOptionRows as $optionRow) {
            $optionsByQuestion[$optionRow['question_id']][] = $optionRow;
        }
    }

    $questions = [];
    foreach ($questionRows as $questionRow) {
        $questionType = $questionRow['question_type'] ?: 'multiple_choice';
        $content = decode_json_array($questionRow['content_json'] ?? null);
        $answerKey = decode_json_array($questionRow['answer_key_json'] ?? null);
        $options = [];
        $correctAnswer = null;
        $optionRecords = [];

        if ($questionType === 'multiple_choice') {
            $optionRows = $optionsByQuestion[$questionRow['id']] ?? [];

            foreach ($optionRows as $optionIndex => $optionRow) {
                $options[] = $optionRow['option_text'];
                $optionRecords[] = [
                    'id' => (int) $optionRow['id'],
                    'text' => $optionRow['option_text'],
                    'displayOrder' => (int) $optionRow['display_order'],
                    'isCorrect' => (bool) $optionRow['is_correct'],
                ];

                if ((int) $optionRow['is_correct'] === 1) {
                    $correctAnswer = $optionIndex;
                }
            }

            if (isset($content['options']) && is_array($content['options']) && count($options) === 0) {
                $options = array_values(array_map('strval', $content['options']));
            }

            if ($correctAnswer === null && isset($answerKey['correctAnswer'])) {
                $correctAnswer = (int) $answerKey['correctAnswer'];
            }
        }

        $questions[] = [
            'id' => (int) $questionRow['id'],
            'questionType' => $questionType,
            'text' => $questionRow['question_text'],
            'instructions' => $questionRow['instructions'] ?? '',
            'mediaUrl' => $questionRow['media_url'] ?? '',
            'options' => $options,
            'correctAnswer' => $correctAnswer,
            'items' => isset($content['items']) && is_array($content['items'])
                ? array_values(array_map('strval', $content['items']))
                : [],
            'correctOrder' => isset($answerKey['correctOrder']) && is_array($answerKey['correctOrder'])
                ? array_values(array_map('strval', $answerKey['correctOrder']))
                : [],
            'labels' => isset($content['labels']) && is_array($content['labels'])
                ? array_values($content['labels'])
                : [],
            'matchingPairs' => isset($content['pairs']) && is_array($content['pairs'])
                ? array_values($content['pairs'])
                : [],
            'timer' => (int) $questionRow['time_limit_seconds'],
            'showLeaderboardAfter' => (bool) $questionRow['show_leaderboard_after'],
            'displayOrder' => (int) $questionRow['display_order'],
            'optionRecords' => $optionRecords,
            'content' => $content,
            'answerKey' => $answerKey,
        ];
    }

    return $questions;
}

function get_effective_current_question_index(array $sessionRow, array $questions): int
{
    if ($sessionRow['status'] === 'waiting') {
        return -1;
    }

    if (!$sessionRow['current_question_id']) {
        return -1;
    }

    foreach ($questions as $index => $question) {
        if ((int) $question['id'] === (int) $sessionRow['current_question_id']) {
            return $index;
        }
    }

    return -1;
}

function hydrate_admin_session(PDO $pdo, array $sessionRow, array $questions): array
{
    $currentQuestionIndex = get_effective_current_question_index($sessionRow, $questions);
    $currentQuestionId = $sessionRow['current_question_id'] ? (int) $sessionRow['current_question_id'] : null;
    $currentQuestionType = $currentQuestionIndex >= 0 && isset($questions[$currentQuestionIndex])
        ? (string) $questions[$currentQuestionIndex]['questionType']
        : null;
    $liveFeed = fetch_admin_live_feed(
        $pdo,
        (int) $sessionRow['id'],
        $currentQuestionId,
        (string) $sessionRow['status'],
        $currentQuestionType
    );

    // Compute answered count with a dedicated query so it stays accurate even with paginated feed
    $answeredParticipants = 0;
    if ($currentQuestionId !== null) {
        $answeredStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM participant_answers WHERE session_id = ? AND question_id = ?'
        );
        $answeredStmt->execute([(int) $sessionRow['id'], $currentQuestionId]);
        $answeredParticipants = (int) $answeredStmt->fetchColumn();
    }

    $currentQuestionStats = null;
    if ($currentQuestionIndex >= 0 && isset($questions[$currentQuestionIndex])) {
        $currentQuestionStats = fetch_current_question_stats(
            $pdo,
            (int) $sessionRow['id'],
            (int) $questions[$currentQuestionIndex]['id'],
            (string) $questions[$currentQuestionIndex]['questionType'],
            $questions[$currentQuestionIndex]
        );
    }

    return [
        'id' => (int) $sessionRow['id'],
        'code' => $sessionRow['public_code'],
        'title' => $sessionRow['title'],
        'description' => $sessionRow['description'] ?? '',
        'videoUrl' => $sessionRow['youtube_url'] ?? '',
        'thumbnailUrl' => $sessionRow['thumbnail_url'] ?? '',
        'introVideoUrl' => $sessionRow['intro_video_url'] ?? '',
        'questions' => array_map(
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
        ),
        'questionCount' => count($questions),
        'status' => $sessionRow['status'],
        'currentQuestionId' => $currentQuestionId,
        'currentQuestionIndex' => $currentQuestionIndex,
        'questionStartedAt' => format_datetime_for_client($sessionRow['question_started_at']),
        'serverNow' => format_server_now_for_client(),
        'hostConnectionStatus' => $sessionRow['host_connection_status'] ?? 'connected',
        'hostLastSeenAt' => format_datetime_for_client($sessionRow['host_last_seen_at'] ?? null),
        'pausedAt' => format_datetime_for_client($sessionRow['paused_at'] ?? null),
        'pauseReason' => $sessionRow['pause_reason'] ?? null,
        'participants' => (int) $sessionRow['participant_count'],
        'liveFeed' => $liveFeed,
        'liveMetrics' => [
            'totalParticipants' => (int) $sessionRow['participant_count'],
            'answeredParticipants' => $answeredParticipants,
            'waitingParticipants' => max(0, (int) $sessionRow['participant_count'] - $answeredParticipants),
        ],
        'currentQuestionStats' => $currentQuestionStats,
        'currentQuestion' => $currentQuestionIndex >= 0 ? [
            'id' => $questions[$currentQuestionIndex]['id'],
            'questionType' => $questions[$currentQuestionIndex]['questionType'],
            'text' => $questions[$currentQuestionIndex]['text'],
            'instructions' => $questions[$currentQuestionIndex]['instructions'],
            'mediaUrl' => $questions[$currentQuestionIndex]['mediaUrl'],
            'options' => $questions[$currentQuestionIndex]['options'],
            'correctAnswer' => $questions[$currentQuestionIndex]['correctAnswer'],
            'items' => $questions[$currentQuestionIndex]['items'],
            'correctOrder' => $questions[$currentQuestionIndex]['correctOrder'],
            'labels' => $questions[$currentQuestionIndex]['labels'],
            'matchingPairs' => $questions[$currentQuestionIndex]['matchingPairs'],
            'timer' => $questions[$currentQuestionIndex]['timer'],
            'showLeaderboardAfter' => $questions[$currentQuestionIndex]['showLeaderboardAfter'],
        ] : null,
        'timeRemainingSeconds' => $currentQuestionIndex >= 0 && in_array($sessionRow['status'], ['active', 'paused'], true)
            ? calculate_time_remaining_seconds(
                $sessionRow['question_started_at'],
                (int) $questions[$currentQuestionIndex]['timer'],
                (int) ($sessionRow['accumulated_pause_ms'] ?? 0),
                $sessionRow['status'] === 'paused' ? ($sessionRow['paused_at'] ?? null) : null
            )
            : null,
    ];
}

function fetch_current_question_stats(PDO $pdo, int $sessionId, int $questionId, string $questionType, array $question): ?array
{
    if ($questionType !== 'multiple_choice') {
        return null;
    }

    $countStmt = $pdo->prepare(
        'SELECT
            qo.display_order,
            COUNT(pa.id) AS answer_count
         FROM question_options qo
         LEFT JOIN participant_answers pa
            ON pa.selected_option_id = qo.id
           AND pa.question_id = ?
           AND pa.session_id = ?
         WHERE qo.question_id = ?
         GROUP BY qo.id, qo.display_order
         ORDER BY qo.display_order ASC'
    );
    $countStmt->execute([$questionId, $sessionId, $questionId]);
    $rows = $countStmt->fetchAll();

    $countsByDisplayOrder = [];
    foreach ($rows as $row) {
        $countsByDisplayOrder[(int) $row['display_order']] = (int) $row['answer_count'];
    }

    $optionCounts = [];
    foreach (($question['options'] ?? []) as $index => $optionText) {
        $optionCounts[] = [
            'name' => chr(65 + $index),
            'optionText' => (string) $optionText,
            'count' => $countsByDisplayOrder[$index + 1] ?? 0,
            'isCorrect' => $question['correctAnswer'] === $index,
        ];
    }

    return [
        'optionCounts' => $optionCounts,
    ];
}

function fetch_admin_live_feed(PDO $pdo, int $sessionId, ?int $currentQuestionId, string $sessionStatus, ?string $currentQuestionType = null, int $limit = 50): array
{
    $stmt = $pdo->prepare(
        'SELECT
            sp.id,
            sp.student_id,
            s.full_name,
            s.phone_number,
            sp.status,
            sp.total_score,
            sp.joined_at,
            sp.last_seen_at,
            pa.answered_at,
            pa.response_json,
            qo.display_order AS selected_option_order
         FROM session_participants sp
         INNER JOIN students s ON s.id = sp.student_id
         LEFT JOIN participant_answers pa
            ON pa.participant_id = sp.id
           AND (? IS NOT NULL AND pa.question_id = ?)
         LEFT JOIN question_options qo
            ON qo.id = pa.selected_option_id
         WHERE sp.session_id = ?
         ORDER BY
            COALESCE(pa.answered_at, sp.last_seen_at, sp.joined_at) DESC,
            sp.id DESC
         LIMIT ' . max(1, (int) $limit)
    );
    $stmt->execute([$currentQuestionId, $currentQuestionId, $sessionId]);
    $rows = $stmt->fetchAll();

    $feed = [];
    foreach ($rows as $row) {
        $hasAnsweredCurrentQuestion = $row['answered_at'] !== null;
        $selectedOptionIndex = $row['selected_option_order'] !== null ? ((int) $row['selected_option_order'] - 1) : null;
        $hasStructuredResponse = $row['response_json'] !== null && trim((string) $row['response_json']) !== '';
        $presence = 'idle';
        $activityLabel = 'Standing by';

        if ($sessionStatus === 'waiting') {
            $presence = 'waiting';
            $activityLabel = 'Joined the lobby';
        } elseif ($sessionStatus === 'active') {
            if ($hasAnsweredCurrentQuestion) {
                $presence = 'active';
                if ($currentQuestionType === 'multiple_choice') {
                    $activityLabel = $selectedOptionIndex !== null
                        ? 'Submitted answer ' . chr(65 + $selectedOptionIndex)
                        : 'Timed out without confirming';
                } elseif ($hasStructuredResponse) {
                    $activityLabel = match ($currentQuestionType) {
                        'sorting' => 'Submitted sequence',
                        'label_image' => 'Submitted labels',
                        'matching' => 'Submitted matches',
                        default => 'Submitted response',
                    };
                } else {
                    $activityLabel = 'Timed out without confirming';
                }
            } else {
                $presence = 'waiting';
                $activityLabel = 'Viewing the live question';
            }
        } elseif ($sessionStatus === 'results') {
            $presence = $hasAnsweredCurrentQuestion ? 'active' : 'idle';
            if ($hasAnsweredCurrentQuestion) {
                if ($currentQuestionType === 'multiple_choice') {
                    $activityLabel = $selectedOptionIndex !== null ? 'Answer recorded for this round' : 'Marked as unanswered';
                } else {
                    $activityLabel = $hasStructuredResponse ? 'Answer recorded for this round' : 'Marked as unanswered';
                }
            } else {
                $activityLabel = 'Did not answer this round';
            }
        } elseif ($sessionStatus === 'leaderboard') {
            $presence = 'active';
            if ($currentQuestionType === 'multiple_choice') {
                $activityLabel = $selectedOptionIndex !== null ? 'Viewing leaderboard standings' : 'Skipped this round';
            } else {
                $activityLabel = $hasStructuredResponse ? 'Viewing leaderboard standings' : 'Skipped this round';
            }
        } elseif ($sessionStatus === 'ended') {
            $presence = 'idle';
            $activityLabel = 'Session completed';
        }

        $lastActivityAt = $row['answered_at'] ?: ($row['last_seen_at'] ?: $row['joined_at']);

        $feed[] = [
            'id' => (int) $row['id'],
            'studentId' => (int) $row['student_id'],
            'name' => $row['full_name'],
            'phoneNumber' => $row['phone_number'],
            'score' => (int) $row['total_score'],
            'hasAnsweredCurrentQuestion' => $hasAnsweredCurrentQuestion,
            'selectedOptionIndex' => $selectedOptionIndex,
            'activityLabel' => $activityLabel,
            'presence' => $presence,
            'lastActivityAt' => format_datetime_for_client($lastActivityAt),
        ];
    }

    return $feed;
}

function hydrate_public_session(array $sessionRow, array $questions): array
{
    $currentQuestionIndex = get_effective_current_question_index($sessionRow, $questions);
    $currentQuestion = null;

    if ($currentQuestionIndex >= 0 && isset($questions[$currentQuestionIndex])) {
        $question = $questions[$currentQuestionIndex];
        $labels = array_map(
            static function (array $label) use ($sessionRow): array {
                if ($sessionRow['status'] === 'active') {
                    unset($label['acceptedAnswers']);
                }

                return $label;
            },
            $question['labels'] ?? []
        );
        $currentQuestion = [
            'id' => $question['id'],
            'questionType' => $question['questionType'],
            'text' => $question['text'],
            'instructions' => $question['instructions'],
            'mediaUrl' => $question['mediaUrl'],
            'options' => $question['options'],
            'items' => $question['items'],
            'labels' => $labels,
            'matchingPairs' => $question['matchingPairs'],
            'timer' => $question['timer'],
            'showLeaderboardAfter' => $question['showLeaderboardAfter'],
        ];

        if ($sessionRow['status'] !== 'active') {
            $currentQuestion['correctAnswer'] = $question['correctAnswer'];
            $currentQuestion['correctOrder'] = $question['correctOrder'];
            $currentQuestion['labels'] = $question['labels'];
            $currentQuestion['matchingPairs'] = $question['matchingPairs'];
        }
    }

    return [
        'id' => (int) $sessionRow['id'],
        'code' => $sessionRow['public_code'],
        'title' => $sessionRow['title'],
        'description' => $sessionRow['description'] ?? '',
        'status' => $sessionRow['status'],
        'currentQuestionId' => $sessionRow['current_question_id'] ? (int) $sessionRow['current_question_id'] : null,
        'currentQuestionIndex' => $currentQuestionIndex,
        'questionStartedAt' => format_datetime_for_client($sessionRow['question_started_at']),
        'serverNow' => format_server_now_for_client(),
        'hostConnectionStatus' => $sessionRow['host_connection_status'] ?? 'connected',
        'hostLastSeenAt' => format_datetime_for_client($sessionRow['host_last_seen_at'] ?? null),
        'pausedAt' => format_datetime_for_client($sessionRow['paused_at'] ?? null),
        'pauseReason' => $sessionRow['pause_reason'] ?? null,
        'participants' => (int) $sessionRow['participant_count'],
        'questionCount' => count($questions),
        'currentQuestion' => $currentQuestion,
        // Build a lightweight question index so clients can derive currentQuestion
        // from lightweight socket payloads without a full HTTP round-trip.
        // Answer keys (correctAnswer, correctOrder, acceptedAnswers) are withheld
        // while the session is active to prevent client-side cheating.
        // Once the session moves to results/leaderboard/ended the correct answers
        // are included so the results screen can render them without another fetch.
        'questions' => array_map(
            static function (array $question) use ($sessionRow): array {
                $isActive = $sessionRow['status'] === 'active';
                // Strip accepted answers from label_image prompts when active
                $labels = array_map(
                    static function (array $label) use ($isActive): array {
                        if ($isActive) {
                            unset($label['acceptedAnswers']);
                        }
                        return $label;
                    },
                    $question['labels'] ?? []
                );
                $entry = [
                    'id'                  => $question['id'],
                    'questionType'        => $question['questionType'],
                    'text'                => $question['text'],
                    'instructions'        => $question['instructions'] ?? null,
                    'mediaUrl'            => $question['mediaUrl'] ?? null,
                    'options'             => $question['options'] ?? [],
                    'optionIds'           => $question['optionIds'] ?? [],
                    'items'               => $question['items'] ?? null,
                    'labels'              => $labels,
                    'matchingPairs'       => $question['matchingPairs'] ?? null,
                    'timer'               => (int) $question['timer'],
                    'showLeaderboardAfter' => (bool) $question['showLeaderboardAfter'],
                ];
                // Include answer keys only after the question is no longer active
                if (!$isActive) {
                    $entry['correctAnswer'] = $question['correctAnswer'] ?? null;
                    $entry['correctOrder']  = $question['correctOrder'] ?? null;
                }
                return $entry;
            },
            $questions
        ),
        'timeRemainingSeconds' => $currentQuestionIndex >= 0 && in_array($sessionRow['status'], ['active', 'paused'], true)
            ? calculate_time_remaining_seconds(
                $sessionRow['question_started_at'],
                (int) $questions[$currentQuestionIndex]['timer'],
                (int) ($sessionRow['accumulated_pause_ms'] ?? 0),
                $sessionRow['status'] === 'paused' ? ($sessionRow['paused_at'] ?? null) : null
            )
            : null,
    ];
}

function fetch_participant_summary(PDO $pdo, int $sessionId, $participantOrToken, string $sessionStatus = 'ended'): ?array
{
    if (is_array($participantOrToken)) {
        $participant = $participantOrToken;
    } else {
        if ($participantOrToken === '') {
            return null;
        }
        $participantStmt = $pdo->prepare(
            'SELECT
                sp.id,
                sp.session_id,
                sp.student_id,
                sp.status,
                sp.total_score,
                sp.joined_at,
                s.full_name,
                s.phone_number
             FROM session_participants sp
             INNER JOIN students s ON s.id = sp.student_id
             WHERE sp.session_id = ? AND sp.join_token = ?
             LIMIT 1'
        );
        $participantStmt->execute([$sessionId, $participantOrToken]);
        $participant = $participantStmt->fetch() ?: null;
    }

    if (!$participant) {
        return null;
    }

    // Use the pre-cached participant_count from quiz_sessions instead of a COUNT(*) scan
    $countStmt = $pdo->prepare('SELECT participant_count FROM quiz_sessions WHERE id = ? LIMIT 1');
    $countStmt->execute([$sessionId]);
    $participantCount = (int) $countStmt->fetchColumn();

    // If session is active/waiting/etc., we do NOT need to calculate ranks or load detailed stats.
    // The student UI only needs the final stats and leaderboard when the session is ended.
    if ($sessionStatus !== 'ended') {
        return [
            'id' => (int) $participant['id'],
            'studentId' => (int) $participant['student_id'],
            'name' => $participant['full_name'],
            'phoneNumber' => $participant['phone_number'],
            'score' => (int) $participant['total_score'],
            'rank' => 1,
            'participantCount' => $participantCount,
            'answersSubmitted' => 0,
            'correctAnswers' => 0,
            'fullyCorrectAnswers' => 0,
            'partiallyCorrectAnswers' => 0,
            'correctParts' => 0,
            'totalParts' => 0,
            'totalResponseTimeMs' => 0,
        ];
    }

    $statsStmt = $pdo->prepare(
        'SELECT
            pa.is_correct,
            pa.score_awarded,
            pa.response_time_ms,
            pa.response_json,
            sq.question_type,
            sq.answer_key_json
         FROM participant_answers pa
         INNER JOIN session_questions sq ON sq.id = pa.question_id
         WHERE pa.session_id = ? AND pa.participant_id = ?'
    );
    $statsStmt->execute([$sessionId, (int) $participant['id']]);
    $answerRows = $statsStmt->fetchAll() ?: [];

    $answersSubmitted = count($answerRows);
    $fullyCorrectAnswers = 0;
    $partiallyCorrectAnswers = 0;
    $totalResponseTimeMs = 0;
    $correctParts = 0;
    $totalParts = 0;

    foreach ($answerRows as $answerRow) {
        $isCorrect = (int) ($answerRow['is_correct'] ?? 0) === 1;
        $scoreAwarded = (int) ($answerRow['score_awarded'] ?? 0);
        $questionType = (string) ($answerRow['question_type'] ?? 'multiple_choice');
        $responseJson = decode_json_array($answerRow['response_json'] ?? null);
        $answerKey = decode_json_array($answerRow['answer_key_json'] ?? null);

        $totalResponseTimeMs += (int) ($answerRow['response_time_ms'] ?? 0);

        if ($isCorrect) {
            $fullyCorrectAnswers++;
        } elseif ($scoreAwarded > 0) {
            $partiallyCorrectAnswers++;
        }

        if ($questionType === 'label_image') {
            $expectedLabels = isset($answerKey['labels']) && is_array($answerKey['labels']) ? $answerKey['labels'] : [];
            $submittedLabels = isset($responseJson['labels']) && is_array($responseJson['labels']) ? $responseJson['labels'] : [];
            $totalParts += count($expectedLabels);

            foreach ($expectedLabels as $expectedLabel) {
                if (!is_array($expectedLabel)) {
                    continue;
                }

                $labelId = (string) ($expectedLabel['id'] ?? '');
                $acceptedAnswers = isset($expectedLabel['acceptedAnswers']) && is_array($expectedLabel['acceptedAnswers'])
                    ? $expectedLabel['acceptedAnswers']
                    : [];
                $submittedRawAnswer = isset($submittedLabels[$labelId]) ? trim((string) $submittedLabels[$labelId]) : '';
                $submittedAnswer = $submittedRawAnswer !== '' ? normalize_free_text_answer($submittedRawAnswer) : '';

                foreach ($acceptedAnswers as $acceptedAnswer) {
                    $cleanAcceptedAnswer = trim((string) $acceptedAnswer);
                    if ($cleanAcceptedAnswer === '') {
                        continue;
                    }

                    if ($submittedAnswer !== '' && $submittedAnswer === normalize_free_text_answer($cleanAcceptedAnswer)) {
                        $correctParts++;
                        break;
                    }
                }
            }

            continue;
        }

        if ($questionType === 'matching') {
            $expectedPairs = isset($answerKey['pairs']) && is_array($answerKey['pairs']) ? $answerKey['pairs'] : [];
            $submittedMatches = isset($responseJson['matches']) && is_array($responseJson['matches']) ? $responseJson['matches'] : [];
            $totalParts += count($expectedPairs);

            foreach ($expectedPairs as $expectedPair) {
                if (!is_array($expectedPair)) {
                    continue;
                }

                $pairId = (string) ($expectedPair['id'] ?? '');
                $selectedPairId = isset($submittedMatches[$pairId]) ? trim((string) $submittedMatches[$pairId]) : '';
                if ($pairId !== '' && $selectedPairId === $pairId) {
                    $correctParts++;
                }
            }

            continue;
        }

        $totalParts++;
        if ($isCorrect) {
            $correctParts++;
        }
    }

    // Optimized rank: single indexed scan with early termination via COUNT
    $rankStmt = $pdo->prepare(
        'SELECT COUNT(*) + 1
         FROM session_participants sp
         WHERE sp.session_id = ?
           AND (
                sp.total_score > ?
                OR (
                    sp.total_score = ?
                    AND (
                        sp.joined_at < ?
                        OR (sp.joined_at = ? AND sp.id < ?)
                    )
                )
           )'
    );
    $rankStmt->execute([
        $sessionId,
        (int) $participant['total_score'],
        (int) $participant['total_score'],
        $participant['joined_at'],
        $participant['joined_at'],
        (int) $participant['id'],
    ]);
    $rank = (int) $rankStmt->fetchColumn();

    return [
        'id' => (int) $participant['id'],
        'studentId' => (int) $participant['student_id'],
        'name' => $participant['full_name'],
        'phoneNumber' => $participant['phone_number'],
        'score' => (int) $participant['total_score'],
        'rank' => $rank,
        'participantCount' => $participantCount,
        'answersSubmitted' => $answersSubmitted,
        'correctAnswers' => $fullyCorrectAnswers,
        'fullyCorrectAnswers' => $fullyCorrectAnswers,
        'partiallyCorrectAnswers' => $partiallyCorrectAnswers,
        'correctParts' => $correctParts,
        'totalParts' => $totalParts,
        'totalResponseTimeMs' => $totalResponseTimeMs,
    ];
}

function fetch_participant_current_question_response(PDO $pdo, int $sessionId, string $participantToken, ?int $questionId): ?array
{
    if ($questionId === null) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT
            pa.id,
            pa.selected_option_id,
            pa.response_json,
            pa.is_correct,
            pa.response_time_ms,
            pa.score_awarded,
            pa.answered_at,
            qo.display_order AS selected_option_order,
            sq.question_type,
            sq.answer_key_json
         FROM session_participants sp
         INNER JOIN participant_answers pa
            ON pa.participant_id = sp.id
           AND pa.session_id = sp.session_id
         INNER JOIN session_questions sq
            ON sq.id = pa.question_id
         LEFT JOIN question_options qo
            ON qo.id = pa.selected_option_id
         WHERE sp.session_id = ?
           AND sp.join_token = ?
           AND pa.question_id = ?
         LIMIT 1'
    );
    $stmt->execute([$sessionId, $participantToken, $questionId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $responseData = ($row['response_json'] ?? null) !== null ? decode_json_array($row['response_json']) : null;

    if (($row['question_type'] ?? '') === 'label_image') {
        $answerKey = decode_json_array($row['answer_key_json'] ?? null);
        $expectedLabels = isset($answerKey['labels']) && is_array($answerKey['labels']) ? $answerKey['labels'] : [];
        $submittedLabels = is_array($responseData) && isset($responseData['labels']) && is_array($responseData['labels'])
            ? $responseData['labels']
            : [];
        $labelResults = [];

        foreach ($expectedLabels as $expectedLabel) {
            if (!is_array($expectedLabel)) {
                continue;
            }

            $labelId = (string) ($expectedLabel['id'] ?? '');
            $acceptedAnswers = isset($expectedLabel['acceptedAnswers']) && is_array($expectedLabel['acceptedAnswers'])
                ? $expectedLabel['acceptedAnswers']
                : [];
            $submittedRawAnswer = isset($submittedLabels[$labelId]) ? trim((string) $submittedLabels[$labelId]) : '';
            $submittedAnswer = $submittedRawAnswer !== '' ? normalize_free_text_answer($submittedRawAnswer) : '';
            $matched = false;
            $cleanAcceptedAnswers = [];

            foreach ($acceptedAnswers as $acceptedAnswer) {
                $cleanAcceptedAnswer = trim((string) $acceptedAnswer);
                if ($cleanAcceptedAnswer === '') {
                    continue;
                }

                $cleanAcceptedAnswers[] = $cleanAcceptedAnswer;
                if ($submittedAnswer !== '' && $submittedAnswer === normalize_free_text_answer($cleanAcceptedAnswer)) {
                    $matched = true;
                }
            }

            $labelResults[$labelId] = [
                'submitted' => $submittedRawAnswer,
                'isCorrect' => $matched,
                'acceptedAnswers' => $cleanAcceptedAnswers,
            ];
        }

        if (!is_array($responseData)) {
            $responseData = [];
        }
        $responseData['labelResults'] = $labelResults;
    } elseif (($row['question_type'] ?? '') === 'matching') {
        $answerKey = decode_json_array($row['answer_key_json'] ?? null);
        $expectedPairs = isset($answerKey['pairs']) && is_array($answerKey['pairs']) ? $answerKey['pairs'] : [];
        $submittedMatches = is_array($responseData) && isset($responseData['matches']) && is_array($responseData['matches'])
            ? $responseData['matches']
            : [];
        $matchingResults = [];
        $pairLookup = [];

        foreach ($expectedPairs as $expectedPair) {
            if (!is_array($expectedPair)) {
                continue;
            }

            $pairId = (string) ($expectedPair['id'] ?? '');
            if ($pairId !== '') {
                $pairLookup[$pairId] = $expectedPair;
            }
        }

        foreach ($expectedPairs as $expectedPair) {
            if (!is_array($expectedPair)) {
                continue;
            }

            $pairId = (string) ($expectedPair['id'] ?? '');
            $correctRightText = trim((string) ($expectedPair['rightText'] ?? ''));
            $correctRightImageUrl = trim((string) ($expectedPair['rightImageUrl'] ?? ''));
            $selectedPairId = isset($submittedMatches[$pairId]) ? trim((string) $submittedMatches[$pairId]) : '';
            $selectedRightText = '';
            $selectedRightImageUrl = '';
            $matched = false;

            if ($selectedPairId !== '' && isset($pairLookup[$selectedPairId]) && is_array($pairLookup[$selectedPairId])) {
                $selectedRightText = trim((string) ($pairLookup[$selectedPairId]['rightText'] ?? ''));
                $selectedRightImageUrl = trim((string) ($pairLookup[$selectedPairId]['rightImageUrl'] ?? ''));
                $matched = $selectedPairId === $pairId;
            }

            $matchingResults[$pairId] = [
                'selectedPairId' => $selectedPairId !== '' ? $selectedPairId : null,
                'selectedRightText' => $selectedRightText,
                'correctRightText' => $correctRightText,
                'selectedRightLabel' => $selectedPairId !== '' && isset($pairLookup[$selectedPairId]) && is_array($pairLookup[$selectedPairId])
                    ? build_matching_option_label($pairLookup[$selectedPairId])
                    : '',
                'correctRightLabel' => build_matching_option_label($expectedPair),
                'selectedRightImageUrl' => $selectedRightImageUrl !== '' ? $selectedRightImageUrl : null,
                'correctRightImageUrl' => $correctRightImageUrl !== '' ? $correctRightImageUrl : null,
                'isCorrect' => $matched,
            ];
        }

        if (!is_array($responseData)) {
            $responseData = [];
        }
        $responseData['matchingResults'] = $matchingResults;
    }

    return [
        'id' => (int) $row['id'],
        'selectedOptionIndex' => $row['selected_option_order'] !== null ? ((int) $row['selected_option_order'] - 1) : null,
        'selectedOptionId' => $row['selected_option_id'] !== null ? (int) $row['selected_option_id'] : null,
        'responseData' => $responseData,
        'isCorrect' => (int) $row['is_correct'] === 1,
        'responseTimeMs' => $row['response_time_ms'] !== null ? (int) $row['response_time_ms'] : null,
        'scoreAwarded' => (int) $row['score_awarded'],
        'answeredAt' => format_datetime_for_client($row['answered_at']),
        'submitted' => true,
    ];
}

function fetch_public_leaderboard(PDO $pdo, int $sessionId, int $limit = 5): array
{
    $stmt = $pdo->prepare(
        'SELECT
            sp.id,
            sp.student_id,
            s.full_name,
            s.phone_number,
            sp.total_score
         FROM session_participants sp
         INNER JOIN students s ON s.id = sp.student_id
         WHERE sp.session_id = ?
         ORDER BY sp.total_score DESC, sp.joined_at ASC, sp.id ASC
         LIMIT ' . (int) $limit
    );
    $stmt->execute([$sessionId]);
    $rows = $stmt->fetchAll();

    $leaderboard = [];
    foreach ($rows as $index => $row) {
        $leaderboard[] = [
            'id' => (int) $row['id'],
            'studentId' => (int) $row['student_id'],
            'name' => $row['full_name'],
            'phoneNumber' => $row['phone_number'],
            'score' => (int) $row['total_score'],
            'rank' => $index + 1,
        ];
    }

    return $leaderboard;
}

function increment_participant_count(PDO $pdo, int $sessionId): int
{
    $updateStmt = $pdo->prepare(
        'UPDATE quiz_sessions SET participant_count = participant_count + 1 WHERE id = ?'
    );
    $updateStmt->execute([$sessionId]);

    $countStmt = $pdo->prepare('SELECT participant_count FROM quiz_sessions WHERE id = ? LIMIT 1');
    $countStmt->execute([$sessionId]);

    return (int) $countStmt->fetchColumn();
}

function recalculate_participant_count(PDO $pdo, int $sessionId): int
{
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM session_participants WHERE session_id = ?');
    $countStmt->execute([$sessionId]);
    $count = (int) $countStmt->fetchColumn();

    $updateStmt = $pdo->prepare('UPDATE quiz_sessions SET participant_count = ? WHERE id = ?');
    $updateStmt->execute([$count, $sessionId]);

    return $count;
}

/**
 * Build a lightweight payload for Socket.IO state-change broadcasts.
 *
 * Students already have all question data from the initial HTTP bootstrap
 * (get_session.php / join_session.php). The socket only needs to tell them
 * which question is now active, what the status is, and timing info.
 *
 * This replaces the previous pattern of broadcasting the ENTIRE hydrated
 * public session (~5–15 KB) on every state transition, which caused:
 *   - high bandwidth at 300–500 concurrent students
 *   - unnecessary json_encode() of large structures
 *   - redundant React state merges and rerenders
 *
 * The resulting payload is ~200–400 bytes.
 */
function build_lightweight_state_payload(array $sessionRow, array $questions): array
{
    $currentQuestionIndex = get_effective_current_question_index($sessionRow, $questions);
    $currentQuestionId = $sessionRow['current_question_id'] ? (int) $sessionRow['current_question_id'] : null;

    $timeRemainingSeconds = null;
    if ($currentQuestionIndex >= 0 && in_array($sessionRow['status'], ['active', 'paused'], true)) {
        $timeRemainingSeconds = calculate_time_remaining_seconds(
            $sessionRow['question_started_at'],
            (int) $questions[$currentQuestionIndex]['timer'],
            (int) ($sessionRow['accumulated_pause_ms'] ?? 0),
            $sessionRow['status'] === 'paused' ? ($sessionRow['paused_at'] ?? null) : null
        );
    }

    return [
        'sessionId'             => (int) $sessionRow['id'],
        'status'                => $sessionRow['status'],
        'currentQuestionId'     => $currentQuestionId,
        'currentQuestionIndex'  => $currentQuestionIndex,
        'questionStartedAt'     => format_datetime_for_client($sessionRow['question_started_at']),
        'serverNow'             => format_server_now_for_client(),
        'timeRemainingSeconds'  => $timeRemainingSeconds,
        'hostConnectionStatus'  => $sessionRow['host_connection_status'] ?? 'connected',
        'pausedAt'              => format_datetime_for_client($sessionRow['paused_at'] ?? null),
        'pauseReason'           => $sessionRow['pause_reason'] ?? null,
        'participants'          => (int) $sessionRow['participant_count'],
        'questionCount'         => count($questions),
    ];
}
