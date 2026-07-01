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

function client_app_api_limit_text(?string $value, int $maxLength): string
{
    $text = trim((string)$value);
    if ($text === '' || $maxLength <= 0) {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength);
    }

    return substr($text, 0, $maxLength);
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

function client_app_api_log(string $message, array $context = []): void
{
    $suffix = '';
    if ($context !== []) {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json) && $json !== '') {
            $suffix = ' ' . $json;
        }
    }

    error_log('client_app_api_auth: ' . $message . $suffix);
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
        $snapshotCpf = '';
        if (isset($event['cliente']) && is_array($event['cliente'])) {
            $snapshotCpf = client_app_api_normalize_cpf(client_app_api_me_pick_text($event['cliente'], [
                'cpf',
                'documento',
                'cpf_cnpj',
                'cpfCnpj',
                'cpfcnpj',
            ]));
        }
        if ($snapshotCpf !== '') {
            return $snapshotCpf;
        }

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
    $eventLocalId = (int)client_app_api_me_pick_text($event, [
        'idlocalevento',
        'id_local_evento',
        'local_id',
    ]);
    $mappedLocalId = (int)($location['me_local_id'] ?? 0);
    if ($eventLocalId > 0 && $mappedLocalId > 0 && $eventLocalId === $mappedLocalId) {
        return true;
    }

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

        $eventResult = eventos_me_buscar_por_id($pdo, $meEventId);
        if (empty($eventResult['ok']) || !is_array($eventResult['event'] ?? null)) {
            client_app_api_log('sync_meeting_event_not_found', [
                'me_event_id' => $meEventId,
            ]);
            return null;
        }

        $snapshot = eventos_me_criar_snapshot($eventResult['event']);
        $idLocalEvento = (int)($snapshot['idlocalevento'] ?? 0);
        if ($idLocalEvento > 0 && trim((string)($snapshot['local'] ?? '')) === '') {
            $stmtLocal = $pdo->prepare("
                SELECT me_local_nome
                FROM logistica_me_locais
                WHERE me_local_id = :me_local_id
                LIMIT 1
            ");
            $stmtLocal->execute([':me_local_id' => $idLocalEvento]);
            $localMapeado = trim((string)$stmtLocal->fetchColumn());
            if ($localMapeado !== '') {
                $snapshot['local'] = $localMapeado;
                $snapshot['localevento'] = $localMapeado;
            }
        }
        $snapshotCpf = client_app_api_me_client_cpf($pdo, $eventResult['event']);
        if ($snapshotCpf !== '') {
            $snapshot['cliente']['cpf'] = $snapshotCpf;
        }

        if ($row) {
            $currentStmt = $pdo->prepare("SELECT me_event_snapshot FROM eventos_reunioes WHERE id = :id LIMIT 1");
            $currentStmt->execute([':id' => (int)$row['id']]);
            $currentSnapshot = json_decode((string)$currentStmt->fetchColumn(), true);
            if (!is_array($currentSnapshot)) {
                $currentSnapshot = [];
            }
            $mergedSnapshot = function_exists('eventos_me_merge_snapshot')
                ? eventos_me_merge_snapshot($currentSnapshot, $snapshot)
                : array_merge($currentSnapshot, $snapshot);
            $update = $pdo->prepare("
                UPDATE eventos_reunioes
                SET me_event_snapshot = :snapshot,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $update->execute([
                ':snapshot' => json_encode($mergedSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':id' => (int)$row['id'],
            ]);

            return [
                'meeting_id' => (int)$row['id'],
                'me_event_id' => (int)$row['me_event_id'],
            ];
        }

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
            client_app_api_log('direct_me_fallback_disabled');
            return null;
        }

        $location = client_app_api_location_row($pdo, $locationId);
        if (!$location) {
            client_app_api_log('direct_me_location_not_mapped', [
                'location_id' => $locationId,
            ]);
            return null;
        }

        client_app_api_me_helpers();
        $resp = eventos_me_request('GET', '/api/v1/events', [
            'start' => $eventDate,
            'end' => $eventDate,
            'limit' => 500,
        ]);
        if (empty($resp['ok']) || !is_array($resp['data'] ?? null)) {
            client_app_api_log('direct_me_request_failed', [
                'event_date' => $eventDate,
                'location_id' => $locationId,
            ]);
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
                client_app_api_log('direct_me_match_synced', [
                    'me_event_id' => $meEventId,
                    'meeting_id' => $meeting['meeting_id'] ?? null,
                ]);
                return $meeting;
            }
        }

        client_app_api_log('direct_me_no_match', [
            'event_date' => $eventDate,
            'location_id' => $locationId,
        ]);
        return null;
    } catch (Throwable $e) {
        error_log('client_app_api_find_meeting_via_me: ' . $e->getMessage());
        return null;
    }
}

function client_app_api_find_meeting_via_local_mirror_and_me_cpf(PDO $pdo, string $cpf, string $eventDate, int $locationId): ?array
{
    try {
        $stmt = $pdo->prepare("
            SELECT
                le.idlocalevento,
                le.localevento,
                le.me_event_id,
                le.data_evento
            FROM logistica_eventos_espelho le
            WHERE le.data_evento = :event_date
              AND le.idlocalevento = :location_id
              AND COALESCE(le.arquivado, FALSE) = FALSE
              AND le.me_event_id IS NOT NULL
              AND le.me_event_id > 0
            ORDER BY le.me_event_id DESC
            LIMIT 10
        ");
        $stmt->execute([
            ':event_date' => $eventDate,
            ':location_id' => $locationId,
        ]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$candidates) {
            client_app_api_log('local_mirror_no_candidates', [
                'event_date' => $eventDate,
                'location_id' => $locationId,
            ]);
            return null;
        }

        foreach ($candidates as $candidate) {
            $meEventId = (int)($candidate['me_event_id'] ?? 0);
            if ($meEventId <= 0) {
                continue;
            }

            $eventResult = eventos_me_buscar_por_id($pdo, $meEventId);
            if (empty($eventResult['ok']) || !is_array($eventResult['event'] ?? null)) {
                client_app_api_log('local_mirror_event_fetch_failed', [
                    'me_event_id' => $meEventId,
                ]);
                continue;
            }

            $eventCpf = client_app_api_me_client_cpf($pdo, $eventResult['event']);
            if ($eventCpf !== '' && $eventCpf === $cpf) {
                $meeting = client_app_api_sync_meeting_from_me($pdo, $meEventId);
                if ($meeting) {
                    client_app_api_log('local_mirror_match_synced', [
                        'me_event_id' => $meEventId,
                        'meeting_id' => $meeting['meeting_id'] ?? null,
                    ]);
                    return $meeting;
                }
            }
        }

        client_app_api_log('local_mirror_no_match_after_cpf', [
            'event_date' => $eventDate,
            'location_id' => $locationId,
        ]);
        return null;
    } catch (Throwable $e) {
        error_log('client_app_api_find_meeting_via_local_mirror_and_me_cpf: ' . $e->getMessage());
        return null;
    }
}

function client_app_api_find_meeting_via_local_meeting_and_me_cpf(PDO $pdo, string $cpf, string $eventDate, int $locationId): ?array
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
              AND COALESCE(le.arquivado, FALSE) = FALSE
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
            LIMIT 10
        ");
        $stmt->execute([
            ':event_date' => $eventDate,
            ':location_id' => $locationId,
        ]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$candidates) {
            client_app_api_log('local_meeting_no_candidates', [
                'event_date' => $eventDate,
                'location_id' => $locationId,
            ]);
            return null;
        }

        foreach ($candidates as $candidate) {
            $snapshot = json_decode((string)($candidate['me_event_snapshot'] ?? ''), true);
            if (is_array($snapshot)) {
                $snapshotCpf = client_app_api_me_client_cpf($pdo, $snapshot);
                if ($snapshotCpf !== '' && $snapshotCpf === $cpf) {
                    client_app_api_log('local_meeting_match_snapshot_cpf', [
                        'meeting_id' => $candidate['meeting_id'] ?? null,
                        'me_event_id' => $candidate['me_event_id'] ?? null,
                    ]);
                    return $candidate;
                }
            }

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
                client_app_api_log('local_meeting_match_me_cpf', [
                    'meeting_id' => $candidate['meeting_id'] ?? null,
                    'me_event_id' => $meEventId,
                ]);
                return $candidate;
            }
        }

        client_app_api_log('local_meeting_no_match_after_cpf', [
            'event_date' => $eventDate,
            'location_id' => $locationId,
        ]);
        return null;
    } catch (Throwable $e) {
        error_log('client_app_api_find_meeting_via_local_meeting_and_me_cpf: ' . $e->getMessage());
        return null;
    }
}

function client_app_api_find_meeting_via_internal_cpf_sources(PDO $pdo, string $cpf, string $eventDate, int $locationId): ?array
{
    try {
        $sources = [];
        if (client_app_api_has_column($pdo, 'comercial_inscricoes', 'me_cliente_cpf')) {
            $sources[] = "
                SELECT DISTINCT me_event_id
                FROM comercial_inscricoes
                WHERE me_event_id IS NOT NULL
                  AND me_event_id > 0
                  AND regexp_replace(COALESCE(me_cliente_cpf, ''), '\\D', '', 'g') = :cpf
            ";
        }
        if (client_app_api_has_column($pdo, 'vendas_pre_contratos', 'cpf')) {
            $sources[] = "
                SELECT DISTINCT me_event_id
                FROM vendas_pre_contratos
                WHERE me_event_id IS NOT NULL
                  AND me_event_id > 0
                  AND regexp_replace(COALESCE(cpf, ''), '\\D', '', 'g') = :cpf
            ";
        }

        if ($sources === []) {
            client_app_api_log('internal_cpf_sources_unavailable');
            return null;
        }

        $sql = "
            WITH cpf_matches AS (
                " . implode("\n                UNION\n", $sources) . "
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
              AND COALESCE(le.arquivado, FALSE) = FALSE
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

        if (count($rows) === 1) {
            client_app_api_log('internal_cpf_match', [
                'meeting_id' => $rows[0]['meeting_id'] ?? null,
                'me_event_id' => $rows[0]['me_event_id'] ?? null,
            ]);
            return $rows[0];
        }

        client_app_api_log('internal_cpf_no_match', [
            'rows' => count($rows),
        ]);
        return null;
    } catch (Throwable $e) {
        error_log('client_app_api_find_meeting_via_internal_cpf_sources: ' . $e->getMessage());
        return null;
    }
}

function client_app_api_find_meeting(PDO $pdo, string $cpf, string $eventDate, int $locationId): ?array
{
    $match = client_app_api_find_meeting_via_local_mirror_and_me_cpf($pdo, $cpf, $eventDate, $locationId);
    if ($match) {
        return $match;
    }

    $match = client_app_api_find_meeting_via_local_meeting_and_me_cpf($pdo, $cpf, $eventDate, $locationId);
    if ($match) {
        return $match;
    }

    $match = client_app_api_find_meeting_via_me($pdo, $cpf, $eventDate, $locationId);
    if ($match) {
        return $match;
    }

    return client_app_api_find_meeting_via_internal_cpf_sources($pdo, $cpf, $eventDate, $locationId);
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
        ':device_name' => client_app_api_limit_text((string)($meta['device_name'] ?? ''), 120),
        ':platform' => client_app_api_limit_text((string)($meta['platform'] ?? ''), 40),
        ':app_version' => client_app_api_limit_text((string)($meta['app_version'] ?? ''), 40),
        ':ip' => client_app_api_limit_text(client_app_api_client_ip(), 64),
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

function client_app_api_portal_base_url(): string
{
    $candidates = [
        (string)(getenv('EVENTOS_CLIENTE_PORTAL_BASE_URL') ?: ''),
        (string)(getenv('APP_CLIENT_PORTAL_URL') ?: ''),
        (string)(getenv('APP_URL') ?: ''),
        (string)(getenv('BASE_URL') ?: ''),
    ];

    foreach ($candidates as $candidate) {
        $value = trim($candidate);
        if ($value !== '') {
            return rtrim($value, '/');
        }
    }

    return 'https://painelpro.smileeventos.com.br';
}

function client_app_api_portal_module_urls(?array $portal): array
{
    $token = trim((string)($portal['token'] ?? ''));
    if ($token === '') {
        return [];
    }

    $base = client_app_api_portal_base_url();
    $queryToken = urlencode($token);

    return [
        'reuniao_final' => $base . '/index.php?page=eventos_cliente_reuniao&token=' . $queryToken,
        'convidados' => $base . '/index.php?page=eventos_cliente_convidados&token=' . $queryToken,
        'arquivos' => $base . '/index.php?page=eventos_cliente_arquivos&token=' . $queryToken,
        'dj' => $base . '/index.php?page=eventos_cliente_dj_portal&token=' . $queryToken,
        'formulario' => $base . '/index.php?page=eventos_cliente_formulario_portal&token=' . $queryToken,
    ];
}

function client_app_api_public_links(PDO $pdo, int $meetingId, string $linkType): array
{
    if ($meetingId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            link_type,
            portal_visible,
            portal_editable,
            is_active,
            form_schema_json,
            submitted_at
        FROM eventos_links_publicos
        WHERE meeting_id = :meeting_id
          AND link_type = :link_type
          AND is_active = TRUE
        ORDER BY COALESCE(slot_index, 1) ASC, id DESC
    ");
    $stmt->execute([
        ':meeting_id' => $meetingId,
        ':link_type' => $linkType,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $schema = json_decode((string)($row['form_schema_json'] ?? '[]'), true);
        $row['form_schema'] = is_array($schema) ? $schema : [];
        $row['portal_visible'] = !empty($row['portal_visible']);
        $row['portal_editable'] = !empty($row['portal_editable']);
        $row['is_active'] = !empty($row['is_active']);
    }
    unset($row);

    return $rows;
}

function client_app_api_form_schema_has_fields($schema): bool
{
    if (!is_array($schema)) {
        return false;
    }

    foreach ($schema as $field) {
        if (!is_array($field)) {
            continue;
        }

        $fieldId = trim((string)($field['id'] ?? ''));
        if (strpos($fieldId, 'legacy_portal_text_') === 0) {
            continue;
        }

        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        if (in_array($type, ['text', 'textarea', 'yesno', 'select', 'file'], true)) {
            return true;
        }
    }

    return false;
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
        $fallbackEditable = $section === 'decoracao'
            ? false
            : (is_array($portal) ? !empty($portal[$meta['fallback_editavel_col']]) : false);
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

    $hideEmbeddedMeetingCards = is_array($portal) && !empty($portal['visivel_reuniao']);
    $formLinks = client_app_api_public_links($pdo, $meetingId, 'cliente_formulario');
    $visibleFormLinks = array_values(array_filter($formLinks, static function (array $item): bool {
        return !empty($item['portal_visible']) && client_app_api_form_schema_has_fields($item['form_schema'] ?? null);
    }));
    $moduleUrls = client_app_api_portal_module_urls($portal);

    $cards = [
        'reuniao_final' => is_array($portal) && !empty($portal['visivel_reuniao']),
        'convidados' => is_array($portal) && !empty($portal['visivel_convidados']),
        'arquivos' => is_array($portal) && !empty($portal['visivel_arquivos']),
        'dj' => !empty($sections['dj_protocolo']['visivel']) && !$hideEmbeddedMeetingCards,
        'formulario' => !empty($sections['formulario']['visivel']) && !$hideEmbeddedMeetingCards && !empty($visibleFormLinks),
    ];

    return [
        'meeting_id' => $meetingId,
        'me_event_id' => (int)($meeting['me_event_id'] ?? 0),
        'name' => $eventName,
        'client_name' => $clientName,
        'date' => $eventDate,
        'time_start' => $eventStart,
        'time_end' => $eventEnd,
        'location' => $eventLocation,
        'cards' => $cards,
        'module_urls' => array_filter([
            'reuniao_final' => !empty($cards['reuniao_final']) ? ($moduleUrls['reuniao_final'] ?? null) : null,
            'convidados' => !empty($cards['convidados']) ? ($moduleUrls['convidados'] ?? null) : null,
            'arquivos' => !empty($cards['arquivos']) ? ($moduleUrls['arquivos'] ?? null) : null,
            'dj' => !empty($cards['dj']) ? ($moduleUrls['dj'] ?? null) : null,
            'formulario' => !empty($cards['formulario']) ? ($moduleUrls['formulario'] ?? null) : null,
        ]),
        'permissions' => [
            'reuniao_editavel' => is_array($portal) && !empty($portal['editavel_reuniao']),
            'convidados_editavel' => is_array($portal) && !empty($portal['editavel_convidados']),
            'arquivos_editavel' => is_array($portal) && !empty($portal['editavel_arquivos']),
        ],
        'summary' => $summary,
    ];
}

function client_app_api_require_eventos_helpers(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    require_once dirname(__DIR__) . '/eventos_reuniao_helper.php';
    require_once dirname(__DIR__) . '/upload_magalu.php';
    $loaded = true;
}

function client_app_api_module_guard(PDO $pdo, int $meetingId, string $moduleKey): array
{
    $event = client_app_api_event_payload($pdo, $meetingId);
    $cards = is_array($event['cards'] ?? null) ? $event['cards'] : [];
    if (empty($cards[$moduleKey])) {
        client_app_api_error(403, 'Esta área não está liberada para este evento.');
    }

    return $event;
}

function client_app_api_event_brief(array $event): array
{
    return [
        'meeting_id' => (int)($event['meeting_id'] ?? 0),
        'me_event_id' => (int)($event['me_event_id'] ?? 0),
        'name' => (string)($event['name'] ?? ''),
        'client_name' => (string)($event['client_name'] ?? ''),
        'date' => (string)($event['date'] ?? ''),
        'time_start' => (string)($event['time_start'] ?? ''),
        'time_end' => (string)($event['time_end'] ?? ''),
        'location' => (string)($event['location'] ?? ''),
    ];
}

function client_app_api_summary_row(array $event): array
{
    $items = [];
    if (!empty($event['date'])) {
        $items[] = ['label' => 'Data', 'value' => (string)$event['date']];
    }
    if (!empty($event['time_start']) || !empty($event['time_end'])) {
        $time = trim((string)($event['time_start'] ?? ''));
        if (!empty($event['time_end'])) {
            $time .= ($time !== '' ? ' às ' : '') . (string)$event['time_end'];
        }
        $items[] = ['label' => 'Horário', 'value' => $time];
    }
    if (!empty($event['location'])) {
        $items[] = ['label' => 'Local', 'value' => (string)$event['location']];
    }
    if (!empty($event['client_name'])) {
        $items[] = ['label' => 'Cliente', 'value' => (string)$event['client_name']];
    }
    return $items;
}

function client_app_api_form_payload_values(string $contentHtml, string $section): array
{
    client_app_api_require_eventos_helpers();
    $payload = eventos_reuniao_extrair_payload_formulario($contentHtml);
    $values = [];
    if (is_array($payload['values'] ?? null)) {
        foreach ($payload['values'] as $key => $value) {
            $values[(string)$key] = is_scalar($value) ? trim((string)$value) : '';
        }
    }
    $legacyHtml = trim((string)($payload['legacy_html'] ?? ''));
    if ($legacyHtml !== '') {
        $values['legacy_portal_text_' . $section] = eventos_reuniao_html_para_texto($legacyHtml);
    }
    $decoracaoAdicionaisHtml = trim((string)($payload['decoracao_adicionais_html'] ?? ''));
    if ($section === 'decoracao' && $decoracaoAdicionaisHtml !== '') {
        $values['legacy_portal_text_decoracao_adicionais'] = eventos_reuniao_html_para_texto($decoracaoAdicionaisHtml);
    }
    return $values;
}

function client_app_api_schema_normalized(array $schema, array $values = []): array
{
    client_app_api_require_eventos_helpers();
    $normalized = eventos_form_template_normalizar_schema($schema);
    if (!empty($values)) {
        $normalized = eventos_reuniao_schema_aplicar_valores_payload($normalized, $values);
    }
    return $normalized;
}

function client_app_api_section_payload(PDO $pdo, int $meetingId, string $section, array $sectionPortalConfig): array
{
    client_app_api_require_eventos_helpers();
    $secao = eventos_reuniao_get_secao($pdo, $meetingId, $section);
    $contentHtml = trim((string)($secao['content_html'] ?? ''));
    $values = client_app_api_form_payload_values($contentHtml, $section);
    $schemaRaw = json_decode((string)($secao['form_schema_json'] ?? '[]'), true);
    $schema = client_app_api_schema_normalized(is_array($schemaRaw) ? $schemaRaw : [], $values);
    $legacyVisible = array_key_exists('legacy_text_portal_visible', (array)$secao)
        ? !empty($secao['legacy_text_portal_visible'])
        : true;
    if ($legacyVisible) {
        $legacyDefault = trim((string)($values['legacy_portal_text_' . $section] ?? ''));
        $schema = eventos_reuniao_schema_adicionar_texto_livre($schema, $section, $legacyDefault);
    }

    return [
        'key' => $section,
        'title' => client_app_api_section_title($section),
        'visible' => !empty($sectionPortalConfig['visivel']),
        'editable' => !empty($sectionPortalConfig['editavel']),
        'schema' => $schema,
        'values' => $values,
        'text_preview' => client_app_api_section_preview($schema, $values),
    ];
}

function client_app_api_section_title(string $section): string
{
    $map = [
        'decoracao' => 'Decoração',
        'observacoes_gerais' => 'Observações Gerais',
        'dj_protocolo' => 'DJ e Protocolo',
        'formulario' => 'Formulário',
    ];
    return $map[$section] ?? ucfirst(str_replace('_', ' ', $section));
}

function client_app_api_field_value(array $field, array $values): string
{
    $fieldId = trim((string)($field['id'] ?? ''));
    if ($fieldId !== '' && array_key_exists($fieldId, $values)) {
        return trim((string)$values[$fieldId]);
    }
    return trim((string)($field['default_value'] ?? ''));
}

function client_app_api_section_preview(array $schema, array $values): string
{
    foreach ($schema as $field) {
        if (!is_array($field)) {
            continue;
        }
        $type = strtolower(trim((string)($field['type'] ?? '')));
        if (!in_array($type, ['text', 'textarea', 'yesno', 'select'], true)) {
            continue;
        }
        $value = client_app_api_field_value($field, $values);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function client_app_api_module_reuniao_final(PDO $pdo, int $meetingId): array
{
    $event = client_app_api_module_guard($pdo, $meetingId, 'reuniao_final');
    $portal = client_app_api_fetch_portal($pdo, $meetingId);
    $sectionsConfig = is_array($portal['secoes'] ?? null) ? $portal['secoes'] : [];

    $sections = [];
    foreach (['decoracao', 'observacoes_gerais'] as $sectionKey) {
        $config = is_array($sectionsConfig[$sectionKey] ?? null) ? $sectionsConfig[$sectionKey] : ['visivel' => true, 'editavel' => false];
        if (empty($config['visivel'])) {
            continue;
        }
        $sections[] = client_app_api_section_payload($pdo, $meetingId, $sectionKey, $config);
    }

    return [
        'key' => 'reuniao_final',
        'title' => 'Reunião Final',
        'event' => client_app_api_event_brief($event),
        'summary' => client_app_api_summary_row($event),
        'sections' => $sections,
        'editable' => !empty($event['permissions']['reuniao_editavel']),
    ];
}

function client_app_api_module_convidados(PDO $pdo, int $meetingId, string $search = ''): array
{
    client_app_api_require_eventos_helpers();
    $event = client_app_api_module_guard($pdo, $meetingId, 'convidados');
    $config = eventos_convidados_get_config($pdo, $meetingId);
    $items = eventos_convidados_listar($pdo, $meetingId, $search);
    $summary = eventos_convidados_resumo($pdo, $meetingId);

    return [
        'key' => 'convidados',
        'title' => 'Convidados',
        'event' => client_app_api_event_brief($event),
        'editable' => !empty($event['permissions']['convidados_editavel']),
        'config' => $config,
        'summary' => $summary,
        'items' => array_map(static function (array $row): array {
            return [
                'id' => (int)($row['id'] ?? 0),
                'name' => (string)($row['nome'] ?? ''),
                'age_group' => (string)($row['faixa_etaria'] ?? ''),
                'table_number' => (string)($row['numero_mesa'] ?? ''),
                'is_checked_in' => !empty($row['is_checked_in']),
                'is_draft' => !empty($row['is_draft']),
            ];
        }, $items),
    ];
}

function client_app_api_module_convidados_salvar(PDO $pdo, int $meetingId, array $body): array
{
    client_app_api_require_eventos_helpers();
    $event = client_app_api_module_guard($pdo, $meetingId, 'convidados');
    if (empty($event['permissions']['convidados_editavel'])) {
        client_app_api_error(403, 'A lista de convidados está em modo de consulta.');
    }

    $guestId = (int)($body['id'] ?? 0);
    $name = trim((string)($body['name'] ?? ''));
    $ageGroup = trim((string)($body['age_group'] ?? ''));
    $tableNumber = trim((string)($body['table_number'] ?? ''));

    $result = $guestId > 0
        ? eventos_convidados_atualizar($pdo, $meetingId, $guestId, $name, $ageGroup, $tableNumber, 0, false)
        : eventos_convidados_adicionar($pdo, $meetingId, $name, $ageGroup, $tableNumber, 'cliente', 0, false);

    if (empty($result['ok'])) {
        client_app_api_log('guests_save_failed', ['meeting_id' => $meetingId, 'error' => $result['error'] ?? 'erro']);
        client_app_api_error(422, (string)($result['error'] ?? 'Não foi possível salvar o convidado.'));
    }

    return [
        'ok' => true,
        'module' => client_app_api_module_convidados($pdo, $meetingId),
    ];
}

function client_app_api_module_convidados_excluir(PDO $pdo, int $meetingId, array $body): array
{
    client_app_api_require_eventos_helpers();
    $event = client_app_api_module_guard($pdo, $meetingId, 'convidados');
    if (empty($event['permissions']['convidados_editavel'])) {
        client_app_api_error(403, 'A lista de convidados está em modo de consulta.');
    }

    $guestId = (int)($body['id'] ?? 0);
    $result = eventos_convidados_excluir($pdo, $meetingId, $guestId, 0);
    if (empty($result['ok'])) {
        client_app_api_log('guests_delete_failed', ['meeting_id' => $meetingId, 'guest_id' => $guestId, 'error' => $result['error'] ?? 'erro']);
        client_app_api_error(422, (string)($result['error'] ?? 'Não foi possível excluir o convidado.'));
    }

    return [
        'ok' => true,
        'module' => client_app_api_module_convidados($pdo, $meetingId),
    ];
}

function client_app_api_module_arquivos(PDO $pdo, int $meetingId): array
{
    client_app_api_require_eventos_helpers();
    $event = client_app_api_module_guard($pdo, $meetingId, 'arquivos');
    $campos = array_values(array_filter(
        eventos_arquivos_listar_campos($pdo, $meetingId, true, false),
        static fn(array $campo): bool => trim((string)($campo['chave_sistema'] ?? '')) === ''
    ));
    $arquivos = eventos_arquivos_listar($pdo, $meetingId, true, null, false);
    $summary = eventos_arquivos_resumo($pdo, $meetingId);

    $grouped = [];
    foreach ($arquivos as $arquivo) {
        $campoId = (int)($arquivo['campo_id'] ?? 0);
        if (!isset($grouped[$campoId])) {
            $grouped[$campoId] = [];
        }
        $grouped[$campoId][] = [
            'id' => (int)($arquivo['id'] ?? 0),
            'name' => (string)($arquivo['original_name'] ?? 'Arquivo'),
            'description' => (string)($arquivo['descricao'] ?? ''),
            'uploaded_by_type' => (string)($arquivo['uploaded_by_type'] ?? ''),
            'public_url' => (string)($arquivo['public_url'] ?? ''),
            'mime_type' => (string)($arquivo['mime_type'] ?? ''),
            'size_bytes' => (int)($arquivo['size_bytes'] ?? 0),
            'uploaded_at' => (string)($arquivo['uploaded_at'] ?? ''),
            'uploaded_by_client' => strtolower(trim((string)($arquivo['uploaded_by_type'] ?? ''))) === 'cliente',
        ];
    }

    return [
        'key' => 'arquivos',
        'title' => 'Arquivos',
        'event' => client_app_api_event_brief($event),
        'editable' => !empty($event['permissions']['arquivos_editavel']),
        'summary' => $summary,
        'fields' => array_map(static function (array $campo) use ($grouped): array {
            $campoId = (int)($campo['id'] ?? 0);
            return [
                'id' => $campoId,
                'title' => (string)($campo['titulo'] ?? ''),
                'description' => (string)($campo['descricao'] ?? ''),
                'required' => !empty($campo['obrigatorio_cliente']),
                'files' => $grouped[$campoId] ?? [],
            ];
        }, $campos),
    ];
}

function client_app_api_upload_error_message(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_OK:
            return '';
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'Arquivo excede o limite permitido pelo servidor.';
        case UPLOAD_ERR_PARTIAL:
            return 'Upload incompleto. Tente novamente.';
        case UPLOAD_ERR_NO_FILE:
            return 'Selecione um arquivo para enviar.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Servidor sem pasta temporária para upload.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Falha ao gravar arquivo temporário.';
        case UPLOAD_ERR_EXTENSION:
            return 'Upload bloqueado por extensão do servidor.';
        default:
            return 'Erro desconhecido de upload.';
    }
}

function client_app_api_module_arquivos_upload(PDO $pdo, int $meetingId): array
{
    client_app_api_require_eventos_helpers();
    $event = client_app_api_module_guard($pdo, $meetingId, 'arquivos');
    if (empty($event['permissions']['arquivos_editavel'])) {
        client_app_api_error(403, 'O envio de arquivos não está habilitado para este evento.');
    }

    $file = $_FILES['arquivo'] ?? null;
    if (!$file || !is_array($file)) {
        client_app_api_error(422, 'Selecione um arquivo para enviar.');
    }
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        client_app_api_error(422, client_app_api_upload_error_message($uploadError));
    }

    $campoId = (int)($_POST['campo_id'] ?? 0);
    $descricao = trim((string)($_POST['descricao'] ?? ''));

    try {
        $uploader = new MagaluUpload(500);
        $uploadResult = $uploader->upload($file, 'eventos/reunioes/' . $meetingId . '/cliente_app_arquivos');
        $saved = eventos_arquivos_salvar_item(
            $pdo,
            $meetingId,
            $uploadResult,
            $campoId > 0 ? $campoId : null,
            $descricao,
            true,
            'cliente',
            null
        );
    } catch (Throwable $e) {
        client_app_api_log('files_upload_exception', ['meeting_id' => $meetingId, 'error' => $e->getMessage()]);
        client_app_api_error(500, 'Falha ao enviar arquivo. Tente novamente.');
    }

    if (empty($saved['ok'])) {
        client_app_api_log('files_upload_failed', ['meeting_id' => $meetingId, 'error' => $saved['error'] ?? 'erro']);
        client_app_api_error(422, (string)($saved['error'] ?? 'Não foi possível salvar o arquivo.'));
    }

    return [
        'ok' => true,
        'module' => client_app_api_module_arquivos($pdo, $meetingId),
    ];
}

function client_app_api_module_arquivos_excluir(PDO $pdo, int $meetingId, array $body): array
{
    client_app_api_require_eventos_helpers();
    $event = client_app_api_module_guard($pdo, $meetingId, 'arquivos');
    if (empty($event['permissions']['arquivos_editavel'])) {
        client_app_api_error(403, 'Os arquivos estão em modo de consulta.');
    }

    $arquivoId = (int)($body['id'] ?? 0);
    $result = eventos_arquivos_excluir_item($pdo, $meetingId, $arquivoId, 0, 'cliente');
    if (empty($result['ok'])) {
        client_app_api_log('files_delete_failed', ['meeting_id' => $meetingId, 'file_id' => $arquivoId, 'error' => $result['error'] ?? 'erro']);
        client_app_api_error(422, (string)($result['error'] ?? 'Não foi possível excluir o arquivo.'));
    }

    return [
        'ok' => true,
        'module' => client_app_api_module_arquivos($pdo, $meetingId),
    ];
}

function client_app_api_public_form_payload(PDO $pdo, int $meetingId, array $link, string $section): array
{
    client_app_api_require_eventos_helpers();
    $linkId = (int)($link['id'] ?? 0);
    $submittedPayload = eventos_reuniao_extrair_payload_formulario((string)($link['content_html_snapshot'] ?? ''));
    $draftPayload = eventos_reuniao_extrair_payload_formulario((string)($link['draft_content_html_snapshot'] ?? ''));
    $submittedValues = is_array($submittedPayload['values'] ?? null) ? $submittedPayload['values'] : [];
    $draftValues = is_array($draftPayload['values'] ?? null) ? $draftPayload['values'] : [];
    $currentValues = !empty($draftValues) ? $draftValues : $submittedValues;
    $schema = client_app_api_schema_normalized(
        is_array($link['form_schema'] ?? null) ? $link['form_schema'] : [],
        $currentValues
    );

    $draftAttachments = $linkId > 0 ? eventos_reuniao_get_anexos_link_rascunho($pdo, $meetingId, $section, $linkId) : [];
    $submittedAttachments = $linkId > 0 ? eventos_reuniao_get_anexos_link_finais($pdo, $meetingId, $section, $linkId) : [];

    $filesByField = [];
    foreach (array_merge($submittedAttachments, $draftAttachments) as $attachment) {
        $fieldKey = trim((string)($attachment['form_field_id'] ?? ''));
        if ($fieldKey === '') {
            continue;
        }
        if (!isset($filesByField[$fieldKey])) {
            $filesByField[$fieldKey] = [];
        }
        $filesByField[$fieldKey][] = [
            'id' => (int)($attachment['id'] ?? 0),
            'name' => trim((string)($attachment['original_name'] ?? 'Arquivo')),
            'description' => trim((string)($attachment['note'] ?? '')),
            'public_url' => trim((string)($attachment['public_url'] ?? '')),
            'size_bytes' => (int)($attachment['size_bytes'] ?? 0),
            'uploaded_by_client' => strtolower(trim((string)($attachment['uploaded_by_type'] ?? ''))) === 'cliente',
        ];
    }

    return [
        'id' => $linkId,
        'slot_index' => (int)($link['slot_index'] ?? 1),
        'title' => trim((string)($link['form_title'] ?? '')) !== '' ? (string)$link['form_title'] : client_app_api_section_title($section),
        'editable' => !empty($link['portal_editable']),
        'submitted_at' => array_key_exists('submitted_at', $link) && $link['submitted_at'] !== null ? (string)$link['submitted_at'] : null,
        'draft_saved_at' => array_key_exists('draft_saved_at', $link) && $link['draft_saved_at'] !== null ? (string)$link['draft_saved_at'] : null,
        'schema' => $schema,
        'values' => $currentValues,
        'draft_attachments' => $draftAttachments,
        'submitted_attachments' => $submittedAttachments,
        'files_by_field' => $filesByField,
        'preview' => client_app_api_section_preview($schema, $currentValues),
    ];
}

function client_app_api_find_module_link(PDO $pdo, int $meetingId, string $moduleKey, int $linkId): array
{
    $mapping = [
        'reuniao_final' => ['link_type' => 'cliente_observacoes', 'section' => 'decoracao'],
        'dj' => ['link_type' => 'cliente_dj', 'section' => 'dj_protocolo'],
        'formulario' => ['link_type' => 'cliente_formulario', 'section' => 'formulario'],
    ];
    $meta = $mapping[$moduleKey] ?? null;
    if (!$meta) {
        client_app_api_error(422, 'Módulo inválido para formulários.');
    }

    client_app_api_module_guard($pdo, $meetingId, $moduleKey === 'reuniao_final' ? 'reuniao_final' : $moduleKey);

    $link = null;
    foreach (eventos_reuniao_listar_links_cliente($pdo, $meetingId, $meta['link_type']) as $candidate) {
        if ((int)($candidate['id'] ?? 0) === $linkId) {
            $link = $candidate;
            break;
        }
    }

    if (!$link || empty($link['portal_visible'])) {
        client_app_api_error(404, 'Formulário não encontrado.');
    }

    return [$link, $meta];
}

function client_app_api_module_links(PDO $pdo, int $meetingId, string $moduleKey): array
{
    client_app_api_require_eventos_helpers();
    $event = client_app_api_module_guard($pdo, $meetingId, $moduleKey);
    $mapping = [
        'dj' => ['link_type' => 'cliente_dj', 'section' => 'dj_protocolo', 'title' => 'DJ e Protocolo'],
        'formulario' => ['link_type' => 'cliente_formulario', 'section' => 'formulario', 'title' => 'Formulários'],
    ];
    $meta = $mapping[$moduleKey] ?? null;
    if (!$meta) {
        client_app_api_error(404, 'Módulo não encontrado.');
    }

    $forms = [];
    foreach (eventos_reuniao_listar_links_cliente($pdo, $meetingId, $meta['link_type']) as $link) {
        if (empty($link['portal_visible'])) {
            continue;
        }
        if (!client_app_api_form_schema_has_fields($link['form_schema'] ?? null)) {
            continue;
        }
        $forms[] = client_app_api_public_form_payload($pdo, $meetingId, $link, $meta['section']);
    }

    return [
        'key' => $moduleKey,
        'title' => $meta['title'],
        'event' => client_app_api_event_brief($event),
        'forms' => $forms,
    ];
}

function client_app_api_form_value_to_html(string $value): string
{
    return nl2br(htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8'));
}

function client_app_api_compose_form_snapshot(array $schema, array $values, string $section, string $title): string
{
    client_app_api_require_eventos_helpers();
    $parts = [];
    $title = trim($title);
    if ($title !== '') {
        $parts[] = '<h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>';
    }

    $legacyHtml = '';
    $decoracaoAdicionaisHtml = '';
    foreach (eventos_form_template_normalizar_schema($schema) as $field) {
        if (!is_array($field)) {
            continue;
        }

        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        $label = trim((string)($field['label'] ?? ''));
        $fieldId = trim((string)($field['id'] ?? ''));
        $value = isset($values[$fieldId]) ? trim((string)$values[$fieldId]) : trim((string)($field['default_value'] ?? ''));

        if (strpos($fieldId, 'legacy_portal_text_') === 0) {
            if ($value !== '') {
                if ($section === 'decoracao' && $fieldId === 'legacy_portal_text_decoracao_adicionais') {
                    $decoracaoAdicionaisHtml = '<p>' . client_app_api_form_value_to_html($value) . '</p>';
                } else {
                    $legacyHtml = '<p>' . client_app_api_form_value_to_html($value) . '</p>';
                }
            }
            continue;
        }

        if ($type === 'divider') {
            $parts[] = '<hr>';
            continue;
        }
        if ($type === 'section') {
            $parts[] = '<h3>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</h3>';
            continue;
        }
        if ($type === 'note') {
            $noteHtml = trim((string)($field['content_html'] ?? ''));
            if ($noteHtml !== '') {
                $parts[] = $noteHtml;
            }
            continue;
        }
        if ($type === 'file') {
            continue;
        }
        if ($value === '') {
            continue;
        }

        $parts[] = '<p><strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</strong><br>' . client_app_api_form_value_to_html($value) . '</p>';
    }

    $payload = [
        'section' => $section,
        'values' => $values,
    ];
    if ($legacyHtml !== '') {
        $payload['legacy_html'] = $legacyHtml;
    }
    if ($decoracaoAdicionaisHtml !== '') {
        $payload['decoracao_adicionais_html'] = $decoracaoAdicionaisHtml;
    }
    $encodedPayload = base64_encode((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($encodedPayload !== '') {
        $parts[] = '<div data-smile-form-payload="' . htmlspecialchars($encodedPayload, ENT_QUOTES, 'UTF-8') . '" style="display:none;"></div>';
    }

    return implode("\n", $parts);
}

function client_app_api_module_form_response_salvar(PDO $pdo, int $meetingId, array $body): array
{
    client_app_api_require_eventos_helpers();
    $moduleKey = trim((string)($body['module'] ?? ''));
    $linkId = (int)($body['link_id'] ?? 0);
    $submit = !empty($body['submit']);
    $values = is_array($body['values'] ?? null) ? $body['values'] : [];

    [$link, $meta] = client_app_api_find_module_link($pdo, $meetingId, $moduleKey, $linkId);
    if (empty($link['portal_editable'])) {
        client_app_api_error(403, 'Este formulário está disponível apenas para consulta.');
    }

    $schema = eventos_form_template_normalizar_schema(is_array($link['form_schema'] ?? null) ? $link['form_schema'] : []);
    $normalizedValues = [];
    $errors = [];
    foreach ($schema as $field) {
        if (!is_array($field)) {
            continue;
        }
        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        $fieldId = trim((string)($field['id'] ?? ''));
        if ($fieldId === '' || in_array($type, ['divider', 'section', 'note', 'file'], true)) {
            continue;
        }
        $value = trim((string)($values[$fieldId] ?? ''));
        $normalizedValues[$fieldId] = $value;
        if (!empty($field['required']) && $value === '') {
            $errors[] = 'Preencha o campo "' . trim((string)($field['label'] ?? 'Campo obrigatório')) . '".';
        }
    }
    if (!empty($errors)) {
        client_app_api_error(422, $errors[0]);
    }

    $snapshotHtml = client_app_api_compose_form_snapshot(
        $schema,
        $normalizedValues,
        $meta['section'],
        trim((string)($link['form_title'] ?? ''))
    );
    $saved = false;
    $savedOk = false;
    if ($submit && $meta['section'] === 'decoracao') {
        $saved = eventos_decoracao_alteracao_pendente_registrar(
            $pdo,
            $meetingId,
            $linkId,
            $snapshotHtml,
            $schema,
            $normalizedValues
        );
        if (!empty($saved['ok'])) {
            eventos_cliente_portal_atualizar_secao_config($pdo, $meetingId, 'decoracao', true, false, 0);
        }
        $savedOk = !empty($saved['ok']);
    } else {
        $saved = $submit
            ? eventos_link_publico_registrar_envio($pdo, $linkId, $snapshotHtml)
            : eventos_link_publico_salvar_rascunho($pdo, $linkId, $snapshotHtml);
        $savedOk = !empty($saved);
    }

    if (!$savedOk) {
        client_app_api_log('form_save_failed', ['meeting_id' => $meetingId, 'link_id' => $linkId, 'module' => $moduleKey]);
        client_app_api_error(500, 'Não foi possível salvar o formulário agora.');
    }

    if ($submit) {
        eventos_reuniao_promover_anexos_rascunho_link($pdo, $linkId);
    }

    return [
        'ok' => true,
        'module' => $moduleKey === 'reuniao_final'
            ? client_app_api_module_reuniao_final($pdo, $meetingId)
            : client_app_api_module_links($pdo, $meetingId, $moduleKey),
    ];
}

function client_app_api_module_form_response_upload(PDO $pdo, int $meetingId, array $body, array $files): array
{
    client_app_api_require_eventos_helpers();
    $moduleKey = trim((string)($body['module'] ?? ''));
    $linkId = (int)($body['link_id'] ?? 0);
    $fieldId = trim((string)($body['field_id'] ?? ''));
    $description = trim((string)($body['description'] ?? ''));
    $submit = !empty($body['submit']);

    if ($fieldId === '') {
        client_app_api_error(422, 'Campo do formulário não informado.');
    }
    if (!isset($files['arquivo']) || !is_array($files['arquivo'])) {
        client_app_api_error(422, 'Selecione um arquivo para enviar.');
    }

    [$link, $meta] = client_app_api_find_module_link($pdo, $meetingId, $moduleKey, $linkId);
    if (empty($link['portal_editable'])) {
        client_app_api_error(403, 'Este formulário está disponível apenas para consulta.');
    }

    $schema = eventos_form_template_normalizar_schema(is_array($link['form_schema'] ?? null) ? $link['form_schema'] : []);
    $fieldMeta = null;
    foreach ($schema as $field) {
        if (trim((string)($field['id'] ?? '')) === $fieldId) {
            $fieldMeta = $field;
            break;
        }
    }
    if (!$fieldMeta || strtolower(trim((string)($fieldMeta['type'] ?? ''))) !== 'file') {
        client_app_api_error(404, 'Campo de arquivo não encontrado.');
    }

    $errorCode = (int)($files['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        client_app_api_error(422, client_app_api_upload_error_message($errorCode));
    }

    try {
        $uploader = new MagaluUpload();
        $upload = $uploader->upload($files['arquivo'], 'cliente-formulario/' . $meetingId . '/' . $moduleKey);
    } catch (Throwable $e) {
        client_app_api_log('form_upload_exception', ['meeting_id' => $meetingId, 'link_id' => $linkId, 'field_id' => $fieldId, 'message' => $e->getMessage()]);
        client_app_api_error(500, 'Não foi possível enviar o arquivo agora.');
    }

    if (empty($upload['sucesso'])) {
        client_app_api_error(422, trim((string)($upload['erro'] ?? 'Falha ao enviar o arquivo.')) ?: 'Falha ao enviar o arquivo.');
    }

    $saved = eventos_reuniao_salvar_anexo(
        $pdo,
        $meetingId,
        $meta['section'],
        $upload,
        'cliente',
        null,
        $description !== '' ? $description : (trim((string)($fieldMeta['label'] ?? '')) ?: null),
        $linkId,
        !$submit,
        $fieldId
    );

    if (empty($saved['ok'])) {
        client_app_api_log('form_upload_save_failed', ['meeting_id' => $meetingId, 'link_id' => $linkId, 'field_id' => $fieldId, 'error' => $saved['error'] ?? 'unknown']);
        client_app_api_error(500, 'Não foi possível registrar o arquivo enviado.');
    }

    if ($submit) {
        eventos_reuniao_promover_anexos_rascunho_link($pdo, $linkId);
    }

    return [
        'ok' => true,
        'module' => client_app_api_module_links($pdo, $meetingId, $moduleKey),
    ];
}

function client_app_api_module_form_response_excluir_arquivo(PDO $pdo, int $meetingId, array $body): array
{
    client_app_api_require_eventos_helpers();
    $moduleKey = trim((string)($body['module'] ?? ''));
    $linkId = (int)($body['link_id'] ?? 0);
    $fileId = (int)($body['file_id'] ?? 0);

    if ($fileId <= 0) {
        client_app_api_error(422, 'Arquivo inválido para exclusão.');
    }

    [$link, $meta] = client_app_api_find_module_link($pdo, $meetingId, $moduleKey, $linkId);
    if (empty($link['portal_editable'])) {
        client_app_api_error(403, 'Este formulário está disponível apenas para consulta.');
    }

    $deleted = eventos_reuniao_excluir_anexo_cliente_publico($pdo, $meetingId, $meta['section'], $fileId, $linkId);
    if (empty($deleted['ok'])) {
        client_app_api_error(422, trim((string)($deleted['error'] ?? 'Não foi possível excluir o arquivo.')) ?: 'Não foi possível excluir o arquivo.');
    }

    return [
        'ok' => true,
        'module' => client_app_api_module_links($pdo, $meetingId, $moduleKey),
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
