<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';



if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$player = require_player();
$playerId = (int)$player['id'];

$gameId = isset($_GET['game_id']) ? (int)$_GET['game_id'] : 0;
if ($gameId <= 0) {
    json_response(['error' => 'Invalid game_id'], 400);
}

$pdo = db();
$stmt = $pdo->prepare("
    SELECT id, player1_id, player2_id, status, board_json, rematch_p1_ready, rematch_p2_ready
    FROM games WHERE id = ?
");
$stmt->execute([$gameId]);
$game = $stmt->fetch();

if (!$game) {
    json_response(['error' => 'Game not found'], 404);
}

$p1 = (int)$game['player1_id'];
$p2 = $game['player2_id'] !== null ? (int)$game['player2_id'] : null;

if ($playerId !== $p1 && $playerId !== $p2) {
    json_response(['error' => 'You are not a player of this game'], 403);
}

$board = json_decode((string)$game['board_json'], true);
if (!is_array($board)) {
    json_response(['error' => 'Corrupted board_json'], 500);
}

/* =========================
   Helpers
========================= */

function apply_timeout_if_needed(array &$board, int $p1, ?int $p2): bool {
    if (!empty($board['result']['finished'])) return false;
    if ($p2 === null) return false;

    $turnSeconds = (int)($board['turn_seconds'] ?? 0);
    $startedAt   = (int)($board['turn_started_at'] ?? 0);
    $turnPlayer  = (int)($board['turn'] ?? $p1);

    if ($turnSeconds <= 0 || $startedAt <= 0) return false;
    if ((time() - $startedAt) < $turnSeconds) return false;

    $winner = ($turnPlayer === $p1) ? $p2 : $p1;

    $board['result'] = [
        'finished' => true,
        'winner' => $winner,
        'reason' => 'timeout',
        'timeout_loser' => $turnPlayer,
        'scores' => $board['result']['scores'] ?? []
    ];
    $board['phase'] = 'finished';

    return true;
}

function start_new_deal_same_match(array &$board, int $p1, int $p2): void {
    // νέα τράπουλα
    $deck = [];
    $suits = ['S','H','D','C'];
    $ranks = ['A','2','3','4','5','6','7','8','9','10','J','Q','K'];
    foreach ($suits as $s) foreach ($ranks as $r) $deck[] = $r.$s;
    shuffle($deck);

    $hand1 = array_splice($deck, 0, 6);
    $hand2 = array_splice($deck, 0, 6);
    $table = array_splice($deck, 0, 4);

    $board['deck'] = array_values($deck);
    $board['hands'][(string)$p1] = array_values($hand1);
    $board['hands'][(string)$p2] = array_values($hand2);
    $board['table_pile'] = array_values($table);

    $board['last_capturer'] = null;

    // UI triggers
    $board['deal_no'] = (int)($board['deal_no'] ?? 0) + 1;
    $board['phase'] = 'deal';
    $board['deal_at'] = time();

    // turn + timer restart
    $board['turn'] = $p1;
    $board['turn_started_at'] = time();
}

function advance_if_round_ended(array &$board, int $p1, int $p2): bool {
    if (!empty($board['result']['finished'])) return false;

    $deck  = is_array($board['deck'] ?? null) ? $board['deck'] : [];
    $h1    = is_array($board['hands'][(string)$p1] ?? null) ? $board['hands'][(string)$p1] : [];
    $h2    = is_array($board['hands'][(string)$p2] ?? null) ? $board['hands'][(string)$p2] : [];

    // round ended only when deck empty AND both hands empty
    if (count($deck) !== 0) return false;
    if (count($h1) !== 0 || count($h2) !== 0) return false;

    // Επιλογή Β: finish στο τέλος του γύρου αν έχει γίνει pending_finish
    if (!empty($board['pending_finish'])) {
        $board['result']['finished'] = true;
        $board['result']['winner'] = $board['pending_winner'] ?? null;
        $board['result']['reason'] = 'target_reached_mid_round_finish_at_round_end';
        $board['phase'] = 'finished';
        return true;
    }

    // αλλιώς: νέο deal (ίδιο match) χωρίς reset captures (όπως θέλεις)
    start_new_deal_same_match($board, $p1, $p2);
    return true;
}

function player_name(PDO $pdo, ?int $pid): ?string {
    if (!$pid) return null;
    $st = $pdo->prepare("SELECT username FROM players WHERE id=?");
    $st->execute([$pid]);
    return $st->fetchColumn() ?: null;
}

function live_score(?array $cap): int {
    if (!$cap) return 0;

    $score = 0;
    if (!empty($cap['has_2C']))  $score += 1;
    if (!empty($cap['has_10D'])) $score += 2;

    $face10 = (int)($cap['face10_count'] ?? 0);
    if (!empty($cap['has_10D'])) $face10 -= 1;
    if ($face10 > 0) $score += $face10;

    $score += (int)($cap['xeri_count'] ?? 0) * 10;
    $score += (int)($cap['xeri_jack_count'] ?? 0) * 20;

    // ✅ +3 bonus για περισσότερα χαρτιά
    $score += (int)($cap['bonus_more_cards'] ?? 0);

    return $score;
}

/* =========================
   1) Timeout check
========================= */

$changed = false;

$timedOut = apply_timeout_if_needed($board, $p1, $p2);
if ($timedOut) {
    // finish now
    $stmt = $pdo->prepare("UPDATE games SET status='finished', board_json=? WHERE id=?");
    $stmt->execute([json_encode($board, JSON_UNESCAPED_UNICODE), $gameId]);
    $game['status'] = 'finished';
} else {
    /* =========================
       2) Auto-advance round (redeal / finish) on state poll
       (μόνο αν έχουμε 2 παίκτες και το game είναι active)
    ========================= */
    if ($p2 !== null && $game['status'] === 'active') {
        if (advance_if_round_ended($board, $p1, $p2)) {
            $changed = true;

            // αν έγινε finish από pending_finish
            if (!empty($board['result']['finished'])) {
                $stmt = $pdo->prepare("UPDATE games SET status='finished', board_json=? WHERE id=?");
                $stmt->execute([json_encode($board, JSON_UNESCAPED_UNICODE), $gameId]);
                $game['status'] = 'finished';
            } else {
                // redeal
                $stmt = $pdo->prepare("UPDATE games SET board_json=? WHERE id=?");
                $stmt->execute([json_encode($board, JSON_UNESCAPED_UNICODE), $gameId]);
            }
        }
    }
}

/* =========================
   Read board fields for response
========================= */

$table = is_array($board['table_pile'] ?? null) ? $board['table_pile'] : [];
$top = $table[0] ?? null;

$deck = is_array($board['deck'] ?? null) ? $board['deck'] : [];
$deckCount = count($deck);

$turn = (int)($board['turn'] ?? $p1);
$myHand = $board['hands'][(string)$playerId] ?? [];

$oppId = ($playerId === $p1) ? $p2 : $p1;

$myCapt = $board['captures'][(string)$playerId] ?? null;
$oppCapt = $oppId ? ($board['captures'][(string)$oppId] ?? null) : null;
$myCapt  = is_array($myCapt) ? $myCapt : null;
$oppCapt = is_array($oppCapt) ? $oppCapt : null;

$meName  = player_name($pdo, $playerId);
$oppName = player_name($pdo, $oppId);

// presence
$opponentLeft = false;
$oppLastSeen = null;

if ($oppId) {
    $ps = $pdo->prepare("SELECT username, in_game, last_seen FROM game_presence WHERE game_id=? AND player_id=?");
    $ps->execute([$gameId, $oppId]);
    $pr = $ps->fetch(PDO::FETCH_ASSOC);

    $presName = $pr['username'] ?? null;
    if ($presName) $oppName = $presName;

    $oppInGame = (int)($pr['in_game'] ?? 0);
    $oppLastSeen = isset($pr['last_seen']) ? (int)$pr['last_seen'] : null;

    $opponentLeft = ($oppInGame === 0) || ($oppLastSeen !== null && (time() - $oppLastSeen) > 15);
}

$myLiveScore  = live_score($myCapt);
$oppLiveScore = live_score($oppCapt);

// timer
$turnSeconds = (int)($board['turn_seconds'] ?? 0);
$turnStarted = (int)($board['turn_started_at'] ?? 0);

$deadline = null;
$remaining = null;

if ($turnSeconds > 0 && $turnStarted > 0 && empty($board['result']['finished'])) {
    $deadline = $turnStarted + $turnSeconds;
    $remaining = max(0, $deadline - time());
}

$dealNo = (int)($board['deal_no'] ?? 0);

json_response([
    'game_id' => (int)$game['id'],
    'status'  => $game['status'],

    'phase'   => $board['phase'] ?? null,
    'deal_no' => $dealNo,

    'target_score' => (int)($board['target_score'] ?? 51),

    // pending finish (επιλογή Β)
    'pending' => [
        'finish' => !empty($board['pending_finish']),
        'winner' => $board['pending_winner'] ?? null
    ],

    'turn_player' => $turn,
    'my_turn'     => ($turn === $playerId),

    'table' => [
        'top' => $top,
        'count' => count($table),
        'cards' => array_values($table)
    ],

    'deck' => [
        'count' => $deckCount
    ],

    'timer' => [
        'turn_seconds' => $turnSeconds,
        'turn_started_at' => $turnStarted ?: null,
        'turn_deadline' => $deadline,
        'remaining' => $remaining
    ],

    // LIVE SCORE (σωρευτικό αφού captures δεν μηδενίζονται)
    'live' => [
        'me' => ['score' => $myLiveScore],
        'opponent' => ['score' => $oppLiveScore]
    ],

    'me' => [
        'player_id' => $playerId,
        'username'  => $meName,
        'hand' => $myHand,
        'captures' => $myCapt
    ],

    'opponent' => [
        'player_id' => $oppId,
        'username'  => $oppName,
        'captures' => $oppCapt
    ],

    'result' => $board['result'] ?? null,

    'players' => [
        'p1' => $p1,
        'p2' => $p2
    ],

    'rematch' => [
        'p1_ready' => (int)$game['rematch_p1_ready'],
        'p2_ready' => (int)$game['rematch_p2_ready']
    ],

    'presence' => [
        'opponent_left' => $opponentLeft,
        'opponent_last_seen' => $oppLastSeen
    ],
]);



