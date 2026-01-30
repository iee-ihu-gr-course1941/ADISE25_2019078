<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';

/*
 * GET /api/games_waiting.php
 * Επιστρέφει λίστα από waiting games (μόνο αυτά που περιμένουν 2ο παίκτη)
 * + turn_seconds + target_score από board_json
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_player(); // θέλεις login για να δεις λίστα (αν θες δημόσιο, βγάλτο)

$pdo = db();

// LIMIT
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
if ($limit < 1) $limit = 1;
if ($limit > 50) $limit = 50;

$sql = "
SELECT
  g.id AS game_id,
  g.player1_id,
  p.username AS host_username,
  g.board_json
FROM games g
LEFT JOIN players p ON p.id = g.player1_id
WHERE g.status = 'waiting'
ORDER BY g.id DESC
LIMIT ?
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// πρόσθεσε turn_seconds/target_score από board_json
$games = [];
foreach ($rows as $r) {
    $board = json_decode((string)($r['board_json'] ?? ''), true);
    if (!is_array($board)) $board = [];

    $games[] = [
        'game_id' => (int)$r['game_id'],
        'player1_id' => (int)$r['player1_id'],
        'host_username' => $r['host_username'],
        'turn_seconds' => (int)($board['turn_seconds'] ?? 0),
        'target_score' => (int)($board['target_score'] ?? 51),
    ];
}

json_response([
    'ok' => true,
    'games' => $games
]);

