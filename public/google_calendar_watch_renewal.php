<?php
// google_calendar_watch_renewal.php — Renovação automática de webhooks do Google Calendar
// Verifica webhooks próximos de expirar (6h antes) e re-registra

// Desabilitar display_errors
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/google_calendar_helper.php';

try {
    $pdo = $GLOBALS['pdo'];
    $helper = new GoogleCalendarHelper();
    
    // Janela de renovação: próximas 6 horas
    $now_unix = time();
    $threshold_unix = $now_unix + (6 * 60 * 60);
    
    error_log("[GOOGLE_WATCH_RENEWAL] Verificando webhooks próximos de expirar (threshold: " . date('Y-m-d H:i:s', $threshold_unix) . ")");
    
    // Buscar candidatos ativos e filtrar em PHP para suportar coluna
    // webhook_expiration em timestamp ou em milissegundos.
    $stmt = $pdo->query("
        SELECT id, google_calendar_id, google_calendar_name, webhook_channel_id, webhook_resource_id, webhook_expiration
        FROM google_calendar_config
        WHERE ativo = TRUE 
        AND webhook_resource_id IS NOT NULL
        AND webhook_expiration IS NOT NULL
        ORDER BY atualizado_em ASC
    ");
    $candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $webhooks = [];
    foreach ($candidatos as $webhook) {
        $expira_unix = GoogleCalendarHelper::parseExpirationToUnix($webhook['webhook_expiration'] ?? null);
        if ($expira_unix <= 0) {
            continue;
        }
        if ($expira_unix > $now_unix && $expira_unix <= $threshold_unix) {
            $webhook['_expiration_unix'] = $expira_unix;
            $webhooks[] = $webhook;
        }
    }
    
    if (empty($webhooks)) {
        error_log("[GOOGLE_WATCH_RENEWAL] ✅ Nenhum webhook precisa ser renovado");
        exit(0);
    }
    
    error_log("[GOOGLE_WATCH_RENEWAL] 📋 Encontrados " . count($webhooks) . " webhook(s) para renovar");
    
    $webhook_url = getenv('GOOGLE_WEBHOOK_URL') ?: ($_ENV['GOOGLE_WEBHOOK_URL'] ?? 'https://painelsmilepro-production.up.railway.app/google_calendar_webhook.php');
    $webhook_url = GoogleCalendarHelper::normalizeWebhookUrl($webhook_url);
    
    foreach ($webhooks as $webhook) {
        $calendar_id = $webhook['google_calendar_id'];
        $expiration_date = date('Y-m-d H:i:s', (int)($webhook['_expiration_unix'] ?? 0));
        
        error_log("[GOOGLE_WATCH_RENEWAL] 🔄 Renovando webhook para: $calendar_id (expira em: $expiration_date)");
        
        try {
            // Parar webhook antigo (opcional, mas recomendado)
            if ($webhook['webhook_resource_id']) {
                try {
                    $helper->stopWebhook($webhook['webhook_resource_id'], $webhook['webhook_channel_id'] ?? null);
                    error_log("[GOOGLE_WATCH_RENEWAL] ✅ Webhook antigo parado");
                } catch (Exception $e) {
                    error_log("[GOOGLE_WATCH_RENEWAL] ⚠️ Erro ao parar webhook antigo (continuando): " . $e->getMessage());
                }
            }
            
            // Registrar novo webhook
            $resultado = $helper->registerWebhook($calendar_id, $webhook_url);
            
            error_log("[GOOGLE_WATCH_RENEWAL] ✅ Webhook renovado para: $calendar_id");
            
        } catch (Exception $e) {
            error_log("[GOOGLE_WATCH_RENEWAL] ❌ Erro ao renovar webhook para $calendar_id: " . $e->getMessage());
        }
    }
    
    error_log("[GOOGLE_WATCH_RENEWAL] ✅ Processamento de renovação concluído");
    
} catch (Exception $e) {
    error_log("[GOOGLE_WATCH_RENEWAL] ❌ Erro fatal: " . $e->getMessage());
    exit(1);
}
