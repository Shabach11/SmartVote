<?php
$host     = 'localhost';
$dbname   = 'smartvote';
$username = 'root';
$password = 'root';    // ← Change if you have a password

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die("❌ Database Connection Failed: " . $e->getMessage());
}
?>