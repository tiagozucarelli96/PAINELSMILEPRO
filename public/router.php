<?php
// Router para servidor embutido do PHP (Railway)
// - Injeta conexao.php antes de servir qualquer .php
// - Deixa arquivos estáticos irem direto

require_once __DIR__ . '/env_bootstrap.php';

function painel_router_normalize_host(?string $host): string
{
    $host = strtolower(trim((string)$host));
    if ($host === '') {
        return '';
    }

    return explode(':', $host)[0];
}

function painel_router_output_file(string $file): void
{
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $contentTypes = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'ico' => 'image/x-icon',
    ];

    if (isset($contentTypes[$extension])) {
        header('Content-Type: ' . $contentTypes[$extension]);
    }

    header('Content-Length: ' . (string)filesize($file));
    readfile($file);
    exit;
}

// ============================================
// CRÍTICO: Verificar webhooks ANTES de qualquer coisa
// ============================================
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($request_uri, PHP_URL_PATH) ?: '/';
$sessionBootstrap = __DIR__ . '/session_bootstrap.php';
$requestHost = painel_router_normalize_host($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
$smileChatHost = painel_router_normalize_host(painel_env('SMILE_CHAT_HOST', 'smilechat.smileeventos.com.br'));
$isSmileChatHost = $smileChatHost !== '' && $requestHost === $smileChatHost;
$smileChatRoot = realpath(__DIR__ . '/atendimento');
$smileChatIndex = $smileChatRoot ? realpath($smileChatRoot . '/index.php') : false;

if ($isSmileChatHost && $smileChatRoot) {
    if ($path === '/' || $path === '/index.php') {
        require $smileChatIndex;
        exit;
    }

    if (str_starts_with($path, '/assets/')) {
        $assetFile = realpath($smileChatRoot . $path);
        if ($assetFile && is_file($assetFile) && str_starts_with($assetFile, $smileChatRoot . DIRECTORY_SEPARATOR)) {
            painel_router_output_file($assetFile);
        }
    }
}

// Se for callback OAuth do Google, servir DIRETAMENTE sem passar por nada
if ($path === '/google/callback' || strpos($path, '/google/callback') !== false) {
    $callback_file = realpath(__DIR__ . '/google_callback.php');
    if ($callback_file && is_file($callback_file)) {
        // Servir callback DIRETAMENTE, sem injeção de conexão ou qualquer coisa
        require $callback_file;
        exit;
    }
}

// Se for webhook do Google Calendar, servir DIRETAMENTE
if ($path === '/google/webhook' || strpos($path, '/google/webhook') !== false) {
    $webhook_file = realpath(__DIR__ . '/google_calendar_webhook.php');
    if ($webhook_file && is_file($webhook_file)) {
        require $webhook_file;
        exit;
    }
}

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

// Diretórios com index.php próprio (ex: apps isolados dentro de /public)
if ($path !== '/' && $file && is_dir($file)) {
    $dirIndex = realpath($file . '/index.php');
    if ($dirIndex && is_file($dirIndex)) {
        require $dirIndex;
        exit;
    }
}

// Se for arquivo estático existente (css, js, imagens), serve direto
if ($path !== '/' && $file && is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}

// Se for um .php existente, injeta conexao e inclui o arquivo
if ($path !== '/' && $file && is_file($file) && str_ends_with($file, '.php')) {
    $isolatedAppsRoot = realpath(__DIR__ . '/atendimento');
    if ($isolatedAppsRoot && str_starts_with($file, $isolatedAppsRoot . DIRECTORY_SEPARATOR)) {
        require $file;
        exit;
    }

    // Arquivos de cron, webhook, endpoints e páginas públicas devem ser servidos diretamente SEM redirecionamento
    $public_files = ['comercial_degust_public.php', 'asaas_webhook.php', 'webhook_me_eventos.php', 'cron.php', 'upload_foto_usuario_endpoint.php',
                     'contabilidade_login.php', 'contabilidade_painel.php', 'contabilidade_guias.php', 'contabilidade_holerites.php',
                     'contabilidade_honorarios.php', 'contabilidade_conversas.php', 'contabilidade_colaboradores.php',
                     'push_get_public_key.php', 'push_check_consent.php', 'push_register_subscription.php', 'push_unregister_subscription.php',
                     'push_debug_env.php', 'google_callback.php', 'google_calendar_webhook.php'];
    
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
    if (is_file($sessionBootstrap)) { require_once $sessionBootstrap; }
    $conn = __DIR__ . '/conexao.php';
    if (is_file($conn)) { require_once $conn; }
    require $file;
    exit;
}

// Caso contrário, cai no index.php (front controller)
if (is_file($sessionBootstrap)) { require_once $sessionBootstrap; }
require __DIR__ . '/index.php';
