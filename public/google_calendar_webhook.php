<?php
// google_calendar_webhook.php — Webhook para receber notificações do Google Calendar
// Rota pública: /google/webhook

// Desabilitar display_errors para não retornar HTML
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/google_calendar_helper.php';
require_once __DIR__ . '/core/helpers.php';

// Log da requisição completa
$request_method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
$request_uri = $_SERVER['REQUEST_URI'] ?? 'UNKNOWN';
$headers = getallheaders() ?: [];
$body = file_get_contents('php://input');

error_log("[GOOGLE_WEBHOOK] ========== WEBHOOK RECEBIDO ==========");
error_log("[GOOGLE_WEBHOOK] Método: $request_method");
error_log("[GOOGLE_WEBHOOK] URI: $request_uri");
error_log("[GOOGLE_WEBHOOK] Headers: " . json_encode($headers));
error_log("[GOOGLE_WEBHOOK] Body: " . substr($body, 0, 500));

// Verificar se é uma requisição de verificação (challenge) - Google envia GET com ?challenge=...
if ($request_method === 'GET') {
    $challenge = $_GET['challenge'] ?? null;
    if ($challenge) {
        header('Content-Type: text/plain');
        header('HTTP/1.1 200 OK');
        echo $challenge;
        error_log("[GOOGLE_WEBHOOK] ✅ Challenge respondido: $challenge");
        exit;
    }
    
    // Se não for challenge, retornar status OK para testes
    header('Content-Type: text/plain');
    echo "Google Calendar Webhook Endpoint - OK";
    exit;
}

// Verificar se é uma notificação POST
if ($request_method === 'POST') {
    // Responder imediatamente com 200 OK para o Google (importante!)
    header('HTTP/1.1 200 OK');
    header('Content-Type: text/plain');
    echo 'OK';
    
    // Processar a notificação em segundo plano (após responder)
    // Extrair headers do Google
    $x_goog_channel_id = $headers['X-Goog-Channel-Id'] ?? $_SERVER['HTTP_X_GOOG_CHANNEL_ID'] ?? null;
    $x_goog_resource_id = $headers['X-Goog-Resource-Id'] ?? $_SERVER['HTTP_X_GOOG_RESOURCE_ID'] ?? null;
    $x_goog_resource_state = $headers['X-Goog-Resource-State'] ?? $_SERVER['HTTP_X_GOOG_RESOURCE_STATE'] ?? null;
    $x_goog_channel_token = $headers['X-Goog-Channel-Token'] ?? $_SERVER['HTTP_X_GOOG_CHANNEL_TOKEN'] ?? null;
    $x_goog_message_number = $headers['X-Goog-Message-Number'] ?? $_SERVER['HTTP_X_GOOG_MESSAGE_NUMBER'] ?? null;
    
    error_log("[GOOGLE_WEBHOOK] Channel ID: $x_goog_channel_id");
    error_log("[GOOGLE_WEBHOOK] Resource ID: $x_goog_resource_id");
    error_log("[GOOGLE_WEBHOOK] Resource State: $x_goog_resource_state");
    error_log("[GOOGLE_WEBHOOK] Channel Token: $x_goog_channel_token");
    error_log("[GOOGLE_WEBHOOK] Message Number: $x_goog_message_number");
    
    // Se for uma notificação de mudança, sincronizar
    if ($x_goog_resource_state === 'exists' || $x_goog_resource_state === 'sync') {
        try {
            $helper = new GoogleCalendarHelper();
            
            // Tentar obter config pelo calendar_id do token ou pela config ativa
            $calendar_id = $x_goog_channel_token; // O token contém o calendar_id
            
            if ($calendar_id) {
                // Buscar config pelo calendar_id
                $stmt = $GLOBALS['pdo']->prepare("SELECT * FROM google_calendar_config WHERE google_calendar_id = :calendar_id AND ativo = TRUE LIMIT 1");
                $stmt->execute([':calendar_id' => $calendar_id]);
                $config = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                // Fallback: buscar config ativa
                $config = $helper->getConfig();
            }
            
            if ($config && $config['ativo']) {
                error_log("[GOOGLE_WEBHOOK] ✅ Iniciando sincronização automática para: {$config['google_calendar_id']}");
                $resultado = $helper->syncCalendarEvents(
                    $config['google_calendar_id'],
                    $config['sync_dias_futuro'] ?? 180
                );
                error_log("[GOOGLE_WEBHOOK] ✅ Sincronização concluída: " . json_encode($resultado));
            } else {
                error_log("[GOOGLE_WEBHOOK] ⚠️ Config não encontrada ou inativa para calendar_id: $calendar_id");
            }
        } catch (Exception $e) {
            error_log("[GOOGLE_WEBHOOK] ❌ Erro na sincronização: " . $e->getMessage());
            error_log("[GOOGLE_WEBHOOK] Stack trace: " . $e->getTraceAsString());
        }
    } elseif ($x_goog_resource_state === 'not_exists') {
        error_log("[GOOGLE_WEBHOOK] ⚠️ Canal expirado ou removido. Resource ID: $x_goog_resource_id");
        // Limpar webhook expirado do banco
        try {
            $stmt = $GLOBALS['pdo']->prepare("UPDATE google_calendar_config SET webhook_resource_id = NULL, webhook_expiration = NULL WHERE webhook_resource_id = :resource_id");
            $stmt->execute([':resource_id' => $x_goog_resource_id]);
            error_log("[GOOGLE_WEBHOOK] ✅ Webhook expirado removido do banco");
        } catch (Exception $e) {
            error_log("[GOOGLE_WEBHOOK] ❌ Erro ao limpar webhook expirado: " . $e->getMessage());
        }
    } else {
        error_log("[GOOGLE_WEBHOOK] ⚠️ Resource State desconhecido: $x_goog_resource_state");
    }
    
    exit;
}

// Se chegou aqui, método não suportado
http_response_code(405);
header('Content-Type: text/plain');
echo "Method Not Allowed";
error_log("[GOOGLE_WEBHOOK] ❌ Método não suportado: $request_method");
