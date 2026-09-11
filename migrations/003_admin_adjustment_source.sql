-- ======================================================
-- BUG FIX: admin_users.php's handleUpdateBalance() was tagging manual admin
-- balance corrections with source='bonus' (credit) or source='withdrawal'
-- (debit) — reusing categories meant for real user-initiated flows. This
-- silently inflated admin_dashboard.php's "Total Withdrawals" stat with
-- transactions that were never actual bank withdrawals (an admin manually
-- deducting a user's balance for a correction/penalty is not the same event
-- as a user requesting money out via api/wallet.php + Cashfree Payouts).
-- Adds a distinct category so reporting stays accurate.
-- ======================================================

ALTER TABLE transactions
    MODIFY COLUMN `source` ENUM('deposit','withdrawal','match_fee','match_win','bonus','refund','commission','admin_adjustment') NOT NULL;
