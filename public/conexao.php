<?php
// public/conexao.php — somente Postgres (Railway)
declare(strict_types=1);

$debug = getenv('APP_DEBUG') === '1';
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');
error_reporting($debug ? E_ALL : (E_ALL & ~E_NOTICE));

$pdo = null;
$db_error = '';

try {
    $dbUrl = getenv('DATABASE_URL'); // ex.: postgres://user:pass@host:port/db?sslmode=require
    if (!$dbUrl) {
        throw new RuntimeException('DATABASE_URL não definido nas variáveis do serviço Web.');
    }
    $dbUrl = preg_replace('#^postgresql://#', 'postgres://', $dbUrl);
    $parts = parse_url($dbUrl);
    if (!$parts || empty($parts['host']) || empty($parts['path'])) {
        throw new RuntimeException('DATABASE_URL inválido.');
    }

    $host = $parts['host'];
    $port = (int)($parts['port'] ?? 5432);
    $user = urldecode($parts['user'] ?? '');
    $pass = urldecode($parts['pass'] ?? '');
    $name = ltrim($parts['path'], '/');

    // sslmode (se existir na query string)
    $query = [];
    if (!empty($parts['query'])) parse_str($parts['query'], $query);
    $sslmode = $query['sslmode'] ?? 'require';

    $dsn = sprintf(
        "pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;options='-c client_encoding=UTF8'",
        $host, $port, $name, $sslmode
    );

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

} catch (Throwable $e) {
    $db_error = $e->getMessage();
    if ($debug) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Erro de conexão (Postgres): " . $db_error . "\n";
        exit;
    }
}
