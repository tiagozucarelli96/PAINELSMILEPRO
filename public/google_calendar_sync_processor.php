<?php
// google_calendar_sync_processor.php — Processador de sincronização do Google Calendar
// Verifica flag "precisa sincronizar", usa lock, executa sincronização
// Pode ser chamado via cron ou manualmente

// Desabilitar display_errors
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/google_calendar_helper.php';

// Lock file para evitar concorrência
$lock_file = sys_get_temp_dir() . '/google_calendar_sync.lock';
$lock_handle = null;

/**
 * Obter lock para sincronização
 */
function acquireLock() {
    global $lock_file, $lock_handle;
    
    $lock_handle = fopen($lock_file, 'w');
    if (!$lock_handle) {
        throw new Exception("Não foi possível criar arquivo de lock");
    }
    
    // Tentar obter lock exclusivo (não bloqueante)
    if (!flock($lock_handle, LOCK_EX | LOCK_NB)) {
        fclose($lock_handle);
        error_log("[GOOGLE_SYNC_PROCESSOR] ⚠️ Sincronização já em andamento (lock ativo)");
        return false;
    }
    
    // Escrever PID no arquivo de lock
    fwrite($lock_handle, getmypid());
    fflush($lock_handle);
    
    return true;
}

/**
 * Liberar lock
 */
function releaseLock() {
    global $lock_handle, $lock_file;
    
    if ($lock_handle) {
        flock($lock_handle, LOCK_UN);
        fclose($lock_handle);
        $lock_handle = null;
    }
    
    // Remover arquivo de lock se existir
    if (file_exists($lock_file)) {
        @unlink($lock_file);
    }
}

// Registrar handler para garantir liberação do lock
register_shutdown_function('releaseLock');

try {
    // Tentar obter lock
    if (!acquireLock()) {
        echo "Sincronização já em andamento\n";
        exit(0);
    }
    
    error_log("[GOOGLE_SYNC_PROCESSOR] 🔄 Iniciando processamento de sincronização");
    
    $pdo = $GLOBALS['pdo'];
    $helper = new GoogleCalendarHelper();
    
    // Buscar calendários que precisam sincronizar
    $stmt = $pdo->query("
        SELECT id, google_calendar_id, google_calendar_name, sync_dias_futuro
        FROM google_calendar_config
        WHERE ativo = TRUE AND precisa_sincronizar = TRUE
        ORDER BY atualizado_em ASC
    ");
    $calendarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($calendarios)) {
        error_log("[GOOGLE_SYNC_PROCESSOR] ✅ Nenhum calendário precisa sincronizar");
        exit(0);
    }
    
    error_log("[GOOGLE_SYNC_PROCESSOR] 📋 Encontrados " . count($calendarios) . " calendário(s) para sincronizar");
    
    foreach ($calendarios as $calendario) {
        $calendar_id = $calendario['google_calendar_id'];
        $config_id = $calendario['id'];
        $sync_dias = $calendario['sync_dias_futuro'] ?? 180;
        
        error_log("[GOOGLE_SYNC_PROCESSOR] 🔄 Sincronizando: $calendar_id");
        
        try {
            // Executar sincronização
            $resultado = $helper->syncCalendarEvents($calendar_id, $sync_dias);
            
            // Limpar flag e registrar resumo
            $stmt = $pdo->prepare("
                UPDATE google_calendar_config
                SET precisa_sincronizar = FALSE,
                    ultima_sincronizacao_at = NOW(),
                    ultima_sincronizacao_resumo = :resumo,
                    atualizado_em = NOW()
                WHERE id = :config_id
            ");
            $stmt->execute([
                ':config_id' => $config_id,
                ':resumo' => json_encode([
                    'importados' => $resultado['importados'] ?? 0,
                    'atualizados' => $resultado['atualizados'] ?? 0,
                    'pulados' => $resultado['pulados'] ?? 0,
                    'total_encontrado' => $resultado['total_encontrado'] ?? 0
                ])
            ]);
            
            error_log(sprintf(
                "[GOOGLE_SYNC_PROCESSOR] ✅ Sincronização concluída: %s | Importados: %d | Atualizados: %d | Pulados: %d",
                $calendar_id,
                $resultado['importados'] ?? 0,
                $resultado['atualizados'] ?? 0,
                $resultado['pulados'] ?? 0
            ));
            
        } catch (Exception $e) {
            error_log("[GOOGLE_SYNC_PROCESSOR] ❌ Erro ao sincronizar $calendar_id: " . $e->getMessage());
            // Não limpar flag em caso de erro - tentará novamente na próxima execução
        }
    }
    
    error_log("[GOOGLE_SYNC_PROCESSOR] ✅ Processamento concluído");
    
} catch (Exception $e) {
    error_log("[GOOGLE_SYNC_PROCESSOR] ❌ Erro fatal: " . $e->getMessage());
    exit(1);
} finally {
    releaseLock();
}
