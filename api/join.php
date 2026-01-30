<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';

/*
 * POST /api/join.php
 * Body: { "game_id": 1 }
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$player = require_player();
$p2 = (int)$player['id'];

$data = read_json();
$gameId = (int)($data['game_id'] ?? 0);
if ($gameId <= 0) json_response(['error' => 'Invalid game_id'], 400);

$pdo = db();

function ensure_capture_slot(array &$board, int $pid): void {
    $k = (string)$pid;
    if (!isset($board['captures']) || !is_array($board['captures'])) $board['captures'] = [];
    if (!isset($board['captures'][$k]) || !is_array($board['captures'][$k])) {
        $board['captures'][$k] = [
            'cards_count' => 0,
            'xeri_count' => 0,
            'xeri_jack_count' => 0,
            'has_2C' => false,     // ✅ 2♣
            'has_10D' => false,
            'face10_count' => 0,
            'bonus_more_cards' => 0 
        ];
    } else {
        //  Fix παλιό typo αν υπάρχει
        if (isset($board['captures'][$k]['has_2S']) && !isset($board['captures'][$k]['has_2C'])) {
            $board['captures'][$k]['has_2C'] = (bool)$board['captures'][$k]['has_2S'];
            unset($board['captures'][$k]['has_2S']);
        }
        // συμπλήρωσε τυχόν missing fields χωρίς reset
        $board['captures'][$k] += [
            'cards_count' => 0,
            'xeri_count' => 0,
            'xeri_jack_count' => 0,
            'has_2C' => false,
            'has_10D' => false,
            'face10_count' => 0,
            'bonus_more_cards' => 0
        ];
    }
}

try{
    $pdo->beginTransaction();

    //  Lock row για να μην γίνει διπλό join
    $stmt = $pdo->prepare("SELECT * FROM games WHERE id = ? FOR UPDATE");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
        $pdo->rollBack();
        json_response(['error' => 'Game not found'], 404);
    }

    if ($game['status'] === 'finished') {
        $pdo->rollBack();
        json_response(['error' => 'Game finished'], 409);
    }

    $p1 = (int)$game['player1_id'];
    if ($p2 === $p1) {
        $pdo->rollBack();
        json_response(['error' => 'Player already in game'], 409);
    }

    // Αν έχει ήδη player2
    if ($game['player2_id'] !== null) {
        if ((int)$game['player2_id'] !== $p2) {
            $pdo->rollBack();
            json_response(['error' => 'Game already has 2 players'], 409);
        }
        $pdo->commit();
        json_response(['game_id' => $gameId, 'status' => $game['status']], 200);
    }

    // Βάλε τον δεύτερο παίκτη + κάνε active
    $stmt = $pdo->prepare("UPDATE games SET player2_id = ?, status = 'active' WHERE id = ?");
    $stmt->execute([$p2, $gameId]);

    // ---- load old board (από games.php) και ΜΗΝ το πετάς ----
    $board = json_decode((string)$game['board_json'], true);
    if (!is_array($board)) $board = [];

    // turn_seconds από create
    $turnSeconds = (int)($board['turn_seconds'] ?? 20);
    if ($turnSeconds < 5) $turnSeconds = 5;
    if ($turnSeconds > 180) $turnSeconds = 180;

    // target_score από create
    $allowedTargetScores = [1, 51, 101, 151, 201, 251, 301];
    $targetScore = (int)($board['target_score'] ?? 51);
    if (!in_array($targetScore, $allowedTargetScores, true)) $targetScore = 51;

    // ---- deal / create deck ----
    $deck = [];
    $suits = ['S','H','D','C'];
    $ranks = ['A','2','3','4','5','6','7','8','9','10','J','Q','K'];
    foreach ($suits as $s) foreach ($ranks as $r) $deck[] = $r.$s;
    shuffle($deck);

    $hand1 = array_splice($deck, 0, 6);
    $hand2 = array_splice($deck, 0, 6);
    $table = array_splice($deck, 0, 4);

    // ---- UI triggers ----
    $board['deal_no'] = (int)($board['deal_no'] ?? 0) + 1;
    $board['phase'] = 'deal';

    // ---- set core state ----
    $board['turn'] = $p1; // host ξεκινάει
    $board['turn_seconds'] = $turnSeconds;
    $board['target_score'] = $targetScore;
    $board['turn_started_at'] = time();

    $board['deck'] = array_values($deck);
    $board['table_pile'] = array_values($table);

    // hands
    if (!isset($board['hands']) || !is_array($board['hands'])) $board['hands'] = [];
    $board['hands'][(string)$p1] = array_values($hand1);
    $board['hands'][(string)$p2] = array_values($hand2);

    // captures: μην κάνεις reset, απλά εξασφάλισε slots
    ensure_capture_slot($board, $p1);
    ensure_capture_slot($board, $p2);

    $board['last_capturer'] = null;

    // result: κράτα ό,τι υπάρχει, συμπλήρωσε defaults
    if (!isset($board['result']) || !is_array($board['result'])) $board['result'] = [];
    $board['result'] += [
        'finished' => false,
        'winner' => null,
        'reason' => null,
        'scores' => []
    ];

    //  Επιλογή Β flags (αν λείπουν)
    $board += [
        'pending_finish' => false,
        'pending_winner' => null,
        'pending_at' => null
    ];

    // ---- save board ----
    $stmt = $pdo->prepare("UPDATE games SET board_json = ? WHERE id = ?");
    $stmt->execute([json_encode($board, JSON_UNESCAPED_UNICODE), $gameId]);

    $pdo->commit();

    json_response([
        'game_id' => $gameId,
        'status' => 'active',
        'turn_seconds' => $turnSeconds,
        'target_score' => $targetScore,
        'deal_no' => (int)$board['deal_no']
    ], 200);

}catch(Throwable $e){
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}



