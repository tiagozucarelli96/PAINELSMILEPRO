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

$cacheContext = implode(':', [
    !empty($_SESSION['perm_superadmin']) ? '1' : '0',
    !empty($_SESSION['perm_logistico']) ? '1' : '0',
    !empty($_SESSION['perm_logistico_divergencias']) ? '1' : '0',
    (string)($_SESSION['unidade_scope'] ?? 'todas'),
    (string)((int)($_SESSION['unidade_id'] ?? 0)),
]);
$cachePath = sys_get_temp_dir() . '/dashboard_logistica_alertas_' . md5($cacheContext) . '.html';
$cacheTtl = 120;
$cacheMtime = @filemtime($cachePath);
if ($cacheMtime !== false && (time() - $cacheMtime) < $cacheTtl) {
    $cachedHtml = @file_get_contents($cachePath);
    if (is_string($cachedHtml)) {
        echo $cachedHtml;
        exit;
    }
}

try {
    $html = build_logistica_notifications_bar($GLOBALS['pdo']);
    @file_put_contents($cachePath, $html, LOCK_EX);
    echo $html;
} catch (Throwable $e) {
    error_log('[DASHBOARD_LOGISTICA_ALERTAS] ' . $e->getMessage());
}
