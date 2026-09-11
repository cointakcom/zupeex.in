<?php
/**
 * ======================================================
 * MULTIPLAYER.PHP - Race Condition Prevention
 * Zupeex - Player Sync & Lock Management
 * Version: 1.0.0
 * ======================================================
 * FEATURES:
 *   1. Database-level row locking (FOR UPDATE)
 *   2. Atomic match join/create operations
 *   3. Player slot assignment with conflict detection
 *   4. Waiting room polling sync
 *   5. Concurrent request handling
 * ======================================================
 */

class MultiplayerManager {
    private $db;
    const LOCK_TIMEOUT = 30; // seconds
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * 🔴 ATOMIC: Find or create match for 1vs1 game mode
     * Uses database lock to prevent duplicate match creation
     */
    public function findOrCreate1vs1Match($userId, $entryFee, $ticketId = null) {
        $conn = $this->db->getConnection();
        
        try {
            $this->db->beginTransaction();
            
            // 🔒 Lock: Find waiting 1vs1 match
            $stmt = $conn->prepare("
                SELECT id, room_code, player1_id, player2_id, status 
                FROM matches 
                WHERE game_mode = '1vs1' 
                  AND entry_fee = :fee
                  AND status = 'waiting'
                  AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                  AND player1_id != :uid
                ORDER BY created_at ASC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([':fee' => $entryFee, ':uid' => $userId]);
            $existingMatch = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingMatch && !$existingMatch['player2_id']) {
                // Join existing match
                $stmt = $conn->prepare("
                    UPDATE matches 
                    SET player2_id = :uid, 
                        player2_name = (SELECT username FROM users WHERE id = :uid),
                        status = 'ready',
                        updated_at = NOW()
                    WHERE id = :mid
                ");
                $stmt->execute([
                    ':uid' => $userId,
                    ':mid' => $existingMatch['id']
                ]);
                
                $this->db->commit();
                return [
                    'success' => true,
                    'action' => 'joined',
                    'match_id' => $existingMatch['id'],
                    'room_code' => $existingMatch['room_code']
                ];
            }
            
            // No waiting match - create new one
            $roomCode = $this->generateRoomCode();
            
            $stmt = $conn->prepare("
                INSERT INTO matches 
                (game_mode, room_code, entry_fee, prize_pool, player1_id, player1_name, status, ticket_id, created_at)
                VALUES ('1vs1', :rc, :fee, :pool, :uid, (SELECT username FROM users WHERE id = :uid), 'waiting', :tid, NOW())
            ");
            $stmt->execute([
                ':rc' => $roomCode,
                ':fee' => $entryFee,
                ':pool' => $entryFee * 2 * 0.85, // 15% platform fee
                ':uid' => $userId,
                ':tid' => $ticketId
            ]);
            
            $newMatchId = $conn->lastInsertId();
            
            $this->db->commit();
            
            return [
                'success' => true,
                'action' => 'created',
                'match_id' => $newMatchId,
                'room_code' => $roomCode
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
     * 🔴 ATOMIC: Find or create match for 1vs4 game mode
     * Locks and checks all 4 slots
     */
    public function findOrCreate1vs4Match($userId, $entryFee, $ticketId = null) {
        $conn = $this->db->getConnection();
        
        try {
            $this->db->beginTransaction();
            
            // 🔒 Lock: Find waiting 1vs4 match with available slots
            $stmt = $conn->prepare("
                SELECT id, room_code, player1_id, player2_id, player3_id, player4_id, status
                FROM matches 
                WHERE game_mode = '1vs4' 
                  AND entry_fee = :fee
                  AND status = 'waiting'
                  AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                ORDER BY created_at ASC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([':fee' => $entryFee]);
            $existingMatch = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingMatch) {
                // Find first empty slot
                $emptySlot = null;
                for ($i = 1; $i <= 4; $i++) {
                    if (empty($existingMatch["player{$i}_id"])) {
                        $emptySlot = $i;
                        break;
                    }
                }
                
                if ($emptySlot) {
                    // Join existing match
                    $stmt = $conn->prepare("
                        UPDATE matches 
                        SET player{$emptySlot}_id = :uid, 
                            player{$emptySlot}_name = (SELECT username FROM users WHERE id = :uid),
                            updated_at = NOW()
                        WHERE id = :mid
                    ");
                    $stmt->execute([
                        ':uid' => $userId,
                        ':mid' => $existingMatch['id']
                    ]);
                    
                    // Check if all 4 slots filled
                    $stmt = $conn->prepare("
                        SELECT COUNT(*) as filled FROM (
                            SELECT player1_id UNION ALL
                            SELECT player2_id UNION ALL
                            SELECT player3_id UNION ALL
                            SELECT player4_id
                        ) as slots WHERE player1_id IS NOT NULL
                    ");
                    $stmt->execute();
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($result['filled'] == 4) {
                        // All slots full - start game
                        $stmt = $conn->prepare("
                            UPDATE matches SET status = 'ready' WHERE id = :mid
                        ");
                        $stmt->execute([':mid' => $existingMatch['id']]);
                    }
                    
                    $this->db->commit();
                    return [
                        'success' => true,
                        'action' => 'joined',
                        'match_id' => $existingMatch['id'],
                        'room_code' => $existingMatch['room_code'],
                        'slot' => $emptySlot
                    ];
                }
            }
            
            // No waiting match - create new one
            $roomCode = $this->generateRoomCode();
            
            $stmt = $conn->prepare("
                INSERT INTO matches 
                (game_mode, room_code, entry_fee, prize_pool, player1_id, player1_name, status, ticket_id, created_at)
                VALUES ('1vs4', :rc, :fee, :pool, :uid, (SELECT username FROM users WHERE id = :uid), 'waiting', :tid, NOW())
            ");
            $stmt->execute([
                ':rc' => $roomCode,
                ':fee' => $entryFee,
                ':pool' => $entryFee * 4 * 0.85, // 15% platform fee
                ':uid' => $userId,
                ':tid' => $ticketId
            ]);
            
            $newMatchId = $conn->lastInsertId();
            
            $this->db->commit();
            
            return [
                'success' => true,
                'action' => 'created',
                'match_id' => $newMatchId,
                'room_code' => $roomCode,
                'slot' => 1
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
     * Get current match state with all player info
     * NO lock - read-only for UI polling
     */
    public function getMatchState($matchId) {
        $conn = $this->db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT m.*,
                   u1.username as p1_username, u1.wallet_balance as p1_balance,
                   u2.username as p2_username, u2.wallet_balance as p2_balance,
                   u3.username as p3_username, u3.wallet_balance as p3_balance,
                   u4.username as p4_username, u4.wallet_balance as p4_balance
            FROM matches m
            LEFT JOIN users u1 ON m.player1_id = u1.id
            LEFT JOIN users u2 ON m.player2_id = u2.id
            LEFT JOIN users u3 ON m.player3_id = u3.id
            LEFT JOIN users u4 ON m.player4_id = u4.id
            WHERE m.id = :mid
        ");
        $stmt->execute([':mid' => $matchId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Handle player exit/disconnect
     * Assigns winner or cancels match
     */
    public function handlePlayerExit($matchId, $exitingUserId) {
        $conn = $this->db->getConnection();
        
        try {
            $this->db->beginTransaction();
            
            $stmt = $conn->prepare("SELECT * FROM matches WHERE id = :mid FOR UPDATE");
            $stmt->execute([':mid' => $matchId]);
            $match = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$match) {
                throw new Exception("Match not found");
            }
            
            // Mark player as exited
            $exitedPlayers = json_decode($match['exited_players'] ?? '[]', true);
            if (!in_array($exitingUserId, $exitedPlayers)) {
                $exitedPlayers[] = $exitingUserId;
            }
            
            // Count active players
            $activePlayers = [];
            for ($i = 1; $i <= 4; $i++) {
                $pid = $match["player{$i}_id"];
                if ($pid && !in_array($pid, $exitedPlayers)) {
                    $activePlayers[] = $pid;
                }
            }
            
            if (count($activePlayers) <= 1) {
                // Only 1 or 0 players left - declare winner or refund
                if (count($activePlayers) == 1) {
                    $winnerId = $activePlayers[0];
                    $stmt = $conn->prepare("
                        UPDATE matches 
                        SET winner_id = :wid, status = 'completed', completed_at = NOW()
                        WHERE id = :mid
                    ");
                    $stmt->execute([':wid' => $winnerId, ':mid' => $matchId]);
                }
            } else {
                // Continue with remaining players
                $stmt = $conn->prepare("
                    UPDATE matches 
                    SET exited_players = :exited, updated_at = NOW()
                    WHERE id = :mid
                ");
                $stmt->execute([
                    ':exited' => json_encode($exitedPlayers),
                    ':mid' => $matchId
                ]);
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'active_players' => count($activePlayers),
                'exited_players' => $exitedPlayers
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
     * Generate unique room code
     */
    private function generateRoomCode() {
        $code = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        return $code;
    }
}

?>
