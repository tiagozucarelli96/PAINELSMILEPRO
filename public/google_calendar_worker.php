<?php
/**
 * google_calendar_worker.php
 * Worker interno para manter sincronização Google Calendar sem cron externo.
 * - Renova webhooks próximos de expirar
 * - Processa sincronizações pendentes (precisa_sincronizar = TRUE)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Acesso negado.\n";
    exit(1);
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/google_calendar_helper.php';
require_once __DIR__ . '/google_calendar_sync_processor.php';

date_default_timezone_set('America/Sao_Paulo');

/**
 * Logging padrão do worker.
 */
function google_worker_log(string $message): void {
    error_log('[GOOGLE_WORKER] ' . $message);
}

/**
 * Renova webhooks que expiram nas próximas 6 horas.
 */
function google_worker_renew_webhooks(PDO $pdo, GoogleCalendarHelper $helper): array {
    $now_unix = time();
    $threshold_unix = $now_unix + (6 * 60 * 60);

    $stmt = $pdo->query("
        SELECT id, google_calendar_id, google_calendar_name, webhook_channel_id, webhook_resource_id, webhook_expiration
        FROM google_calendar_config
        WHERE ativo = TRUE
        AND webhook_resource_id IS NOT NULL
        AND webhook_expiration IS NOT NULL
        ORDER BY atualizado_em ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $due = [];
    foreach ($rows as $row) {
        $expira_unix = GoogleCalendarHelper::parseExpirationToUnix($row['webhook_expiration'] ?? null);
        if ($expira_unix > $now_unix && $expira_unix <= $threshold_unix) {
            $row['_expiration_unix'] = $expira_unix;
            $due[] = $row;
        }
    }

    if (empty($due)) {
        return [
            'checked' => count($rows),
            'renewed' => 0,
            'errors' => []
        ];
    }

    $webhook_url = getenv('GOOGLE_WEBHOOK_URL') ?: ($_ENV['GOOGLE_WEBHOOK_URL'] ?? 'https://painelsmilepro-production.up.railway.app/google_calendar_webhook.php');
    $webhook_url = GoogleCalendarHelper::normalizeWebhookUrl($webhook_url);

    $renewed = 0;
    $errors = [];

    foreach ($due as $item) {
        $calendar_id = (string)$item['google_calendar_id'];
        try {
            if (!empty($item['webhook_resource_id'])) {
                try {
                    $helper->stopWebhook((string)$item['webhook_resource_id'], $item['webhook_channel_id'] ?? null);
                } catch (Exception $stopErr) {
                    google_worker_log("Falha ao parar webhook antigo de {$calendar_id}: " . $stopErr->getMessage());
                }
            }

            $helper->registerWebhook($calendar_id, $webhook_url);
            $renewed++;
        } catch (Exception $e) {
            $errors[] = [
                'calendar_id' => $calendar_id,
                'erro' => $e->getMessage()
            ];
            google_worker_log("Erro ao renovar webhook de {$calendar_id}: " . $e->getMessage());
        }
    }

    return [
        'checked' => count($rows),
        'renewed' => $renewed,
        'errors' => $errors
    ];
}

$tick_seconds = max(30, (int)(getenv('GOOGLE_WORKER_TICK_SECONDS') ?: 60));
$sync_interval = max(30, (int)(getenv('GOOGLE_WORKER_SYNC_INTERVAL_SECONDS') ?: 60));
$renew_interval = max(300, (int)(getenv('GOOGLE_WORKER_RENEW_INTERVAL_SECONDS') ?: 900));
$heartbeat_interval = max(120, (int)(getenv('GOOGLE_WORKER_HEARTBEAT_SECONDS') ?: 300));

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
}
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$running): void {
        $running = false;
    });
    pcntl_signal(SIGINT, function () use (&$running): void {
        $running = false;
    });
}

google_worker_log("Iniciado | tick={$tick_seconds}s, sync={$sync_interval}s, renew={$renew_interval}s");

$last_sync = 0;
$last_renew = 0;
$last_heartbeat = 0;

while ($running) {
    $now = time();

    try {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo instanceof PDO) {
            require __DIR__ . '/conexao.php';
            $pdo = $GLOBALS['pdo'] ?? null;
        }
        if (!$pdo instanceof PDO) {
            throw new Exception('PDO indisponível');
        }

        $helper = new GoogleCalendarHelper();

        if (($now - $last_renew) >= $renew_interval) {
            $renew = google_worker_renew_webhooks($pdo, $helper);
            $last_renew = $now;
            if (!empty($renew['renewed']) || !empty($renew['errors'])) {
                google_worker_log('Renovação executada: ' . json_encode($renew));
            }
        }

        if (($now - $last_sync) >= $sync_interval) {
            $sync = google_calendar_sync_processor_run($pdo);
            $last_sync = $now;
            if (empty($sync['skipped']) && (!empty($sync['processed']) || !empty($sync['errors']))) {
                google_worker_log('Sync executada: ' . json_encode($sync));
            }
        }

        if (($now - $last_heartbeat) >= $heartbeat_interval) {
            $last_heartbeat = $now;
            google_worker_log('Heartbeat OK');
        }
    } catch (Exception $e) {
        google_worker_log('Erro no loop: ' . $e->getMessage());
        $GLOBALS['pdo'] = null;
    }

    sleep($tick_seconds);
}

google_worker_log('Finalizado');
exit(0);
