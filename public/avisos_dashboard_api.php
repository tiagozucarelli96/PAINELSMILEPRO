<?php

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['logado'])) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/administrativo_avisos_helper.php';

$pdo = $GLOBALS['pdo'];
adminAvisosEnsureSchema($pdo);

$usuarioId = adminAvisosUsuarioLogadoId();
$action = (string)($_GET['action'] ?? '');

try {
    ob_clean();

    if ($action === 'detalhes') {
        $avisoId = (int)($_GET['id'] ?? 0);
        if ($avisoId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Aviso inválido']);
            exit;
        }

        $aviso = adminAvisosBuscarDetalheDashboard($pdo, $avisoId, $usuarioId);
        if (!$aviso) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Aviso indisponível']);
            exit;
        }

        adminAvisosRegistrarVisualizacao($pdo, $avisoId, $usuarioId);

        echo json_encode([
            'success' => true,
            'data' => [
                'id' => (int)$aviso['id'],
                'assunto' => (string)$aviso['assunto'],
                'conteudo_html' => (string)$aviso['conteudo_html'],
                'visualizacao_unica' => adminAvisosBoolValue($aviso['visualizacao_unica'] ?? false),
                'expira_em' => $aviso['expira_em'],
                'criado_em' => $aviso['criado_em'],
                'criador_nome' => $aviso['criador_nome'] ?? null,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ação inválida']);
} catch (Throwable $e) {
    http_response_code(500);
    ob_clean();
    echo json_encode([
        'success' => false,
        'error' => 'Falha ao processar aviso',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
