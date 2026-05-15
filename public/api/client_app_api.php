<?php
declare(strict_types=1);

function client_app_api_pdo(): PDO
{
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
        ':sucesso' => $success,
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
        return null;
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

function client_app_api_snapshot(array $sessionOrMeetingRow): array
{
    $snapshot = json_decode((string)($sessionOrMeetingRow['me_event_snapshot'] ?? '{}'), true);
    return is_array($snapshot) ? $snapshot : [];
}

function client_app_api_event_payload(PDO $pdo, int $meetingId): array
{
    $meeting = eventos_reuniao_get($pdo, $meetingId);
    if (!$meeting) {
        throw new RuntimeException('Evento não encontrado.');
    }

    $snapshot = client_app_api_snapshot($meeting);
    $portal = eventos_cliente_portal_get($pdo, $meetingId);

    $clientName = eventos_me_snapshot_cliente_nome($snapshot, 'Cliente');
    $eventName = trim((string)($snapshot['nome'] ?? 'Seu Evento'));
    $eventDate = trim((string)($snapshot['data'] ?? ''));
    $eventStart = trim((string)($snapshot['hora_inicio'] ?? $snapshot['hora'] ?? ''));
    $eventEnd = trim((string)($snapshot['hora_fim'] ?? ''));
    $eventLocation = eventos_me_snapshot_local($snapshot, 'Local não informado');

    $sections = $portal['secoes'] ?? eventos_cliente_portal_obter_config_secoes($portal);
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
        $summary['convidados'] = eventos_convidados_resumo($pdo, $meetingId);
    }
    if (!empty($portal['visivel_arquivos'])) {
        $summary['arquivos'] = eventos_arquivos_resumo($pdo, $meetingId);
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
            'reuniao_final' => !empty($portal['visivel_reuniao']),
            'convidados' => !empty($portal['visivel_convidados']),
            'arquivos' => !empty($portal['visivel_arquivos']),
            'dj' => !empty($sections['dj_protocolo']['visivel']),
            'formulario' => !empty($sections['formulario']['visivel']),
        ],
        'permissions' => [
            'reuniao_editavel' => !empty($portal['editavel_reuniao']),
            'convidados_editavel' => !empty($portal['editavel_convidados']),
            'arquivos_editavel' => !empty($portal['editavel_arquivos']),
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

