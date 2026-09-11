-- ======================================================
-- ROUND 5 — PRIORITY 2: Tournament Tickets (1vs1 / 1vs4)
-- A SEPARATE system from Custom Tournament (tournaments/tournament_registrations).
-- Admin creates permanent ticket templates (mode + bet amount). Users buy a
-- ticket, wait in a matchmaking queue for the required number of same-amount
-- players, then get auto-matched into a real `matches` row.
-- ======================================================

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ticket_queue` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `ticket_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `status` ENUM('waiting','matched','cancelled') NOT NULL DEFAULT 'waiting',
    `match_id` INT(11) DEFAULT NULL,
    `transaction_id` INT(11) DEFAULT NULL COMMENT 'the debit transaction for the entry fee, so cancel can refund precisely',
    `joined_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_ticket_status` (`ticket_id`, `status`),
    INDEX `idx_user_waiting` (`user_id`, `status`),
    CONSTRAINT `fk_ticket_queue_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `game_tickets`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ticket_queue_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Traceability only — lets admin see which matches came from which ticket
-- template. Not required for gameplay logic (payout %/duration are already
-- fixed into the match row itself at creation time).
ALTER TABLE matches ADD COLUMN IF NOT EXISTS ticket_id INT(11) DEFAULT NULL AFTER tournament_id;

-- Seed a starting set of tickets so the feature isn't empty on first deploy —
-- admin can edit/deactivate/add more via the admin panel.
INSERT INTO game_tickets (game_mode, entry_fee, duration_minutes, payout_percent, is_active) VALUES
    ('1vs1', 10, 15, 70.00, 1),
    ('1vs1', 50, 15, 70.00, 1),
    ('1vs1', 100, 15, 70.00, 1),
    ('1vs1', 500, 15, 70.00, 1),
    ('1vs4', 10, 15, 70.00, 1),
    ('1vs4', 50, 15, 70.00, 1),
    ('1vs4', 100, 15, 70.00, 1),
    ('1vs4', 500, 15, 70.00, 1);
