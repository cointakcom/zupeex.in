-- Round 5: Refer & Earn rebuild — ₹100 deposit-gated reward (was: broken
-- ₹50-on-signup logic writing to referral_bonuses). Fresh installs get this
-- from database.sql directly.

ALTER TABLE transactions
    MODIFY COLUMN `source` ENUM('deposit','withdrawal','match_fee','match_win','bonus','refund','commission','admin_adjustment','referral_bonus') NOT NULL;

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

-- Backfill: create a referrals row for every existing referred_by relationship
-- that doesn't already have one, so historical referrals aren't orphaned.
INSERT IGNORE INTO referrals (referrer_id, referred_user_id, total_deposited, reward_credited)
SELECT u.referred_by, u.id, 0, 0
FROM users u
WHERE u.referred_by IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM referrals r WHERE r.referred_user_id = u.id);
