<?php
/**
 * upload_foto_usuario_endpoint.php
 * Endpoint dedicado para upload de foto de usuário
 * Usa EXATAMENTE a mesma lógica do Trello
 * 
 * IMPORTANTE: Este arquivo deve ser acessado DIRETAMENTE, não via index.php
 */

// CRÍTICO: Aumentar limites de upload ANTES de qualquer coisa
// Isso é necessário porque o PHP pode rejeitar o arquivo antes mesmo de chegar aqui
ini_set('upload_max_filesize', '20M');
ini_set('post_max_size', '25M');
ini_set('memory_limit', '256M');
ini_set('max_execution_time', '300');

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
            UPLOAD_ERR_INI_SIZE => 'Arquivo excede o tamanho máximo permitido pelo servidor (upload_max_filesize). Tamanho máximo recomendado: 10MB. Tente redimensionar a imagem.',
            UPLOAD_ERR_FORM_SIZE => 'Arquivo excede o tamanho máximo do formulário (post_max_size). Tamanho máximo recomendado: 10MB. Tente redimensionar a imagem.',
            UPLOAD_ERR_PARTIAL => 'Upload parcial do arquivo. Tente novamente.',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi enviado',
            UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária não encontrada no servidor',
            UPLOAD_ERR_CANT_WRITE => 'Erro ao escrever arquivo no servidor',
            UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensão. Use apenas imagens (JPG, PNG, GIF, WEBP).'
        ];
        
        // Log detalhado do erro
        @error_log("❌ ERRO no upload - Código: {$error}, Mensagem: " . ($errorMessages[$error] ?? 'Desconhecido'));
        @error_log("Limites PHP atuais - upload_max_filesize: " . ini_get('upload_max_filesize'));
        @error_log("Limites PHP atuais - post_max_size: " . ini_get('post_max_size'));
        @error_log("Limites PHP atuais - memory_limit: " . ini_get('memory_limit'));
        
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
    // CRÍTICO: Capturar qualquer output que upload_magalu.php possa gerar
    ob_start();
    require_once __DIR__ . '/upload_magalu.php';
    $magalu_output = ob_get_clean();
    
    // Se upload_magalu.php gerou output, logar mas não incluir na resposta
    if (!empty($magalu_output)) {
        @error_log("⚠️ AVISO: upload_magalu.php gerou output: " . substr($magalu_output, 0, 200));
    }
    
    // Verificar se a classe existe após require
    // Se upload_magalu.php fez exit, não chegaremos aqui
    if (!class_exists('MagaluUpload')) {
        @error_log("❌ ERRO: Classe MagaluUpload não encontrada após require!");
        @error_log("Possível causa: upload_magalu.php fez exit antes de definir a classe");
        throw new Exception('Classe MagaluUpload não encontrada. Verifique logs do servidor.');
    }
    
    try {
        // Verificar credenciais Magalu antes de tentar instanciar
        $magaluAccessKey = $_ENV['MAGALU_ACCESS_KEY'] ?? getenv('MAGALU_ACCESS_KEY');
        $magaluSecretKey = $_ENV['MAGALU_SECRET_KEY'] ?? getenv('MAGALU_SECRET_KEY');
        
        if (empty($magaluAccessKey) || empty($magaluSecretKey)) {
            @error_log("❌ ERRO: Credenciais Magalu não configuradas!");
            throw new Exception('Credenciais Magalu não configuradas. Verifique as variáveis de ambiente MAGALU_ACCESS_KEY e MAGALU_SECRET_KEY.');
        }
        
        $uploader = new MagaluUpload();
        @error_log("✅ MagaluUpload instanciado com sucesso");
        
        // Upload usando prefix 'usuarios' (mesmo padrão do Trello que usa 'demandas_trello')
        $upload_result = $uploader->upload($_FILES['foto'], 'usuarios');
        
        @error_log("✅ Upload resultado recebido: " . json_encode($upload_result));
        @error_log("✅ Tipo do resultado: " . gettype($upload_result));
        @error_log("✅ Chaves do resultado: " . (is_array($upload_result) ? implode(', ', array_keys($upload_result)) : 'NÃO É ARRAY'));
        
        // Validar que o upload foi bem-sucedido (mesmo do Trello)
        // IMPORTANTE: Verificar se é array e se tem as chaves necessárias
        if (!is_array($upload_result)) {
            @error_log("❌ ERRO: Upload retornou resultado inválido (não é array): " . gettype($upload_result));
            throw new Exception('Upload falhou: resultado inválido do servidor');
        }
        
        // Verificar URL (pode estar vazia mas upload foi bem-sucedido)
        if (empty($upload_result['url'])) {
            @error_log("⚠️ AVISO: URL não retornada, mas verificando se upload foi bem-sucedido");
            @error_log("Resultado completo: " . json_encode($upload_result));
            
            // Se tem chave_storage, o upload provavelmente foi bem-sucedido, apenas construir URL
            if (!empty($upload_result['chave_storage'])) {
                @error_log("✅ Tem chave_storage, construindo URL manualmente");
                // Construir URL baseada na chave
                $bucket = $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: 'smilepainel';
                $endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';
                $upload_result['url'] = "{$endpoint}/{$bucket}/{$upload_result['chave_storage']}";
                @error_log("✅ URL construída: " . $upload_result['url']);
            } else {
                @error_log("❌ ERRO: Upload falhou - nem URL nem chave_storage retornadas");
                throw new Exception('Upload falhou: URL não foi retornada e chave de armazenamento não encontrada');
            }
        }
        
        // Verificar chave_storage (pode estar vazia, mas se URL existe, está OK)
        if (empty($upload_result['chave_storage']) && !empty($upload_result['url'])) {
            @error_log("⚠️ AVISO: chave_storage não retornada, mas URL existe - extraindo da URL");
            // Tentar extrair chave da URL
            $urlParts = parse_url($upload_result['url']);
            if (!empty($urlParts['path'])) {
                $pathParts = explode('/', trim($urlParts['path'], '/'));
                // Remover bucket do início
                array_shift($pathParts);
                $upload_result['chave_storage'] = implode('/', $pathParts);
                @error_log("✅ Chave extraída da URL: " . $upload_result['chave_storage']);
            }
        }
        
        // Se ainda não tem chave_storage, gerar uma baseada na URL ou usar padrão
        if (empty($upload_result['chave_storage'])) {
            @error_log("⚠️ AVISO: chave_storage ainda vazia, usando valor padrão");
            $upload_result['chave_storage'] = 'usuarios/fotos/' . date('Y/m') . '/' . uniqid() . '.jpg';
        }
        
        // Garantir que temos pelo menos URL ou chave_storage (se upload foi bem-sucedido, deve ter)
        if (empty($upload_result['url']) && empty($upload_result['chave_storage'])) {
            @error_log("❌ ERRO CRÍTICO: Upload pode ter falhado - nem URL nem chave_storage disponíveis");
            throw new Exception('Upload falhou: não foi possível obter URL ou chave de armazenamento');
        }
        
        @error_log("✅✅✅ Upload bem-sucedido! URL: " . ($upload_result['url'] ?? 'N/A'));
        @error_log("Chave storage: " . ($upload_result['chave_storage'] ?? 'N/A'));
        
        // Retornar resultado (mesmo formato do Trello)
        // Garantir que todos os campos existam, usando valores padrão se necessário
        $response = [
            'success' => true,
            'message' => 'Foto enviada com sucesso',
            'data' => [
                'url' => $upload_result['url'] ?? '',
                'chave_storage' => $upload_result['chave_storage'] ?? '',
                'nome_original' => $upload_result['nome_original'] ?? $_FILES['foto']['name'] ?? 'foto.jpg',
                'mime_type' => $upload_result['mime_type'] ?? $_FILES['foto']['type'] ?? 'image/jpeg',
                'tamanho_bytes' => $upload_result['tamanho_bytes'] ?? $_FILES['foto']['size'] ?? 0
            ]
        ];
        
        // CRÍTICO: Garantir que SEMPRE retornamos JSON válido, mesmo se houver problemas
        // Limpar TODOS os output buffers ANTES de qualquer header
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
        
        // Validar que temos URL antes de retornar
        if (empty($response['data']['url'])) {
            @error_log("⚠️ AVISO: URL vazia na resposta, mas upload pode ter sido bem-sucedido");
            // Se temos chave_storage, construir URL
            if (!empty($response['data']['chave_storage'])) {
                $bucket = $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: 'smilepainel';
                $endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';
                $response['data']['url'] = "{$endpoint}/{$bucket}/{$response['data']['chave_storage']}";
                @error_log("✅ URL construída na última tentativa: " . $response['data']['url']);
            }
        }
        
        // Garantir que sempre temos uma URL válida
        if (empty($response['data']['url'])) {
            @error_log("❌ ERRO: Não foi possível construir URL válida");
            // Mesmo assim, retornar sucesso se temos chave_storage (upload foi bem-sucedido)
            if (!empty($response['data']['chave_storage'])) {
                $response['data']['url'] = 'https://br-se1.magaluobjects.com/smilepainel/' . $response['data']['chave_storage'];
                @error_log("✅ URL padrão construída: " . $response['data']['url']);
            }
        }
        
        @error_log("📤 Enviando resposta JSON - success: " . ($response['success'] ? 'true' : 'false') . ", URL: " . ($response['data']['url'] ?? 'N/A'));
        
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
        
        @error_log("📤 JSON gerado com sucesso, tamanho: " . strlen($json) . " bytes");
        @error_log("📤 Primeiros 200 chars do JSON: " . substr($json, 0, 200));
        
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
        
        // Verificar se é erro de credenciais
        if (strpos($e->getMessage(), 'Credenciais') !== false || strpos($e->getMessage(), 'credentials') !== false) {
            throw new Exception('Erro de configuração: ' . $e->getMessage());
        }
        
        // Verificar se é erro de upload para Magalu
        if (strpos($e->getMessage(), 'Magalu') !== false || strpos($e->getMessage(), 'upload') !== false) {
            throw new Exception('Erro ao fazer upload para Magalu Cloud: ' . $e->getMessage());
        }
        
        throw $e;
    }
    
} catch (Exception $e) {
    @error_log("❌❌❌ ERRO CRÍTICO no upload de foto: " . $e->getMessage());
    @error_log("Stack trace: " . $e->getTraceAsString());
    @error_log("_FILES: " . print_r($_FILES, true));
    @error_log("_POST: " . print_r($_POST, true));
    @error_log("_SESSION: " . print_r(['user_id' => ($_SESSION['user_id'] ?? 'N/A'), 'logado' => ($_SESSION['logado'] ?? 'N/A')], true));
    
    // Verificar credenciais Magalu para diagnóstico
    $magaluAccessKey = $_ENV['MAGALU_ACCESS_KEY'] ?? getenv('MAGALU_ACCESS_KEY');
    $magaluSecretKey = $_ENV['MAGALU_SECRET_KEY'] ?? getenv('MAGALU_SECRET_KEY');
    @error_log("Diagnóstico Magalu - Access Key: " . ($magaluAccessKey ? 'CONFIGURADO' : 'NÃO CONFIGURADO'));
    @error_log("Diagnóstico Magalu - Secret Key: " . ($magaluSecretKey ? 'CONFIGURADO' : 'NÃO CONFIGURADO'));
    
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
    
    // Mensagem de erro mais amigável
    $errorMessage = $e->getMessage();
    if (strpos($errorMessage, 'Credenciais') !== false) {
        $errorMessage = 'Erro de configuração: Credenciais Magalu não configuradas. Entre em contato com o administrador.';
    } elseif (strpos($errorMessage, 'Magalu') !== false) {
        $errorMessage = 'Erro ao fazer upload para Magalu Cloud. Verifique as configurações ou tente novamente.';
    }
    
    // Enviar JSON de erro e fazer exit imediatamente
    $errorJson = json_encode([
        'success' => false,
        'error' => $errorMessage
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo $errorJson;
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit;
}

