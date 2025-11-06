<?php
/**
 * upload_foto_usuario_endpoint.php
 * Endpoint dedicado para upload de foto de usuário
 * Usa EXATAMENTE a mesma lógica do Trello
 * 
 * IMPORTANTE: Este arquivo deve ser acessado DIRETAMENTE, não via index.php
 */

// Limpar qualquer output buffer anterior
while (ob_get_level() > 0) {
    ob_end_clean();
}

ini_set('display_errors', 0);
error_reporting(E_ALL);

// Iniciar sessão se necessário
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Definir header JSON ANTES de qualquer output
header('Content-Type: application/json; charset=utf-8');

// Verificar autenticação (compatível com múltiplas variáveis de sessão)
$usuario_id_session = $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? null;
$logado = $_SESSION['logado'] ?? $_SESSION['logged_in'] ?? null;

// Aceitar se apenas 'logado' estiver definido (compatível com login.php)
if (empty($usuario_id_session) && isset($_SESSION['logado']) && $_SESSION['logado'] == 1 && isset($_SESSION['id'])) {
    $usuario_id_session = $_SESSION['id'];
}

if (empty($usuario_id_session) || !$logado || (int)$logado !== 1) {
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'error' => 'Não autenticado. Por favor, recarregue a página e faça login novamente.'
    ]);
    exit;
}

// Aceitar apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Método não permitido'
    ]);
    exit;
}

try {
    // Verificar se arquivo foi enviado
    if (!isset($_FILES['foto'])) {
        throw new Exception('Nenhum arquivo foi enviado');
    }
    
    if ($_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
        $error = $_FILES['foto']['error'];
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'Arquivo excede o tamanho máximo permitido',
            UPLOAD_ERR_FORM_SIZE => 'Arquivo excede o tamanho máximo do formulário',
            UPLOAD_ERR_PARTIAL => 'Upload parcial do arquivo',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi enviado',
            UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária não encontrada',
            UPLOAD_ERR_CANT_WRITE => 'Erro ao escrever arquivo',
            UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensão'
        ];
        
        throw new Exception($errorMessages[$error] ?? 'Erro no upload: código ' . $error);
    }
    
    error_log("=== UPLOAD FOTO ENDPOINT: INÍCIO ===");
    error_log("Arquivo recebido: " . ($_FILES['foto']['name'] ?? 'N/A'));
    error_log("Tamanho: " . ($_FILES['foto']['size'] ?? 0) . " bytes");
    error_log("Tipo: " . ($_FILES['foto']['type'] ?? 'N/A'));
    error_log("Error code: " . ($_FILES['foto']['error'] ?? 'N/A'));
    
    // Verificar se arquivo temporário existe
    if (!isset($_FILES['foto']['tmp_name']) || !is_uploaded_file($_FILES['foto']['tmp_name'])) {
        error_log("ERRO: Arquivo temporário inválido ou não encontrado");
        throw new Exception('Arquivo temporário inválido');
    }
    
    // Usar EXATAMENTE a mesma lógica do Trello
    require_once __DIR__ . '/upload_magalu.php';
    
    try {
        $uploader = new MagaluUpload();
        error_log("✅ MagaluUpload instanciado com sucesso");
        
        // Upload usando prefix 'usuarios' (mesmo padrão do Trello que usa 'demandas_trello')
        $upload_result = $uploader->upload($_FILES['foto'], 'usuarios');
        
        error_log("✅ Upload resultado recebido: " . json_encode($upload_result));
        
        // Validar que o upload foi bem-sucedido (mesmo do Trello)
        if (empty($upload_result['url'])) {
            error_log("❌ ERRO: Upload falhou - URL não retornada");
            error_log("Resultado completo: " . json_encode($upload_result));
            throw new Exception('Upload falhou: URL não foi retornada');
        }
        
        if (empty($upload_result['chave_storage'])) {
            error_log("❌ ERRO: Upload falhou - chave_storage não retornada");
            throw new Exception('Upload falhou: chave de armazenamento não foi retornada');
        }
        
        error_log("✅✅✅ Upload bem-sucedido! URL: " . $upload_result['url']);
        error_log("Chave storage: " . $upload_result['chave_storage']);
        
        // Retornar resultado (mesmo formato do Trello)
        $response = [
            'success' => true,
            'message' => 'Foto enviada com sucesso',
            'data' => [
                'url' => $upload_result['url'],
                'chave_storage' => $upload_result['chave_storage'],
                'nome_original' => $upload_result['nome_original'],
                'mime_type' => $upload_result['mime_type'],
                'tamanho_bytes' => $upload_result['tamanho_bytes']
            ]
        ];
        
        error_log("✅ Resposta JSON sendo enviada: " . json_encode($response));
        echo json_encode($response);
        exit;
        
    } catch (Exception $e) {
        error_log("❌ ERRO no MagaluUpload: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("❌❌❌ ERRO CRÍTICO no upload de foto: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    error_log("_FILES: " . print_r($_FILES, true));
    error_log("_POST: " . print_r($_POST, true));
    error_log("_SESSION: " . print_r(['user_id' => ($_SESSION['user_id'] ?? 'N/A'), 'logado' => ($_SESSION['logado'] ?? 'N/A')], true));
    
    // Garantir que não há output antes do JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}

