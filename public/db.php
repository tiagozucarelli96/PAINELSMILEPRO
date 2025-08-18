<?php
$host = getenv('PGHOST') ?: 'localhost';
$db   = getenv('PGDATABASE') ?: 'railway';
$user = getenv('PGUSER') ?: 'postgres';
$pass = getenv('PGPASSWORD') ?: '';
$port = getenv('PGPORT') ?: '5432';

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}
?>
