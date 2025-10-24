<?php
// Verificar se estamos em ambiente local
if (!getenv("DATABASE_URL") || getenv("DATABASE_URL") === "") {
    // Usar configuração local
    require_once __DIR__ . "/../config_local.php";
    $pdo = getLocalDbConnection();
    $GLOBALS["pdo"] = $pdo;
    return;
}

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
    $user = isset($parts['user']) ? urldecode($parts['user']) : '';
    $pass = isset($parts['pass']) ? urldecode($parts['pass']) : '';
    $name = ltrim($parts['path'], '/');

    // Query string (sslmode etc.)
    $query = [];
    if (!empty($parts['query'])) { parse_str($parts['query'], $query); }
    $sslmode = isset($query['sslmode']) ? strtolower((string)$query['sslmode']) : 'require';
    $sslmode = rtrim($sslmode, "_- ");
    $allowed = array('disable','allow','prefer','require','verify-ca','verify-full');
    if (!in_array($sslmode, $allowed, true)) {
        $sslmode = 'require';
    }

    // DSN final
    $dsn = 'pgsql:host='.$host.';port='.$port.';dbname='.$name.';sslmode='.$sslmode.";options='-c client_encoding=UTF8'";

    // Conexão
    $pdo = new PDO($dsn, $user, $pass, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ));

    // Ajusta o search_path para o schema importado
    $schema = getenv('DB_SCHEMA');
    if (!$schema) { $schema = 'smilee12_painel_smile'; }
    $schema = preg_replace('/[^a-zA-Z0-9_]/', '', $schema);
    if ($schema && $schema !== 'public') {
        $pdo->exec('SET search_path TO '.$schema.', public');
    }

} catch (Throwable $e) {
    $db_error = $e->getMessage();
    if ($debug) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Erro de conexão (Postgres): '.$db_error."\n";
        exit;
    }
}
