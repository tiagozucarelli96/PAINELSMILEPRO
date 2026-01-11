<?php
// cron_google_calendar_sync.php — Sincronização automática do Google Calendar
// Executar via cron: */30 * * * * (a cada 30 minutos)

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/google_calendar_helper.php';

error_log("[GOOGLE_CALENDAR_CRON] Iniciando sincronização automática");

try {
    $helper = new GoogleCalendarHelper();
    
    // Verificar se está conectado
    if (!$helper->isConnected()) {
        error_log("[GOOGLE_CALENDAR_CRON] Google Calendar não está conectado. Pulando sincronização.");
        exit(0);
    }
    
    // Obter configuração
    $config = $helper->getConfig();
    if (!$config || !$config['ativo']) {
        error_log("[GOOGLE_CALENDAR_CRON] Calendário não configurado ou inativo. Pulando sincronização.");
        exit(0);
    }
    
    // Sincronizar
    $resultado = $helper->syncCalendarEvents(
        $config['google_calendar_id'],
        $config['sync_dias_futuro'] ?? 180
    );
    
    error_log(sprintf(
        "[GOOGLE_CALENDAR_CRON] Sincronização concluída. Importados: %d, Atualizados: %d",
        $resultado['importados'],
        $resultado['atualizados']
    ));
    
} catch (Exception $e) {
    error_log("[GOOGLE_CALENDAR_CRON] Erro: " . $e->getMessage());
    exit(1);
}

exit(0);
