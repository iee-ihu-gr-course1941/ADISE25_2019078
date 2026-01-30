<?php
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    /* ===== ENV DETECTION (users.it.teithe.gr vs local) ===== */
    $socket  = '/home/student/iee/2019/iee2019078/mysql/run/mysql.sock';
    $isUsers = file_exists($socket);

    if ($isUsers) {
        /* ===== USERS (socket) ===== */
        $dbname = 'xeri_db';       // ✅ Η βάση που έφτιαξες στο users
        $user   = 'iee2019078';    // ✅ το username σου
        $pass   = 'iee2019078';        // ✅ από db_upass.php

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
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    return $pdo;
}




