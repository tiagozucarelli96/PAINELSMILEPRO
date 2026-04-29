<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/setup_administrativo_juridico.php';
require_once __DIR__ . '/setup_gestao_documentos.php';
require_once __DIR__ . '/core/clicksign_helper.php';

setupAdministrativoJuridico($pdo);
setupGestaoDocumentos($pdo);

header('Content-Type: application/json; charset=utf-8');

function clicksignWebhookLog(array $data): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents($dir . '/clicksign_webhook.log', $line, FILE_APPEND);
}

function clicksignWebhookJsonResponse(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function clicksignWebhookFindValue(array $payload, array $path)
{
    $cursor = $payload;
    foreach ($path as $segment) {
        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
            return null;
        }
        $cursor = $cursor[$segment];
    }

    return $cursor;
}

function clicksignWebhookExtractEnvelopeId(array $payload): string
{
    $candidates = [
        ['envelope_id'],
        ['envelopeId'],
        ['data', 'id'],
        ['data', 'attributes', 'envelope_id'],
        ['data', 'attributes', 'envelopeId'],
        ['data', 'attributes', 'data', 'envelope', 'key'],
        ['data', 'attributes', 'data', 'envelope', 'id'],
        ['data', 'relationships', 'envelope', 'data', 'id'],
        ['event', 'data', 'envelope', 'key'],
        ['event', 'data', 'envelope', 'id'],
    ];

    foreach ($candidates as $path) {
        $value = clicksignWebhookFindValue($payload, $path);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($json) && preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i', $json, $match)) {
        return strtolower($match[0]);
    }

    return '';
}

function clicksignWebhookExtractEventName(array $payload): string
{
    $candidates = [
        ['event', 'name'],
        ['event', 'type'],
        ['type'],
        ['name'],
        ['data', 'attributes', 'name'],
        ['data', 'attributes', 'event'],
    ];

    foreach ($candidates as $path) {
        $value = clicksignWebhookFindValue($payload, $path);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    return 'unknown';
}

function clicksignWebhookUpdateJuridico(PDO $pdo, array $summary, string $envelopeId): int
{
    $stmt = $pdo->prepare(
        'SELECT id
         FROM administrativo_juridico_arquivos
         WHERE clicksign_envelope_id = :envelope_id'
    );
    $stmt->execute([':envelope_id' => $envelopeId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (empty($ids)) {
        return 0;
    }

    $update = $pdo->prepare(
        'UPDATE administrativo_juridico_arquivos
         SET status_assinatura = :status,
             clicksign_sign_url = :sign_url,
             clicksign_payload = :payload::jsonb,
             clicksign_ultimo_erro = NULL,
             atualizado_em = NOW()
         WHERE id = :id'
    );

    $count = 0;
    foreach ($ids as $id) {
        $update->execute([
            ':status' => $summary['status_local'] ?? 'enviado',
            ':sign_url' => $summary['signers'][0]['signature_url'] ?? null,
            ':payload' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id' => (int)$id,
        ]);
        $count++;
    }

    return $count;
}

function clicksignWebhookUpdateGestaoDocumentos(PDO $pdo, array $summary, string $envelopeId): int
{
    $stmt = $pdo->prepare(
        'SELECT id
         FROM administrativo_documentos_colaboradores
         WHERE clicksign_envelope_id = :envelope_id'
    );
    $stmt->execute([':envelope_id' => $envelopeId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (empty($ids)) {
        return 0;
    }

    $update = $pdo->prepare(
        'UPDATE administrativo_documentos_colaboradores
         SET status_assinatura = :status,
             clicksign_sign_url = :sign_url,
             clicksign_payload = :payload::jsonb,
             clicksign_ultimo_erro = NULL,
             atualizado_em = NOW()
         WHERE id = :id'
    );

    $count = 0;
    foreach ($ids as $id) {
        $update->execute([
            ':status' => $summary['status_local'] ?? 'enviado',
            ':sign_url' => $summary['signers'][0]['signature_url'] ?? null,
            ':payload' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id' => (int)$id,
        ]);
        $count++;
    }

    return $count;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    clicksignWebhookJsonResponse(405, ['success' => false, 'error' => 'method_not_allowed']);
}

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    clicksignWebhookLog([
        'received' => false,
        'reason' => 'invalid_json',
        'raw' => $rawBody,
    ]);
    clicksignWebhookJsonResponse(400, ['success' => false, 'error' => 'invalid_json']);
}

$envelopeId = clicksignWebhookExtractEnvelopeId($payload);
$eventName = clicksignWebhookExtractEventName($payload);

clicksignWebhookLog([
    'received' => true,
    'event' => $eventName,
    'envelope_id' => $envelopeId,
    'payload' => $payload,
]);

if ($envelopeId === '') {
    clicksignWebhookJsonResponse(202, [
        'success' => true,
        'message' => 'Webhook recebido sem envelope identificável.',
    ]);
}

$clicksign = new ClicksignHelper();
if (!$clicksign->isConfigured()) {
    clicksignWebhookLog([
        'received' => true,
        'event' => $eventName,
        'envelope_id' => $envelopeId,
        'sync' => false,
        'reason' => 'clicksign_not_configured',
    ]);
    clicksignWebhookJsonResponse(202, [
        'success' => true,
        'message' => 'Webhook recebido, mas integração local não está configurada.',
    ]);
}

$summary = $clicksign->consultarFluxoAssinatura($envelopeId);
if (!($summary['success'] ?? false)) {
    clicksignWebhookLog([
        'received' => true,
        'event' => $eventName,
        'envelope_id' => $envelopeId,
        'sync' => false,
        'reason' => $summary['error'] ?? 'summary_failed',
    ]);
    clicksignWebhookJsonResponse(202, [
        'success' => true,
        'message' => 'Webhook recebido, mas não foi possível sincronizar agora.',
    ]);
}

$pdo->beginTransaction();
try {
    $juridicoUpdated = clicksignWebhookUpdateJuridico($pdo, $summary, $envelopeId);
    $gestaoUpdated = clicksignWebhookUpdateGestaoDocumentos($pdo, $summary, $envelopeId);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    clicksignWebhookLog([
        'received' => true,
        'event' => $eventName,
        'envelope_id' => $envelopeId,
        'sync' => false,
        'reason' => $e->getMessage(),
    ]);
    clicksignWebhookJsonResponse(500, ['success' => false, 'error' => 'sync_failed']);
}

clicksignWebhookLog([
    'received' => true,
    'event' => $eventName,
    'envelope_id' => $envelopeId,
    'sync' => true,
    'status_local' => $summary['status_local'] ?? null,
    'juridico_updated' => $juridicoUpdated,
    'gestao_updated' => $gestaoUpdated,
]);

clicksignWebhookJsonResponse(200, [
    'success' => true,
    'event' => $eventName,
    'envelope_id' => $envelopeId,
    'status_local' => $summary['status_local'] ?? null,
    'juridico_updated' => $juridicoUpdated,
    'gestao_updated' => $gestaoUpdated,
]);
