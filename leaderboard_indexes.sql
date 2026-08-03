-- Migration script: Add composite indexes for leaderboard and answer counts
-- Apply this to production database to prevent filesorts at 300+ concurrency

-- Covers the leaderboard ORDER BY clause
ALTER TABLE session_participants
  ADD INDEX idx_sp_leaderboard (session_id, total_score DESC, joined_at ASC, id ASC);

-- Covers the answer-count query in submit_answer.php
ALTER TABLE participant_answers
  ADD INDEX idx_pa_session_question (session_id, question_id);
