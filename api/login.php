<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/../lib/auth.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['error' => 'Method not allowed'], 405);
    }

    $data = read_json();
    $username = trim((string)($data['username'] ?? ''));

    if ($username === '' || strlen($username) > 50) {
        json_response(['error' => 'Invalid username'], 400);
    }

    $pdo = db();

    $stmt = $pdo->prepare("SELECT id, token FROM players WHERE username = ?");
    $stmt->execute([$username]);
    $existing = $stmt->fetch();

    if ($existing) {
        json_response([
            'player_id' => (int)$existing['id'],
            'token' => $existing['token']
        ]);
    }

    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare("INSERT INTO players (username, token) VALUES (?, ?)");
    $stmt->execute([$username, $token]);

    json_response([
        'player_id' => (int)$pdo->lastInsertId(),
        'token' => $token
    ], 201);

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'Server error',
        'type'  => get_class($e),
        'msg'   => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


