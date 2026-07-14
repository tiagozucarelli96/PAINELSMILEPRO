<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/config_env.php';
require_once __DIR__ . '/pixgo_helper.php';
require_once __DIR__ . '/eventos_financeiro_helper.php';
require_once __DIR__ . '/comercial_pagamento_solicitacao_helper.php';

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
