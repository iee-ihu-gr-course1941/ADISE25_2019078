<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';

$player = require_player();                 // -> ['id', 'username']
$player_id = (int)$player['id'];

$input = read_json();
$game_id = (int)($input['game_id'] ?? 0);
$text = trim((string)($input['text'] ?? ''));

if ($game_id <= 0) {
    json_response(['error' => 'bad game_id'], 400);
}
if ($text === '' || mb_strlen($text) > 240) {
    json_response(['error' => 'bad text'], 400);
}

//  έλεγχος ότι ο παίκτης ανήκει στο game
$pdo = db();
$check = $pdo->prepare("SELECT 1 FROM games WHERE id = ? AND (player1_id = ? OR player2_id = ?)");
$check->execute([$game_id, $player_id, $player_id]);
if (!$check->fetchColumn()) {
    json_response(['error' => 'forbidden'], 403);
}

// 1) insert message
$stmt = $pdo->prepare("INSERT INTO game_messages (game_id, player_id, text) VALUES (?, ?, ?)");
$stmt->execute([$game_id, $player_id, $text]);

// 2) clear typing for THIS player (ώστε να μη “μένει” το typing)
$clear = $pdo->prepare("DELETE FROM game_typing WHERE game_id = ? AND player_id = ?");
$clear->execute([$game_id, $player_id]);

json_response(['ok' => true], 200);


