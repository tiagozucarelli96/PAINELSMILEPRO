<?php
/**
 * upload_foto_usuario_endpoint.php
 * Endpoint dedicado para upload de foto de usuário
 * Usa EXATAMENTE a mesma lógica do Trello
 * 
 * IMPORTANTE: Este arquivo deve ser acessado DIRETAMENTE, não via index.php
 */

// CRÍTICO: Desabilitar TODOS os outputs possíveis
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0); // Desabilitar completamente para evitar warnings

// Limpar QUALQUER output buffer anterior
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Iniciar sessão se necessário
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CRÍTICO: NÃO iniciar buffer aqui - vamos limpar tudo antes de enviar
// ob_start() pode capturar output indesejado

// Carregar conexão ANTES de upload_magalu (ele pode precisar)
if (!isset($GLOBALS['pdo'])) {
    require_once __DIR__ . '/conexao.php';
}

// Verificar autenticação (compatível com múltiplas variáveis de sessão)
$usuario_id_session = $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? null;
$logado = $_SESSION['logado'] ?? $_SESSION['logged_in'] ?? null;

// Aceitar se apenas 'logado' estiver definido (compatível com login.php)
if (empty($usuario_id_session) && isset($_SESSION['logado']) && $_SESSION['logado'] == 1 && isset($_SESSION['id'])) {
    $usuario_id_session = $_SESSION['id'];
}

// CRÍTICO: Verificar autenticação ANTES de incluir upload_magalu.php
// upload_magalu.php também verifica sessão e pode fazer exit com JSON
// Mas queremos controlar isso, então verificamos aqui primeiro
if (empty($usuario_id_session) || !$logado || (int)$logado !== 1) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_clean();
    header_remove();
    header('Content-Type: application/json', true);
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'error' => 'Não autenticado. Por favor, recarregue a página e faça login novamente.'
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// IMPORTANTE: upload_magalu.php verifica sessão no início e pode fazer exit
// Mas já verificamos acima, então vamos temporariamente desabilitar a verificação
// usando uma flag ou simplesmente ignorando o exit dele
// Na verdade, melhor: vamos incluir e se ele fizer exit, não chegaremos aqui
// Mas como já verificamos sessão, ele não deve fazer exit

// Aceitar apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_clean();
    header_remove();
    header('Content-Type: application/json', true);
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Método não permitido'
    ], JSON_UNESCAPED_SLASHES);
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
    
    // Logs apenas para debug (não afetam output)
    @error_log("=== UPLOAD FOTO ENDPOINT: INÍCIO ===");
    @error_log("Arquivo recebido: " . ($_FILES['foto']['name'] ?? 'N/A'));
    @error_log("Tamanho: " . ($_FILES['foto']['size'] ?? 0) . " bytes");
    @error_log("Tipo: " . ($_FILES['foto']['type'] ?? 'N/A'));
    @error_log("Error code: " . ($_FILES['foto']['error'] ?? 'N/A'));
    
    // Verificar se arquivo temporário existe
    if (!isset($_FILES['foto']['tmp_name']) || !is_uploaded_file($_FILES['foto']['tmp_name'])) {
        @error_log("ERRO: Arquivo temporário inválido ou não encontrado");
        throw new Exception('Arquivo temporário inválido');
    }
    
    // Usar EXATAMENTE a mesma lógica do Trello
    // IMPORTANTE: upload_magalu.php tem verificação de sessão no início
    // Mas já verificamos sessão acima, então ele não deve fazer exit
    // Se fizer exit, nosso código não chegará aqui (o que é ok)
    require_once __DIR__ . '/upload_magalu.php';
    
    // Verificar se a classe existe após require
    // Se upload_magalu.php fez exit, não chegaremos aqui
    if (!class_exists('MagaluUpload')) {
        @error_log("❌ ERRO: Classe MagaluUpload não encontrada após require!");
        @error_log("Possível causa: upload_magalu.php fez exit antes de definir a classe");
        throw new Exception('Classe MagaluUpload não encontrada. Verifique logs do servidor.');
    }
    
    try {
        $uploader = new MagaluUpload();
        @error_log("✅ MagaluUpload instanciado com sucesso");
        
        // Upload usando prefix 'usuarios' (mesmo padrão do Trello que usa 'demandas_trello')
        $upload_result = $uploader->upload($_FILES['foto'], 'usuarios');
        
        @error_log("✅ Upload resultado recebido: " . json_encode($upload_result));
        
        // Validar que o upload foi bem-sucedido (mesmo do Trello)
        if (empty($upload_result['url'])) {
            @error_log("❌ ERRO: Upload falhou - URL não retornada");
            @error_log("Resultado completo: " . json_encode($upload_result));
            throw new Exception('Upload falhou: URL não foi retornada');
        }
        
        if (empty($upload_result['chave_storage'])) {
            @error_log("❌ ERRO: Upload falhou - chave_storage não retornada");
            throw new Exception('Upload falhou: chave de armazenamento não foi retornada');
        }
        
        @error_log("✅✅✅ Upload bem-sucedido! URL: " . $upload_result['url']);
        @error_log("Chave storage: " . $upload_result['chave_storage']);
        
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
        
        // CRÍTICO: Limpar TODOS os output buffers ANTES de qualquer header
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Garantir que não há output antes do JSON
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // CRÍTICO: Limpar qualquer conteúdo que possa ter sido gerado
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        // CRÍTICO: Remover TODOS os headers anteriores
        if (function_exists('header_remove')) {
            header_remove();
        }
        
        // Garantir que headers estão corretos (sem charset)
        header('Content-Type: application/json', true, 200);
        header('X-Content-Type-Options: nosniff', true);
        header('Cache-Control: no-cache, no-store, must-revalidate', true);
        header('Pragma: no-cache', true);
        header('Expires: 0', true);
        
        // Enviar JSON e fazer exit imediatamente
        // Usar flags para garantir JSON válido
        $json = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            @error_log("❌ ERRO ao gerar JSON: " . json_last_error_msg());
            // Limpar antes de enviar erro
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            if (function_exists('header_remove')) {
                header_remove();
            }
            header('Content-Type: application/json', true, 500);
            $errorJson = json_encode(['success' => false, 'error' => 'Erro ao gerar resposta JSON'], JSON_UNESCAPED_SLASHES);
            echo $errorJson;
            flush();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            exit;
        }
        
        // Enviar JSON puro - SEM espaço antes, SEM BOM, APENAS JSON
        echo $json;
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        exit;
        
    } catch (Exception $e) {
        @error_log("❌ ERRO no MagaluUpload: " . $e->getMessage());
        @error_log("Stack trace: " . $e->getTraceAsString());
        throw $e;
    }
    
} catch (Exception $e) {
    @error_log("❌❌❌ ERRO CRÍTICO no upload de foto: " . $e->getMessage());
    @error_log("Stack trace: " . $e->getTraceAsString());
    @error_log("_FILES: " . print_r($_FILES, true));
    @error_log("_POST: " . print_r($_POST, true));
    @error_log("_SESSION: " . print_r(['user_id' => ($_SESSION['user_id'] ?? 'N/A'), 'logado' => ($_SESSION['logado'] ?? 'N/A')], true));
    
    // CRÍTICO: Limpar TODOS os output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    // Garantir que headers estão corretos
    if (function_exists('header_remove')) {
        header_remove();
    }
    header('Content-Type: application/json', true, 500);
    header('X-Content-Type-Options: nosniff', true);
    
    // Enviar JSON de erro e fazer exit imediatamente
    $errorJson = json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo $errorJson;
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit;
}

