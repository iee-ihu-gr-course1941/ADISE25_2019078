<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$player = require_player();
$p1 = (int)$player['id'];

$pdo = db();
$data = read_json();

$turnSeconds = (int)($data['turn_seconds'] ?? 20);
if ($turnSeconds < 5) $turnSeconds = 5;
if ($turnSeconds > 180) $turnSeconds = 180;

// ✅ target_score (win score)
$allowedTargetScores = [1, 51, 101, 151, 201, 251, 301];
$targetScore = (int)($data['target_score'] ?? 51);
if (!in_array($targetScore, $allowedTargetScores, true)) {
    $targetScore = 51;
}

/*
  Αρχικοποίηση παιχνιδιού:
  - status=waiting (περιμένει 2ο)
  - board_json (θα γεμίσει το join όταν έρθει ο 2ος)
*/
$board = [
    // core
    'turn' => $p1,
    'turn_seconds' => $turnSeconds,
    'target_score' => $targetScore,

    // timer
    'turn_started_at' => null, // θα μπει time() όταν γίνει active στο join

    // UI triggers
    'deal_no' => 0,
    'phase' => 'waiting', // waiting μέχρι να μπει 2ος

    // ✅ Επιλογή Β: κλείδωμα νίκης μέσα στον γύρο (finish στο τέλος γύρου)
    'pending_finish' => false,
    'pending_winner' => null,
    'pending_at' => null,

    // game state
    'deck' => [],
    'table_pile' => [],
    'hands' => [
        (string)$p1 => []
    ],

    // captures (σωρευτικά - δεν θα μηδενίζονται)
    'captures' => [
        (string)$p1 => [
            'cards_count' => 0,
            'xeri_count' => 0,
            'xeri_jack_count' => 0,
            'has_2C' => false,   // ✅ FIX: 2♣ (όχι 2S)
            'has_10D' => false,
            'face10_count' => 0,
            'bonus_more_cards' => 0
        ]
    ],

    'last_capturer' => null,

    // result
    'result' => [
        'finished' => false,
        'winner' => null,
        'reason' => null,
        'scores' => []
    ]
];

$stmt = $pdo->prepare("INSERT INTO games (player1_id, status, board_json) VALUES (?, 'waiting', ?)");
$stmt->execute([$p1, json_encode($board, JSON_UNESCAPED_UNICODE)]);

$gameId = (int)$pdo->lastInsertId();

json_response([
    'game_id' => $gameId,
    'status' => 'waiting',
    'turn_seconds' => $turnSeconds,
    'target_score' => $targetScore
], 201);



