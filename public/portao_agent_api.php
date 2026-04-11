<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/portao_helper.php';

$pdo = $GLOBALS['pdo'] ?? ($pdo ?? null);
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Conexao com banco indisponivel.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

portao_ensure_schema($pdo);

function portao_agent_api_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function portao_agent_api_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function portao_agent_api_bearer_token(): string
{
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (is_array($headers)) {
            $header = (string)($headers['Authorization'] ?? $headers['authorization'] ?? '');
        }
    }

    if (preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
        return trim((string)$matches[1]);
    }

    return '';
}

function portao_agent_api_parse_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'ok', 'yes', 'sim', 'success'], true);
}

$expectedToken = portao_agent_token();
if ($expectedToken === '') {
    portao_agent_api_response(503, [
        'ok' => false,
        'message' => 'PORTAO_AGENT_TOKEN nao configurado.',
    ]);
}

$providedToken = portao_agent_api_bearer_token();
if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    portao_agent_api_response(401, [
        'ok' => false,
        'message' => 'Token do agente invalido.',
    ]);
}

$body = portao_agent_api_read_json_body();
$action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? ($body['action'] ?? ''))));
$agentId = trim((string)($_GET['agent_id'] ?? $_POST['agent_id'] ?? ($body['agent_id'] ?? 'portao-agent')));

if ($action === '') {
    portao_agent_api_response(400, [
        'ok' => false,
        'message' => 'Informe ?action=next ou ?action=ack.',
    ]);
}

if ($action === 'next') {
    $result = portao_fetch_next_command($pdo, $agentId);
    if (empty($result['ok'])) {
        portao_agent_api_response(500, [
            'ok' => false,
            'message' => (string)($result['message'] ?? 'Falha ao buscar comando pendente.'),
        ]);
    }

    $command = $result['command'] ?? null;
    if (!is_array($command)) {
        portao_agent_api_response(200, [
            'ok' => true,
            'command' => null,
            'poll_after_seconds' => 3,
        ]);
    }

    $payload = [];
    if (!empty($command['payload_json'])) {
        $decodedPayload = json_decode((string)$command['payload_json'], true);
        if (is_array($decodedPayload)) {
            $payload = $decodedPayload;
        }
    }

    portao_agent_api_response(200, [
        'ok' => true,
        'command' => [
            'id' => (int)($command['id'] ?? 0),
            'action' => (string)($command['acao'] ?? ''),
            'origin' => (string)($command['origem'] ?? 'manual'),
            'status' => (string)($command['status'] ?? 'processing'),
            'requested_at' => (string)($command['criado_em'] ?? ''),
            'requested_by' => [
                'user_id' => isset($command['usuario_id']) ? (int)$command['usuario_id'] : null,
                'user_name' => (string)($command['usuario_nome'] ?? ''),
            ],
            'payload' => $payload,
        ],
        'poll_after_seconds' => 1,
    ]);
}

if ($action === 'ack') {
    $commandId = (int)($_POST['command_id'] ?? ($body['command_id'] ?? 0));
    $success = portao_agent_api_parse_bool($_POST['ok'] ?? ($body['ok'] ?? false));
    $message = trim((string)($_POST['message'] ?? ($body['message'] ?? '')));
    $estado = trim((string)($_POST['estado'] ?? ($body['estado'] ?? '')));
    $resultPayload = $body['payload'] ?? null;
    if (!is_array($resultPayload)) {
        $resultPayload = null;
    }

    $result = portao_finish_command(
        $pdo,
        $commandId,
        $success,
        $message,
        $estado !== '' ? $estado : null,
        $resultPayload,
        $agentId
    );

    if (empty($result['ok'])) {
        portao_agent_api_response(422, [
            'ok' => false,
            'message' => (string)($result['message'] ?? 'Falha ao confirmar comando.'),
        ]);
    }

    portao_agent_api_response(200, [
        'ok' => true,
        'message' => (string)($result['message'] ?? 'Comando confirmado.'),
        'command_id' => (int)($result['command_id'] ?? $commandId),
        'status' => (string)($result['status'] ?? ($success ? 'success' : 'error')),
        'estado' => (string)($result['estado'] ?? ''),
    ]);
}

portao_agent_api_response(400, [
    'ok' => false,
    'message' => 'Acao invalida. Use next ou ack.',
]);
