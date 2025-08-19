<?php
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

    // Normaliza prefixo e decompõe a URL
    $dbUrl = preg_replace('#^postgresql://#', 'postgres://', $dbUrl);
    $parts = parse_url($dbUrl);
    if (!$parts || empty($parts['host']) || empty($parts['path'])) {
        throw new RuntimeException('DATABASE_URL inválido.');
    }

    $host = $parts['host'];
    $port = isset($parts['port']) ? (int)$parts['port'] : 5432;
    $user = urldecode($parts['user'] ?? '');
    $pass = urldecode($parts['pass'] ?? '');
    $name = ltrim($parts['path'], '/');

    // Lê e saneia sslmode
    $query = [];
    if (!empty($parts['query'])) parse_str($parts['query'], $query);
    $sslmode = strtolower((string)($query['sslmode'] ?? 'require'));
    $sslmode = rtrim($sslmode, "_- ");
    $allowed = ['disable','allow','prefer','require','verif]()
