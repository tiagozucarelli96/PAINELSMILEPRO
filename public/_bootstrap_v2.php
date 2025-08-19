<?php
// _bootstrap.php — inicialização comum
declare(strict_types=1);

// Debug controlado por env
$APP_DEBUG = getenv('APP_DEBUG') === '1';

ini_set('display_errors', $APP_DEBUG ? '1' : '0');
ini_set('display_startup_errors', $APP_DEBUG ? '1' : '0');
error_reporting($APP_DEBUG ? E_ALL : 0);

// Timezone (operação Brasil)
date_default_timezone_set('America/Sao_Paulo');

// Sessão segura
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    session_name('SMILESESS');
    session_start();
}

// Conexão (PostgreSQL via DATABASE_URL)
require_once __DIR__ . '/conexao.php';

// Helpers de auth
function is_logado(): bool {
    return isset($_SESSION['logado']) && $_SESSION['logado'] == 1 && ($_SESSION['status'] ?? '') === 'ativo';
}
function exige_login(): void {
    if (!is_logado()) {
        header('Location: /login.php');
        exit;
    }
}
