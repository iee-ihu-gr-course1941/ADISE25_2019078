<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['error' => 'Method not allowed'], 405);
}

$player = require_player();
$pid = (int)$player['id'];

$data = read_json();
$gameId = (int)($data['game_id'] ?? 0);
if ($gameId <= 0) json_response(['error' => 'Invalid game_id'], 400);

$pdo = db();

try{
  $pdo->beginTransaction();

  // lock game row
  $stmt = $pdo->prepare("SELECT * FROM games WHERE id = ? FOR UPDATE");
  $stmt->execute([$gameId]);
  $game = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$game) { $pdo->rollBack(); json_response(['error'=>'Game not found'], 404); }

  $p1 = (int)$game['player1_id'];
  $p2 = (int)($game['player2_id'] ?? 0);

  if ($pid !== $p1 && $pid !== $p2) { $pdo->rollBack(); json_response(['error'=>'Not your game'], 403); }
  if (!$p2) { $pdo->rollBack(); json_response(['error'=>'No opponent yet'], 409); }

  
  if ((string)$game['status'] !== 'finished') {
    $pdo->rollBack();
    json_response(['error' => 'Game not finished'], 409);
  }

  // 1) Σήκωσε το ready flag για τον παίκτη που πάτησε
  if ($pid === $p1) {
    $pdo->prepare("UPDATE games SET rematch_p1_ready=1 WHERE id=?")->execute([$gameId]);
  } else {
    $pdo->prepare("UPDATE games SET rematch_p2_ready=1 WHERE id=?")->execute([$gameId]);
  }

  // ξαναδιάβασε τα flags (μέσα στο ίδιο lock)
  $stmt2 = $pdo->prepare("SELECT rematch_p1_ready, rematch_p2_ready, board_json FROM games WHERE id=? FOR UPDATE");
  $stmt2->execute([$gameId]);
  $r = $stmt2->fetch(PDO::FETCH_ASSOC);

  $p1Ready = (int)($r['rematch_p1_ready'] ?? 0);
  $p2Ready = (int)($r['rematch_p2_ready'] ?? 0);

  // 2) Αν ΔΕΝ είναι και οι δύο έτοιμοι -> μόνο ενημέρωση
  if (!($p1Ready === 1 && $p2Ready === 1)) {
    $pdo->commit();
    json_response([
      'ok' => true,
      'game_id' => $gameId,
      'status' => 'finished',
      'waiting_for_other' => true,
      'rematch' => ['p1_ready'=>$p1Ready, 'p2_ready'=>$p2Ready],
    ], 200);
  }

  // 3) Και οι δύο ready -> κάνε reset board (ίδιες ρυθμίσεις)
  $oldBoard = json_decode((string)$r['board_json'], true);
  if (!is_array($oldBoard)) $oldBoard = [];

  $turnSeconds = (int)($oldBoard['turn_seconds'] ?? 20);
  if ($turnSeconds < 5) $turnSeconds = 5;
  if ($turnSeconds > 180) $turnSeconds = 180;

  $allowedTargetScores = [1, 51, 101, 151, 201, 251, 301];
  $targetScore = (int)($oldBoard['target_score'] ?? 51);
  if (!in_array($targetScore, $allowedTargetScores, true)) $targetScore = 51;

  // === ίδια αρχικοποίηση με join.php ===
  $deck = [];
  $suits = ['S','H','D','C'];
  $ranks = ['A','2','3','4','5','6','7','8','9','10','J','Q','K'];
  foreach ($suits as $s) foreach ($ranks as $rnk) $deck[] = $rnk.$s;
  shuffle($deck);

  $hand1 = array_splice($deck, 0, 6);
  $hand2 = array_splice($deck, 0, 6);
  $table = array_splice($deck, 0, 4);

  $board = [
    'turn' => $p1,
    'turn_seconds' => $turnSeconds,
    'target_score' => $targetScore,
    'turn_started_at' => time(),
    'deck' => array_values($deck),
    'table_pile' => array_values($table),
    'hands' => [
      (string)$p1 => array_values($hand1),
      (string)$p2 => array_values($hand2)
    ],
    'captures' => [
      (string)$p1 => [
        'cards_count' => 0, 'xeri_count' => 0, 'xeri_jack_count' => 0,
        'has_2C' => false, 'has_10D' => false, 'face10_count' => 0
      ],
      (string)$p2 => [
        'cards_count' => 0, 'xeri_count' => 0, 'xeri_jack_count' => 0,
        'has_2C' => false, 'has_10D' => false, 'face10_count' => 0
      ]
    ],
    'last_capturer' => null,
    'result' => ['finished'=>false, 'winner'=>null, 'scores'=>[]]
  ];

  // κάνε reset game + μηδένισε flags
  $upd = $pdo->prepare("
    UPDATE games
    SET status='active',
        board_json=?,
        rematch_p1_ready=0,
        rematch_p2_ready=0
    WHERE id=?
  ");
  $upd->execute([json_encode($board, JSON_UNESCAPED_UNICODE), $gameId]);

  $pdo->prepare("DELETE FROM moves WHERE game_id=?")->execute([$gameId]);
  $pdo->prepare("DELETE FROM game_typing WHERE game_id=?")->execute([$gameId]);

  $pdo->commit();

  json_response([
    'ok' => true,
    'game_id' => $gameId,
    'status' => 'active',
    'turn_seconds' => $turnSeconds,
    'target_score' => $targetScore
  ], 200);

}catch(Exception $e){
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_response(['error'=>'Server error', 'detail'=>$e->getMessage()], 500);
}



