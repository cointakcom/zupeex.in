<?php
/**
 * ======================================================
 * LUDO_ENGINE.PHP - Complete Board Game Logic
 * Zupeex - Real Ludo Game Implementation
 * Version: 2.0.0
 * ======================================================
 * FEATURES:
 *   1. 4-player board state management
 *   2. Token movement with collision detection
 *   3. Safe spots & capture logic
 *   4. Dice rolling (1-6)
 *   5. Turn management
 *   6. Win condition detection
 * ======================================================
 */

class LudoEngine {
    private $db;
    
    // Board Constants
    const BOARD_SIZE = 52;
    const HOME_SIZE = 6;
    const TOTAL_TOKENS = 4;
    const SAFE_SPOTS = [0, 8, 13, 21, 26, 34, 39, 47];
    
    // Player colors
    const COLORS = ['red', 'green', 'yellow', 'blue'];
    const COLOR_HOMES = [
        'red' => [1, 2, 3, 4],
        'green' => [14, 15, 16, 17],
        'yellow' => [27, 28, 29, 30],
        'blue' => [40, 41, 42, 43]
    ];
    
    // Starting positions on board
    const START_POSITIONS = [0, 13, 26, 39];
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Initialize new game - create board state
     */
    public function initializeBoard($matchId, $players) {
        $conn = $this->db->getConnection();
        
        $boardState = [
            'match_id' => $matchId,
            'players' => [],
            'current_turn' => 0,
            'dice_value' => 0,
            'consecutive_sixes' => 0,
            'game_status' => 'playing',
            'winner' => null,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Initialize each player
        foreach ($players as $index => $playerId) {
            $boardState['players'][$index] = [
                'user_id' => $playerId,
                'color' => self::COLORS[$index],
                'tokens' => [
                    ['position' => -1, 'status' => 'home'], // Not on board
                    ['position' => -1, 'status' => 'home'],
                    ['position' => -1, 'status' => 'home'],
                    ['position' => -1, 'status' => 'home']
                ],
                'tokens_home' => 4,
                'tokens_in_home' => 0,
                'score' => 0
            ];
        }
        
        // Save board state to database
        $stmt = $conn->prepare("
            UPDATE matches 
            SET board_state = :state, status = 'playing', started_at = NOW()
            WHERE id = :mid
        ");
        $stmt->execute([
            ':state' => json_encode($boardState),
            ':mid' => $matchId
        ]);
        
        return $boardState;
    }
    
    /**
     * Roll dice and get value (1-6)
     */
    public function rollDice($matchId, $playerId) {
        $diceValue = rand(1, 6);
        $conn = $this->db->getConnection();
        
        try {
            $this->db->beginTransaction();
            
            // Get current board state
            $stmt = $conn->prepare("SELECT board_state FROM matches WHERE id = :mid FOR UPDATE");
            $stmt->execute([':mid' => $matchId]);
            $match = $stmt->fetch(PDO::FETCH_ASSOC);
            $boardState = json_decode($match['board_state'], true);
            
            // Update consecutive sixes
            if ($diceValue == 6) {
                $boardState['consecutive_sixes']++;
            } else {
                $boardState['consecutive_sixes'] = 0;
            }
            
            // If 3 sixes in a row, forfeit turn
            if ($boardState['consecutive_sixes'] >= 3) {
                $boardState['consecutive_sixes'] = 0;
                $boardState['current_turn'] = ($boardState['current_turn'] + 1) % 4;
                $diceValue = 0; // No movement
            }
            
            $boardState['dice_value'] = $diceValue;
            
            // Save state
            $stmt = $conn->prepare("
                UPDATE matches 
                SET board_state = :state, dice_value = :dice, dice_rolled_by = :uid, last_dice_roll_time = NOW()
                WHERE id = :mid
            ");
            $stmt->execute([
                ':state' => json_encode($boardState),
                ':dice' => $diceValue,
                ':uid' => $playerId,
                ':mid' => $matchId
            ]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'dice_value' => $diceValue,
                'consecutive_sixes' => $boardState['consecutive_sixes']
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Move token from home to board or on board
     */
    public function moveToken($matchId, $playerId, $tokenIndex, $diceValue) {
        $conn = $this->db->getConnection();
        
        try {
            $this->db->beginTransaction();
            
            $stmt = $conn->prepare("SELECT board_state FROM matches WHERE id = :mid FOR UPDATE");
            $stmt->execute([':mid' => $matchId]);
            $match = $stmt->fetch(PDO::FETCH_ASSOC);
            $boardState = json_decode($match['board_state'], true);
            
            // Find player index
            $playerIndex = null;
            foreach ($boardState['players'] as $idx => $player) {
                if ($player['user_id'] == $playerId) {
                    $playerIndex = $idx;
                    break;
                }
            }
            
            if ($playerIndex === null) {
                throw new Exception("Player not found in game");
            }
            
            $player = &$boardState['players'][$playerIndex];
            $token = &$player['tokens'][$tokenIndex];
            
            // Token in home - can only move with 6 or higher
            if ($token['position'] == -1) {
                if ($diceValue == 6) {
                    $token['position'] = self::START_POSITIONS[$playerIndex];
                    $token['status'] = 'playing';
                    $player['tokens_home']--;
                } else {
                    throw new Exception("Need 6 to bring token out of home");
                }
            } else {
                // Token already on board - move it
                $newPosition = ($token['position'] + $diceValue) % (self::BOARD_SIZE + self::HOME_SIZE);
                
                // Check safe spots
                if (!in_array($newPosition, self::SAFE_SPOTS)) {
                    // Capture opponent tokens at this position
                    $this->captureOpponentTokens($boardState, $playerIndex, $newPosition);
                }
                
                $token['position'] = $newPosition;
                
                // Check if token reached home
                if ($newPosition >= self::BOARD_SIZE) {
                    $token['status'] = 'home';
                    $player['tokens_in_home']++;
                    $player['score']++;
                }
            }
            
            // Log game action
            $stmt = $conn->prepare("
                INSERT INTO game_actions (match_id, user_id, action_type, dice_value, token_number, to_position, created_at)
                VALUES (:mid, :uid, 'move_token', :dice, :tok, :pos, NOW())
            ");
            $stmt->execute([
                ':mid' => $matchId,
                ':uid' => $playerId,
                ':dice' => $diceValue,
                ':tok' => $tokenIndex,
                ':pos' => $token['position']
            ]);
            
            // Check win condition
            if ($player['tokens_in_home'] == self::TOTAL_TOKENS) {
                $boardState['game_status'] = 'completed';
                $boardState['winner'] = $playerId;
            } else {
                // If not 6, next player's turn
                if ($diceValue != 6) {
                    $boardState['current_turn'] = ($boardState['current_turn'] + 1) % 4;
                    $boardState['consecutive_sixes'] = 0;
                }
            }
            
            // Save state
            $stmt = $conn->prepare("
                UPDATE matches 
                SET board_state = :state, winner_id = :winner, status = :status
                WHERE id = :mid
            ");
            $stmt->execute([
                ':state' => json_encode($boardState),
                ':winner' => $boardState['winner'],
                ':status' => $boardState['game_status'],
                ':mid' => $matchId
            ]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'new_position' => $token['position'],
                'tokens_in_home' => $player['tokens_in_home'],
                'is_winner' => $boardState['winner'] == $playerId,
                'board_state' => $boardState
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Capture opponent tokens at position (if not safe spot)
     */
    private function captureOpponentTokens(&$boardState, $playerIndex, $position) {
        foreach ($boardState['players'] as $oppIdx => $opponent) {
            if ($oppIdx != $playerIndex) {
                foreach ($opponent['tokens'] as $tokenIdx => $token) {
                    if ($token['position'] == $position && $token['status'] != 'home') {
                        // Send token back home
                        $boardState['players'][$oppIdx]['tokens'][$tokenIdx]['position'] = -1;
                        $boardState['players'][$oppIdx]['tokens'][$tokenIdx]['status'] = 'home';
                        $boardState['players'][$oppIdx]['tokens_home']++;
                    }
                }
            }
        }
    }
    
    /**
     * Get current board state for UI
     */
    public function getBoardState($matchId) {
        $conn = $this->db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT id, board_state, dice_value, status, winner_id
            FROM matches 
            WHERE id = :mid
        ");
        $stmt->execute([':mid' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) {
            return null;
        }
        
        $boardState = json_decode($match['board_state'], true);
        $boardState['match_id'] = $match['id'];
        $boardState['dice_value'] = $match['dice_value'];
        $boardState['status'] = $match['status'];
        $boardState['winner_id'] = $match['winner_id'];
        
        return $boardState;
    }
    
    /**
     * Get valid moves for current player
     */
    public function getValidMoves($matchId, $playerId) {
        $boardState = $this->getBoardState($matchId);
        
        if (!$boardState || $boardState['dice_value'] == 0) {
            return ['valid_moves' => []];
        }
        
        $validMoves = [];
        $diceValue = $boardState['dice_value'];
        
        // Find player
        $playerIndex = null;
        foreach ($boardState['players'] as $idx => $player) {
            if ($player['user_id'] == $playerId) {
                $playerIndex = $idx;
                break;
            }
        }
        
        if ($playerIndex === null) {
            return ['valid_moves' => []];
        }
        
        $player = $boardState['players'][$playerIndex];
        
        // Check each token
        foreach ($player['tokens'] as $tokenIdx => $token) {
            if ($token['status'] == 'home') {
                // Token in home - can only move with 6
                if ($diceValue == 6) {
                    $validMoves[] = [
                        'token_index' => $tokenIdx,
                        'from' => -1,
                        'to' => self::START_POSITIONS[$playerIndex],
                        'action' => 'bring_out'
                    ];
                }
            } elseif ($token['status'] == 'playing') {
                // Token on board - can move
                $newPos = ($token['position'] + $diceValue) % (self::BOARD_SIZE + self::HOME_SIZE);
                $validMoves[] = [
                    'token_index' => $tokenIdx,
                    'from' => $token['position'],
                    'to' => $newPos,
                    'action' => 'move'
                ];
            }
        }
        
        return [
            'valid_moves' => $validMoves,
            'must_move' => count($validMoves) > 0,
            'can_roll_again' => $diceValue == 6
        ];
    }
}

?>
