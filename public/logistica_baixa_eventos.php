<?php
// logistica_baixa_eventos.php — Endpoint manual/cron para baixa automática
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/logistica_baixa_helper.php';

$token = $_GET['token'] ?? '';
$env_token = getenv('LOGISTICA_CRON_TOKEN') ?: '';

$is_token_valid = ($env_token !== '' && hash_equals($env_token, $token));
$is_logged = !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_logistico_divergencias']);

if (!$is_token_valid && !$is_logged) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acesso negado.']);
    exit;
}

$scope = $_SESSION['unidade_scope'] ?? 'todas';
$unit_id = (int)($_SESSION['unidade_id'] ?? 0);
$filter_unit = (!$is_token_valid && $scope === 'unidade' && $unit_id > 0);
$user_id = (int)($_SESSION['id'] ?? 0);

$summary = logistica_processar_baixa_eventos($pdo, date('Y-m-d'), $filter_unit, $unit_id, $user_id);
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'summary' => $summary]);
