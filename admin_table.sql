CREATE TABLE IF NOT EXISTS admin (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id BIGINT UNSIGNED NOT NULL,
    public_code CHAR(6) NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    youtube_url VARCHAR(500) NULL,
    thumbnail_url VARCHAR(500) NULL,
    intro_video_url VARCHAR(500) NULL,
    status ENUM('draft','scheduled','waiting','active','paused','leaderboard','results','ended','archived') NOT NULL DEFAULT 'draft',
    current_question_id BIGINT UNSIGNED NULL,
    question_started_at DATETIME(6) NULL,
    host_connection_status ENUM('connected','reconnecting','disconnected') NOT NULL DEFAULT 'connected',
    host_last_seen_at DATETIME(6) NULL,
    paused_at DATETIME(6) NULL,
    pause_reason VARCHAR(255) NULL,
    accumulated_pause_ms INT UNSIGNED NOT NULL DEFAULT 0,
    participant_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quiz_sessions_public_code (public_code),
    KEY idx_quiz_sessions_admin_id (admin_id),
    KEY idx_quiz_sessions_status (status),
    KEY idx_quiz_sessions_created_at (created_at),
    CONSTRAINT fk_quiz_sessions_admin FOREIGN KEY (admin_id) REFERENCES admin(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS session_questions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    display_order INT UNSIGNED NOT NULL,
    question_type ENUM('multiple_choice','sorting','drag_drop','find_on_image','label_image','matching','fill_in_blanks') NOT NULL DEFAULT 'multiple_choice',
    question_text TEXT NOT NULL,
    instructions TEXT NULL,
    media_url VARCHAR(500) NULL,
    content_json JSON NULL,
    answer_key_json JSON NULL,
    scoring_json JSON NULL,
    time_limit_seconds SMALLINT UNSIGNED NOT NULL,
    show_leaderboard_after TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_session_questions_order (session_id, display_order),
    KEY idx_session_questions_session_id (session_id),
    CONSTRAINT fk_session_questions_session FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_options (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id BIGINT UNSIGNED NOT NULL,
    display_order TINYINT UNSIGNED NOT NULL,
    option_text VARCHAR(500) NOT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_question_options_order (question_id, display_order),
    KEY idx_question_options_question_id (question_id),
    CONSTRAINT fk_question_options_question FOREIGN KEY (question_id) REFERENCES session_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS students (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    phone_number VARCHAR(20) NOT NULL UNIQUE,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS session_participants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    join_token CHAR(36) NOT NULL,
    status ENUM('joined','active','disconnected','finished') NOT NULL DEFAULT 'joined',
    total_score INT NOT NULL DEFAULT 0,
    joined_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    disconnected_at DATETIME(6) NULL,
    active_socket_id VARCHAR(100) NULL,
    active_socket_connected_at DATETIME(6) NULL,
    connection_status ENUM('connected','reconnecting','disconnected') NOT NULL DEFAULT 'connected',
    last_socket_error VARCHAR(255) NULL,
    UNIQUE KEY uq_session_participants_join_token (join_token),
    KEY idx_session_participants_session_id (session_id),
    UNIQUE KEY uq_session_participants_student_session (session_id, student_id),
    CONSTRAINT fk_session_participants_session FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_session_participants_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS participant_question_option_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    participant_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    option_order_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_participant_question_option_order (participant_id, question_id),
    KEY idx_participant_question_option_orders_question_id (question_id),
    CONSTRAINT fk_participant_question_option_orders_participant FOREIGN KEY (participant_id) REFERENCES session_participants(id) ON DELETE CASCADE,
    CONSTRAINT fk_participant_question_option_orders_question FOREIGN KEY (question_id) REFERENCES session_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS participant_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    participant_id BIGINT UNSIGNED NOT NULL,
    selected_option_id BIGINT UNSIGNED NULL,
    response_json JSON NULL,
    is_correct TINYINT(1) NOT NULL,
    response_time_ms INT UNSIGNED NULL,
    score_awarded INT NOT NULL DEFAULT 0,
    max_score INT NOT NULL DEFAULT 1000,
    answered_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_participant_answers_question_participant (question_id, participant_id),
    KEY idx_participant_answers_session_id (session_id),
    KEY idx_participant_answers_participant_id (participant_id),
    CONSTRAINT fk_participant_answers_session FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_participant_answers_question FOREIGN KEY (question_id) REFERENCES session_questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_participant_answers_participant FOREIGN KEY (participant_id) REFERENCES session_participants(id) ON DELETE CASCADE,
    CONSTRAINT fk_participant_answers_option FOREIGN KEY (selected_option_id) REFERENCES question_options(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS session_score_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    participant_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NULL,
    answer_id BIGINT UNSIGNED NULL,
    score_delta INT NOT NULL,
    event_type ENUM('answer_score','bonus','penalty','adjustment') NOT NULL DEFAULT 'answer_score',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY idx_session_score_events_session_id (session_id),
    KEY idx_session_score_events_participant_id (participant_id),
    CONSTRAINT fk_session_score_events_session FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_session_score_events_participant FOREIGN KEY (participant_id) REFERENCES session_participants(id) ON DELETE CASCADE,
    CONSTRAINT fk_session_score_events_question FOREIGN KEY (question_id) REFERENCES session_questions(id) ON DELETE SET NULL,
    CONSTRAINT fk_session_score_events_answer FOREIGN KEY (answer_id) REFERENCES participant_answers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE quiz_sessions
    ADD CONSTRAINT fk_quiz_sessions_current_question
    FOREIGN KEY (current_question_id) REFERENCES session_questions(id) ON DELETE SET NULL;

-- Composite index for leaderboard (prevents filesort on LIMIT queries)
ALTER TABLE session_participants
    ADD INDEX idx_sp_leaderboard (session_id, total_score DESC, joined_at ASC, id ASC);

-- Composite index for answer counts
ALTER TABLE participant_answers
    ADD INDEX idx_pa_session_question (session_id, question_id);
