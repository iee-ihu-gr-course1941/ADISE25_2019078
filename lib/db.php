<?php
declare(strict_types=1);

require_once __DIR__ . '/db_upass.php';   // ⬅️ ΜΟΝΟ αυτό το extra αρχείο

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    /* ===== ENV DETECTION (users.ihu.gr vs local) ===== */
    $socket = '/home/student/iee/2019/iee2019078/mysql/run/mysql.sock';
    $isUsers = file_exists($socket);

    if ($isUsers) {
        /* ===== USERS.IHU.GR ===== */
        $dbname = 'adise25_2019078';
        $user   = 'iee2019078';
        $pass   = DB_UPASS;   // ⬅️ ΔΕΝ υπάρχει κωδικός εδώ

        $dsn = "mysql:unix_socket={$socket};dbname={$dbname};charset=utf8mb4";
    } else {
        /* ===== LOCAL (XAMPP) ===== */
        $host   = '127.0.0.1';
        $port   = '3307';
        $dbname = 'xeri_db';
        $user   = 'root';
        $pass   = '';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    }

    $pdo = new PDO(
        $dsn,
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    return $pdo;
}


