<?php
/**
 * google_calendar_auto_sync.php
 * Dispara sincronização automática do Google Calendar com throttling.
 */

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../env_bootstrap.php';
require_once __DIR__ . '/google_calendar_helper.php';

/**
 * Executa auto-sync de forma segura e com throttling por sessão.
 */
function google_calendar_auto_sync($pdo, string $source = ''): void {
    try {
        $workerEnabled = function_exists('painel_env_bool')
            ? painel_env_bool('GOOGLE_WORKER_ENABLED', false)
            : filter_var(getenv('GOOGLE_WORKER_ENABLED') ?: false, FILTER_VALIDATE_BOOL);
        $syncOnRequest = function_exists('painel_env_bool')
            ? painel_env_bool('GOOGLE_SYNC_ON_REQUEST', !$workerEnabled)
            : filter_var(getenv('GOOGLE_SYNC_ON_REQUEST') ?: (!$workerEnabled ? '1' : '0'), FILTER_VALIDATE_BOOL);

        if ($workerEnabled && !$syncOnRequest) {
            return;
        }

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
        $expiration_unix = GoogleCalendarHelper::parseExpirationToUnix($config['webhook_expiration'] ?? null);
        $threshold_unix = time() + 3600; // 1h
        if ($expiration_unix === 0 || $expiration_unix <= $threshold_unix) {
            try {
                $helper = new GoogleCalendarHelper();
                if ($helper->isConnected()) {
                    $webhook_url = getenv('GOOGLE_WEBHOOK_URL') ?: ($_ENV['GOOGLE_WEBHOOK_URL'] ?? 'https://painelsmilepro-production.up.railway.app/google_calendar_webhook.php');
                    $webhook_url = GoogleCalendarHelper::normalizeWebhookUrl($webhook_url);
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
            $processor_script = dirname(__DIR__) . '/google_calendar_sync_processor.php';
            if (file_exists($processor_script)) {
                $cmd = sprintf(
                    '%s %s > /proc/1/fd/2 2>&1 &',
                    escapeshellarg(PHP_BINARY),
                    escapeshellarg($processor_script)
                );
                @exec($cmd);
                error_log("[GOOGLE_AUTO_SYNC] {$source} | Processador disparado em background");
            }
        }
    } catch (Exception $e) {
        error_log("[GOOGLE_AUTO_SYNC] Erro: " . $e->getMessage());
    }
}
?>
