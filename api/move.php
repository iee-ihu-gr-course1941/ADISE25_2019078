<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';

/*
 * POST /api/move.php
 * Body: { "game_id": 1, "card": "3H" }
 * Headers: Authorization: Bearer <token>
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$player = require_player();
$playerId = (int)$player['id'];

$data = read_json();
$gameId = (int)($data['game_id'] ?? 0);
$card  = strtoupper(trim((string)($data['card'] ?? '')));

if ($gameId <= 0) json_response(['error' => 'Invalid game_id'], 400);
if ($card === '') json_response(['error' => 'Missing card'], 400);

function card_rank(string $c): string { return substr($c, 0, -1); }
function is_jack(string $c): bool { return card_rank($c) === 'J'; }

function ensure_capture_slot(array &$board, string $playerKey): void {
    if (!isset($board['captures'][$playerKey]) || !is_array($board['captures'][$playerKey])) {
        $board['captures'][$playerKey] = [
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

function capture_apply(array &$cap, array $capturedCards, bool $xeri, bool $xeriWithJack): void {
    $cap['cards_count'] += count($capturedCards);

    if ($xeriWithJack) {
        $cap['xeri_jack_count'] += 1; 
    } elseif ($xeri) {
        $cap['xeri_count'] += 1;      
    }

    foreach ($capturedCards as $cc) {
        if ($cc === '2C')  $cap['has_2C'] = true;
        if ($cc === '10D') $cap['has_10D'] = true;

        $r = card_rank($cc);
        if ($r === 'A' || $r === 'K' || $r === 'Q' || $r === 'J' || $r === '10') {
            $cap['face10_count'] += 1;
        }
    }
}

/** Live score από captures (σωρευτικό όσο δεν μηδενίζεις captures) */
function live_score_cap(array $cap): int {
    $score = 0;

    if (!empty($cap['has_2C']))  $score += 1;
    if (!empty($cap['has_10D'])) $score += 2;

    $face10 = (int)($cap['face10_count'] ?? 0);
    if (!empty($cap['has_10D'])) $face10 -= 1;
    if ($face10 > 0) $score += $face10;

    $score += (int)($cap['xeri_count'] ?? 0) * 10;
    $score += (int)($cap['xeri_jack_count'] ?? 0) * 20;

    // +3 bonus για περισσότερα χαρτιά
    $score += (int)($cap['bonus_more_cards'] ?? 0);

    return $score;
}

/** Next deal μέσα στον ίδιο γύρο (όταν και τα 2 χέρια άδεια αλλά deck έχει ακόμα). */
function deal_next_if_needed(array &$board, int $p1, int $p2): bool {
    if (!empty($board['result']['finished'])) return false;

    $deck = is_array($board['deck'] ?? null) ? $board['deck'] : [];
    $h1   = is_array($board['hands'][(string)$p1] ?? null) ? $board['hands'][(string)$p1] : [];
    $h2   = is_array($board['hands'][(string)$p2] ?? null) ? $board['hands'][(string)$p2] : [];

    if (count($h1) !== 0 || count($h2) !== 0) return false;
    if (count($deck) === 0) return false;

    // μοίρασε μέχρι 6 στον καθένα (ό,τι υπάρχει διαθέσιμο)
    $take1 = min(6, count($deck));
    $hand1 = array_splice($deck, 0, $take1);

    $take2 = min(6, count($deck));
    $hand2 = array_splice($deck, 0, $take2);

    $board['deck'] = array_values($deck);
    $board['hands'][(string)$p1] = array_values($hand1);
    $board['hands'][(string)$p2] = array_values($hand2);

    // UI trigger για animation
    $board['deal_no'] = (int)($board['deal_no'] ?? 0) + 1;
    $board['phase'] = 'deal';
    $board['deal_at'] = time();

    // restart timer
    $board['turn_started_at'] = time();

    return true;
}

/** Νέο γύρο: ΜΟΝΟ νέο deck/hands/table. ΔΕΝ μηδενίζει captures. */
function start_new_round(array &$board, int $p1, int $p2): void {
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

    // captures παραμένουν (μόνο ensure ότι υπάρχουν)
    ensure_capture_slot($board, (string)$p1);
    ensure_capture_slot($board, (string)$p2);


    $board['deal_no'] = (int)($board['deal_no'] ?? 0) + 1;
    $board['phase'] = 'deal';
    $board['deal_at'] = time();

    $board['turn'] = $p1;
    $board['turn_started_at'] = time();

    $board['round_cards_base'] = [
    (string)$p1 => (int)($board['captures'][(string)$p1]['cards_count'] ?? 0),
    (string)$p2 => (int)($board['captures'][(string)$p2]['cards_count'] ?? 0),
    ];
}

/**
 * Τέλος γύρου όταν:
 * - deck empty
 * - και τα 2 χέρια empty
 *
 * τότε:
 * - ό,τι έμεινε στο τραπέζι -> last_capturer
 * - αν pending_finish => finished (Επιλογή Β)
 * - αλλιώς νέο γύρο
 */
function end_round_or_finish_match_if_needed(array &$board, int $p1, int $p2): void {
    if (!empty($board['result']['finished'])) return;

    $deck  = is_array($board['deck'] ?? null) ? $board['deck'] : [];
    $h1    = is_array($board['hands'][(string)$p1] ?? null) ? $board['hands'][(string)$p1] : [];
    $h2    = is_array($board['hands'][(string)$p2] ?? null) ? $board['hands'][(string)$p2] : [];
    $table = is_array($board['table_pile'] ?? null) ? $board['table_pile'] : [];

    if (count($deck) !== 0) return;
    if (count($h1) !== 0 || count($h2) !== 0) return;

    // 1) ό,τι έμεινε στο τραπέζι το παίρνει ο last_capturer
    $last = $board['last_capturer'] ?? null;
    if ($last !== null && count($table) > 0) {
        $k = (string)$last;
        ensure_capture_slot($board, $k);
        capture_apply($board['captures'][$k], $table, false, false);
        $board['table_pile'] = [];
    }

    // ✅ +3 για περισσότερα χαρτιά (ΜΟΝΟ του τρέχοντος γύρου)
    $base = is_array($board['round_cards_base'] ?? null) ? $board['round_cards_base'] : [
        (string)$p1 => 0, (string)$p2 => 0
    ];

    $c1 = (int)($board['captures'][(string)$p1]['cards_count'] ?? 0) - (int)($base[(string)$p1] ?? 0);
    $c2 = (int)($board['captures'][(string)$p2]['cards_count'] ?? 0) - (int)($base[(string)$p2] ?? 0);

    if ($c1 > $c2) {
        $board['captures'][(string)$p1]['bonus_more_cards'] =
            (int)($board['captures'][(string)$p1]['bonus_more_cards'] ?? 0) + 3;
    } elseif ($c2 > $c1) {
        $board['captures'][(string)$p2]['bonus_more_cards'] =
            (int)($board['captures'][(string)$p2]['bonus_more_cards'] ?? 0) + 3;
    }

    // reset βάση για τον επόμενο γύρο (θα την ξαναβάλει start_new_round)
    $board['round_cards_base'] = [
        (string)$p1 => (int)($board['captures'][(string)$p1]['cards_count'] ?? 0),
        (string)$p2 => (int)($board['captures'][(string)$p2]['cards_count'] ?? 0),
    ];

    // ✅ CHECK TARGET ΜΕΤΑ το +3 (γιατί μπορεί να φτάσει 51 εδώ)
$target = (int)($board['target_score'] ?? 51);

$p1ScoreNow = live_score_cap($board['captures'][(string)$p1] ?? []);
$p2ScoreNow = live_score_cap($board['captures'][(string)$p2] ?? []);

if ($p1ScoreNow >= $target || $p2ScoreNow >= $target) {
    // winner: αν έχει pending_winner κράτα αυτόν, αλλιώς ο μεγαλύτερος
    $winner = null;
    if (!empty($board['pending_winner'])) {
        $winner = (int)$board['pending_winner'];
    } else {
        if ($p1ScoreNow !== $p2ScoreNow) $winner = ($p1ScoreNow > $p2ScoreNow) ? $p1 : $p2;
    }

    $board['result'] = [
        'finished' => true,
        'winner' => $winner,
        'reason' => 'target_reached_end_of_round',
        'target_score' => $target,
        'scores' => [
            (string)$p1 => $p1ScoreNow,
            (string)$p2 => $p2ScoreNow
        ]
    ];
    $board['phase'] = 'finished';
    return;
}

    // 2) αλλιώς νέο γύρο (χωρίς reset captures)
    start_new_round($board, $p1, $p2);
}

function apply_timeout_if_needed(array &$board, int $p1, int $p2): bool {
    if (!empty($board['result']['finished'])) return false;

    $turnSeconds = (int)($board['turn_seconds'] ?? 0);
    $startedAt   = (int)($board['turn_started_at'] ?? 0);
    $turnPlayer  = (int)($board['turn'] ?? $p1);

    if ($turnSeconds <= 0 || $startedAt <= 0) return false;
    $elapsed = time() - $startedAt;
    if ($elapsed < $turnSeconds) return false;

    $winner = ($turnPlayer === $p1) ? $p2 : $p1;

    $board['result'] = [
        'finished' => true,
        'winner' => $winner,
        'reason' => 'timeout',
        'timeout_loser' => $turnPlayer,
        'timeout_elapsed' => $elapsed,
        'timeout_limit' => $turnSeconds,
        'scores' => $board['result']['scores'] ?? []
    ];
    $board['phase'] = 'finished';
    return true;
}

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM games WHERE id = ? FOR UPDATE");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) json_response(['error' => 'Game not found'], 404);

    $p1 = (int)$game['player1_id'];
    $p2 = $game['player2_id'] !== null ? (int)$game['player2_id'] : null;

    if ($playerId !== $p1 && $playerId !== $p2) {
        json_response(['error' => 'Not a player of this game'], 403);
    }

    if ($game['status'] !== 'active') {
        json_response(['error' => 'Game not active'], 409);
    }
    if ($p2 === null) {
        json_response(['error' => 'Waiting for opponent'], 409);
    }

    $board = json_decode((string)$game['board_json'], true);
    if (!is_array($board)) json_response(['error' => 'Corrupt board_json'], 500);

    if (!empty($board['result']['finished'])) {
        json_response(['error' => 'Game finished'], 409);
    }

    if (!isset($board['deal_no'])) $board['deal_no'] = 0;
    if (!isset($board['phase'])) $board['phase'] = 'play';
    if (!isset($board['captures']) || !is_array($board['captures'])) $board['captures'] = [];

    ensure_capture_slot($board, (string)$p1);
    ensure_capture_slot($board, (string)$p2);

    if (!isset($board['round_cards_base']) || !is_array($board['round_cards_base'])) {
        $board['round_cards_base'] = [
            (string)$p1 => (int)($board['captures'][(string)$p1]['cards_count'] ?? 0),
            (string)$p2 => (int)($board['captures'][(string)$p2]['cards_count'] ?? 0),
        ];
    }

    if (!isset($board['pending_finish'])) $board['pending_finish'] = false;
    if (!isset($board['pending_winner'])) $board['pending_winner'] = null;

    // timeout check
    if (apply_timeout_if_needed($board, $p1, $p2)) {
        $stmt = $pdo->prepare("UPDATE games SET status = 'finished', board_json = ? WHERE id = ?");
        $stmt->execute([json_encode($board, JSON_UNESCAPED_UNICODE), $gameId]);

        $pdo->commit();
        json_response([
            'ok' => true,
            'status' => 'finished',
            'result' => $board['result'],
            'turn_player' => $board['turn'] ?? null
        ], 200);
    }

    $turn = (int)($board['turn'] ?? 0);
    if ($turn !== $playerId) {
        json_response(['error' => 'Not your turn'], 409);
    }

    $hand = $board['hands'][(string)$playerId] ?? [];
    if (!in_array($card, $hand, true)) {
        json_response(['error' => 'Card not in your hand'], 409);
    }

    // Remove card from hand
    $idx = array_search($card, $hand, true);
    unset($hand[$idx]);
    $hand = array_values($hand);
    $board['hands'][(string)$playerId] = $hand;

    $table = $board['table_pile'] ?? [];
    $table = is_array($table) ? $table : [];
    $tableCountBefore = count($table);
    $top = $tableCountBefore > 0 ? $table[0] : null;

    $capture = false;
    $xeri = false;
    $xeriWithJack = false;
    $capturedCards = [];

    // Capture rules
    if ($top !== null) {
        $rankMatch = (card_rank($card) === card_rank($top));
        $jack = is_jack($card);

        if ($rankMatch || $jack) {
            $capture = true;

            $single = ($tableCountBefore === 1) ? ($table[0] ?? null) : null;
            $singleIsJack = ($single !== null && is_jack($single));
            $xeri = ($tableCountBefore === 1);

            // 20 μόνο όταν J παίρνει J και ήταν μονόφυλλο
            $xeriWithJack = ($xeri && $jack && $singleIsJack);

            // αν J πάνω σε ΜΗ-J μονόφυλλο -> κανένα bonus
            if ($xeri && $jack && !$singleIsJack) {
                $xeri = false;
                $xeriWithJack = false;
            }

            $capturedCards = $table;
            $capturedCards[] = $card;

            $board['table_pile'] = [];

            $k = (string)$playerId;
            ensure_capture_slot($board, $k);
            capture_apply($board['captures'][$k], $capturedCards, $xeri, $xeriWithJack);

            $board['last_capturer'] = $playerId;
        }
    }

    if (!$capture) {
        array_unshift($table, $card);
        $board['table_pile'] = array_values($table);
    }

    // Switch turn
    $next = ($playerId === $p1) ? $p2 : $p1;
    $board['turn'] = $next;
    $board['phase'] = 'play';

    // ✅ Επιλογή Β: αν κάποιος πιάσει target μέσα στον γύρο, "κλειδώνει" ότι θα τελειώσει στο τέλος γύρου
    if (empty($board['pending_finish'])) {
        $target = (int)($board['target_score'] ?? 51);
        $myScoreNow = live_score_cap($board['captures'][(string)$playerId] ?? []);
        if ($myScoreNow >= $target) {
            $board['pending_finish'] = true;
            $board['pending_winner'] = $playerId;
            $board['pending_at'] = time();
        }
    }

    // ✅ 1) Next deal (όταν άδειασαν χέρια αλλά έχει deck)
    deal_next_if_needed($board, $p1, $p2);

    // ✅ 2) End of round (μόνο όταν deck=0 και άδειασαν και τα 2 χέρια)
    end_round_or_finish_match_if_needed($board, $p1, $p2);

    // reset timer μόνο αν δεν τελείωσε
    if (empty($board['result']['finished'])) {
        if (($board['phase'] ?? '') !== 'deal') {
            $board['turn_started_at'] = time();
        }
    }

    $newStatus = !empty($board['result']['finished']) ? 'finished' : 'active';

    $stmt = $pdo->prepare("UPDATE games SET status = ?, board_json = ? WHERE id = ?");
    $stmt->execute([$newStatus, json_encode($board, JSON_UNESCAPED_UNICODE), $gameId]);

    $stmt = $pdo->prepare("INSERT INTO moves (game_id, player_id, move_json) VALUES (?,?,?)");
    $stmt->execute([$gameId, $playerId, json_encode([
        'card' => $card,
        'capture' => $capture,
        'xeri' => $xeri,
        'xeri_with_jack' => $xeriWithJack
    ], JSON_UNESCAPED_UNICODE)]);

    $pdo->commit();

    json_response([
        'ok' => true,
        'game_id' => $gameId,
        'played' => $card,
        'capture' => $capture,
        'xeri' => $xeri,
        'xeri_with_jack' => $xeriWithJack,
        'next_turn' => $next,
        'status' => $newStatus,
        'phase' => $board['phase'] ?? null,
        'deal_no' => (int)($board['deal_no'] ?? 0),
        'pending_finish' => !empty($board['pending_finish']),
        'pending_winner' => $board['pending_winner'] ?? null
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}






