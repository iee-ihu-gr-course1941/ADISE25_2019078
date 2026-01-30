<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function read_json(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?? '', true);
    if (!is_array($data)) {
        json_response(['error' => 'Invalid JSON'], 400);
    }
    return $data;
}

function get_bearer_token(): ?string {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? null;
    if (!$auth) return null;
    if (preg_match('/Bearer\s+(.+)/', $auth, $m)) {
        return trim($m[1]);
    }
    return null;
}

function require_player(): array {
    $token = get_bearer_token();
    if (!$token) {
        json_response(['error' => 'Missing token'], 401);
    }

    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, username FROM players WHERE token = ?");
    $stmt->execute([$token]);
    $player = $stmt->fetch();

    if (!$player) {
        json_response(['error' => 'Invalid token'], 401);
    }

    return $player;
}

