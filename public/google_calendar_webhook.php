<?php
// google_calendar_webhook.php — Webhook para receber notificações do Google Calendar
// Rota pública: /google/webhook

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/google_calendar_helper.php';

// Log da requisição
error_log("[GOOGLE_CALENDAR_WEBHOOK] Webhook recebido: " . $_SERVER['REQUEST_METHOD']);
error_log("[GOOGLE_CALENDAR_WEBHOOK] Headers: " . json_encode(getallheaders()));
error_log("[GOOGLE_CALENDAR_WEBHOOK] Body: " . file_get_contents('php://input'));

// Verificar se é uma requisição de verificação (challenge)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $challenge = $_GET['challenge'] ?? null;
    if ($challenge) {
        header('Content-Type: text/plain');
        echo $challenge;
        error_log("[GOOGLE_CALENDAR_WEBHOOK] Challenge respondido: $challenge");
        exit;
    }
}

// Verificar se é uma notificação POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $headers = getallheaders();
    $x_goog_channel_id = $headers['X-Goog-Channel-Id'] ?? null;
    $x_goog_resource_id = $headers['X-Goog-Resource-Id'] ?? null;
    $x_goog_resource_state = $headers['X-Goog-Resource-State'] ?? null;
    
    error_log("[GOOGLE_CALENDAR_WEBHOOK] Channel ID: $x_goog_channel_id");
    error_log("[GOOGLE_CALENDAR_WEBHOOK] Resource ID: $x_goog_resource_id");
    error_log("[GOOGLE_CALENDAR_WEBHOOK] Resource State: $x_goog_resource_state");
    
    // Se for uma notificação de mudança, sincronizar
    if ($x_goog_resource_state === 'exists' || $x_goog_resource_state === 'sync') {
        try {
            $helper = new GoogleCalendarHelper();
            $config = $helper->getConfig();
            
            if ($config && $config['ativo']) {
                error_log("[GOOGLE_CALENDAR_WEBHOOK] Iniciando sincronização automática");
                $resultado = $helper->syncCalendarEvents(
                    $config['google_calendar_id'],
                    $config['sync_dias_futuro'] ?? 180
                );
                error_log("[GOOGLE_CALENDAR_WEBHOOK] Sincronização concluída: " . json_encode($resultado));
            }
        } catch (Exception $e) {
            error_log("[GOOGLE_CALENDAR_WEBHOOK] Erro na sincronização: " . $e->getMessage());
        }
    }
    
    http_response_code(200);
    echo "OK";
    exit;
}

http_response_code(400);
echo "Bad Request";
