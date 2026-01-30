<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';

$user = require_player();
$player_id = (int)$user['id'];

$input = read_json();
$game_id = (int)($input['game_id'] ?? 0);

if ($game_id <= 0) json_response(['error' => 'bad game_id'], 400);

$pdo = db();

// upsert "last_typing"
$sql = "
  INSERT INTO game_typing (game_id, player_id, last_typing)
  VALUES (?, ?, NOW())
  ON DUPLICATE KEY UPDATE last_typing = NOW()
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$game_id, $player_id]);

json_response(['ok' => true]);
