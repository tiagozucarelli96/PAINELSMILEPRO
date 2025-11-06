<?php
// Router para servidor embutido do PHP (Railway)
// - Injeta conexao.php antes de servir qualquer .php
// - Deixa arquivos estáticos irem direto

// ============================================
// CRÍTICO: Verificar webhooks ANTES de qualquer coisa
// ============================================
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH) ?: '/';

// Se for webhook Asaas, servir DIRETAMENTE sem passar por nada
if (strpos($path, 'asaas_webhook.php') !== false || strpos($request_uri, 'asaas_webhook.php') !== false) {
    $webhook_file = realpath(__DIR__ . '/asaas_webhook.php');
    if ($webhook_file && is_file($webhook_file)) {
        // Servir webhook DIRETAMENTE, sem injeção de conexão ou qualquer coisa
        require $webhook_file;
        exit;
    }
}

$file = realpath(__DIR__ . $path);

// Se for arquivo estático existente (css, js, imagens), serve direto
if ($path !== '/' && $file && is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}

// Se for um .php existente, injeta conexao e inclui o arquivo
if ($path !== '/' && $file && is_file($file) && str_ends_with($file, '.php')) {
    // Arquivos de cron, webhook, endpoints e páginas públicas devem ser servidos diretamente SEM redirecionamento
    $public_files = ['comercial_degust_public.php', 'asaas_webhook.php', 'webhook_me_eventos.php', 'cron.php', 'upload_foto_usuario_endpoint.php'];
    
    // Verificação ESPECIAL para webhooks - SEM conexão automática, eles gerenciam sua própria conexão
    if (strpos($path, 'asaas_webhook.php') !== false || basename($file) === 'asaas_webhook.php') {
        // Webhook deve ser servido DIRETAMENTE sem injeção de conexão (ele faz isso internamente)
        require $file;
        exit;
    }
    
    // Permitir acesso DIRETO a arquivos de teste/debug que bypassam router
    if (strpos(basename($file), '_direto.php') !== false || 
        strpos(basename($file), '_test.php') !== false ||
        strpos(basename($file), '_debug.php') !== false ||
        strpos(basename($file), '_ultra_simples.php') !== false ||
        strpos(basename($file), '_simples.php') !== false) {
        // Arquivos com sufixo _direto, _test, _debug, _ultra_simples ou _simples podem ser acessados diretamente
        // IMPORTANTE: Servir SEM injeção de conexão para evitar redirecionamentos
        require $file;
        exit;
    }
    
    // Verificar se é endpoint de upload (deve ser servido ANTES de qualquer outra verificação)
    if (strpos($path, 'upload_foto_usuario_endpoint') !== false || basename($file) === 'upload_foto_usuario_endpoint.php') {
        require $file;
        exit;
    }
    
    if (strpos($path, 'cron') !== false || strpos($path, '/cron') !== false || 
        strpos($path, 'webhook') !== false || strpos($path, '/webhook') !== false ||
        in_array(basename($file), $public_files)) {
        require $file;
        exit;
    }
    
    // Injeção de conexão (idempotente)
    $conn = __DIR__ . '/conexao.php';
    if (is_file($conn)) { require_once $conn; }
    require $file;
    exit;
}

// Caso contrário, cai no index.php (front controller)
require __DIR__ . '/index.php';
