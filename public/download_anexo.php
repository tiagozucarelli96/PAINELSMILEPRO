<?php
// download_anexo.php
// Download protegido de anexos

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_anexos_helper.php';
require_once __DIR__ . '/core/lc_permissions_stub.php';

$anexo_id = intval($_GET['id'] ?? 0);
$token = $_GET['t'] ?? null;

if (!$anexo_id) {
    http_response_code(400);
    die('ID do anexo não fornecido');
}

try {
    $anexos_manager = new LcAnexosManager($pdo);
    
    // Verificar permissão
    $usuario_id = $_SESSION['usuario_id'] ?? null;
    $tem_permissao = false;
    
    if ($token) {
        // Portal público - verificar token
        $tem_permissao = $anexos_manager->verificarPermissaoDownload($anexo_id, null, $token);
    } elseif ($usuario_id) {
        // Usuário logado - verificar permissão
        $tem_permissao = $anexos_manager->verificarPermissaoDownload($anexo_id, $usuario_id);
    }
    
    if (!$tem_permissao) {
        http_response_code(403);
        die('Acesso negado');
    }
    
    // Buscar dados do anexo
    $stmt = $pdo->prepare("
        SELECT a.nome_original, a.caminho_arquivo, a.tipo_mime, a.tamanho_bytes,
               s.id as solicitacao_id, s.status
        FROM lc_anexos_pagamentos a
        JOIN lc_solicitacoes_pagamento s ON s.id = a.solicitacao_id
        WHERE a.id = ?
    ");
    $stmt->execute([$anexo_id]);
    $anexo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$anexo) {
        http_response_code(404);
        die('Anexo não encontrado');
    }
    
    // Verificar se arquivo existe
    if (!file_exists($anexo['caminho_arquivo'])) {
        http_response_code(404);
        die('Arquivo não encontrado no servidor');
    }
    
    // Registrar download
    $anexos_manager->registrarDownload($anexo_id, $usuario_id);
    
    // Configurar headers para download
    $nome_arquivo = $anexo['nome_original'];
    $tamanho = $anexo['tamanho_bytes'];
    $tipo_mime = $anexo['tipo_mime'];
    
    // Headers de segurança
    header('Content-Type: ' . $tipo_mime);
    header('Content-Disposition: attachment; filename="' . addslashes($nome_arquivo) . '"');
    header('Content-Length: ' . $tamanho);
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Headers de segurança adicionais
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    
    // Limpar buffer de saída
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Enviar arquivo
    readfile($anexo['caminho_arquivo']);
    exit;
    
} catch (Exception $e) {
    error_log("Erro no download: " . $e->getMessage());
    http_response_code(500);
    die('Erro interno do servidor');
}
?>
