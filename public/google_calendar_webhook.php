<?php
// google_calendar_webhook.php — Webhook para receber notificações do Google Calendar
// Rota pública: /google/webhook
// Requisitos: Aceitar POST, ler headers, marcar flag, responder 204 imediatamente

// Desabilitar display_errors
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

// Verificar se é GET (challenge do Google)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $challenge = $_GET['challenge'] ?? null;
    if ($challenge) {
        header('Content-Type: text/plain');
        header('HTTP/1.1 200 OK');
        echo $challenge;
        error_log("[GOOGLE_WEBHOOK] Challenge respondido");
        exit;
    }
    // Se não for challenge, retornar status OK para testes
    header('Content-Type: text/plain');
    echo "Google Calendar Webhook Endpoint - OK";
    exit;
}

// Verificar se é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain');
    echo "Method Not Allowed";
    exit;
}

// Ler headers ANTES de responder
$channel_id = $_SERVER['HTTP_X_GOOG_CHANNEL_ID'] ?? null;
$resource_id = $_SERVER['HTTP_X_GOOG_RESOURCE_ID'] ?? null;
$resource_state = $_SERVER['HTTP_X_GOOG_RESOURCE_STATE'] ?? null;
$message_number = $_SERVER['HTTP_X_GOOG_MESSAGE_NUMBER'] ?? null;
$channel_token = $_SERVER['HTTP_X_GOOG_CHANNEL_TOKEN'] ?? null; // calendar_id

// Responder imediatamente com 204 No Content (ANTES de qualquer processamento pesado)
http_response_code(204);
header('Content-Type: text/plain');
// Não enviar body em 204
if (ob_get_level() > 0) {
    ob_end_clean();
}
// Fechar conexão com cliente (fastcgi_finish_request se disponível)
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Processar em segundo plano (após responder)
try {
    // Log resumido (sem dumping de todos headers)
    error_log(sprintf(
        "[GOOGLE_WEBHOOK] POST | State: %s | Calendar: %s | Resource: %s",
        $resource_state ?? 'N/A',
        $channel_token ? substr($channel_token, 0, 30) . '...' : 'N/A',
        $resource_id ? substr($resource_id, 0, 30) . '...' : 'N/A'
    ));
    
    // Ignorar eventos com resource_state = "sync" (handshake inicial)
    if ($resource_state === 'sync') {
        error_log("[GOOGLE_WEBHOOK] Handshake inicial ignorado");
        exit;
    }
    
    // Validar que temos o calendar_id (channel_token)
    if (!$channel_token) {
        error_log("[GOOGLE_WEBHOOK] ⚠️ Channel token não encontrado");
        exit;
    }
    
    // Buscar configuração do calendário
    $pdo = $GLOBALS['pdo'];
    $stmt = $pdo->prepare("
        SELECT id, google_calendar_id, webhook_channel_id, webhook_resource_id, ativo
        FROM google_calendar_config
        WHERE google_calendar_id = :calendar_id AND ativo = TRUE
        LIMIT 1
    ");
    $stmt->execute([':calendar_id' => $channel_token]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        error_log("[GOOGLE_WEBHOOK] ⚠️ Config não encontrada para calendar_id: " . substr($channel_token, 0, 30));
        exit;
    }
    
    // Validar que X-Goog-Channel-Id bate com o canal salvo (quando existir)
    if ($config['webhook_channel_id'] && $config['webhook_channel_id'] !== $channel_id) {
        error_log("[GOOGLE_WEBHOOK] ⚠️ Channel ID não confere. Esperado: " . substr($config['webhook_channel_id'], 0, 30) . ", Recebido: " . substr($channel_id, 0, 30));
        exit;
    }
    
    // Validar que X-Goog-Resource-Id bate com o resource salvo (quando existir)
    if ($config['webhook_resource_id'] && $config['webhook_resource_id'] !== $resource_id) {
        error_log("[GOOGLE_WEBHOOK] ⚠️ Resource ID não confere");
        exit;
    }
    
    // Se for notificação de mudança (exists)
    if ($resource_state === 'exists') {
        // Marcar flag "precisa sincronizar" no banco
        $stmt = $pdo->prepare("
            UPDATE google_calendar_config
            SET precisa_sincronizar = TRUE,
                atualizado_em = NOW()
            WHERE id = :config_id
        ");
        $stmt->execute([':config_id' => $config['id']]);
        
        error_log("[GOOGLE_WEBHOOK] ✅ Flag 'precisa_sincronizar' marcado para: " . substr($channel_token, 0, 30));

        // Processar sincronização imediatamente (evita depender de cron)
        $processor_script = __DIR__ . '/google_calendar_sync_processor.php';
        if (file_exists($processor_script)) {
            ob_start();
            include $processor_script;
            ob_end_clean();
        } else {
            error_log("[GOOGLE_WEBHOOK] ⚠️ Processador não encontrado: $processor_script");
        }
    } elseif ($resource_state === 'not_exists') {
        // Canal expirado - limpar webhook
        $stmt = $pdo->prepare("
            UPDATE google_calendar_config
            SET webhook_channel_id = NULL,
                webhook_resource_id = NULL,
                webhook_expiration = NULL,
                precisa_sincronizar = FALSE,
                atualizado_em = NOW()
            WHERE id = :config_id
        ");
        $stmt->execute([':config_id' => $config['id']]);
        
        error_log("[GOOGLE_WEBHOOK] ⚠️ Canal expirado removido para: " . substr($channel_token, 0, 30));
    }
    
} catch (Exception $e) {
    error_log("[GOOGLE_WEBHOOK] ❌ Erro: " . $e->getMessage());
}
