<?php
require_once __DIR__ . '/logistica_tz.php';
// logistica_cron_runner.php — Runner diário (sync + faltas + baixa)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/logistica_alertas_helper.php';
require_once __DIR__ . '/logistica_baixa_helper.php';

if (!defined('LOGISTICA_SYNC_ONLY')) {
    define('LOGISTICA_SYNC_ONLY', true);
}
require_once __DIR__ . '/logistica_conexao.php';

if (!function_exists('logistica_run_daily')) {
    function logistica_run_daily(PDO $pdo, array $options = []): array {
        $start = microtime(true);
        $summary = [
            'tz' => 'America/Sao_Paulo',
            'sync' => ['created' => 0, 'updated' => 0],
            'faltas' => ['eventos_verificados' => 0, 'alertas_gerados' => 0],
            'baixa' => ['eventos_processados' => 0, 'baixados' => 0, 'bloqueados' => 0]
        ];

        try {
            $errors = [];
            $messages = [];
            $schema_ok = ensure_logistica_schema($pdo, $errors, $messages);
            if (!$schema_ok) {
                throw new RuntimeException('Base Logística ausente.');
            }

            $has_nome_evento = has_column($pdo, 'logistica_eventos_espelho', 'nome_evento');
            $unidades = get_unidades_internas($pdo);
            $unidadesByCodigo = [];
            foreach ($unidades as $u) {
                $unidadesByCodigo[$u['codigo']] = (int)$u['id'];
            }
            $mapeamentos = load_mapeamentos($pdo);
            $novosLocais = [];

            $sync = sync_eventos($pdo, $mapeamentos, $unidadesByCodigo, $novosLocais, $has_nome_evento);
            if (!$sync['ok']) {
                throw new RuntimeException($sync['error']);
            }
            $summary['sync']['created'] = (int)($sync['inserted'] ?? 0);
            $summary['sync']['updated'] = (int)($sync['updated'] ?? 0);

            $scope = $_SESSION['unidade_scope'] ?? 'todas';
            $unit_id = (int)($_SESSION['unidade_id'] ?? 0);
            $filter_unit = (!empty($options['filter_unit']) && $scope === 'unidade' && $unit_id > 0);

            $faltas = logistica_compute_alertas_eventos($pdo, 3, $filter_unit, $unit_id);
            $alertas = count($faltas['faltas']) + count($faltas['sem_lista']) + count($faltas['sem_detalhe']) + count($faltas['conflitos']);
            $summary['faltas']['eventos_verificados'] = (int)($faltas['eventos_total'] ?? 0);
            $summary['faltas']['alertas_gerados'] = $alertas;

            $baixa = logistica_processar_baixa_eventos($pdo, date('Y-m-d'), $filter_unit, $unit_id, (int)($_SESSION['id'] ?? 0));
            $summary['baixa']['eventos_processados'] = $baixa['eventos_processados'] ?? 0;
            $summary['baixa']['baixados'] = $baixa['eventos_baixados'] ?? 0;
            $summary['baixa']['bloqueados'] = $baixa['eventos_bloqueados'] ?? 0;

            $summary['duration_ms'] = (int)round((microtime(true) - $start) * 1000);

            $stmt = $pdo->prepare("
                INSERT INTO logistica_cron_execucoes (status, resumo_json, duracao_ms, erro_msg)
                VALUES ('ok', :resumo, :duracao, NULL)
            ");
            $stmt->execute([
                ':resumo' => json_encode($summary, JSON_UNESCAPED_UNICODE),
                ':duracao' => $summary['duration_ms']
            ]);

            return ['ok' => true, 'summary' => $summary];
        } catch (Throwable $e) {
            $duration = (int)round((microtime(true) - $start) * 1000);
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO logistica_cron_execucoes (status, resumo_json, duracao_ms, erro_msg)
                    VALUES ('erro', NULL, :duracao, :erro)
                ");
                $stmt->execute([
                    ':duracao' => $duration,
                    ':erro' => $e->getMessage()
                ]);
            } catch (Throwable $ignored) {
            }
            return ['ok' => false, 'error' => $e->getMessage(), 'duration_ms' => $duration];
        }
    }
}

if (defined('LOGISTICA_CRON_INTERNAL')) {
    return;
}

$token = $_GET['token'] ?? '';
$headerToken = $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
$envToken = getenv('LOGISTICA_CRON_TOKEN') ?: '';
$valid = $envToken !== '' && (hash_equals($envToken, $token) || hash_equals($envToken, $headerToken));

if (!$valid) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Token inválido.']);
    exit;
}

$result = logistica_run_daily($pdo, []);
header('Content-Type: application/json');
echo json_encode($result, JSON_UNESCAPED_UNICODE);
