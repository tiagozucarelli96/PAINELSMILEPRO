<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/config_env.php';
require_once __DIR__ . '/pixgo_helper.php';
require_once __DIR__ . '/eventos_financeiro_helper.php';
require_once __DIR__ . '/comercial_pagamento_solicitacao_helper.php';
require_once __DIR__ . '/core/notification_dispatcher.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pixgo_webhook_response(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    pixgo_webhook_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
}
if (!$pdo instanceof PDO) {
    pixgo_webhook_response(503, ['ok' => false, 'error' => 'database_unavailable']);
}
if (!PIXGO_ENABLED || trim((string)PIXGO_API_KEY) === '' || trim((string)PIXGO_WEBHOOK_SECRET) === '') {
    pixgo_webhook_response(503, ['ok' => false, 'error' => 'pixgo_not_configured']);
}

$rawBody = (string)file_get_contents('php://input');
$timestamp = trim((string)($_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? ''));
$signature = trim((string)($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ''));
$headerEvent = trim((string)($_SERVER['HTTP_X_WEBHOOK_EVENT'] ?? ''));

if (!PixGoHelper::validateWebhookSignature($rawBody, $timestamp, $signature)) {
    pixgo_webhook_response(401, ['ok' => false, 'error' => 'invalid_signature']);
}

function pixgo_webhook_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SELECT to_regclass(:table)");
        $stmt->execute([':table' => $table]);
        return trim((string)($stmt->fetchColumn() ?: '')) !== '';
    } catch (Throwable $e) {
        return false;
    }
}

function pixgo_webhook_user_column_exists(PDO $pdo, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'usuarios'
              AND column_name = :column
            LIMIT 1
        ");
        $stmt->execute([':column' => $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function pixgo_webhook_ceo_recipients(PDO $pdo): array
{
    $hasCargo = pixgo_webhook_user_column_exists($pdo, 'cargo');
    $hasAtivo = pixgo_webhook_user_column_exists($pdo, 'ativo');
    $hasEmail = pixgo_webhook_user_column_exists($pdo, 'email');
    $hasCelular = pixgo_webhook_user_column_exists($pdo, 'celular');
    $hasTelefone = pixgo_webhook_user_column_exists($pdo, 'telefone');
    $hasSuperadmin = pixgo_webhook_user_column_exists($pdo, 'perm_superadmin');

    $select = [
        'id',
        'nome',
        $hasEmail ? 'email' : "NULL::text AS email",
        $hasCelular ? 'celular' : "NULL::text AS celular",
        $hasTelefone ? 'telefone' : "NULL::text AS telefone",
    ];
    $activeWhere = $hasAtivo ? 'COALESCE(ativo, TRUE) = TRUE' : '1=1';

    try {
        if ($hasCargo) {
            $stmt = $pdo->query("
                SELECT " . implode(', ', $select) . "
                FROM usuarios
                WHERE {$activeWhere}
                  AND UPPER(TRIM(COALESCE(cargo, ''))) = 'CEO'
                ORDER BY id ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($rows !== []) {
                return array_map(static function (array $row): array {
                    return [
                        'id' => (int)$row['id'],
                        'nome' => (string)($row['nome'] ?? ''),
                        'email' => (string)($row['email'] ?? ''),
                        'phone' => (string)($row['celular'] ?? $row['telefone'] ?? ''),
                    ];
                }, $rows);
            }
        }

        if ($hasSuperadmin) {
            $stmt = $pdo->query("
                SELECT " . implode(', ', $select) . "
                FROM usuarios
                WHERE {$activeWhere}
                  AND COALESCE(perm_superadmin, FALSE) = TRUE
                ORDER BY id ASC
                LIMIT 5
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return array_map(static function (array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'nome' => (string)($row['nome'] ?? ''),
                    'email' => (string)($row['email'] ?? ''),
                    'phone' => (string)($row['celular'] ?? $row['telefone'] ?? ''),
                ];
            }, $rows);
        }
    } catch (Throwable $e) {
        error_log('[PIXGO_WEBHOOK] Falha ao buscar CEO para notificação: ' . $e->getMessage());
    }

    return [];
}

function pixgo_webhook_fetch_payment_snapshot(PDO $pdo, string $paymentId): ?array
{
    if ($paymentId === '') {
        return null;
    }

    try {
        if (pixgo_webhook_table_exists($pdo, 'comercial_pagamento_solicitacoes')) {
            comercial_pagamento_solicitacao_ensure_schema($pdo);
            $stmt = $pdo->prepare("
                SELECT 'comercial' AS origem,
                       s.id,
                       NULL::bigint AS evento_id,
                       s.status,
                       s.valor_original AS valor,
                       s.descricao,
                       s.vencimento::text AS vencimento,
                       COALESCE(e.nome_evento, s.evento_nome, '') AS evento_nome,
                       e.data_evento::text AS evento_data,
                       NULL::text AS nome_formando
                FROM comercial_pagamento_solicitacoes s
                LEFT JOIN logistica_eventos_espelho e ON e.id = s.evento_id
                WHERE s.pixgo_payment_id = :payment_id
                   OR EXISTS (
                        SELECT 1
                        FROM comercial_pagamento_pixgo_tentativas t
                        WHERE t.solicitacao_id = s.id
                          AND t.payment_id = :payment_id
                   )
                LIMIT 1
            ");
            $stmt->execute([':payment_id' => $paymentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (is_array($row)) {
                return $row;
            }
        }
    } catch (Throwable $e) {
        error_log('[PIXGO_WEBHOOK] Snapshot comercial: ' . $e->getMessage());
    }

    try {
        if (pixgo_webhook_table_exists($pdo, 'eventos_financeiro_receitas')) {
            $stmt = $pdo->prepare("
                SELECT 'evento' AS origem,
                       r.id,
                       r.evento_id,
                       r.status,
                       r.valor,
                       r.descricao,
                       r.vencimento::text AS vencimento,
                       COALESCE(e.nome_evento, '') AS evento_nome,
                       e.data_evento::text AS evento_data,
                       NULL::text AS nome_formando
                FROM eventos_financeiro_receitas r
                LEFT JOIN logistica_eventos_espelho e ON e.id = r.evento_id
                WHERE r.pixgo_payment_id = :payment_id
                LIMIT 1
            ");
            $stmt->execute([':payment_id' => $paymentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (is_array($row)) {
                return $row;
            }
        }
    } catch (Throwable $e) {
        error_log('[PIXGO_WEBHOOK] Snapshot evento: ' . $e->getMessage());
    }

    try {
        if (pixgo_webhook_table_exists($pdo, 'eventos_formatura_financeiro')) {
            $stmt = $pdo->prepare("
                SELECT 'formatura' AS origem,
                       fin.id,
                       fin.evento_id,
                       fin.status,
                       fin.valor,
                       fin.descricao,
                       fin.vencimento::text AS vencimento,
                       COALESCE(e.nome_evento, '') AS evento_nome,
                       e.data_evento::text AS evento_data,
                       COALESCE(f.nome_formando, '') AS nome_formando
                FROM eventos_formatura_financeiro fin
                LEFT JOIN logistica_eventos_espelho e ON e.id = fin.evento_id
                LEFT JOIN eventos_formatura_formandos f ON f.id = fin.formando_id
                WHERE fin.pixgo_payment_id = :payment_id
                LIMIT 1
            ");
            $stmt->execute([':payment_id' => $paymentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (is_array($row)) {
                return $row;
            }
        }
    } catch (Throwable $e) {
        error_log('[PIXGO_WEBHOOK] Snapshot formatura: ' . $e->getMessage());
    }

    return null;
}

function pixgo_webhook_payment_url(array $snapshot, string $paymentId): string
{
    $origem = (string)($snapshot['origem'] ?? '');
    if ($origem === 'comercial') {
        return 'index.php?page=comercial_gerenciar_pix&q=' . rawurlencode($paymentId);
    }

    $eventoId = (int)($snapshot['evento_id'] ?? 0);
    if ($origem === 'formatura' && $eventoId > 0) {
        return 'index.php?page=eventos_formatura&evento_id=' . $eventoId;
    }
    if ($eventoId > 0) {
        return 'index.php?page=eventos_financeiro&evento_id=' . $eventoId;
    }

    return 'index.php?page=financeiro';
}

function pixgo_webhook_app_url(): string
{
    $baseUrl = trim((string)(getenv('APP_PUBLIC_URL') ?: getenv('APP_URL') ?: (defined('APP_URL') ? APP_URL : '')));
    if ($baseUrl === '' || str_contains(strtolower($baseUrl), 'railway.app')) {
        $baseUrl = 'https://painelpro.smileeventos.com.br';
    }

    return rtrim($baseUrl, '/');
}

function pixgo_webhook_notify_ceo_payment_completed(PDO $pdo, string $paymentId, ?array $beforeSnapshot, ?array $afterSnapshot): void
{
    $snapshot = $afterSnapshot ?: $beforeSnapshot;
    if (!$snapshot) {
        return;
    }
    if ((string)($beforeSnapshot['status'] ?? '') === 'pago') {
        return;
    }

    $recipients = pixgo_webhook_ceo_recipients($pdo);
    if ($recipients === []) {
        error_log('[PIXGO_WEBHOOK] Nenhum destinatário CEO encontrado para pagamento ' . $paymentId);
        return;
    }

    $valor = format_currency($snapshot['valor'] ?? 0);
    $descricao = trim((string)($snapshot['descricao'] ?? 'Pagamento PixGo'));
    $eventoNome = trim((string)($snapshot['evento_nome'] ?? ''));
    $eventoData = trim((string)($snapshot['evento_data'] ?? ''));
    $formando = trim((string)($snapshot['nome_formando'] ?? ''));
    $origem = (string)($snapshot['origem'] ?? '');

    $partes = ["Pagamento PixGo confirmado: {$valor}"];
    if ($descricao !== '') {
        $partes[] = $descricao;
    }
    if ($eventoNome !== '') {
        $eventoTexto = 'Evento: ' . $eventoNome;
        if ($eventoData !== '') {
            $eventoTexto .= ' - ' . brDateOnly($eventoData);
        }
        $partes[] = $eventoTexto;
    }
    if ($formando !== '') {
        $partes[] = 'Formando: ' . $formando;
    }
    if (!empty($snapshot['vencimento'])) {
        $partes[] = 'Vencimento: ' . brDateOnly((string)$snapshot['vencimento']);
    }

    $titulo = 'PixGo pago: ' . $valor;
    $mensagem = implode("\n", $partes);
    $urlDestino = pixgo_webhook_payment_url($snapshot, $paymentId);

    try {
        $dispatcher = new NotificationDispatcher($pdo);
        $dispatcher->ensureInternalSchema();
        $dispatcher->dispatch(
            $recipients,
            [
                'tipo' => 'pixgo_pagamento_confirmado',
                'referencia_id' => (int)($snapshot['id'] ?? 0) ?: null,
                'titulo' => $titulo,
                'mensagem' => $mensagem,
                'url_destino' => $urlDestino,
                'push_titulo' => $titulo,
                'push_mensagem' => str_replace("\n", ' · ', $mensagem),
                'push_data' => [
                    'url' => $urlDestino,
                    'tipo' => 'pixgo_pagamento_confirmado',
                    'payment_id' => $paymentId,
                    'origem' => $origem,
                ],
                'whatsapp_mensagem' => $mensagem . "\n\nAbrir no painel: " . pixgo_webhook_app_url() . '/' . ltrim($urlDestino, '/'),
            ],
            [
                'internal' => true,
                'push' => true,
                'whatsapp' => true,
            ]
        );
    } catch (Throwable $e) {
        error_log('[PIXGO_WEBHOOK] Falha ao notificar CEO sobre pagamento ' . $paymentId . ': ' . $e->getMessage());
    }
}

try {
    $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    pixgo_webhook_response(400, ['ok' => false, 'error' => 'invalid_json']);
}
if (!is_array($payload)) {
    pixgo_webhook_response(400, ['ok' => false, 'error' => 'invalid_payload']);
}

$event = trim((string)($payload['event'] ?? $headerEvent));
if ($event === '' || ($headerEvent !== '' && !hash_equals($headerEvent, $event))) {
    pixgo_webhook_response(400, ['ok' => false, 'error' => 'event_mismatch']);
}
$allowedEvents = ['payment.completed', 'payment.expired', 'payment.refunded'];
if (!in_array($event, $allowedEvents, true)) {
    pixgo_webhook_response(400, ['ok' => false, 'error' => 'unsupported_event']);
}

$data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
$paymentId = trim((string)($data['payment_id'] ?? $payload['payment_id'] ?? ''));
if ($paymentId === '') {
    pixgo_webhook_response(400, ['ok' => false, 'error' => 'missing_payment_id']);
}

try {
    eventos_financeiro_ensure_schema($pdo);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pixgo_webhook_events (
            id BIGSERIAL PRIMARY KEY,
            payment_id VARCHAR(120) NOT NULL,
            event_type VARCHAR(80) NOT NULL,
            signature TEXT NULL,
            payload_raw TEXT NOT NULL,
            signature_valid BOOLEAN NOT NULL DEFAULT FALSE,
            processing_status VARCHAR(30) NOT NULL DEFAULT 'recebido',
            error_message TEXT NULL,
            received_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            processed_at TIMESTAMPTZ NULL,
            UNIQUE(payment_id, event_type)
        )
    ");

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
        INSERT INTO pixgo_webhook_events
            (payment_id, event_type, signature, payload_raw, signature_valid, processing_status)
        VALUES
            (:payment_id, :event_type, :signature, :payload_raw, TRUE, 'processando')
        ON CONFLICT (payment_id, event_type) DO NOTHING
        RETURNING id
    ");
    $stmt->execute([
        ':payment_id' => $paymentId,
        ':event_type' => $event,
        ':signature' => $signature,
        ':payload_raw' => $rawBody,
    ]);
    $eventId = $stmt->fetchColumn();
    if (!$eventId) {
        $pdo->rollBack();
        pixgo_webhook_response(200, ['ok' => true, 'duplicate' => true]);
    }

    $beforeSnapshot = pixgo_webhook_fetch_payment_snapshot($pdo, $paymentId);
    $updatedEvento = eventos_financeiro_atualizar_pixgo_payment($pdo, $paymentId, $data, $event);
    $updatedFormatura = eventos_formatura_financeiro_atualizar_pixgo_payment($pdo, $paymentId, $data, $event);
    $updatedComercial = comercial_pagamento_atualizar_pixgo_payment($pdo, $paymentId, $data, $event);

    $stmt = $pdo->prepare("
        UPDATE pixgo_webhook_events
        SET processing_status = :status,
            processed_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':status' => ($updatedEvento || $updatedFormatura || $updatedComercial) ? 'processado' : 'sem_vinculo',
        ':id' => $eventId,
    ]);
    $pdo->commit();

    $afterSnapshot = null;
    if ($event === 'payment.completed' && ($updatedEvento || $updatedFormatura || $updatedComercial)) {
        $afterSnapshot = pixgo_webhook_fetch_payment_snapshot($pdo, $paymentId);
        pixgo_webhook_notify_ceo_payment_completed($pdo, $paymentId, $beforeSnapshot, $afterSnapshot);
    }

    pixgo_webhook_response(200, [
        'ok' => true,
        'processed' => $updatedEvento || $updatedFormatura || $updatedComercial,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[PIXGO_WEBHOOK] ' . $e->getMessage());
    pixgo_webhook_response(500, ['ok' => false, 'error' => 'processing_failed']);
}
