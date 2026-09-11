-- ======================================================
-- Round 6: Score system overhaul (dice/turn/kill/home points + end-game
-- bonuses, with a highest-score-wins tie-breaker chain), Exit Game button,
-- and disconnect grace-period handling.
-- Fresh installs get this from database.sql directly.
-- ======================================================

ALTER TABLE matches
    ADD COLUMN score_stats JSON DEFAULT NULL
    COMMENT 'Per-player tie-breaker counters: {home_count, kills, rolls}. Kept separate from `scores` (plain point totals) so the existing scoreboard UI, which expects a plain number, is unaffected.'
    AFTER scores,
    ADD COLUMN exited_players JSON DEFAULT NULL
    COMMENT 'Array of user_ids who voluntarily used the Exit Game button (distinct from a timeout forfeit).'
    AFTER score_stats;
