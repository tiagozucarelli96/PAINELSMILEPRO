<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/permissoes_boot.php';
require_once __DIR__ . '/me_eventos_pendencias_helper.php';
require_once __DIR__ . '/pacotes_evento_helper.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

$canUse = !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_comercial']);
if (empty($_SESSION['logado']) || !$canUse) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sem permissão.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'next'));

try {
    if ($action === 'next') {
        $pendencia = me_eventos_pendencias_buscar_proxima($pdo);
        $pacotes = pacotes_evento_listar($pdo, false);
        $tiposEvento = eventos_reuniao_tipos_evento_real_options($pdo, false);
        echo json_encode([
            'ok' => true,
            'pendencia' => $pendencia,
            'tipos_evento_real' => $tiposEvento,
            'pacotes' => array_map(static function (array $pkg): array {
                return [
                    'id' => (int)($pkg['id'] ?? 0),
                    'nome' => (string)($pkg['nome'] ?? ''),
                    'categoria' => (string)($pkg['categoria'] ?? 'Pacote'),
                ];
            }, $pacotes),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'complete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pendenciaId = (int)($_POST['pendencia_id'] ?? 0);
        $userId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
        $result = me_eventos_pendencias_concluir($pdo, $pendenciaId, $_POST, $userId);
        if (empty($result['ok'])) {
            http_response_code(422);
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ação inválida.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('me_eventos_pendencias_api: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro interno.'], JSON_UNESCAPED_UNICODE);
}
