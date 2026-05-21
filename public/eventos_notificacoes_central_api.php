<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_notificacoes_central_helper.php';

header('Content-Type: application/json; charset=utf-8');

$usuarioId = (int)($_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 0);
$canUse = !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_notificacoes_eventos']);
if (empty($_SESSION['logado']) || $usuarioId <= 0 || !$canUse) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sem permissão.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));
if ($action === 'ignore') {
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Notificação inválida.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => eventosNotificacoesCentralIgnorar($pdo, $id, $usuarioId)], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Ação inválida.'], JSON_UNESCAPED_UNICODE);

