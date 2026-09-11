<?php
/**
 * ======================================================
 * TOURNAMENT_MANAGER.PHP - Custom Tournament System
 * Zupeex - Admin Tournament Creation & User Join
 * Version: 1.0.0
 * ======================================================
 * FEATURES:
 *   1. Admin creates custom tournaments
 *   2. Users browse and join via tickets
 *   3. Bracket generation & matchmaking
 *   4. Real-time player count updates
 *   5. Prize pool & payout management
 * ======================================================
 */

class TournamentManager {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * 🏆 ADMIN: Create new custom tournament
     */
    public function createTournament($adminId, $data) {
        $conn = $this->db->getConnection();
        
        try {
            $this->db->beginTransaction();
            
            // Validate admin
            $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = :uid");
            $stmt->execute([':uid' => $adminId]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$admin || !$admin['is_admin']) {
                throw new Exception("Unauthorized: Admin access required");
            }
            
            // Generate tournament code
            $tournamentCode = 'TOUR-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            
            // Parse prize config
            $prizeConfig = [
                '1st' => $data['first_prize'] ?? 60,
                '2nd' => $data['second_prize'] ?? 30,
                '3rd' => $data['third_prize'] ?? 10
            ];
            
            // Create tournament
            $stmt = $conn->prepare("
                INSERT INTO tournaments 
                (tournament_code, name, game_mode, entry_fee, prize_pool, max_players, min_players, 
                 first_prize_percent, second_prize_percent, third_prize_percent,
                 scoring_mode, status, registration_open, created_by, created_at)
                VALUES 
                (:code, :name, :mode, :fee, :pool, :max, :min, :p1, :p2, :p3, :scoring, 'scheduled', 1, :admin, NOW())
            ");
            $stmt->execute([
                ':code' => $tournamentCode,
                ':name' => $data['name'] ?? 'Custom Ludo Tournament',
                ':mode' => $data['game_mode'] ?? '1vs1',
                ':fee' => floatval($data['entry_fee'] ?? 10),
                ':pool' => floatval($data['entry_fee'] ?? 10) * intval($data['max_players'] ?? 4) * 0.85,
                ':max' => intval($data['max_players'] ?? 4),
                ':min' => intval($data['min_players'] ?? 2),
                ':p1' => $prizeConfig['1st'],
                ':p2' => $prizeConfig['2nd'],
                ':p3' => $prizeConfig['3rd'],
                ':scoring' => $data['scoring_mode'] ?? 'winner_takes_all',
                ':admin' => $adminId
            ]);
            
            $tournamentId = $conn->lastInsertId();
            
            // Create initial game tickets for this tournament
            if ($data['game_mode'] == '1vs1') {
                $stmt = $conn->prepare("
                    INSERT INTO game_tickets (game_mode, entry_fee, duration_minutes, payout_percent, is_active, created_by, created_at)
                    VALUES ('1vs1', :fee, 15, 70, 1, :admin, NOW())
                ");
                $stmt->execute([
                    ':fee' => floatval($data['entry_fee']),
                    ':admin' => $adminId
                ]);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO game_tickets (game_mode, entry_fee, duration_minutes, payout_percent, is_active, created_by, created_at)
                    VALUES ('1vs4', :fee, 15, 70, 1, :admin, NOW())
                ");
                $stmt->execute([
                    ':fee' => floatval($data['entry_fee']),
                    ':admin' => $adminId
                ]);
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'tournament_id' => $tournamentId,
                'tournament_code' => $tournamentCode,
                'message' => 'Tournament created successfully'
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
     * Get all active tournaments for users to browse
     */
    public function getActiveTournaments($limit = 20, $offset = 0) {
        $conn = $this->db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT 
                t.*,
                COUNT(tr.id) as registered_count,
                u.username as created_by_name
            FROM tournaments t
            LEFT JOIN tournament_registrations tr ON t.id = tr.tournament_id
            LEFT JOIN users u ON t.created_by = u.id
            WHERE t.status IN ('scheduled', 'active') 
              AND t.registration_open = 1
            GROUP BY t.id
            ORDER BY t.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get tournament details
     */
    public function getTournamentDetails($tournamentId) {
        $conn = $this->db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT 
                t.*,
                COUNT(DISTINCT tr.id) as registered_count,
                COUNT(DISTINCT CASE WHEN tr.status = 'winner' THEN tr.user_id END) as winners_count
            FROM tournaments t
            LEFT JOIN tournament_registrations tr ON t.id = tr.tournament_id
            WHERE t.id = :tid
            GROUP BY t.id
        ");
        $stmt->execute([':tid' => $tournamentId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * 👤 USER: Join tournament
     */
    public function joinTournament($userId, $tournamentId) {
        $conn = $this->db->getConnection();
        
        try {
            $this->db->beginTransaction();
            
            // Get tournament details
            $stmt = $conn->prepare("SELECT * FROM tournaments WHERE id = :tid FOR UPDATE");
            $stmt->execute([':tid' => $tournamentId]);
            $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$tournament) {
                throw new Exception("Tournament not found");
            }
            
            if ($tournament['registration_open'] != 1) {
                throw new Exception("Tournament registration is closed");
            }
            
            // Check if already registered
            $stmt = $conn->prepare("
                SELECT id FROM tournament_registrations 
                WHERE tournament_id = :tid AND user_id = :uid
            ");
            $stmt->execute([':tid' => $tournamentId, ':uid' => $userId]);
            if ($stmt->fetch()) {
                throw new Exception("Already registered for this tournament");
            }
            
            // Check player count
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count FROM tournament_registrations WHERE tournament_id = :tid
            ");
            $stmt->execute([':tid' => $tournamentId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] >= $tournament['max_players']) {
                throw new Exception("Tournament is full");
            }
            
            // Check wallet balance
            $stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = :uid FOR UPDATE");
            $stmt->execute([':uid' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user['wallet_balance'] < $tournament['entry_fee']) {
                throw new Exception("Insufficient wallet balance");
            }
            
            // Deduct entry fee
            $newBalance = $user['wallet_balance'] - $tournament['entry_fee'];
            $stmt = $conn->prepare("
                UPDATE users SET wallet_balance = :bal, updated_at = NOW() WHERE id = :uid
            ");
            $stmt->execute([':bal' => $newBalance, ':uid' => $userId]);
            
            // Register user
            $stmt = $conn->prepare("
                INSERT INTO tournament_registrations 
                (tournament_id, user_id, entry_fee_paid, status, registered_at)
                VALUES (:tid, :uid, :fee, 'registered', NOW())
            ");
            $stmt->execute([
                ':tid' => $tournamentId,
                ':uid' => $userId,
                ':fee' => $tournament['entry_fee']
            ]);
            
            // Log transaction
            $stmt = $conn->prepare("
                INSERT INTO transactions 
                (user_id, tournament_id, amount, type, source, description, status, balance_before, balance_after, created_at)
                VALUES (:uid, :tid, :amt, 'debit', 'tournament_entry', :desc, 'success', :before, :after, NOW())
            ");
            $stmt->execute([
                ':uid' => $userId,
                ':tid' => $tournamentId,
                ':amt' => $tournament['entry_fee'],
                ':desc' => 'Tournament entry fee: ' . $tournament['name'],
                ':before' => $user['wallet_balance'],
                ':after' => $newBalance
            ]);
            
            // Update tournament registered count
            $stmt = $conn->prepare("
                UPDATE tournaments SET registered_players = (
                    SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = :tid
                ) WHERE id = :tid
            ");
            $stmt->execute([':tid' => $tournamentId]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Successfully joined tournament',
                'balance_remaining' => $newBalance
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
     * Generate bracket and create matches
     */
    public function generateBracket($tournamentId) {
        $conn = $this->db->getConnection();
        
        try {
            $this->db->beginTransaction();
            
            // Get tournament
            $stmt = $conn->prepare("SELECT * FROM tournaments WHERE id = :tid FOR UPDATE");
            $stmt->execute([':tid' => $tournamentId]);
            $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$tournament) {
                throw new Exception("Tournament not found");
            }
            
            // Get registered players
            $stmt = $conn->prepare("
                SELECT u.id FROM tournament_registrations tr
                JOIN users u ON tr.user_id = u.id
                WHERE tr.tournament_id = :tid
                ORDER BY tr.registered_at ASC
            ");
            $stmt->execute([':tid' => $tournamentId]);
            $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($players) < $tournament['min_players']) {
                throw new Exception("Not enough players to start tournament");
            }
            
            // Create first round matches
            $matchCount = intdiv(count($players), 2);
            for ($i = 0; $i < $matchCount; $i++) {
                $player1 = $players[$i * 2]['id'];
                $player2 = $players[$i * 2 + 1]['id'] ?? null;
                
                $roomCode = 'TOUR-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                
                $stmt = $conn->prepare("
                    INSERT INTO tournament_matches 
                    (tournament_id, round, player1_id, player2_id, status, created_at)
                    VALUES (:tid, 1, :p1, :p2, 'pending', NOW())
                ");
                $stmt->execute([
                    ':tid' => $tournamentId,
                    ':p1' => $player1,
                    ':p2' => $player2
                ]);
            }
            
            // Update tournament status
            $stmt = $conn->prepare("
                UPDATE tournaments SET status = 'active', registration_open = 0 WHERE id = :tid
            ");
            $stmt->execute([':tid' => $tournamentId]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Bracket generated successfully',
                'matches_created' => $matchCount
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
     * Award prizes to winners
     */
    public function awardPrizes($tournamentId) {
        $conn = $this->db->getConnection();
        
        try {
            $this->db->beginTransaction();
            
            $stmt = $conn->prepare("SELECT * FROM tournaments WHERE id = :tid FOR UPDATE");
            $stmt->execute([':tid' => $tournamentId]);
            $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get winners
            $stmt = $conn->prepare("
                SELECT user_id, position FROM tournament_registrations 
                WHERE tournament_id = :tid AND status IN ('winner', 'runner_up', 'third_place')
                ORDER BY position ASC
            ");
            $stmt->execute([':tid' => $tournamentId]);
            $winners = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $prizes = [
                'winner' => ($tournament['prize_pool'] * $tournament['first_prize_percent']) / 100,
                'runner_up' => ($tournament['prize_pool'] * $tournament['second_prize_percent']) / 100,
                'third_place' => ($tournament['prize_pool'] * $tournament['third_prize_percent']) / 100
            ];
            
            foreach ($winners as $winner) {
                $status = $winner['position'] == 1 ? 'winner' : ($winner['position'] == 2 ? 'runner_up' : 'third_place');
                $prizeAmount = $prizes[$status] ?? 0;
                
                // Credit prize
                $stmt = $conn->prepare("
                    UPDATE users SET wallet_balance = wallet_balance + :amt WHERE id = :uid
                ");
                $stmt->execute([':amt' => $prizeAmount, ':uid' => $winner['user_id']]);
                
                // Log transaction
                $stmt = $conn->prepare("
                    INSERT INTO transactions 
                    (user_id, tournament_id, amount, type, source, description, status, created_at)
                    VALUES (:uid, :tid, :amt, 'credit', 'tournament_prize', :desc, 'success', NOW())
                ");
                $stmt->execute([
                    ':uid' => $winner['user_id'],
                    ':tid' => $tournamentId,
                    ':amt' => $prizeAmount,
                    ':desc' => 'Tournament prize: ' . ucfirst(str_replace('_', ' ', $status))
                ]);
                
                // Update registration
                $stmt = $conn->prepare("
                    UPDATE tournament_registrations 
                    SET prize_won = :prize, status = :status
                    WHERE tournament_id = :tid AND user_id = :uid
                ");
                $stmt->execute([
                    ':prize' => $prizeAmount,
                    ':status' => $status,
                    ':tid' => $tournamentId,
                    ':uid' => $winner['user_id']
                ]);
            }
            
            // Mark tournament complete
            $stmt = $conn->prepare("
                UPDATE tournaments SET status = 'completed', end_time = NOW() WHERE id = :tid
            ");
            $stmt->execute([':tid' => $tournamentId]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Prizes awarded successfully',
                'total_distributed' => array_sum($prizes)
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}

?>
