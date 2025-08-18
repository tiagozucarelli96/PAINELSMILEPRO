<?php
// conexao.php — Railway / PostgreSQL
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$pdo = null;
$sslmode = 'require';

$DATABASE_URL = getenv('DATABASE_URL');

if ($DATABASE_URL) {
    if (stripos($DATABASE_URL, 'sslmode=') === false) {
        $DATABASE_URL .= (str_contains($DATABASE_URL, '?') ? '&' : '?') . 'sslmode=require';
    }
    $parts   = parse_url($DATABASE_URL);
    $DB_HOST = $parts['host'] ?? 'localhost';
    $DB_PORT = $parts['port'] ?? '5432';
    $DB_NAME = isset($parts['path']) ? ltrim($parts['path'], '/') : 'postgres';
    $DB_USER = $parts['user'] ?? '';
    $DB_PASS = $parts['pass'] ?? '';
} else {
    $DB_HOST = getenv('PGHOST')     ?: 'localhost';
    $DB_PORT = getenv('PGPORT')     ?: '5432';
    $DB_NAME = getenv('PGDATABASE') ?: 'postgres';
    $DB_USER = getenv('PGUSER')     ?: 'postgres';
    $DB_PASS = getenv('PGPASSWORD') ?: '';
}

$dsn = "pgsql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};sslmode={$sslmode}";

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Erro de conexão ao banco.";
    if (getenv('APP_DEBUG') === '1') {
        echo " Detalhes: " . $e->getMessage();
    }
    exit;
}
