<?php
declare(strict_types=1);

require_once __DIR__ . '/env_bootstrap.php';

$debug = painel_is_debug();
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');
error_reporting($debug ? E_ALL : (E_ALL & ~E_NOTICE));

$pdo = null;
$db_error = '';
$databaseConfig = null;

try {
    $databaseConfig = painel_database_config_from_url();
    if ($databaseConfig !== null) {
        $pdo = painel_get_shared_pdo($databaseConfig);
        if (!$pdo instanceof PDO) {
            $pdo = new PDO($databaseConfig['dsn'], $databaseConfig['user'], $databaseConfig['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec($databaseConfig['search_path_sql']);
            painel_set_shared_pdo($pdo, $databaseConfig);
        }
    } else {
        if (painel_is_local_request()) {
            throw new RuntimeException('DATABASE_URL não definido no ambiente local. Configure o arquivo .env local antes de iniciar o servidor.');
        }

        $host = painel_env('DB_HOST', 'localhost');
        $port = painel_env('DB_PORT', '5432');
        $dbname = painel_env('DB_NAME', 'painel_smile');
        $user = painel_env('DB_USER', 'tiagozucarelli');
        $password = painel_env('DB_PASS', '');

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=disable',
            $host,
            $port,
            $dbname
        );

        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        painel_set_shared_pdo($pdo);
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

$GLOBALS['pdo'] = $pdo;
$GLOBALS['db_error'] = $db_error;
$GLOBALS['painel_database_config'] = $databaseConfig;
