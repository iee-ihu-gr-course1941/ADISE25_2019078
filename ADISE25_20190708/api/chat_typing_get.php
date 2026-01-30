<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';

$user = require_player();
$me_id = (int)$user['id'];

$game_id = (int)($_GET['game_id'] ?? 0);
if ($game_id <= 0) json_response(['error' => 'bad game_id'], 400);

$pdo = db();

// TTL (2s πιο snappy, βάλε 3 αν θες)
$ttlSeconds = 2;

$sql = "
  SELECT t.player_id, p.username, t.last_typing
  FROM game_typing t
  JOIN players p ON p.id = t.player_id
  WHERE t.game_id = ?
    AND t.player_id <> ?
    AND t.last_typing >= (NOW() - INTERVAL ? SECOND)
  ORDER BY t.last_typing DESC
  LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$game_id, $me_id, $ttlSeconds]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

json_response([
  'typing' => (bool)$row,
  'player_id' => $row ? (int)$row['player_id'] : null,
  'username' => $row['username'] ?? null,
  'last_typing' => $row['last_typing'] ?? null,
]);

