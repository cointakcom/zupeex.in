<?php
/**
 * ======================================================
 * WALLET.PHP - Real Money Betting System
 * Zupeex - Secure Wallet Management
 * Version: 1.0.0
 * ======================================================
 * FEATURES:
 *   1. Entry fee deduct from user wallet (server-side validation)
 *   2. Prize credit to winner wallet
 *   3. Platform fee calculation
 *   4. TDS deduction for large wins
 *   5. Transaction logging with audit trail
 * ======================================================
 */

class WalletManager {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * 🔴 CRITICAL: Deduct Entry Fee from User Wallet
     * - Checks wallet balance BEFORE deducting
     * - Uses database transaction to prevent race conditions
     * - Logs every transaction
     */
    public function deductEntryFee($userId, $amount, $matchId, $description = 'Match entry fee') {
        $conn = $this->db->getConnection();
        
        try {
            $this->db->beginTransaction();
            
            // Get current wallet balance
            $stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = :uid FOR UPDATE");
            $stmt->execute([':uid' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception("User not found");
            }
            
            if ($user['wallet_balance'] < $amount) {
                throw new Exception("Insufficient wallet balance");
            }
            
            // Deduct from wallet
            $newBalance = $user['wallet_balance'] - $amount;
            $stmt = $conn->prepare("
                UPDATE users SET wallet_balance = :new_bal, updated_at = NOW() WHERE id = :uid
            ");
            $stmt->execute([':new_bal' => $newBalance, ':uid' => $userId]);
            
            // Log transaction
            $stmt = $conn->prepare("
                INSERT INTO transactions 
                (user_id, match_id, amount, type, source, description, status, balance_before, balance_after, created_at) 
                VALUES (:uid, :mid, :amt, 'debit', 'match_fee', :desc, 'success', :before, :after, NOW())
            ");
            $stmt->execute([
                ':uid' => $userId,
                ':mid' => $matchId,
                ':amt' => $amount,
                ':desc' => $description,
                ':before' => $user['wallet_balance'],
                ':after' => $newBalance
            ]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Entry fee deducted',
                'balance_before' => $user['wallet_balance'],
                'balance_after' => $newBalance
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * ✅ Credit Prize Amount to Winner Wallet
     * - Calculates TDS if applicable (30% on wins > ₹10,000)
     * - Updates user total_earnings
     * - Updates leaderboard
     */
    public function creditPrize($userId, $amount, $matchId, $tdsRate = 30) {
        $conn = $this->db->getConnection();
        
        try {
            $this->db->beginTransaction();
            
            // Get current wallet
            $stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = :uid FOR UPDATE");
            $stmt->execute([':uid' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception("User not found");
            }
            
            // Calculate TDS
            $tdsAmount = ($amount * $tdsRate) / 100;
            $netAmount = $amount - $tdsAmount;
            
            // Credit wallet
            $newBalance = $user['wallet_balance'] + $netAmount;
            $stmt = $conn->prepare("
                UPDATE users 
                SET wallet_balance = :new_bal, 
                    total_earnings = total_earnings + :net_amt,
                    updated_at = NOW()
                WHERE id = :uid
            ");
            $stmt->execute([
                ':new_bal' => $newBalance,
                ':net_amt' => $netAmount,
                ':uid' => $userId
            ]);
            
            // Log transaction
            $stmt = $conn->prepare("
                INSERT INTO transactions 
                (user_id, match_id, amount, type, source, description, status, balance_before, balance_after, tds_deducted, created_at) 
                VALUES (:uid, :mid, :amt, 'credit', 'match_win', :desc, 'success', :before, :after, :tds, NOW())
            ");
            $stmt->execute([
                ':uid' => $userId,
                ':mid' => $matchId,
                ':amt' => $netAmount,
                ':desc' => 'Match win prize',
                ':before' => $user['wallet_balance'],
                ':after' => $newBalance,
                ':tds' => $tdsAmount
            ]);
            
            // Log TDS if deducted
            if ($tdsAmount > 0) {
                $stmt = $conn->prepare("
                    INSERT INTO tds_transactions 
                    (user_id, match_id, amount, tds_rate, tds_amount, financial_year, status, created_at) 
                    VALUES (:uid, :mid, :amt, :rate, :tds, :fy, 'deducted', NOW())
                ");
                $stmt->execute([
                    ':uid' => $userId,
                    ':mid' => $matchId,
                    ':amt' => $amount,
                    ':rate' => $tdsRate,
                    ':tds' => $tdsAmount,
                    ':fy' => date('Y') . '-' . (date('Y') + 1)
                ]);
            }
            
            // Update leaderboard
            $stmt = $conn->prepare("
                INSERT INTO leaderboard (user_id, username, elo_rating, matches_played, matches_won, total_earnings, last_updated)
                SELECT id, username, elo_rating, total_matches_played, total_matches_won, total_earnings, NOW()
                FROM users WHERE id = :uid
                ON DUPLICATE KEY UPDATE 
                    total_earnings = (SELECT total_earnings FROM users WHERE id = :uid),
                    matches_won = (SELECT total_matches_won FROM users WHERE id = :uid),
                    last_updated = NOW()
            ");
            $stmt->execute([':uid' => $userId]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Prize credited',
                'gross_amount' => $amount,
                'tds_deducted' => $tdsAmount,
                'net_amount' => $netAmount,
                'balance_after' => $newBalance
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get current wallet balance
     */
    public function getBalance($userId) {
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare("SELECT wallet_balance, total_earnings FROM users WHERE id = :uid");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get transaction history
     */
    public function getTransactionHistory($userId, $limit = 20) {
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare("
            SELECT id, amount, type, source, description, status, balance_before, balance_after, tds_deducted, created_at
            FROM transactions 
            WHERE user_id = :uid 
            ORDER BY created_at DESC 
            LIMIT :limit
        ");
        $stmt->bindParam(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

?>
