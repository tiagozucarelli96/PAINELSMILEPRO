#!/usr/bin/env php
<?php
declare(strict_types=1);

function portao_agent_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return (string)$value;
    }

    return $default;
}

function portao_agent_log(string $message): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
}

function portao_agent_json_request(string $method, string $url, string $token, ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'cURL nao disponivel no PHP CLI.'];
    }

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ];

    $body = null;
    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'message' => 'Falha HTTP: ' . ($curlError ?: 'erro desconhecido')];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'message' => 'Resposta nao-JSON: ' . substr($raw, 0, 300),
        ];
    }

    $ok = ($httpCode >= 200 && $httpCode < 300) && !empty($decoded['ok']);
    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'message' => (string)($decoded['message'] ?? ''),
        'data' => $decoded,
    ];
}

function portao_agent_local_execute(string $url, string $token, array $command): array
{
    $payload = [
        'action' => (string)($command['action'] ?? ''),
        'origin' => (string)($command['origin'] ?? 'manual'),
        'command_id' => (int)($command['id'] ?? 0),
        'requested_at' => (string)($command['requested_at'] ?? date('c')),
        'payload' => $command['payload'] ?? [],
    ];

    return portao_agent_json_request('POST', $url, $token, $payload);
}

$baseUrl = rtrim((string)(portao_agent_env('PORTAO_AGENT_BASE_URL', '') ?? ''), '/');
$token = trim((string)(portao_agent_env('PORTAO_AGENT_TOKEN', '') ?? ''));
$localUrl = trim((string)(portao_agent_env('PORTAO_LOCAL_URL', '') ?? ''));
$localToken = trim((string)(portao_agent_env('PORTAO_LOCAL_TOKEN', $token) ?? $token));
$agentId = trim((string)(portao_agent_env('PORTAO_AGENT_ID', gethostname() ?: 'portao-agent') ?? 'portao-agent'));
$pollSeconds = (int)(portao_agent_env('PORTAO_AGENT_POLL_SECONDS', '3') ?? '3');
$runOnce = in_array('--once', $argv, true);

if ($baseUrl === '' || $token === '' || $localUrl === '') {
    fwrite(STDERR, "Variaveis obrigatorias:\n");
    fwrite(STDERR, "  PORTAO_AGENT_BASE_URL=https://seu-app.railway.app\n");
    fwrite(STDERR, "  PORTAO_AGENT_TOKEN=segredo-compartilhado\n");
    fwrite(STDERR, "  PORTAO_LOCAL_URL=http://127.0.0.1:8080/portao\n");
    exit(1);
}

if ($pollSeconds < 1) {
    $pollSeconds = 1;
}
if ($pollSeconds > 60) {
    $pollSeconds = 60;
}

$nextUrl = $baseUrl . '/portao_agent_api.php?action=next&agent_id=' . rawurlencode($agentId);
$ackUrl = $baseUrl . '/portao_agent_api.php?action=ack&agent_id=' . rawurlencode($agentId);

portao_agent_log('Agente iniciado em ' . $agentId . '.');

do {
    $next = portao_agent_json_request('GET', $nextUrl, $token, null);
    if (empty($next['ok'])) {
        portao_agent_log('Falha ao consultar fila: ' . ($next['message'] ?? 'erro desconhecido'));
        if ($runOnce) {
            exit(1);
        }
        sleep($pollSeconds);
        continue;
    }

    $payload = is_array($next['data'] ?? null) ? $next['data'] : [];
    $command = is_array($payload['command'] ?? null) ? $payload['command'] : null;

    if ($command === null) {
        if ($runOnce) {
            exit(0);
        }
        sleep(max(1, (int)($payload['poll_after_seconds'] ?? $pollSeconds)));
        continue;
    }

    portao_agent_log('Executando comando #' . (int)$command['id'] . ' (' . (string)$command['action'] . ').');
    $local = portao_agent_local_execute($localUrl, $localToken, $command);
    $success = !empty($local['ok']);
    $localData = is_array($local['data'] ?? null) ? $local['data'] : [];
    $estado = '';

    if ($success) {
        $estado = trim((string)($localData['estado'] ?? ''));
        if ($estado === '') {
            $estado = ((string)$command['action'] === 'abrir') ? 'aberto' : 'fechado';
        }
    }

    $ackPayload = [
        'command_id' => (int)$command['id'],
        'ok' => $success,
        'message' => $local['message'] ?? ($success ? 'Comando executado localmente.' : 'Falha na execucao local.'),
        'estado' => $estado,
        'payload' => $localData,
    ];

    $ack = portao_agent_json_request('POST', $ackUrl, $token, $ackPayload);
    if (!empty($ack['ok'])) {
        portao_agent_log('Comando #' . (int)$command['id'] . ' confirmado com status ' . ($success ? 'success' : 'error') . '.');
    } else {
        portao_agent_log('Falha ao confirmar comando #' . (int)$command['id'] . ': ' . ($ack['message'] ?? 'erro desconhecido'));
    }

    if ($runOnce) {
        exit($success ? 0 : 1);
    }
} while (true);
