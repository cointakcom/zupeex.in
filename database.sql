-- ======================================================
-- DATABASE SCHEMA - COMPLETE WITH CUSTOM ADMIN
-- Ludo Tournament Platform - Production Ready
-- Version: 6.2.0 - LOGIN FIXED
-- Admin: Aakashhunmine / Aakashhunmine@8090
-- ======================================================
--
-- ✅ THIS FILE IS ALREADY FULLY MERGED / UP TO DATE.
-- Every schema change from the numbered migration files below has been
-- checked against this file and is already included here — nothing is
-- missing, so a FRESH install only ever needs to run THIS single file:
--
--   002_turn_timer.sql            -> matches.turn_started_at            (present)
--   003_admin_adjustment_source.sql -> transactions.source ENUM +admin_adjustment (present)
--   004_tournament_tickets.sql    -> game_tickets, ticket_queue, matches.ticket_id (present)
--   005_kyc_personal_details.sql  -> users.kyc_full_name / kyc_dob / kyc_address /
--                                     kyc_city / kyc_state / kyc_pincode (present)
--   006_referrals_rebuild.sql     -> referrals table, transactions.source ENUM +referral_bonus (present)
--   007_scoring_and_exit.sql      -> matches.score_stats, matches.exited_players (present)
--
-- Those migration files are ONLY for upgrading an OLD, already-live database
-- that was created before this version of database.sql existed. Do NOT run
-- them against a database that was created from this file — every one of
-- them uses ADD COLUMN / MODIFY COLUMN without an "IF NOT EXISTS" guard, so
-- running them again here would fail with a "Duplicate column" error since
-- the columns already exist.
-- ======================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+05:30";

-- ==============================================
-- 1. USERS TABLE — FIXED (password instead of password_hash)
-- ==============================================
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(50) NOT NULL,
    `mobile` VARCHAR(10) NOT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `password` VARCHAR(255) NOT NULL,  -- 🔥 FIXED: password_hash → password
    `kyc_full_name` VARCHAR(100) DEFAULT NULL,
    `kyc_dob` DATE DEFAULT NULL,
    `kyc_address` VARCHAR(255) DEFAULT NULL,
    `kyc_city` VARCHAR(100) DEFAULT NULL,
    `kyc_state` VARCHAR(100) DEFAULT NULL,
    `kyc_pincode` VARCHAR(10) DEFAULT NULL,
    `wallet_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_matches_played` INT(11) NOT NULL DEFAULT 0,
    `total_matches_won` INT(11) NOT NULL DEFAULT 0,
    `total_earnings` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_withdrawn` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `elo_rating` INT(11) NOT NULL DEFAULT 1200,
    `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `kyc_status` ENUM('not_submitted','pending','verified','rejected') NOT NULL DEFAULT 'not_submitted',
    `pan_number` VARCHAR(20) DEFAULT NULL,
    `aadhaar_number` VARCHAR(20) DEFAULT NULL,
    `refer_code` VARCHAR(20) DEFAULT NULL,
    `referred_by` INT(11) DEFAULT NULL,
    `referral_earnings` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `failed_login_attempts` INT(11) NOT NULL DEFAULT 0,
    `last_login` TIMESTAMP NULL DEFAULT NULL,
    `last_withdrawal_date` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_mobile` (`mobile`),
    UNIQUE KEY `uk_username` (`username`),
    UNIQUE KEY `uk_refer_code` (`refer_code`),
    KEY `idx_is_active` (`is_active`),
    KEY `idx_kyc_status` (`kyc_status`),
    KEY `idx_elo_rating` (`elo_rating`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 2. SESSIONS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `sessions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `session_token` VARCHAR(255) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `device_type` VARCHAR(50) DEFAULT NULL,
    `last_activity` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_session_token` (`session_token`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_expires_at` (`expires_at`),
    CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 3. TOURNAMENTS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `tournaments` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `tournament_code` VARCHAR(20) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `game_mode` ENUM('1vs1','1vs4') DEFAULT '1vs1',
    `entry_fee` DECIMAL(10,2) NOT NULL,
    `prize_pool` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `platform_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `max_players` INT(11) NOT NULL DEFAULT 4,
    `total_players` INT(11) DEFAULT 100,
    `registered_players` INT(11) DEFAULT 0,
    `min_players` INT(11) NOT NULL DEFAULT 2,
    `current_players` INT(11) NOT NULL DEFAULT 0,
    `first_prize_percent` DECIMAL(5,2) DEFAULT 60.00,
    `second_prize_percent` DECIMAL(5,2) DEFAULT 30.00,
    `third_prize_percent` DECIMAL(5,2) DEFAULT 10.00,
    `first_prize_amount` DECIMAL(10,2) DEFAULT 0.00,
    `second_prize_amount` DECIMAL(10,2) DEFAULT 0.00,
    `third_prize_amount` DECIMAL(10,2) DEFAULT 0.00,
    `scoring_mode` ENUM('winner_takes_all','points_timed') NOT NULL DEFAULT 'winner_takes_all',
    `time_limit_minutes` INT(11) DEFAULT NULL,
    `top100_payout_config` JSON DEFAULT NULL,
    `status` ENUM('scheduled','active','in_progress','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    `registration_open` TINYINT(1) DEFAULT 1,
    `winner_id` INT(11) DEFAULT NULL,
    `winner_amount` DECIMAL(10,2) DEFAULT NULL,
    `start_time` TIMESTAMP NULL DEFAULT NULL,
    `end_time` TIMESTAMP NULL DEFAULT NULL,
    `registration_start` TIMESTAMP NULL DEFAULT NULL,
    `registration_end` TIMESTAMP NULL DEFAULT NULL,
    `tournament_start` TIMESTAMP NULL DEFAULT NULL,
    `tournament_end` TIMESTAMP NULL DEFAULT NULL,
    `created_by` INT(11) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tournament_code` (`tournament_code`),
    KEY `idx_status` (`status`),
    KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 4. TOURNAMENT REGISTRATIONS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `tournament_registrations` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `tournament_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `entry_fee_paid` DECIMAL(10,2) NOT NULL,
    `status` ENUM('registered','playing','eliminated','winner','runner_up','third_place') DEFAULT 'registered',
    `position` INT(11) DEFAULT NULL,
    `prize_won` DECIMAL(10,2) DEFAULT 0.00,
    `total_score` INT(11) NOT NULL DEFAULT 0,
    `registered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tournament_user` (`tournament_id`, `user_id`),
    KEY `idx_user_id` (`user_id`),
    CONSTRAINT `fk_reg_tournament` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_reg_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 5. TOURNAMENT MATCHES TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `tournament_matches` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `tournament_id` INT(11) NOT NULL,
    `match_id` INT(11) DEFAULT NULL,
    `round` INT(11) DEFAULT 1,
    `bracket_position` INT(11) DEFAULT NULL,
    `player1_id` INT(11) DEFAULT NULL,
    `player2_id` INT(11) DEFAULT NULL,
    `player3_id` INT(11) DEFAULT NULL,
    `player4_id` INT(11) DEFAULT NULL,
    `winner_id` INT(11) DEFAULT NULL,
    `status` ENUM('pending','in_progress','completed') DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_tournament_id` (`tournament_id`),
    CONSTRAINT `fk_tm_tournament` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 5b. GAME TICKETS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `game_tickets` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `game_mode` ENUM('1vs1','1vs4') NOT NULL,
    `entry_fee` DECIMAL(10,2) NOT NULL,
    `duration_minutes` INT(11) NOT NULL DEFAULT 15,
    `payout_percent` DECIMAL(5,2) NOT NULL DEFAULT 70.00,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_mode_active` (`game_mode`, `is_active`),
    INDEX `idx_entry_fee` (`entry_fee`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 5c. TICKET QUEUE TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `ticket_queue` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `ticket_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `status` ENUM('waiting','matched','cancelled') NOT NULL DEFAULT 'waiting',
    `match_id` INT(11) DEFAULT NULL,
    `transaction_id` INT(11) DEFAULT NULL,
    `joined_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_ticket_status` (`ticket_id`, `status`),
    INDEX `idx_user_waiting` (`user_id`, `status`),
    CONSTRAINT `fk_ticket_queue_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `game_tickets`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ticket_queue_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 5d. SEED TICKETS
-- ==============================================
INSERT IGNORE INTO game_tickets (game_mode, entry_fee, duration_minutes, payout_percent, is_active) VALUES
    ('1vs1', 10, 15, 70.00, 1),
    ('1vs1', 50, 15, 70.00, 1),
    ('1vs1', 100, 15, 70.00, 1),
    ('1vs1', 500, 15, 70.00, 1),
    ('1vs4', 10, 15, 70.00, 1),
    ('1vs4', 50, 15, 70.00, 1),
    ('1vs4', 100, 15, 70.00, 1),
    ('1vs4', 500, 15, 70.00, 1);

-- ==============================================
-- 6. MATCHES TABLE — FIXED (added foreign keys for players)
-- ==============================================
CREATE TABLE IF NOT EXISTS `matches` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `tournament_id` INT(11) DEFAULT NULL,
    `ticket_id` INT(11) DEFAULT NULL,
    `game_mode` ENUM('1vs1','1vs4') NOT NULL DEFAULT '1vs1',
    `room_code` VARCHAR(10) NOT NULL,
    `entry_fee` DECIMAL(10,2) NOT NULL,
    `prize_pool` DECIMAL(10,2) NOT NULL,
    `platform_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `player1_id` INT(11) NOT NULL,
    `player2_id` INT(11) DEFAULT NULL,
    `player3_id` INT(11) DEFAULT NULL,
    `player4_id` INT(11) DEFAULT NULL,
    `player1_name` VARCHAR(50) NOT NULL,
    `player2_name` VARCHAR(50) DEFAULT NULL,
    `player3_name` VARCHAR(50) DEFAULT NULL,
    `player4_name` VARCHAR(50) DEFAULT NULL,
    `status` ENUM('waiting','ready','playing','paused','completed','cancelled') NOT NULL DEFAULT 'waiting',
    `current_turn_id` INT(11) DEFAULT NULL,
    `dice_value` TINYINT(1) DEFAULT NULL,
    `dice_rolled_by` INT(11) DEFAULT NULL,
    `last_dice_roll_time` TIMESTAMP NULL DEFAULT NULL,
    `consecutive_sixes` TINYINT(1) NOT NULL DEFAULT 0,
    `turn_started_at` DATETIME DEFAULT NULL,
    `player_colors` JSON DEFAULT NULL,
    `scores` JSON DEFAULT NULL,
    `score_stats` JSON DEFAULT NULL,
    `exited_players` JSON DEFAULT NULL,
    `match_ends_at` TIMESTAMP NULL DEFAULT NULL,
    `winner_id` INT(11) DEFAULT NULL,
    `winner_name` VARCHAR(50) DEFAULT NULL,
    `winning_amount` DECIMAL(10,2) DEFAULT NULL,
    `tds_deducted` DECIMAL(10,2) DEFAULT NULL,
    `turn_number` INT(11) NOT NULL DEFAULT 0,
    `max_turns` INT(11) NOT NULL DEFAULT 50,
    `board_state` JSON DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `started_at` TIMESTAMP NULL DEFAULT NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_room_code` (`room_code`),
    KEY `idx_tournament_id` (`tournament_id`),
    KEY `idx_player1_id_status` (`player1_id`, `status`),
    KEY `idx_status_created` (`status`, `created_at`),
    KEY `idx_matchmaking` (`game_mode`, `entry_fee`, `status`),
    KEY `idx_ticket_id` (`ticket_id`),
    KEY `idx_turn_started_at` (`turn_started_at`),
    CONSTRAINT `fk_matches_tournament` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_matches_player1` FOREIGN KEY (`player1_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_matches_player2` FOREIGN KEY (`player2_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_matches_player3` FOREIGN KEY (`player3_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_matches_player4` FOREIGN KEY (`player4_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_matches_winner` FOREIGN KEY (`winner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 7. TRANSACTIONS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `transactions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `tournament_id` INT(11) DEFAULT NULL,
    `match_id` INT(11) DEFAULT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `type` ENUM('credit','debit') NOT NULL,
    `source` ENUM('deposit','withdrawal','match_fee','match_win','bonus','refund','commission','admin_adjustment','referral_bonus') NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `order_id` VARCHAR(50) DEFAULT NULL,
    `status` ENUM('pending','processing','success','failed') NOT NULL DEFAULT 'pending',
    `balance_before` DECIMAL(12,2) NOT NULL,
    `balance_after` DECIMAL(12,2) NOT NULL,
    `payment_gateway` VARCHAR(50) DEFAULT NULL,
    `gateway_transaction_id` VARCHAR(100) DEFAULT NULL,
    `tds_deducted` DECIMAL(10,2) DEFAULT NULL,
    `metadata` JSON DEFAULT NULL,
    `processed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id_status` (`user_id`, `status`),
    KEY `idx_order_id` (`order_id`),
    KEY `idx_source` (`source`),
    CONSTRAINT `fk_transactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 8. GAME ACTIONS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `game_actions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `match_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `action_type` VARCHAR(50) NOT NULL,
    `dice_value` TINYINT(1) DEFAULT NULL,
    `token_number` TINYINT(1) DEFAULT NULL,
    `from_position` INT(11) DEFAULT NULL,
    `to_position` INT(11) DEFAULT NULL,
    `opponent_captured` TINYINT(1) DEFAULT 0,
    `metadata` JSON DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_match_id_created` (`match_id`, `created_at`),
    KEY `idx_user_id` (`user_id`),
    CONSTRAINT `fk_game_actions_match` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_game_actions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 9. KYC DOCUMENTS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `kyc_documents` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `document_type` ENUM('pan','aadhaar','bank','selfie') NOT NULL,
    `document_number` VARCHAR(50) NOT NULL,
    `document_image_front` VARCHAR(255) NOT NULL,
    `document_image_back` VARCHAR(255) DEFAULT NULL,
    `selfie_image` VARCHAR(255) DEFAULT NULL,
    `bank_account_number` VARCHAR(50) DEFAULT NULL,
    `bank_ifsc` VARCHAR(20) DEFAULT NULL,
    `bank_account_name` VARCHAR(100) DEFAULT NULL,
    `upi_id` VARCHAR(50) DEFAULT NULL,
    `status` ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    `rejection_reason` TEXT DEFAULT NULL,
    `verified_by` INT(11) DEFAULT NULL,
    `verified_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_kyc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 10. WITHDRAWALS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `withdrawals` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `bank_account_number` VARCHAR(50) NOT NULL,
    `bank_ifsc` VARCHAR(20) NOT NULL,
    `bank_account_name` VARCHAR(100) NOT NULL,
    `upi_id` VARCHAR(50) DEFAULT NULL,
    `transaction_id` VARCHAR(100) DEFAULT NULL,
    `status` ENUM('pending','approved','processing','completed','rejected') NOT NULL DEFAULT 'pending',
    `rejection_reason` TEXT DEFAULT NULL,
    `admin_notes` TEXT DEFAULT NULL,
    `processed_by` INT(11) DEFAULT NULL,
    `processed_at` TIMESTAMP NULL DEFAULT NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `metadata` JSON DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id_status` (`user_id`, `status`),
    CONSTRAINT `fk_withdrawals_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 11. DISPUTE TICKETS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `dispute_tickets` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `match_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `opponent_id` INT(11) DEFAULT NULL,
    `ticket_number` VARCHAR(20) NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `priority` ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    `status` ENUM('open','investigating','resolved','closed') NOT NULL DEFAULT 'open',
    `resolution_type` ENUM('winner_declared','refund','cancelled','replay','no_action') DEFAULT NULL,
    `resolution_notes` TEXT DEFAULT NULL,
    `refund_amount` DECIMAL(10,2) DEFAULT NULL,
    `admin_notes` TEXT DEFAULT NULL,
    `resolved_by` INT(11) DEFAULT NULL,
    `resolved_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ticket_number` (`ticket_number`),
    KEY `idx_match_id` (`match_id`),
    KEY `idx_user_id` (`user_id`),
    CONSTRAINT `fk_dispute_match` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_dispute_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 12. TICKET MESSAGES TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `ticket_messages` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `ticket_id` INT(11) NOT NULL,
    `user_id` INT(11) DEFAULT NULL,
    `message` TEXT NOT NULL,
    `screenshot_url` VARCHAR(255) DEFAULT NULL,
    `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ticket_id` (`ticket_id`),
    CONSTRAINT `fk_ticket_message_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `dispute_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 13. SYSTEM SETTINGS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT NOT NULL,
    `setting_group` VARCHAR(50) NOT NULL DEFAULT 'general',
    `setting_type` ENUM('string','integer','decimal','boolean','json','text') NOT NULL DEFAULT 'string',
    `description` VARCHAR(255) DEFAULT NULL,
    `is_editable` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 14. REFERRALS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `referrals` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `referrer_id` INT(11) NOT NULL,
    `referred_user_id` INT(11) NOT NULL,
    `total_deposited` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `reward_credited` TINYINT(1) NOT NULL DEFAULT 0,
    `reward_credited_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_referred_user` (`referred_user_id`),
    KEY `idx_referrer_id` (`referrer_id`, `reward_credited`),
    CONSTRAINT `fk_referrals_referrer` FOREIGN KEY (`referrer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_referrals_referred` FOREIGN KEY (`referred_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 15. TDS TRANSACTIONS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `tds_transactions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `match_id` INT(11) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `tds_rate` DECIMAL(5,2) NOT NULL DEFAULT 30.00,
    `tds_amount` DECIMAL(12,2) NOT NULL,
    `financial_year` VARCHAR(10) NOT NULL,
    `status` ENUM('pending','deducted','deposited') NOT NULL DEFAULT 'pending',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    CONSTRAINT `fk_tds_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 16. LEADERBOARD TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `leaderboard` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `username` VARCHAR(50) NOT NULL,
    `elo_rating` INT(11) NOT NULL DEFAULT 1200,
    `matches_played` INT(11) NOT NULL DEFAULT 0,
    `matches_won` INT(11) NOT NULL DEFAULT 0,
    `total_earnings` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `last_updated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_id` (`user_id`),
    KEY `idx_elo_rating` (`elo_rating` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 17. MAINTENANCE LOGS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `maintenance_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `action` VARCHAR(100) NOT NULL,
    `details` JSON DEFAULT NULL,
    `admin_id` INT(11) DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 18. ADMIN AUDIT LOG TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `admin_audit_log` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `admin_id` INT(11) NOT NULL,
    `admin_username` VARCHAR(50) NOT NULL,
    `action` VARCHAR(100) NOT NULL,
    `target_id` INT(11) DEFAULT NULL,
    `target_type` VARCHAR(50) DEFAULT NULL,
    `details` JSON DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_admin_id` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 19. FINANCIAL METRICS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `financial_metrics` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `metric_date` DATE NOT NULL,
    `daily_deposits` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `daily_withdrawals` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `daily_platform_revenue` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `daily_matches_played` INT(11) NOT NULL DEFAULT 0,
    `daily_new_users` INT(11) NOT NULL DEFAULT 0,
    `total_platform_liability` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_user_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_tds_deducted` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_metric_date` (`metric_date`),
    KEY `idx_metric_date` (`metric_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 20. WEBSOCKET SESSIONS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `websocket_sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `socket_id` VARCHAR(255) NOT NULL,
    `user_id` INT NOT NULL,
    `match_id` INT,
    `room_code` VARCHAR(10),
    `is_active` BOOLEAN DEFAULT TRUE,
    `connected_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_activity` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_socket_id` (`socket_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_room_code` (`room_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- DEFAULT SYSTEM SETTINGS
-- ==============================================
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `setting_group`, `setting_type`, `description`, `is_editable`) VALUES 
('site_name', 'Zupeex', 'general', 'string', 'Website name', 1),
('platform_fee', '15', 'financial', 'decimal', 'Platform commission percentage', 1),
('min_entry_fee', '1', 'financial', 'integer', 'Minimum entry fee', 1),
('max_entry_fee', '10000', 'financial', 'integer', 'Maximum entry fee', 1),
('session_timeout', '1800', 'system', 'integer', 'Session timeout', 1),
('max_login_attempts', '5', 'system', 'integer', 'Max failed logins', 1),
('maintenance_mode', '0', 'system', 'boolean', 'Maintenance mode', 1),
('maintenance_message', 'We are currently performing scheduled maintenance.', 'system', 'text', 'Maintenance message', 1),
('referral_bonus', '100', 'financial', 'integer', 'Referral reward amount', 1),
('referral_deposit_threshold', '500', 'financial', 'integer', 'Required deposit for referral reward', 1),
('min_withdrawal', '200', 'financial', 'integer', 'Min withdrawal', 1),
('max_withdrawal', '100000', 'financial', 'integer', 'Max withdrawal', 1),
('tds_rate', '30', 'financial', 'decimal', 'TDS rate', 1),
('tds_threshold', '10000', 'financial', 'integer', 'TDS threshold', 1),
('game_timeout', '15', 'gameplay', 'integer', 'Turn timeout (seconds)', 1),
('max_turns', '50', 'gameplay', 'integer', 'Max turns', 1),
('elo_k_factor', '32', 'gameplay', 'integer', 'ELO K-factor', 1);

-- ==============================================
-- ADMIN USER: Aakashhunmine / Aakashhunmine@8090
-- ==============================================
INSERT IGNORE INTO `users` (`username`, `mobile`, `email`, `password`, `is_admin`, `is_verified`, `is_active`, `refer_code`, `created_at`) 
VALUES ('Aakashhunmine', '9999999998', 'admin@zupeex.com', '$2y$19$J6Sz6sgXSA3s9kbOZayFWea8OxRqx2.bTV3FyhSXr5JT9FkP/sMK.', 1, 1, 1, 'ADMIN001', CURRENT_TIMESTAMP);

-- ==============================================
-- SAMPLE TOURNAMENTS
-- ==============================================
INSERT IGNORE INTO `tournaments` (`tournament_code`, `name`, `game_mode`, `entry_fee`, `prize_pool`, `platform_fee`, `max_players`, `total_players`, `min_players`, `first_prize_percent`, `second_prize_percent`, `third_prize_percent`, `status`, `created_by`, `created_at`) VALUES 
('T1001', '₹10 Beginner Cup', '1vs1', 10.00, 0.00, 0.00, 2, 300, 2, 60.00, 30.00, 10.00, 'scheduled', 1, NOW()),
('T1002', '₹50 Pro League', '1vs1', 50.00, 0.00, 0.00, 2, 200, 2, 55.00, 30.00, 15.00, 'scheduled', 1, NOW()),
('T1003', '₹100 Mega Battle', '1vs4', 100.00, 0.00, 0.00, 4, 100, 4, 60.00, 25.00, 15.00, 'scheduled', 1, NOW());

-- ==============================================
-- CLEANUP PROCEDURES
-- ==============================================
DELIMITER $$

CREATE PROCEDURE IF NOT EXISTS clean_expired_sessions()
BEGIN
    DELETE FROM sessions WHERE expires_at < NOW() OR is_active = 0;
END$$

CREATE PROCEDURE IF NOT EXISTS archive_old_game_actions()
BEGIN
    CREATE TABLE IF NOT EXISTS game_actions_archive LIKE game_actions;
    INSERT INTO game_actions_archive SELECT * FROM game_actions WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    DELETE FROM game_actions WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
END$$

DELIMITER ;

COMMIT;