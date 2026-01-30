<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

$player = require_player();
$player_id = (int)$player['id'];

$game_id = (int)($_GET['game_id'] ?? 0);
$after_id = (int)($_GET['after_id'] ?? 0);
if ($after_id < 0) {
    $after_id = 0;
}

if ($game_id <= 0) {
    json_response(['error' => 'bad game_id'], 400);
}

//  έλεγχος ότι ο παίκτης ανήκει στο game
$pdo = db();
$check = $pdo->prepare("SELECT 1 FROM games WHERE id = ? AND (player1_id = ? OR player2_id = ?)");
$check->execute([$game_id, $player_id, $player_id]);
if (!$check->fetchColumn()) {
    json_response(['error' => 'forbidden'], 403);
}

$sql = "
  SELECT
    m.id,
    m.game_id,
    m.player_id,
    m.text,
    m.created_at,
    p.username
  FROM game_messages m
  JOIN players p ON p.id = m.player_id
  WHERE m.game_id = ? AND m.id > ?
  ORDER BY m.id ASC
  LIMIT 50
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$game_id, $after_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$r) {
  $r['id'] = (int)$r['id'];
  $r['game_id'] = (int)$r['game_id'];
  $r['player_id'] = (int)$r['player_id'];
}

json_response(['messages' => $rows], 200);

