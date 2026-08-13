<?php
/**
 * One-time schema ensure for PostgreSQL.
 * Creates missing quiz tables and id sequences.
 *
 * Auth: admin Bearer token OR ?key=SETUP_KEY matching env SETUP_KEY
 */
require_once 'config.php';
require_once 'auth_helper.php';

header('Content-Type: application/json');

$setupKey = getenv('SETUP_KEY') ?: '';
$providedKey = trim((string) ($_GET['key'] ?? ''));
$authorized = false;

if ($setupKey !== '' && $providedKey !== '' && hash_equals($setupKey, $providedKey)) {
    $authorized = true;
} else {
    $token = get_bearer_token();
    if ($token !== null && verify_auth_token($token) !== null) {
        $authorized = true;
    }
}

if (!$authorized) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

function table_exists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM pg_tables WHERE schemaname = 'public' AND tablename = ? LIMIT 1"
    );
    $stmt->execute([$name]);
    return (bool) $stmt->fetchColumn();
}

function ensure_id_sequence(PDO $pdo, string $table): void
{
    $seq = $table . '_id_seq';
    $pdo->exec("CREATE SEQUENCE IF NOT EXISTS {$seq}");
    $pdo->exec(
        "SELECT setval('{$seq}', COALESCE((SELECT MAX(id) FROM {$table}), 1), true)"
    );
    $pdo->exec(
        "ALTER TABLE {$table} ALTER COLUMN id SET DEFAULT nextval('{$seq}')"
    );
    $pdo->exec("ALTER SEQUENCE {$seq} OWNED BY {$table}.id");
}

$created = [];
$ensuredSequences = [];
$errors = [];

try {
    $pdo->beginTransaction();

    if (!table_exists($pdo, 'admin')) {
        $pdo->exec(
            'CREATE TABLE admin (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                username VARCHAR(50) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )'
        );
        $created[] = 'admin';
    }

    if (!table_exists($pdo, 'quiz_sessions')) {
        $pdo->exec(
            "CREATE TABLE quiz_sessions (
                id BIGSERIAL PRIMARY KEY,
                admin_id BIGINT NOT NULL REFERENCES admin(id) ON DELETE CASCADE,
                public_code CHAR(6) NOT NULL UNIQUE,
                title VARCHAR(150) NOT NULL,
                description TEXT NULL,
                youtube_url VARCHAR(500) NULL,
                thumbnail_url VARCHAR(500) NULL,
                intro_video_url VARCHAR(500) NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'draft',
                current_question_id BIGINT NULL,
                question_started_at TIMESTAMPTZ NULL,
                participant_count INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                host_connection_status VARCHAR(50) NOT NULL DEFAULT 'connected',
                host_last_seen_at TIMESTAMPTZ NULL,
                paused_at TIMESTAMPTZ NULL,
                pause_reason VARCHAR(255) NULL,
                accumulated_pause_ms INTEGER NOT NULL DEFAULT 0
            )"
        );
        $created[] = 'quiz_sessions';
    }

    if (!table_exists($pdo, 'session_questions')) {
        $pdo->exec(
            "CREATE TABLE session_questions (
                id BIGSERIAL PRIMARY KEY,
                session_id BIGINT NOT NULL REFERENCES quiz_sessions(id) ON DELETE CASCADE,
                display_order INTEGER NOT NULL,
                question_type VARCHAR(50) NOT NULL DEFAULT 'multiple_choice',
                question_text TEXT NOT NULL,
                instructions TEXT NULL,
                media_url VARCHAR(500) NULL,
                content_json JSONB NULL,
                answer_key_json JSONB NULL,
                scoring_json JSONB NULL,
                time_limit_seconds SMALLINT NOT NULL,
                show_leaderboard_after BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (session_id, display_order)
            )"
        );
        $created[] = 'session_questions';
    }

    if (!table_exists($pdo, 'question_options')) {
        $pdo->exec(
            'CREATE TABLE question_options (
                id BIGSERIAL PRIMARY KEY,
                question_id BIGINT NOT NULL REFERENCES session_questions(id) ON DELETE CASCADE,
                display_order SMALLINT NOT NULL,
                option_text VARCHAR(500) NOT NULL,
                is_correct BOOLEAN NOT NULL DEFAULT FALSE,
                UNIQUE (question_id, display_order)
            )'
        );
        $created[] = 'question_options';
    }

    if (!table_exists($pdo, 'students')) {
        $pdo->exec(
            'CREATE TABLE students (
                id BIGSERIAL PRIMARY KEY,
                full_name VARCHAR(100) NOT NULL,
                phone_number VARCHAR(20) NOT NULL UNIQUE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )'
        );
        $created[] = 'students';
    }

    if (!table_exists($pdo, 'session_participants')) {
        $pdo->exec(
            "CREATE TABLE session_participants (
                id BIGSERIAL PRIMARY KEY,
                session_id BIGINT NOT NULL REFERENCES quiz_sessions(id) ON DELETE CASCADE,
                student_id BIGINT NOT NULL REFERENCES students(id) ON DELETE CASCADE,
                join_token CHAR(36) NOT NULL UNIQUE,
                status VARCHAR(50) NOT NULL DEFAULT 'joined',
                total_score INTEGER NOT NULL DEFAULT 0,
                joined_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                disconnected_at TIMESTAMPTZ NULL,
                active_socket_id VARCHAR(100) NULL,
                active_socket_connected_at TIMESTAMPTZ NULL,
                connection_status VARCHAR(50) NOT NULL DEFAULT 'connected',
                last_socket_error VARCHAR(255) NULL,
                UNIQUE (session_id, student_id)
            )"
        );
        $created[] = 'session_participants';
    }

    if (!table_exists($pdo, 'participant_question_option_orders')) {
        $pdo->exec(
            'CREATE TABLE participant_question_option_orders (
                id BIGSERIAL PRIMARY KEY,
                participant_id BIGINT NOT NULL REFERENCES session_participants(id) ON DELETE CASCADE,
                question_id BIGINT NOT NULL REFERENCES session_questions(id) ON DELETE CASCADE,
                option_order_json JSONB NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (participant_id, question_id)
            )'
        );
        $created[] = 'participant_question_option_orders';
    }

    if (!table_exists($pdo, 'participant_answers')) {
        $pdo->exec(
            'CREATE TABLE participant_answers (
                id BIGSERIAL PRIMARY KEY,
                session_id BIGINT NOT NULL REFERENCES quiz_sessions(id) ON DELETE CASCADE,
                question_id BIGINT NOT NULL REFERENCES session_questions(id) ON DELETE CASCADE,
                participant_id BIGINT NOT NULL REFERENCES session_participants(id) ON DELETE CASCADE,
                selected_option_id BIGINT NULL REFERENCES question_options(id) ON DELETE CASCADE,
                response_json JSONB NULL,
                is_correct BOOLEAN NOT NULL,
                response_time_ms INTEGER NULL,
                score_awarded INTEGER NOT NULL DEFAULT 0,
                max_score INTEGER NOT NULL DEFAULT 1000,
                answered_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (question_id, participant_id)
            )'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_pa_session_question
             ON participant_answers (session_id, question_id)'
        );
        $created[] = 'participant_answers';
    }

    if (!table_exists($pdo, 'session_score_events')) {
        $pdo->exec(
            "CREATE TABLE session_score_events (
                id BIGSERIAL PRIMARY KEY,
                session_id BIGINT NOT NULL REFERENCES quiz_sessions(id) ON DELETE CASCADE,
                participant_id BIGINT NOT NULL REFERENCES session_participants(id) ON DELETE CASCADE,
                question_id BIGINT NULL REFERENCES session_questions(id) ON DELETE SET NULL,
                answer_id BIGINT NULL REFERENCES participant_answers(id) ON DELETE SET NULL,
                score_delta INTEGER NOT NULL,
                event_type VARCHAR(50) NOT NULL DEFAULT 'answer_score',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );
        $created[] = 'session_score_events';
    }

    if (!table_exists($pdo, 'session_events')) {
        $pdo->exec(
            'CREATE TABLE session_events (
                id BIGSERIAL PRIMARY KEY,
                session_id BIGINT NOT NULL REFERENCES quiz_sessions(id) ON DELETE CASCADE,
                participant_id BIGINT NULL REFERENCES session_participants(id) ON DELETE SET NULL,
                event_type VARCHAR(50) NOT NULL,
                event_payload JSONB NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )'
        );
        $created[] = 'session_events';
    }

    // Ensure auto-increment sequences exist even for tables created earlier without them.
    foreach ([
        'admin',
        'quiz_sessions',
        'session_questions',
        'question_options',
        'students',
        'session_participants',
        'participant_question_option_orders',
        'participant_answers',
        'session_score_events',
        'session_events',
    ] as $table) {
        if (table_exists($pdo, $table)) {
            ensure_id_sequence($pdo, $table);
            $ensuredSequences[] = $table . '_id_seq';
        }
    }

    // Optional FK for current_question_id (ignore if already present).
    try {
        $pdo->exec(
            'ALTER TABLE quiz_sessions
             ADD CONSTRAINT fk_quiz_sessions_current_question
             FOREIGN KEY (current_question_id)
             REFERENCES session_questions(id)
             ON DELETE SET NULL'
        );
    } catch (Throwable $ignored) {
        // Constraint may already exist.
    }

    $pdo->commit();

    $tables = $pdo->query(
        "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"
    )->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'created' => $created,
        'ensuredSequences' => $ensuredSequences,
        'tables' => $tables,
        'message' => 'Schema ensured successfully',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Schema ensure failed',
        'debug_error' => $e->getMessage(),
        'created' => $created,
        'errors' => $errors,
    ]);
}
