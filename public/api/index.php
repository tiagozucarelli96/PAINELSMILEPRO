<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

client_app_api_allow_cors();

if (client_app_api_method() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = rtrim(client_app_api_request_path(), '/');
if ($path === '') {
    $path = '/';
}
$method = client_app_api_method();

try {
    if ($method === 'GET' && $path === '/v1/health') {
        client_app_api_json(200, [
            'ok' => true,
            'service' => 'cliente-app-api',
            'date' => gmdate('c'),
        ]);
    }
    if ($method === 'GET' && $path === '/v1/client/locations') {
        $pdo = client_app_api_pdo();
        client_app_api_json(200, [
            'ok' => true,
            'items' => client_app_api_locations($pdo),
        ]);
    }

    if ($method === 'POST' && $path === '/v1/auth/login') {
        $pdo = client_app_api_pdo();
        client_app_api_ensure_schema($pdo);
        $body = client_app_api_request_body();
        $cpf = client_app_api_normalize_cpf((string)($body['cpf'] ?? ''));
        $eventDate = trim((string)($body['event_date'] ?? ''));
        $locationId = (int)($body['event_location_id'] ?? 0);

        if ($cpf === '' || strlen($cpf) !== 11 || !client_app_api_validate_cpf($cpf)) {
            client_app_api_error(422, 'CPF inválido.');
        }
        if ($eventDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            client_app_api_error(422, 'Data do evento inválida. Use YYYY-MM-DD.');
        }
        if ($locationId <= 0) {
            client_app_api_error(422, 'Local do evento inválido.');
        }

        $ip = client_app_api_client_ip();
        if (client_app_api_is_login_blocked($pdo, $cpf, $ip)) {
            client_app_api_error(429, 'Muitas tentativas. Aguarde alguns minutos e tente novamente.');
        }

        $meetingMatch = client_app_api_find_meeting($pdo, $cpf, $eventDate, $locationId);
        if (!$meetingMatch) {
            client_app_api_record_login_attempt($pdo, $cpf, $eventDate, (string)$locationId, null, false, 'nao_encontrado');
            client_app_api_error(401, 'Dados do evento não conferem.');
        }

        $meetingId = (int)($meetingMatch['meeting_id'] ?? 0);
        $session = client_app_api_create_session($pdo, $meetingId, $cpf, [
            'device_name' => $body['device_name'] ?? '',
            'platform' => $body['platform'] ?? '',
            'app_version' => $body['app_version'] ?? '',
        ]);
        $event = client_app_api_event_payload($pdo, $meetingId);

        client_app_api_record_login_attempt($pdo, $cpf, $eventDate, (string)$locationId, $meetingId, true, 'ok');
        client_app_api_json(200, [
            'ok' => true,
            'token' => $session['token'],
            'expires_at' => $session['expires_at'],
            'event' => [
                'meeting_id' => $event['meeting_id'],
                'me_event_id' => $event['me_event_id'],
                'name' => $event['name'],
                'date' => $event['date'],
                'location' => $event['location'],
                'client_name' => $event['client_name'],
            ],
        ]);
    }

    if ($method === 'GET' && $path === '/v1/auth/me') {
        $pdo = client_app_api_pdo();
        $session = client_app_api_require_session($pdo);
        client_app_api_json(200, [
            'ok' => true,
            'session' => [
                'id' => (int)($session['id'] ?? 0),
                'meeting_id' => (int)($session['meeting_id'] ?? 0),
                'expires_at' => str_replace(' ', 'T', (string)($session['expires_at'] ?? '')) . 'Z',
            ],
            'event' => client_app_api_event_payload($pdo, (int)$session['meeting_id']),
        ]);
    }

    if ($method === 'POST' && $path === '/v1/auth/logout') {
        $pdo = client_app_api_pdo();
        $session = client_app_api_require_session($pdo);
        $stmt = $pdo->prepare("UPDATE cliente_app_sessoes SET revoked_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => (int)$session['id']]);

        client_app_api_json(200, [
            'ok' => true,
        ]);
    }

    if ($method === 'GET' && $path === '/v1/client/event/summary') {
        $pdo = client_app_api_pdo();
        $session = client_app_api_require_session($pdo);
        client_app_api_json(200, [
            'ok' => true,
            'event' => client_app_api_event_payload($pdo, (int)$session['meeting_id']),
        ]);
    }

    client_app_api_error(404, 'Endpoint não encontrado.');
} catch (Throwable $e) {
    error_log('client_app_api: ' . $e->getMessage());
    client_app_api_error(500, 'Erro interno ao processar a requisição.');
}
