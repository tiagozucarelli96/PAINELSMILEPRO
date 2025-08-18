<?php
// logout.php — encerra a sessão e redireciona

// se vier incluído por engano após alguma saída, limpa buffers
while (ob_get_level()) { ob_end_clean(); }

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

// evita voltar no histórico com sessão antiga
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// redireciona
header('Location: login.php');
exit;
