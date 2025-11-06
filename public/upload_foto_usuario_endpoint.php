<?php
/**
 * upload_foto_usuario_endpoint.php
 * Endpoint dedicado para upload de foto de usuário
 * Usa EXATAMENTE a mesma lógica do Trello
 */

ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar autenticação
$usuario_id_session = $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? null;
$logado = $_SESSION['logado'] ?? $_SESSION['logged_in'] ?? null;

if (empty($usuario_id_session) || !$logado || (int)$logado !== 1) {
    http_response_code(401);
    header('Content-Type: application/json');
    ob_clean();
    echo json_encode([
        'success' => false, 
        'error' => 'Não autenticado'
    ]);
    exit;
}

// Aceitar apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    ob_clean();
    echo json_encode([
        'success' => false,
        'error' => 'Método não permitido'
    ]);
    exit;
}

ob_clean();
header('Content-Type: application/json; charset=utf-8');

try {
    // Verificar se arquivo foi enviado
    if (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
        $error = $_FILES['foto']['error'] ?? 'Arquivo não fornecido';
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'Arquivo excede o tamanho máximo permitido',
            UPLOAD_ERR_FORM_SIZE => 'Arquivo excede o tamanho máximo do formulário',
            UPLOAD_ERR_PARTIAL => 'Upload parcial do arquivo',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi enviado',
            UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária não encontrada',
            UPLOAD_ERR_CANT_WRITE => 'Erro ao escrever arquivo',
            UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensão'
        ];
        
        throw new Exception($errorMessages[$error] ?? 'Erro no upload: ' . $error);
    }
    
    error_log("=== UPLOAD FOTO ENDPOINT: INÍCIO ===");
    error_log("Arquivo recebido: " . $_FILES['foto']['name']);
    error_log("Tamanho: " . $_FILES['foto']['size'] . " bytes");
    error_log("Tipo: " . $_FILES['foto']['type']);
    
    // Usar EXATAMENTE a mesma lógica do Trello
    require_once __DIR__ . '/upload_magalu.php';
    
    $uploader = new MagaluUpload();
    error_log("MagaluUpload instanciado");
    
    // Upload usando prefix 'usuarios' (mesmo padrão do Trello que usa 'demandas_trello')
    $upload_result = $uploader->upload($_FILES['foto'], 'usuarios');
    
    error_log("Upload resultado: " . json_encode($upload_result));
    
    // Validar que o upload foi bem-sucedido (mesmo do Trello)
    if (empty($upload_result['url'])) {
        error_log("ERRO: Upload falhou - URL não retornada");
        throw new Exception('Upload falhou: URL não foi retornada');
    }
    
    if (empty($upload_result['chave_storage'])) {
        error_log("ERRO: Upload falhou - chave_storage não retornada");
        throw new Exception('Upload falhou: chave de armazenamento não foi retornada');
    }
    
    error_log("✅ Upload bem-sucedido!");
    error_log("URL: " . $upload_result['url']);
    error_log("Chave: " . $upload_result['chave_storage']);
    
    // Retornar resultado (mesmo formato do Trello)
    echo json_encode([
        'success' => true,
        'message' => 'Foto enviada com sucesso',
        'data' => [
            'url' => $upload_result['url'],
            'chave_storage' => $upload_result['chave_storage'],
            'nome_original' => $upload_result['nome_original'],
            'mime_type' => $upload_result['mime_type'],
            'tamanho_bytes' => $upload_result['tamanho_bytes']
        ]
    ]);
    exit;
    
} catch (Exception $e) {
    error_log("ERRO no upload de foto: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}

