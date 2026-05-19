<?php
/**
 * portal_dj_login.php
 * Compatibilidade: o Portal DJ agora é público.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$redirect_raw = trim((string)($_GET['redirect'] ?? ''));
$redirect_target = '';
$starts_ok = (substr($redirect_raw, 0, 9) === '/index.php' || substr($redirect_raw, 0, 8) === 'index.php');
if ($redirect_raw !== '' && $starts_ok && strpos($redirect_raw, 'page=portal_dj') !== false) {
    $redirect_target = $redirect_raw;
}

header('Location: ' . ($redirect_target !== '' ? $redirect_target : 'index.php?page=portal_dj'));
exit;
