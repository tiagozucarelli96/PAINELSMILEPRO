<?php
/**
 * google_calendar_auto_sync.php
 * Dispara sincronização automática do Google Calendar com throttling.
 */

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/google_calendar_helper.php';

/**
 * Executa auto-sync de forma segura e com throttling por sessão.
 */
function google_calendar_auto_sync($pdo, string $source = ''): void {
    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!$pdo instanceof PDO) {
            return;
        }

        $logWebhookCheck = function (string $status, array $details) use ($pdo) {
            try {
                if ($status !== 'erro') {
                    return;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO google_calendar_sync_logs (tipo, total_eventos, detalhes)
                    VALUES ('erro', 0, :detalhes)
                ");
                $payload = [
                    'status' => $status,
                    'details' => $details,
                    'webhook_check' => true
                ];
                $stmt->execute([':detalhes' => json_encode($payload)]);
            } catch (Exception $e) {
                // Ignorar falha de log para não interromper fluxo
            }
        };

        $now = time();
        $last = (int)($_SESSION['google_sync_last_trigger'] ?? 0);
        // Evita execuções repetidas na mesma sessão (5 minutos)
        if ($last > 0 && ($now - $last) < 300) {
            return;
        }
        $_SESSION['google_sync_last_trigger'] = $now;

        $stmt = $pdo->query("
            SELECT id, google_calendar_id, sync_dias_futuro, precisa_sincronizar,
                   ultima_sincronizacao, webhook_expiration
            FROM google_calendar_config
            WHERE ativo = TRUE
            LIMIT 1
        ");
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$config) {
            return;
        }

        $needsSync = !empty($config['precisa_sincronizar']);
        $ultima = $config['ultima_sincronizacao'] ? strtotime((string)$config['ultima_sincronizacao']) : 0;
        if (!$needsSync && ($ultima === 0 || ($now - $ultima) > 600)) {
            $needsSync = true;
        }

        // Se webhook está ausente ou prestes a expirar, tentar re-registrar
        $expiration_ms = isset($config['webhook_expiration']) ? (int)$config['webhook_expiration'] : 0;
        $threshold_ms = (int)round((microtime(true) + 3600) * 1000); // 1h
        if ($expiration_ms === 0 || $expiration_ms <= $threshold_ms) {
            try {
                $helper = new GoogleCalendarHelper();
                if ($helper->isConnected()) {
                    $webhook_url = getenv('GOOGLE_WEBHOOK_URL') ?: ($_ENV['GOOGLE_WEBHOOK_URL'] ?? 'https://painelsmilepro-production.up.railway.app/google_calendar_webhook.php');
                    if (strpos($webhook_url, '/google/webhook') !== false) {
                        $webhook_url = str_replace('/google/webhook', '/google_calendar_webhook.php', $webhook_url);
                    }
                    $helper->registerWebhook($config['google_calendar_id'], $webhook_url);
                    $logWebhookCheck('ok', ['webhook_url' => $webhook_url, 'source' => $source ?: 'auto']);
                }
            } catch (Exception $e) {
                error_log("[GOOGLE_AUTO_SYNC] Falha ao renovar webhook ({$source}): " . $e->getMessage());
                $logWebhookCheck('erro', [
                    'mensagem' => $e->getMessage(),
                    'webhook_url' => $webhook_url ?? null,
                    'source' => $source ?: 'auto'
                ]);
            }
        }

        if ($needsSync) {
            $helper = new GoogleCalendarHelper();
            if (!$helper->isConnected()) {
                return;
            }

            $calendar_id = (string)$config['google_calendar_id'];
            $sync_dias = (int)($config['sync_dias_futuro'] ?? 180);

            $resultado = $helper->syncCalendarEvents($calendar_id, $sync_dias);

            // Limpar flag após sincronização
            $stmt = $pdo->prepare("
                UPDATE google_calendar_config
                SET precisa_sincronizar = FALSE,
                    atualizado_em = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':id' => $config['id']]);

            error_log(sprintf(
                "[GOOGLE_AUTO_SYNC] %s | Importados: %d, Atualizados: %d, Pulados: %d",
                $source ?: 'auto',
                $resultado['importados'] ?? 0,
                $resultado['atualizados'] ?? 0,
                $resultado['pulados'] ?? 0
            ));
        }
    } catch (Exception $e) {
        error_log("[GOOGLE_AUTO_SYNC] Erro: " . $e->getMessage());
    }
}
?>
