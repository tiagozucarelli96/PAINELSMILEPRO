<?php
// cron_google_calendar_daily.php — Job diário para sincronização do Google Calendar
// Garante sincronização 1x/dia mesmo sem webhook
// Railway cron: 0 2 * * * (2h da manhã todos os dias)

// Desabilitar display_errors
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/google_calendar_sync_processor.php';

// O processador já tem lock e trata tudo
// Apenas marcar todos os calendários ativos como "precisa sincronizar"
// e chamar o processador

try {
    $pdo = $GLOBALS['pdo'];
    
    error_log("[GOOGLE_CRON_DAILY] 🔄 Iniciando sincronização diária");
    
    // Marcar todos os calendários ativos como "precisa sincronizar"
    $stmt = $pdo->exec("
        UPDATE google_calendar_config
        SET precisa_sincronizar = TRUE,
            atualizado_em = NOW()
        WHERE ativo = TRUE
    ");
    
    $rows_updated = $stmt;
    error_log("[GOOGLE_CRON_DAILY] 📋 Marcados $rows_updated calendário(s) para sincronização");
    
    // Chamar o processador (que já tem lock)
    // O processador vai verificar o flag e sincronizar
    require __DIR__ . '/google_calendar_sync_processor.php';
    
    error_log("[GOOGLE_CRON_DAILY] ✅ Sincronização diária concluída");
    
} catch (Exception $e) {
    error_log("[GOOGLE_CRON_DAILY] ❌ Erro fatal: " . $e->getMessage());
    exit(1);
}
