<?php
// dbconfig.php — Database connection
define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_NAME',    'restaurant_db');
define('DB_USER',    'root');   // ← change if needed
define('DB_PASS',    '');       // ← change if needed
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
    } catch (PDOException $e) {
        die('<div style="font-family:sans-serif;padding:40px;color:#a83232"><h2>⚠ DB Connection Failed</h2><p>'.htmlspecialchars($e->getMessage()).'</p><p>Edit dbconfig.php — Host: <b>'.DB_HOST.'</b> DB: <b>'.DB_NAME.'</b> User: <b>'.DB_USER.'</b></p></div>');
    }
    return $pdo;
}
