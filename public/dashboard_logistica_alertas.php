<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if (empty($_SESSION['logado'])) {
    http_response_code(401);
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/notifications_bar.php';

$canView = !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_logistico']) || !empty($_SESSION['perm_logistico_divergencias']);
if (!$canView) {
    exit;
}

try {
    echo build_logistica_notifications_bar($GLOBALS['pdo']);
} catch (Throwable $e) {
    error_log('[DASHBOARD_LOGISTICA_ALERTAS] ' . $e->getMessage());
}
