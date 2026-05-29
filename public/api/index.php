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
            client_app_api_log('login_not_found', [
                'event_date' => $eventDate,
                'location_id' => $locationId,
                'me_available' => client_app_api_me_available(),
            ]);
            client_app_api_error(401, 'Evento não encontrado com os dados informados.');
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

    if ($method === 'GET' && $path === '/v1/client/modules/reuniao-final') {
        $pdo = client_app_api_pdo();
        $session = client_app_api_require_session($pdo);
        client_app_api_json(200, [
            'ok' => true,
            'module' => client_app_api_module_reuniao_final($pdo, (int)$session['meeting_id']),
        ]);
    }

    if ($method === 'GET' && $path === '/v1/client/modules/convidados') {
        $pdo = client_app_api_pdo();
        $session = client_app_api_require_session($pdo);
        $search = trim((string)($_GET['search'] ?? ''));
        client_app_api_json(200, [
            'ok' => true,
            'module' => client_app_api_module_convidados($pdo, (int)$session['meeting_id'], $search),
        ]);
    }

    if ($method === 'POST' && $path === '/v1/client/modules/convidados/save') {
        $pdo = client_app_api_pdo();
        $session = client_app_api_require_session($pdo);
        $body = client_app_api_request_body();
        client_app_api_json(200, client_app_api_module_convidados_salvar($pdo, (int)$session['meeting_id'], $body));
    }

    if ($method === 'POST' && $path === '/v1/client/modules/convidados/delete') {
        $pdo = client_app_api_pdo();
        $session = client_app_api_require_session($pdo);
        $body = client_app_api_request_body();
        client_app_api_json(200, client_app_api_module_convidados_excluir($pdo, (int)$session['meeting_id'], $body));
    }

    if ($method === 'GET' && $path === '/v1/client/modules/arquivos') {
        $pdo = client_app_api_pdo();
        $session = client_app_api_require_session($pdo);
        client_app_api_json(200, [
            'ok' => true,
            'module' => client_app_api_module_arquivos($pdo, (int)$session['meeting_id']),
        ]);
    }

    if ($method === 'POST' && $path === '/v1/client/modules/arquivos/upload') {
        $pdo = client_app_api_pdo();
        $session = client_app_api_require_session($pdo);
        client_app_api_json(200, client_app_api_module_arquivos_upload($pdo, (int)$session['meeting_id']));
    }

    if ($method === 'POST' && $path === '/v1/client/modules/arquivos/delete') {
        $pdo = client_app_api_pdo();
        $session = client_app_api_require_session($pdo);
        $body = client_app_api_request_body();
        client_app_api_json(200, client_app_api_module_arquivos_excluir($pdo, (int)$session['meeting_id'], $body));
    }

    if ($method === 'GET' && $path === '/v1/client/modules/dj') {
        $pdo = client_app_api_pdo();
        $session = client_app_api_require_session($pdo);
        client_app_api_json(200, [
            'ok' => true,
            'module' => client_app_api_module_links($pdo, (int)$session['meeting_id'], 'dj'),
        ]);
    }

    if ($method === 'GET' && $path === '/v1/client/modules/formulario') {
        $pdo = client_app_api_pdo();
        $session = client_app_api_require_session($pdo);
        client_app_api_json(200, [
            'ok' => true,
            'module' => client_app_api_module_links($pdo, (int)$session['meeting_id'], 'formulario'),
        ]);
    }

    if ($method === 'POST' && $path === '/v1/client/modules/form-response/save') {
        $pdo = client_app_api_pdo();
        $session = client_app_api_require_session($pdo);
        $body = client_app_api_request_body();
        client_app_api_json(200, client_app_api_module_form_response_salvar($pdo, (int)$session['meeting_id'], $body));
    }

    if ($method === 'POST' && $path === '/v1/client/modules/form-response/upload') {
        $pdo = client_app_api_pdo();
        $session = client_app_api_require_session($pdo);
        client_app_api_json(200, client_app_api_module_form_response_upload($pdo, (int)$session['meeting_id'], $_POST, $_FILES));
    }

    if ($method === 'POST' && $path === '/v1/client/modules/form-response/delete-file') {
        $pdo = client_app_api_pdo();
        $session = client_app_api_require_session($pdo);
        $body = client_app_api_request_body();
        client_app_api_json(200, client_app_api_module_form_response_excluir_arquivo($pdo, (int)$session['meeting_id'], $body));
    }

    client_app_api_error(404, 'Endpoint não encontrado.');
} catch (Throwable $e) {
    error_log('client_app_api: ' . $e->getMessage());
    client_app_api_error(500, 'Erro interno ao processar a requisição.');
}
