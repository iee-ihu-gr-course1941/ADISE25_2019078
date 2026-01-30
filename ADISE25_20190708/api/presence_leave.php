<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'Method not allowed'], 405);

$player = require_player();
$pid = (int)$player['id'];

$data = read_json();
$gameId = (int)($data['game_id'] ?? 0);
if ($gameId <= 0) json_response(['error'=>'Invalid game_id'], 400);

$pdo = db();
$stmt = $pdo->prepare("UPDATE game_presence SET in_game=0, last_seen=? WHERE game_id=? AND player_id=?");
$stmt->execute([time(), $gameId, $pid]);

json_response(['ok'=>true], 200);
