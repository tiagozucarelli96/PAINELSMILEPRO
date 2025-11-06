<?php
/**
 * Upload de foto de usuário para Magalu Storage
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/magalu_integration_helper.php';

header('Content-Type: application/json; charset=utf-8');

// Verificar permissão
if (empty($_SESSION['logado']) || empty($_SESSION['perm_configuracoes'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

// Verificar se é upload de arquivo
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['foto'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Nenhum arquivo enviado']);
    exit;
}

$file = $_FILES['foto'];

// Validar arquivo
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Erro ao fazer upload: ' . $file['error']]);
    exit;
}

// Obter ID do usuário (se estiver editando) ou usar 0 (será atualizado depois)
$usuario_id = isset($_POST['usuario_id']) ? (int)$_POST['usuario_id'] : 0;
if ($usuario_id === 0) {
    $usuario_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;
}

try {
    // Usar Magalu Integration Helper
    $magaluHelper = new MagaluIntegrationHelper();
    
    $resultado = $magaluHelper->uploadFotoUsuario($file, $usuario_id);
    
    if ($resultado['sucesso']) {
        echo json_encode([
            'success' => true,
            'path' => $resultado['url'],
            'url' => $resultado['url'],
            'key' => $resultado['key'] ?? '',
            'provider' => 'Magalu Object Storage'
        ], JSON_UNESCAPED_SLASHES);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $resultado['erro'] ?? 'Erro ao fazer upload'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro: ' . $e->getMessage()
    ]);
}
