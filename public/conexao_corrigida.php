<?php
/**
 * conexao_corrigida.php — Conexão com banco de dados CORRIGIDA
 */

// Verificar se estamos em ambiente local
if (!getenv("DATABASE_URL") || getenv("DATABASE_URL") === "") {
    // Configuração local simples
    $host = 'localhost';
    $port = '5432';
    $dbname = 'painel_smile';
    $user = 'tiagozucarelli';
    $password = '';
    
    try {
        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=disable";
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $GLOBALS['pdo'] = $pdo;
        return;
    } catch (Exception $e) {
        error_log("Erro de conexão local: " . $e->getMessage());
        die("Erro de conexão com banco de dados local: " . $e->getMessage());
    }
}

// Se chegou aqui, usar configuração de produção (Railway)
$debug = getenv('APP_DEBUG') === '1';
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');
error_reporting($debug ? E_ALL : (E_ALL & ~E_NOTICE));

$pdo = null;
$db_error = '';

try {
    $dbUrl = getenv('DATABASE_URL');
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

    // DSN final com search_path forçado
    $dsn = 'pgsql:host='.$host.';port='.$port.';dbname='.$name.';sslmode='.$sslmode.";options='-c client_encoding=UTF8 -c search_path=smilee12_painel_smile,public'";

    // Conexão
    $pdo = new PDO($dsn, $user, $pass, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ));
    
    // Forçar search_path imediatamente após conexão
    $pdo->exec('SET search_path TO smilee12_painel_smile, public');
    
    // Verificar se o search_path foi aplicado corretamente
    $stmt = $pdo->query("SHOW search_path");
    $current_path = $stmt->fetchColumn();
    
    if (strpos($current_path, 'smilee12_painel_smile') === false) {
        // Se não funcionou, tentar novamente com comando mais específico
        $pdo->exec('SET search_path = smilee12_painel_smile, public');
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
?>
