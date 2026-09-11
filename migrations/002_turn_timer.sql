-- Round 5, Priority 1: server-side 15s turn timer — migration for EXISTING
-- deployed databases (fresh installs get this from database.sql directly).
ALTER TABLE matches
    ADD COLUMN turn_started_at DATETIME DEFAULT NULL
    COMMENT 'Server clock for the 15s turn timer. NULL while match is waiting for players.'
    AFTER consecutive_sixes;

UPDATE matches
SET turn_started_at = CURRENT_TIMESTAMP
WHERE status IN ('playing', 'ready')
  AND current_turn_id IS NOT NULL
  AND turn_started_at IS NULL;
