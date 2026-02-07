<?php
// google_calendar_sync_processor.php — Processador de sincronização do Google Calendar
// Verifica flag "precisa sincronizar", usa lock, executa sincronização.

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/google_calendar_helper.php';

if (!function_exists('google_calendar_sync_processor_run')) {
    /**
     * Processa sincronizações pendentes do Google Calendar.
     */
    function google_calendar_sync_processor_run(?PDO $pdo = null): array {
        $pdo = $pdo instanceof PDO ? $pdo : ($GLOBALS['pdo'] ?? null);
        if (!$pdo instanceof PDO) {
            throw new Exception('Conexão com banco não disponível');
        }

        $lock_file = sys_get_temp_dir() . '/google_calendar_sync.lock';
        $lock_handle = fopen($lock_file, 'c');
        if (!$lock_handle) {
            throw new Exception('Não foi possível criar arquivo de lock');
        }

        $has_lock = flock($lock_handle, LOCK_EX | LOCK_NB);
        if (!$has_lock) {
            fclose($lock_handle);
            error_log('[GOOGLE_SYNC_PROCESSOR] ⚠️ Sincronização já em andamento (lock ativo)');
            return [
                'success' => true,
                'skipped' => true,
                'message' => 'Sincronização já em andamento'
            ];
        }

        ftruncate($lock_handle, 0);
        fwrite($lock_handle, (string)getmypid());
        fflush($lock_handle);

        try {
            error_log('[GOOGLE_SYNC_PROCESSOR] 🔄 Iniciando processamento de sincronização');

            $helper = new GoogleCalendarHelper();
            $stmt = $pdo->query("
                SELECT id, google_calendar_id, google_calendar_name, sync_dias_futuro
                FROM google_calendar_config
                WHERE ativo = TRUE AND precisa_sincronizar = TRUE
                ORDER BY atualizado_em ASC
            ");
            $calendarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($calendarios)) {
                error_log('[GOOGLE_SYNC_PROCESSOR] ✅ Nenhum calendário precisa sincronizar');
                return [
                    'success' => true,
                    'processed' => 0,
                    'errors' => [],
                    'message' => 'Nenhum calendário precisa sincronizar'
                ];
            }

            error_log('[GOOGLE_SYNC_PROCESSOR] 📋 Encontrados ' . count($calendarios) . ' calendário(s) para sincronizar');

            $sincronizados = 0;
            $erros = [];

            foreach ($calendarios as $calendario) {
                $calendar_id = $calendario['google_calendar_id'];
                $config_id = (int)$calendario['id'];
                $sync_dias = (int)($calendario['sync_dias_futuro'] ?? 180);

                error_log("[GOOGLE_SYNC_PROCESSOR] 🔄 Sincronizando: $calendar_id");

                try {
                    $resultado = $helper->syncCalendarEvents($calendar_id, $sync_dias);

                    $stmtUpdate = $pdo->prepare("
                        UPDATE google_calendar_config
                        SET precisa_sincronizar = FALSE,
                            ultima_sincronizacao = NOW(),
                            atualizado_em = NOW()
                        WHERE id = :config_id
                    ");
                    $stmtUpdate->execute([':config_id' => $config_id]);

                    $sincronizados++;
                    error_log(sprintf(
                        '[GOOGLE_SYNC_PROCESSOR] ✅ Sincronização concluída: %s | Importados: %d | Atualizados: %d | Pulados: %d',
                        $calendar_id,
                        (int)($resultado['importados'] ?? 0),
                        (int)($resultado['atualizados'] ?? 0),
                        (int)($resultado['pulados'] ?? 0)
                    ));
                } catch (Exception $e) {
                    $erros[] = [
                        'calendar_id' => $calendar_id,
                        'erro' => $e->getMessage()
                    ];
                    error_log("[GOOGLE_SYNC_PROCESSOR] ❌ Erro ao sincronizar $calendar_id: " . $e->getMessage());
                    // Não limpar flag em caso de erro - tentará novamente na próxima execução.
                }
            }

            error_log('[GOOGLE_SYNC_PROCESSOR] ✅ Processamento concluído');

            return [
                'success' => empty($erros),
                'processed' => $sincronizados,
                'total' => count($calendarios),
                'errors' => $erros,
                'message' => empty($erros)
                    ? 'Processamento concluído'
                    : 'Processamento concluído com erros'
            ];
        } finally {
            flock($lock_handle, LOCK_UN);
            fclose($lock_handle);
            if (file_exists($lock_file)) {
                @unlink($lock_file);
            }
        }
    }
}

$is_direct_execution = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__;
if ($is_direct_execution) {
    try {
        $resultado = google_calendar_sync_processor_run($GLOBALS['pdo'] ?? null);
        echo ($resultado['message'] ?? 'OK') . "\n";
        exit(!empty($resultado['success']) ? 0 : 1);
    } catch (Exception $e) {
        error_log('[GOOGLE_SYNC_PROCESSOR] ❌ Erro fatal: ' . $e->getMessage());
        echo "Erro: " . $e->getMessage() . "\n";
        exit(1);
    }
}
