<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap_v2.php';
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: /login_v2.php');
exit;
