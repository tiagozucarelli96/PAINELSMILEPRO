<?php
declare(strict_types=1);

function client_app_api_pdo(): PDO
{
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
        require_once dirname(__DIR__) . '/conexao.php';
    }
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Conexão com banco indisponível.');
    }

    return $pdo;
}

function client_app_api_allow_cors(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
}

function client_app_api_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function client_app_api_error(int $status, string $message, array $extra = []): void
{
    client_app_api_json($status, array_merge([
        'ok' => false,
        'error' => $message,
    ], $extra));
}

function client_app_api_request_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function client_app_api_request_path(): string
{
    $forced = trim((string)($_SERVER['PAINEL_API_PATH_INFO'] ?? ''));
    if ($forced !== '') {
        return '/' . ltrim($forced, '/');
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = is_string($path) ? $path : '/';
    $apiPos = strpos($path, '/api/');
    if ($apiPos !== false) {
        return substr($path, $apiPos + 4) ?: '/';
    }

    if ($path === '/api') {
        return '/';
    }

    return $path;
}

function client_app_api_method(): string
{
    return strtoupper(trim((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')));
}

function client_app_api_bearer_token(): string
{
    $headers = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
    ];

    foreach ($headers as $header) {
        if (!is_string($header) || stripos($header, 'Bearer ') !== 0) {
            continue;
        }

        return trim(substr($header, 7));
    }

    return '';
}

function client_app_api_normalize_cpf(?string $cpf): string
{
    return preg_replace('/\D+/', '', (string)$cpf);
}

function client_app_api_validate_cpf(string $cpf): bool
{
    $cpf = client_app_api_normalize_cpf($cpf);
    if (strlen($cpf) !== 11) {
        return false;
    }
    if (preg_match('/(\d)\1{10}/', $cpf)) {
        return false;
    }

    for ($t = 9; $t < 11; $t++) {
        $sum = 0;
        for ($c = 0; $c < $t; $c++) {
            $sum += ((int)$cpf[$c]) * (($t + 1) - $c);
        }
        $digit = ((10 * $sum) % 11) % 10;
        if ((int)$cpf[$t] !== $digit) {
            return false;
        }
    }

    return true;
}

function client_app_api_uuid_token(): string
{
    return bin2hex(random_bytes(32));
}

function client_app_api_hash_token(string $token): string
{
    return hash('sha256', $token);
}

function client_app_api_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $cacheKey = $table . '.' . $column;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = :table_name
          AND column_name = :column_name
        LIMIT 1
    ");
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);

    $cache[$cacheKey] = (bool)$stmt->fetchColumn();
    return $cache[$cacheKey];
}

function client_app_api_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cliente_app_sessoes (
            id BIGSERIAL PRIMARY KEY,
            meeting_id BIGINT NOT NULL REFERENCES eventos_reunioes(id) ON DELETE CASCADE,
            cpf_hash VARCHAR(64) NOT NULL,
            access_token_hash VARCHAR(64) NOT NULL UNIQUE,
            device_name VARCHAR(120),
            platform VARCHAR(40),
            app_version VARCHAR(40),
            ip VARCHAR(64),
            user_agent TEXT,
            last_seen_at TIMESTAMP NOT NULL DEFAULT NOW(),
            expires_at TIMESTAMP NOT NULL,
            revoked_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        );
        CREATE INDEX IF NOT EXISTS idx_cliente_app_sessoes_meeting_id ON cliente_app_sessoes(meeting_id);
        CREATE INDEX IF NOT EXISTS idx_cliente_app_sessoes_expires_at ON cliente_app_sessoes(expires_at);
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cliente_app_login_tentativas (
            id BIGSERIAL PRIMARY KEY,
            cpf_digitado VARCHAR(11) NOT NULL DEFAULT '',
            data_evento_digitada DATE NULL,
            local_digitado VARCHAR(120) NOT NULL DEFAULT '',
            meeting_id_encontrado BIGINT NULL REFERENCES eventos_reunioes(id) ON DELETE SET NULL,
            sucesso BOOLEAN NOT NULL DEFAULT FALSE,
            motivo VARCHAR(80) NOT NULL DEFAULT '',
            ip VARCHAR(64),
            user_agent TEXT,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        );
        CREATE INDEX IF NOT EXISTS idx_cliente_app_login_tentativas_cpf_created_at
            ON cliente_app_login_tentativas(cpf_digitado, created_at DESC);
        CREATE INDEX IF NOT EXISTS idx_cliente_app_login_tentativas_ip_created_at
            ON cliente_app_login_tentativas(ip, created_at DESC);
    ");

    $ready = true;
}

function client_app_api_client_ip(): string
{
    return trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
}

function client_app_api_user_agent(): string
{
    return trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function client_app_api_is_login_blocked(PDO $pdo, string $cpf, string $ip): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM cliente_app_login_tentativas
        WHERE sucesso = FALSE
          AND created_at >= NOW() - INTERVAL '15 minutes'
          AND (
              (:cpf <> '' AND cpf_digitado = :cpf)
              OR (:ip <> '' AND ip = :ip)
          )
    ");
    $stmt->execute([
        ':cpf' => $cpf,
        ':ip' => $ip,
    ]);

    return (int)$stmt->fetchColumn() >= 5;
}

function client_app_api_record_login_attempt(
    PDO $pdo,
    string $cpf,
    ?string $eventDate,
    string $locationId,
    ?int $meetingId,
    bool $success,
    string $reason
): void {
    $stmt = $pdo->prepare("
        INSERT INTO cliente_app_login_tentativas
            (cpf_digitado, data_evento_digitada, local_digitado, meeting_id_encontrado, sucesso, motivo, ip, user_agent, created_at)
        VALUES
            (:cpf_digitado, :data_evento_digitada, :local_digitado, :meeting_id_encontrado, :sucesso, :motivo, :ip, :user_agent, NOW())
    ");
    $stmt->execute([
        ':cpf_digitado' => $cpf,
        ':data_evento_digitada' => $eventDate,
        ':local_digitado' => $locationId,
        ':meeting_id_encontrado' => $meetingId,
        ':sucesso' => $success ? 'true' : 'false',
        ':motivo' => $reason,
        ':ip' => client_app_api_client_ip(),
        ':user_agent' => client_app_api_user_agent(),
    ]);
}

function client_app_api_locations(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            me_local_id,
            me_local_nome,
            COALESCE(NULLIF(TRIM(space_visivel), ''), TRIM(me_local_nome)) AS display_name
        FROM logistica_me_locais
        WHERE status_mapeamento = 'MAPEADO'
          AND me_local_id IS NOT NULL
          AND me_local_id > 0
        ORDER BY display_name, me_local_nome
    ");

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = [
            'id' => (int)($row['me_local_id'] ?? 0),
            'name' => trim((string)($row['display_name'] ?? '')),
            'source_name' => trim((string)($row['me_local_nome'] ?? '')),
        ];
    }

    return $items;
}

function client_app_api_location_row(PDO $pdo, int $locationId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            me_local_id,
            me_local_nome,
            COALESCE(NULLIF(TRIM(space_visivel), ''), TRIM(me_local_nome)) AS display_name
        FROM logistica_me_locais
        WHERE status_mapeamento = 'MAPEADO'
          AND me_local_id = :location_id
        LIMIT 1
    ");
    $stmt->execute([
        ':location_id' => $locationId,
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function client_app_api_me_helpers(): void
{
    require_once dirname(__DIR__) . '/eventos_me_helper.php';
}

function client_app_api_me_fallback_enabled(): bool
{
    return painel_env_bool('CLIENT_APP_ENABLE_ME_FALLBACK', false);
}

function client_app_api_me_available(): bool
{
    if (!client_app_api_me_fallback_enabled()) {
        return false;
    }
    try {
        client_app_api_me_helpers();
        $cfg = eventos_me_get_config();
        return trim((string)($cfg['base'] ?? '')) !== '' && trim((string)($cfg['token'] ?? '')) !== '';
    } catch (Throwable $e) {
        error_log('client_app_api_me_available: ' . $e->getMessage());
        return false;
    }
}

function client_app_api_me_pick_text(array $source, array $keys): string
{
    foreach ($keys as $key) {
        $value = $source[$key] ?? null;
        if (is_scalar($value)) {
            $text = trim((string)$value);
            if ($text !== '') {
                return $text;
            }
        }
    }

    return '';
}

function client_app_api_me_client_cpf(PDO $pdo, array $event): string
{
    try {
        client_app_api_me_helpers();

        $eventCpf = client_app_api_normalize_cpf(client_app_api_me_pick_text($event, [
            'cpfCliente',
            'cpf',
            'cliente_cpf',
            'documento',
            'cpf_cliente',
            'clienteCpf',
            'documentoCliente',
            'cpfDocumento',
            'cpfcnpj',
            'cpfCnpj',
            'doc',
        ]));
        if ($eventCpf !== '') {
            return $eventCpf;
        }

        $clientId = (int)($event['idcliente'] ?? $event['idCliente'] ?? $event['cliente_id'] ?? $event['id_cliente'] ?? $event['clienteId'] ?? $event['client_id'] ?? 0);
        if ($clientId <= 0) {
            return '';
        }

        $resp = eventos_me_request('GET', '/api/v1/clients/' . $clientId);
        if (empty($resp['ok']) || !is_array($resp['data'] ?? null)) {
            return '';
        }

        $client = $resp['data']['data'] ?? $resp['data'] ?? [];
        if (!is_array($client)) {
            return '';
        }

        return client_app_api_normalize_cpf(client_app_api_me_pick_text($client, [
            'cpf',
            'cpfCliente',
            'cpfcliente',
            'cliente_cpf',
            'cpf_cnpj',
            'cpfCnpj',
            'cpfcnpj',
            'documento',
            'documentoCliente',
            'doc',
        ]));
    } catch (Throwable $e) {
        error_log('client_app_api_me_client_cpf: ' . $e->getMessage());
        return '';
    }
}

function client_app_api_me_event_local_matches(array $event, array $location): bool
{
    $eventLocal = mb_strtolower(trim(client_app_api_me_pick_text($event, [
        'localevento',
        'localEvento',
        'nomelocal',
        'local',
        'endereco',
    ])));
    if ($eventLocal === '') {
        return false;
    }

    $candidates = [
        mb_strtolower(trim((string)($location['me_local_nome'] ?? ''))),
        mb_strtolower(trim((string)($location['display_name'] ?? ''))),
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && ($eventLocal === $candidate || str_contains($eventLocal, $candidate) || str_contains($candidate, $eventLocal))) {
            return true;
        }
    }

    return false;
}

function client_app_api_sync_meeting_from_me(PDO $pdo, int $meEventId): ?array
{
    try {
        client_app_api_me_helpers();
        $existing = $pdo->prepare("SELECT id, me_event_id FROM eventos_reunioes WHERE me_event_id = :me_event_id LIMIT 1");
        $existing->execute([
            ':me_event_id' => $meEventId,
        ]);
        $row = $existing->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row) {
            return [
                'meeting_id' => (int)$row['id'],
                'me_event_id' => (int)$row['me_event_id'],
            ];
        }

        $eventResult = eventos_me_buscar_por_id($pdo, $meEventId);
        if (empty($eventResult['ok']) || !is_array($eventResult['event'] ?? null)) {
            return null;
        }

        $snapshot = eventos_me_criar_snapshot($eventResult['event']);
        $insert = $pdo->prepare("
            INSERT INTO eventos_reunioes (me_event_id, me_event_snapshot, status, created_by, created_at, updated_at)
            VALUES (:me_event_id, :snapshot, 'rascunho', 0, NOW(), NOW())
            RETURNING id, me_event_id
        ");
        $insert->execute([
            ':me_event_id' => $meEventId,
            ':snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $created = $insert->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$created) {
            return null;
        }

        $sections = ['decoracao', 'observacoes_gerais', 'dj_protocolo', 'formulario'];
        $sectionStmt = $pdo->prepare("
            INSERT INTO eventos_reunioes_secoes (meeting_id, section, content_html, content_text, created_at, updated_at)
            VALUES (:meeting_id, :section, '', '', NOW(), NOW())
            ON CONFLICT (meeting_id, section) DO NOTHING
        ");
        foreach ($sections as $section) {
            $sectionStmt->execute([
                ':meeting_id' => (int)$created['id'],
                ':section' => $section,
            ]);
        }

        return [
            'meeting_id' => (int)$created['id'],
            'me_event_id' => (int)$created['me_event_id'],
        ];
    } catch (Throwable $e) {
        error_log('client_app_api_sync_meeting_from_me: ' . $e->getMessage());
        return null;
    }
}

function client_app_api_find_meeting_via_me(PDO $pdo, string $cpf, string $eventDate, int $locationId): ?array
{
    try {
        if (!client_app_api_me_available()) {
            return null;
        }

        $location = client_app_api_location_row($pdo, $locationId);
        if (!$location) {
            return null;
        }

        client_app_api_me_helpers();
        $resp = eventos_me_request('GET', '/api/v1/events', [
            'start' => $eventDate,
            'end' => $eventDate,
            'limit' => 500,
        ]);
        if (empty($resp['ok']) || !is_array($resp['data'] ?? null)) {
            return null;
        }

        $events = $resp['data']['data'] ?? $resp['data'] ?? [];
        if (!is_array($events)) {
            return null;
        }

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $eventDateCandidate = trim((string)($event['dataevento'] ?? $event['data'] ?? ''));
            if ($eventDateCandidate !== $eventDate) {
                continue;
            }
            if (!client_app_api_me_event_local_matches($event, $location)) {
                continue;
            }

            $eventCpf = client_app_api_me_client_cpf($pdo, $event);
            if ($eventCpf === '' || $eventCpf !== $cpf) {
                continue;
            }

            $meEventId = (int)($event['id'] ?? 0);
            if ($meEventId <= 0) {
                continue;
            }

            $meeting = client_app_api_sync_meeting_from_me($pdo, $meEventId);
            if ($meeting) {
                return $meeting;
            }
        }

        return null;
    } catch (Throwable $e) {
        error_log('client_app_api_find_meeting_via_me: ' . $e->getMessage());
        return null;
    }
}

function client_app_api_find_meeting_via_local_event_and_me_cpf(PDO $pdo, string $cpf, string $eventDate, int $locationId): ?array
{
    try {
        if (!client_app_api_me_available()) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT
                r.id AS meeting_id,
                r.me_event_id,
                r.me_event_snapshot,
                le.idlocalevento,
                le.localevento,
                COALESCE(
                    NULLIF(TRIM(r.me_event_snapshot->>'data'), '')::date,
                    le.data_evento
                ) AS event_date
            FROM eventos_reunioes r
            LEFT JOIN logistica_eventos_espelho le
                ON le.me_event_id = r.me_event_id
            WHERE COALESCE(
                    NULLIF(TRIM(r.me_event_snapshot->>'data'), '')::date,
                    le.data_evento
                  ) = :event_date
              AND (
                    le.idlocalevento = :location_id
                    OR EXISTS (
                        SELECT 1
                        FROM logistica_me_locais lm
                        WHERE lm.status_mapeamento = 'MAPEADO'
                          AND lm.me_local_id = :location_id
                          AND LOWER(TRIM(lm.me_local_nome)) = LOWER(TRIM(COALESCE(le.localevento, r.me_event_snapshot->>'local', r.me_event_snapshot->>'nomelocal', '')))
                    )
                  )
            ORDER BY r.updated_at DESC NULLS LAST, r.id DESC
            LIMIT 5
        ");
        $stmt->execute([
            ':event_date' => $eventDate,
            ':location_id' => $locationId,
        ]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$candidates) {
            return null;
        }

        foreach ($candidates as $candidate) {
            $meEventId = (int)($candidate['me_event_id'] ?? 0);
            if ($meEventId <= 0) {
                continue;
            }

            $eventResult = eventos_me_buscar_por_id($pdo, $meEventId);
            if (empty($eventResult['ok']) || !is_array($eventResult['event'] ?? null)) {
                continue;
            }

            $eventCpf = client_app_api_me_client_cpf($pdo, $eventResult['event']);
            if ($eventCpf !== '' && $eventCpf === $cpf) {
                return $candidate;
            }
        }

        return null;
    } catch (Throwable $e) {
        error_log('client_app_api_find_meeting_via_local_event_and_me_cpf: ' . $e->getMessage());
        return null;
    }
}

function client_app_api_find_meeting(PDO $pdo, string $cpf, string $eventDate, int $locationId): ?array
{
    $commercialHasCpf = client_app_api_has_column($pdo, 'comercial_inscricoes', 'me_cliente_cpf');
    $salesHasCpf = client_app_api_has_column($pdo, 'vendas_pre_contratos', 'cpf');

    $sources = [];
    if ($commercialHasCpf) {
        $sources[] = "
            SELECT DISTINCT me_event_id
            FROM comercial_inscricoes
            WHERE me_event_id IS NOT NULL
              AND me_event_id > 0
              AND regexp_replace(COALESCE(me_cliente_cpf, ''), '\\D', '', 'g') = :cpf
        ";
    }
    if ($salesHasCpf) {
        $sources[] = "
            SELECT DISTINCT me_event_id
            FROM vendas_pre_contratos
            WHERE me_event_id IS NOT NULL
              AND me_event_id > 0
              AND regexp_replace(COALESCE(cpf, ''), '\\D', '', 'g') = :cpf
        ";
    }

    if (empty($sources)) {
        throw new RuntimeException('Nenhuma fonte de CPF está disponível para a API do app.');
    }

    $sql = "
        WITH cpf_matches AS (
            " . implode("\n            UNION\n", $sources) . "
        )
        SELECT
            r.id AS meeting_id,
            r.me_event_id,
            r.me_event_snapshot,
            le.idlocalevento,
            le.localevento,
            COALESCE(
                NULLIF(TRIM(r.me_event_snapshot->>'data'), '')::date,
                le.data_evento
            ) AS event_date
        FROM eventos_reunioes r
        INNER JOIN cpf_matches cm
            ON cm.me_event_id = r.me_event_id
        LEFT JOIN logistica_eventos_espelho le
            ON le.me_event_id = r.me_event_id
        WHERE COALESCE(
                NULLIF(TRIM(r.me_event_snapshot->>'data'), '')::date,
                le.data_evento
              ) = :event_date
          AND (
                le.idlocalevento = :location_id
                OR EXISTS (
                    SELECT 1
                    FROM logistica_me_locais lm
                    WHERE lm.status_mapeamento = 'MAPEADO'
                      AND lm.me_local_id = :location_id
                      AND LOWER(TRIM(lm.me_local_nome)) = LOWER(TRIM(COALESCE(le.localevento, r.me_event_snapshot->>'local', r.me_event_snapshot->>'nomelocal', '')))
                )
              )
        ORDER BY r.updated_at DESC NULLS LAST, r.id DESC
        LIMIT 2
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':cpf' => $cpf,
        ':event_date' => $eventDate,
        ':location_id' => $locationId,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) !== 1) {
        $localMeetingMatch = client_app_api_find_meeting_via_local_event_and_me_cpf($pdo, $cpf, $eventDate, $locationId);
        if ($localMeetingMatch) {
            return $localMeetingMatch;
        }
        return client_app_api_find_meeting_via_me($pdo, $cpf, $eventDate, $locationId);
    }

    return $rows[0];
}

function client_app_api_create_session(PDO $pdo, int $meetingId, string $cpf, array $meta = []): array
{
    $token = client_app_api_uuid_token();
    $tokenHash = client_app_api_hash_token($token);
    $expiresAt = (new DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        INSERT INTO cliente_app_sessoes
            (meeting_id, cpf_hash, access_token_hash, device_name, platform, app_version, ip, user_agent, last_seen_at, expires_at, created_at)
        VALUES
            (:meeting_id, :cpf_hash, :access_token_hash, :device_name, :platform, :app_version, :ip, :user_agent, NOW(), :expires_at, NOW())
        RETURNING id, expires_at
    ");
    $stmt->execute([
        ':meeting_id' => $meetingId,
        ':cpf_hash' => hash('sha256', $cpf),
        ':access_token_hash' => $tokenHash,
        ':device_name' => trim((string)($meta['device_name'] ?? '')),
        ':platform' => trim((string)($meta['platform'] ?? '')),
        ':app_version' => trim((string)($meta['app_version'] ?? '')),
        ':ip' => client_app_api_client_ip(),
        ':user_agent' => client_app_api_user_agent(),
        ':expires_at' => $expiresAt,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'token' => $token,
        'session_id' => (int)($row['id'] ?? 0),
        'expires_at' => str_replace(' ', 'T', (string)($row['expires_at'] ?? $expiresAt)) . 'Z',
    ];
}

function client_app_api_load_session(PDO $pdo, string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT s.*, r.me_event_id, r.me_event_snapshot
        FROM cliente_app_sessoes s
        INNER JOIN eventos_reunioes r
            ON r.id = s.meeting_id
        WHERE s.access_token_hash = :hash
          AND s.revoked_at IS NULL
          AND s.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([
        ':hash' => client_app_api_hash_token($token),
    ]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$session) {
        return null;
    }

    $touch = $pdo->prepare("
        UPDATE cliente_app_sessoes
        SET last_seen_at = NOW(),
            ip = :ip,
            user_agent = :user_agent
        WHERE id = :id
    ");
    $touch->execute([
        ':ip' => client_app_api_client_ip(),
        ':user_agent' => client_app_api_user_agent(),
        ':id' => (int)$session['id'],
    ]);

    return $session;
}

function client_app_api_fetch_portal(PDO $pdo, int $meetingId): ?array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM eventos_cliente_portais
        WHERE meeting_id = :meeting_id
        LIMIT 1
    ");
    $stmt->execute([
        ':meeting_id' => $meetingId,
    ]);
    $portal = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$portal) {
        return null;
    }

    $boolColumns = [
        'is_active',
        'visivel_reuniao',
        'editavel_reuniao',
        'visivel_dj',
        'editavel_dj',
        'visivel_convidados',
        'editavel_convidados',
        'visivel_arquivos',
        'editavel_arquivos',
        'visivel_secao_decoracao',
        'editavel_secao_decoracao',
        'visivel_secao_observacoes_gerais',
        'editavel_secao_observacoes_gerais',
        'visivel_secao_dj_protocolo',
        'editavel_secao_dj_protocolo',
        'visivel_secao_formulario',
        'editavel_secao_formulario',
    ];
    foreach ($boolColumns as $column) {
        if (array_key_exists($column, $portal) && $portal[$column] !== null) {
            $portal[$column] = !empty($portal[$column]);
        }
    }

    $portal['secoes'] = client_app_api_portal_sections($portal);
    return $portal;
}

function client_app_api_portal_sections(?array $portal): array
{
    $map = [
        'decoracao' => [
            'visivel_col' => 'visivel_secao_decoracao',
            'editavel_col' => 'editavel_secao_decoracao',
            'fallback_visivel_col' => 'visivel_reuniao',
            'fallback_editavel_col' => 'editavel_reuniao',
        ],
        'observacoes_gerais' => [
            'visivel_col' => 'visivel_secao_observacoes_gerais',
            'editavel_col' => 'editavel_secao_observacoes_gerais',
            'fallback_visivel_col' => 'visivel_reuniao',
            'fallback_editavel_col' => 'editavel_reuniao',
        ],
        'dj_protocolo' => [
            'visivel_col' => 'visivel_secao_dj_protocolo',
            'editavel_col' => 'editavel_secao_dj_protocolo',
            'fallback_visivel_col' => 'visivel_reuniao',
            'fallback_editavel_col' => 'editavel_reuniao',
        ],
        'formulario' => [
            'visivel_col' => 'visivel_secao_formulario',
            'editavel_col' => 'editavel_secao_formulario',
            'fallback_visivel_col' => 'visivel_reuniao',
            'fallback_editavel_col' => 'editavel_reuniao',
        ],
    ];

    $sections = [];
    foreach ($map as $section => $meta) {
        $fallbackVisible = is_array($portal) ? !empty($portal[$meta['fallback_visivel_col']]) : false;
        $fallbackEditable = is_array($portal) ? !empty($portal[$meta['fallback_editavel_col']]) : false;
        $visible = is_array($portal) && array_key_exists($meta['visivel_col'], $portal) && $portal[$meta['visivel_col']] !== null
            ? !empty($portal[$meta['visivel_col']])
            : $fallbackVisible;
        $editable = is_array($portal) && array_key_exists($meta['editavel_col'], $portal) && $portal[$meta['editavel_col']] !== null
            ? !empty($portal[$meta['editavel_col']])
            : $fallbackEditable;
        if ($editable) {
            $visible = true;
        }

        $sections[$section] = [
            'visivel' => $visible,
            'editavel' => $editable,
        ];
    }

    return $sections;
}

function client_app_api_guest_summary(PDO $pdo, int $meetingId): array
{
    $hasDraftColumn = client_app_api_has_column($pdo, 'eventos_convidados', 'is_draft');
    $sql = $hasDraftColumn
        ? "
            SELECT
                COUNT(*)::int AS total,
                COALESCE(SUM(CASE WHEN checkin_at IS NOT NULL THEN 1 ELSE 0 END), 0)::int AS checkin,
                COALESCE(SUM(CASE WHEN COALESCE(is_draft, FALSE) THEN 1 ELSE 0 END), 0)::int AS rascunho
            FROM eventos_convidados
            WHERE meeting_id = :meeting_id
              AND deleted_at IS NULL
        "
        : "
            SELECT
                COUNT(*)::int AS total,
                COALESCE(SUM(CASE WHEN checkin_at IS NOT NULL THEN 1 ELSE 0 END), 0)::int AS checkin,
                0::int AS rascunho
            FROM eventos_convidados
            WHERE meeting_id = :meeting_id
              AND deleted_at IS NULL
        ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':meeting_id' => $meetingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $total = (int)($row['total'] ?? 0);
    $checkin = (int)($row['checkin'] ?? 0);
    $rascunho = (int)($row['rascunho'] ?? 0);

    return [
        'total' => $total,
        'checkin' => $checkin,
        'pendentes' => max(0, $total - $checkin),
        'rascunho' => $rascunho,
        'publicados' => max(0, $total - $rascunho),
    ];
}

function client_app_api_file_summary(PDO $pdo, int $meetingId): array
{
    $empty = [
        'campos_total' => 0,
        'campos_obrigatorios' => 0,
        'campos_pendentes' => 0,
        'arquivos_total' => 0,
        'arquivos_visiveis_cliente' => 0,
        'arquivos_cliente' => 0,
    ];

    $stmtCampos = $pdo->prepare("
        SELECT
            COUNT(*)::int AS campos_total,
            COALESCE(SUM(CASE WHEN obrigatorio_cliente THEN 1 ELSE 0 END), 0)::int AS campos_obrigatorios
        FROM eventos_arquivos_campos
        WHERE meeting_id = :meeting_id
          AND deleted_at IS NULL
          AND COALESCE(interno_only, FALSE) = FALSE
          AND COALESCE(chave_sistema, '') = ''
          AND ativo = TRUE
    ");
    $stmtCampos->execute([':meeting_id' => $meetingId]);
    $campos = $stmtCampos->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmtPendentes = $pdo->prepare("
        SELECT COUNT(*)::int
        FROM eventos_arquivos_campos c
        WHERE c.meeting_id = :meeting_id
          AND c.deleted_at IS NULL
          AND COALESCE(c.interno_only, FALSE) = FALSE
          AND COALESCE(c.chave_sistema, '') = ''
          AND c.ativo = TRUE
          AND c.obrigatorio_cliente = TRUE
          AND NOT EXISTS (
              SELECT 1
              FROM eventos_arquivos_itens i
              WHERE i.campo_id = c.id
                AND i.deleted_at IS NULL
          )
    ");
    $stmtPendentes->execute([':meeting_id' => $meetingId]);

    $stmtArquivos = $pdo->prepare("
        SELECT
            COUNT(*)::int AS arquivos_total,
            COALESCE(SUM(CASE WHEN visivel_cliente THEN 1 ELSE 0 END), 0)::int AS arquivos_visiveis_cliente,
            COALESCE(SUM(CASE WHEN uploaded_by_type = 'cliente' THEN 1 ELSE 0 END), 0)::int AS arquivos_cliente
        FROM eventos_arquivos_itens
        WHERE meeting_id = :meeting_id
          AND deleted_at IS NULL
    ");
    $stmtArquivos->execute([':meeting_id' => $meetingId]);
    $arquivos = $stmtArquivos->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'campos_total' => (int)($campos['campos_total'] ?? $empty['campos_total']),
        'campos_obrigatorios' => (int)($campos['campos_obrigatorios'] ?? $empty['campos_obrigatorios']),
        'campos_pendentes' => (int)($stmtPendentes->fetchColumn() ?: $empty['campos_pendentes']),
        'arquivos_total' => (int)($arquivos['arquivos_total'] ?? $empty['arquivos_total']),
        'arquivos_visiveis_cliente' => (int)($arquivos['arquivos_visiveis_cliente'] ?? $empty['arquivos_visiveis_cliente']),
        'arquivos_cliente' => (int)($arquivos['arquivos_cliente'] ?? $empty['arquivos_cliente']),
    ];
}

function client_app_api_snapshot(array $sessionOrMeetingRow): array
{
    $snapshot = json_decode((string)($sessionOrMeetingRow['me_event_snapshot'] ?? '{}'), true);
    return is_array($snapshot) ? $snapshot : [];
}

function client_app_api_snapshot_pick_text(array $snapshot, array $paths, string $default = ''): string
{
    foreach ($paths as $path) {
        $value = $snapshot;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value)) {
                $value = null;
                break;
            }

            if (array_key_exists($part, $value)) {
                $value = $value[$part];
                continue;
            }

            $found = false;
            foreach ($value as $key => $candidate) {
                if (is_string($key) && strcasecmp($key, $part) === 0) {
                    $value = $candidate;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $value = null;
                break;
            }
        }

        if (is_scalar($value)) {
            $text = trim((string)$value);
            if ($text !== '') {
                return $text;
            }
        }
    }

    return $default;
}

function client_app_api_snapshot_client_name(array $snapshot, string $default = ''): string
{
    return client_app_api_snapshot_pick_text($snapshot, [
        'cliente.nome',
        'nomecliente',
        'nomeCliente',
        'client_name',
    ], $default);
}

function client_app_api_snapshot_local(array $snapshot, string $default = ''): string
{
    return client_app_api_snapshot_pick_text($snapshot, [
        'local',
        'nomelocal',
        'localevento',
        'localEvento',
        'endereco',
    ], $default);
}

function client_app_api_fetch_meeting(PDO $pdo, int $meetingId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM eventos_reunioes WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $meetingId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function client_app_api_event_payload(PDO $pdo, int $meetingId): array
{
    $meeting = client_app_api_fetch_meeting($pdo, $meetingId);
    if (!$meeting) {
        throw new RuntimeException('Evento não encontrado.');
    }

    $snapshot = client_app_api_snapshot($meeting);
    $portal = client_app_api_fetch_portal($pdo, $meetingId);

    $clientName = client_app_api_snapshot_client_name($snapshot, 'Cliente');
    $eventName = trim((string)($snapshot['nome'] ?? 'Seu Evento'));
    $eventDate = trim((string)($snapshot['data'] ?? ''));
    $eventStart = trim((string)($snapshot['hora_inicio'] ?? $snapshot['hora'] ?? ''));
    $eventEnd = trim((string)($snapshot['hora_fim'] ?? ''));
    $eventLocation = client_app_api_snapshot_local($snapshot, 'Local não informado');

    $sections = is_array($portal) && isset($portal['secoes']) && is_array($portal['secoes'])
        ? $portal['secoes']
        : [
            'decoracao' => ['visivel' => false, 'editavel' => false],
            'observacoes_gerais' => ['visivel' => false, 'editavel' => false],
            'dj_protocolo' => ['visivel' => false, 'editavel' => false],
            'formulario' => ['visivel' => false, 'editavel' => false],
        ];
    $summary = [
        'convidados' => ['total' => 0, 'checkin' => 0, 'pendentes' => 0],
        'arquivos' => [
            'campos_total' => 0,
            'campos_obrigatorios' => 0,
            'campos_pendentes' => 0,
            'arquivos_total' => 0,
            'arquivos_visiveis_cliente' => 0,
            'arquivos_cliente' => 0,
        ],
    ];

    if (!empty($portal['visivel_convidados'])) {
        $summary['convidados'] = client_app_api_guest_summary($pdo, $meetingId);
    }
    if (!empty($portal['visivel_arquivos'])) {
        $summary['arquivos'] = client_app_api_file_summary($pdo, $meetingId);
    }

    return [
        'meeting_id' => $meetingId,
        'me_event_id' => (int)($meeting['me_event_id'] ?? 0),
        'name' => $eventName,
        'client_name' => $clientName,
        'date' => $eventDate,
        'time_start' => $eventStart,
        'time_end' => $eventEnd,
        'location' => $eventLocation,
        'cards' => [
            'reuniao_final' => is_array($portal) && !empty($portal['visivel_reuniao']),
            'convidados' => is_array($portal) && !empty($portal['visivel_convidados']),
            'arquivos' => is_array($portal) && !empty($portal['visivel_arquivos']),
            'dj' => !empty($sections['dj_protocolo']['visivel']),
            'formulario' => !empty($sections['formulario']['visivel']),
        ],
        'permissions' => [
            'reuniao_editavel' => is_array($portal) && !empty($portal['editavel_reuniao']),
            'convidados_editavel' => is_array($portal) && !empty($portal['editavel_convidados']),
            'arquivos_editavel' => is_array($portal) && !empty($portal['editavel_arquivos']),
        ],
        'summary' => $summary,
    ];
}

function client_app_api_require_session(PDO $pdo): array
{
    $session = client_app_api_load_session($pdo, client_app_api_bearer_token());
    if (!$session) {
        client_app_api_error(401, 'Sessão inválida ou expirada.');
    }

    return $session;
}
