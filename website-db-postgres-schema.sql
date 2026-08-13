-- ============================================================
-- PostgreSQL schema converted from MySQL dump: website-db
-- Run this in Render → Postgres → SQL Editor / psql
-- Safe to re-run: uses IF NOT EXISTS
-- ============================================================

BEGIN;

-- 1) admin
CREATE TABLE IF NOT EXISTS admin (
  id BIGSERIAL PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 2) students
CREATE TABLE IF NOT EXISTS students (
  id BIGSERIAL PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  phone_number VARCHAR(20) NOT NULL UNIQUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 3) quiz_sessions
-- current_question_id FK is added later (circular dependency)
CREATE TABLE IF NOT EXISTS quiz_sessions (
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
);

CREATE INDEX IF NOT EXISTS idx_quiz_sessions_admin_id ON quiz_sessions(admin_id);
CREATE INDEX IF NOT EXISTS idx_quiz_sessions_status ON quiz_sessions(status);
CREATE INDEX IF NOT EXISTS idx_quiz_sessions_created_at ON quiz_sessions(created_at);

-- 4) session_questions
CREATE TABLE IF NOT EXISTS session_questions (
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
);

CREATE INDEX IF NOT EXISTS idx_session_questions_session_id ON session_questions(session_id);

-- 5) question_options
CREATE TABLE IF NOT EXISTS question_options (
  id BIGSERIAL PRIMARY KEY,
  question_id BIGINT NOT NULL REFERENCES session_questions(id) ON DELETE CASCADE,
  display_order SMALLINT NOT NULL,
  option_text VARCHAR(500) NOT NULL,
  is_correct BOOLEAN NOT NULL DEFAULT FALSE,
  UNIQUE (question_id, display_order)
);

CREATE INDEX IF NOT EXISTS idx_question_options_question_id ON question_options(question_id);

-- 6) session_participants
CREATE TABLE IF NOT EXISTS session_participants (
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
);

CREATE INDEX IF NOT EXISTS idx_session_participants_session_id ON session_participants(session_id);
CREATE INDEX IF NOT EXISTS idx_sp_leaderboard
  ON session_participants(session_id, total_score DESC, joined_at ASC, id ASC);
CREATE INDEX IF NOT EXISTS idx_participant_lookup
  ON session_participants(session_id, join_token);

-- 7) participant_question_option_orders
CREATE TABLE IF NOT EXISTS participant_question_option_orders (
  id BIGSERIAL PRIMARY KEY,
  participant_id BIGINT NOT NULL REFERENCES session_participants(id) ON DELETE CASCADE,
  question_id BIGINT NOT NULL REFERENCES session_questions(id) ON DELETE CASCADE,
  option_order_json JSONB NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (participant_id, question_id)
);

CREATE INDEX IF NOT EXISTS idx_participant_question_option_orders_question_id
  ON participant_question_option_orders(question_id);

-- 8) participant_answers  << this was missing and caused get_reports 500
CREATE TABLE IF NOT EXISTS participant_answers (
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
);

CREATE INDEX IF NOT EXISTS idx_participant_answers_session_id ON participant_answers(session_id);
CREATE INDEX IF NOT EXISTS idx_participant_answers_participant_id ON participant_answers(participant_id);
CREATE INDEX IF NOT EXISTS idx_pa_session_question ON participant_answers(session_id, question_id);

-- 9) session_score_events
CREATE TABLE IF NOT EXISTS session_score_events (
  id BIGSERIAL PRIMARY KEY,
  session_id BIGINT NOT NULL REFERENCES quiz_sessions(id) ON DELETE CASCADE,
  participant_id BIGINT NOT NULL REFERENCES session_participants(id) ON DELETE CASCADE,
  question_id BIGINT NULL REFERENCES session_questions(id) ON DELETE SET NULL,
  answer_id BIGINT NULL REFERENCES participant_answers(id) ON DELETE SET NULL,
  score_delta INTEGER NOT NULL,
  event_type VARCHAR(50) NOT NULL DEFAULT 'answer_score',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_session_score_events_session_id ON session_score_events(session_id);
CREATE INDEX IF NOT EXISTS idx_session_score_events_participant_id ON session_score_events(participant_id);

-- 10) session_events
CREATE TABLE IF NOT EXISTS session_events (
  id BIGSERIAL PRIMARY KEY,
  session_id BIGINT NOT NULL REFERENCES quiz_sessions(id) ON DELETE CASCADE,
  participant_id BIGINT NULL REFERENCES session_participants(id) ON DELETE SET NULL,
  event_type VARCHAR(50) NOT NULL,
  event_payload JSONB NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_session_events_session_id ON session_events(session_id);
CREATE INDEX IF NOT EXISTS idx_session_events_participant_id ON session_events(participant_id);
CREATE INDEX IF NOT EXISTS idx_session_events_type_created_at ON session_events(event_type, created_at);

-- Circular FK: quiz_sessions.current_question_id → session_questions.id
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM pg_constraint
    WHERE conname = 'fk_quiz_sessions_current_question'
  ) THEN
    ALTER TABLE quiz_sessions
      ADD CONSTRAINT fk_quiz_sessions_current_question
      FOREIGN KEY (current_question_id)
      REFERENCES session_questions(id)
      ON DELETE SET NULL;
  END IF;
END $$;

-- Seed admin (only if empty)
INSERT INTO admin (id, name, username, password)
SELECT 1, 'Kishore', 'mailingkishore72@gmail.com', '123'
WHERE NOT EXISTS (SELECT 1 FROM admin WHERE id = 1);

-- Keep sequences in sync if you later import data with explicit IDs
SELECT setval(pg_get_serial_sequence('admin', 'id'), COALESCE((SELECT MAX(id) FROM admin), 1), true);
SELECT setval(pg_get_serial_sequence('students', 'id'), COALESCE((SELECT MAX(id) FROM students), 1), true);
SELECT setval(pg_get_serial_sequence('quiz_sessions', 'id'), COALESCE((SELECT MAX(id) FROM quiz_sessions), 1), true);
SELECT setval(pg_get_serial_sequence('session_questions', 'id'), COALESCE((SELECT MAX(id) FROM session_questions), 1), true);
SELECT setval(pg_get_serial_sequence('question_options', 'id'), COALESCE((SELECT MAX(id) FROM question_options), 1), true);
SELECT setval(pg_get_serial_sequence('session_participants', 'id'), COALESCE((SELECT MAX(id) FROM session_participants), 1), true);
SELECT setval(pg_get_serial_sequence('participant_question_option_orders', 'id'), COALESCE((SELECT MAX(id) FROM participant_question_option_orders), 1), true);
SELECT setval(pg_get_serial_sequence('participant_answers', 'id'), COALESCE((SELECT MAX(id) FROM participant_answers), 1), true);
SELECT setval(pg_get_serial_sequence('session_score_events', 'id'), COALESCE((SELECT MAX(id) FROM session_score_events), 1), true);
SELECT setval(pg_get_serial_sequence('session_events', 'id'), COALESCE((SELECT MAX(id) FROM session_events), 1), true);

COMMIT;
