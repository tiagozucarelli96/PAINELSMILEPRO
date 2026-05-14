<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/env_bootstrap.php';
require_once dirname(__DIR__) . '/conexao.php';

const WA_APP_TITLE = 'Smile Chat';
const WA_DEFAULT_BASE_PATH = '/atendimento/';
const WA_CORE_CHAT_PERMISSIONS = ['perm_smile_chat', 'perm_smile_chat_admin', 'perm_superadmin'];

wa_start_session();

function wa_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = false;
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $https = true;
    } elseif (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        $https = true;
    }

    session_name('SMILECHATSESSID');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 14,
        'path' => wa_cookie_path(),
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function wa_pdo(): PDO
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Conexão com banco indisponível para o módulo de atendimento.');
    }

    return $pdo;
}

function wa_table_exists(string $table): bool
{
    $stmt = wa_pdo()->prepare("SELECT to_regclass(current_schema() || '.' || :table)");
    $stmt->execute([':table' => $table]);
    return $stmt->fetchColumn() !== null;
}

function wa_tables_ready(): bool
{
    return wa_table_exists('wa_departments')
        && wa_table_exists('wa_inboxes')
        && wa_table_exists('wa_conversations');
}

function wa_schema_file(): string
{
    return dirname(__DIR__, 2) . '/sql/060_atendimento_whatsapp_base.sql';
}

function wa_cookie_path(): string
{
    $basePath = wa_base_path();
    return $basePath === '/' ? '/' : rtrim($basePath, '/');
}

function wa_request_host(): string
{
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    if ($host === '') {
        return '';
    }

    return explode(':', $host)[0];
}

function wa_chat_host(): string
{
    return strtolower(trim((string)(painel_env('SMILE_CHAT_HOST', 'smilechat.smileeventos.com.br') ?? 'smilechat.smileeventos.com.br')));
}

function wa_base_path(): string
{
    if (wa_chat_host() !== '' && wa_request_host() === wa_chat_host()) {
        return '/';
    }

    $configured = trim((string)(painel_env('SMILE_CHAT_BASE_PATH', WA_DEFAULT_BASE_PATH) ?? WA_DEFAULT_BASE_PATH));
    if ($configured === '' || $configured === '/') {
        return '/';
    }

    return '/' . trim($configured, '/') . '/';
}

function wa_install_module(): void
{
    $schemaFile = wa_schema_file();
    if (!is_file($schemaFile)) {
        throw new RuntimeException('Arquivo de schema do módulo não encontrado.');
    }

    $sql = file_get_contents($schemaFile);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException('Schema do módulo vazio.');
    }

    $pdo = wa_pdo();
    $pdo->beginTransaction();

    try {
        $pdo->exec($sql);
        wa_ensure_core_permission_columns();

        $seedDepartments = [
            ['name' => 'COMERCIAL', 'description' => 'Atendimento comercial e orçamentos', 'color' => '#0f766e'],
            ['name' => 'PÓS VENDA', 'description' => 'Suporte após fechamento e operação', 'color' => '#7c3aed'],
            ['name' => 'ADMINISTRATIVO', 'description' => 'Financeiro, contratos e apoio interno', 'color' => '#1d4ed8'],
        ];

        $seedStmt = $pdo->prepare(
            'INSERT INTO wa_departments (name, description, color, sort_order, is_active)
             VALUES (:name, :description, :color, :sort_order, TRUE)'
        );

        foreach ($seedDepartments as $index => $department) {
            $checkStmt = $pdo->prepare('SELECT id FROM wa_departments WHERE name = :name LIMIT 1');
            $checkStmt->execute([':name' => $department['name']]);
            if ($checkStmt->fetchColumn()) {
                continue;
            }

            $seedStmt->execute([
                ':name' => $department['name'],
                ':description' => $department['description'],
                ':color' => $department['color'],
                ':sort_order' => $index,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function wa_normalize_email(string $email): string
{
    return mb_strtolower(trim($email));
}

function wa_csrf_token(): string
{
    if (empty($_SESSION['wa_csrf'])) {
        $_SESSION['wa_csrf'] = bin2hex(random_bytes(16));
    }

    return (string)$_SESSION['wa_csrf'];
}

function wa_validate_csrf(?string $token): void
{
    $expected = (string)($_SESSION['wa_csrf'] ?? '');
    if ($expected === '' || $token === null || !hash_equals($expected, $token)) {
        throw new RuntimeException('Token de sessão inválido. Atualize a página e tente novamente.');
    }
}

function wa_flash(string $type, string $message): void
{
    $_SESSION['wa_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function wa_pull_flash(): ?array
{
    $flash = $_SESSION['wa_flash'] ?? null;
    unset($_SESSION['wa_flash']);
    return is_array($flash) ? $flash : null;
}

function wa_redirect(string $path = ''): never
{
    header('Location: ' . wa_url($path));
    exit;
}

function wa_url(string $path = ''): string
{
    $base = wa_base_path();
    $path = ltrim($path, '/');

    if ($base === '/') {
        return $path === '' ? '/' : '/' . $path;
    }

    $normalizedBase = rtrim($base, '/');
    return $path === '' ? $normalizedBase . '/' : $normalizedBase . '/' . $path;
}

function wa_gateway_base_url(): string
{
    $value = trim((string)(painel_env('WHATSAPP_GATEWAY_BASE_URL', 'http://127.0.0.1:8787') ?? 'http://127.0.0.1:8787'));
    return rtrim($value, '/');
}

function wa_gateway_is_enabled(): bool
{
    return wa_gateway_base_url() !== '';
}

function wa_http_json_request(string $method, string $url, ?array $payload = null, int $timeoutSeconds = 8): array
{
    $headers = [
        'Accept: application/json',
    ];

    $content = null;
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $content = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($content)) {
            throw new RuntimeException('Falha ao serializar payload JSON.');
        }
    }

    $context = stream_context_create([
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
        ],
    ]);

    $result = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $statusLine = $responseHeaders[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);
    $statusCode = isset($matches[1]) ? (int)$matches[1] : 0;

    $decoded = null;
    if (is_string($result) && trim($result) !== '') {
        $decoded = json_decode($result, true);
    }

    return [
        'status' => $statusCode,
        'ok' => $statusCode >= 200 && $statusCode < 300,
        'body' => is_array($decoded) ? $decoded : null,
        'raw' => is_string($result) ? $result : '',
    ];
}

function wa_gateway_request(string $method, string $path, ?array $payload = null, int $timeoutSeconds = 8): array
{
    if (!wa_gateway_is_enabled()) {
        throw new RuntimeException('WHATSAPP_GATEWAY_BASE_URL não configurado.');
    }

    $url = wa_gateway_base_url() . '/' . ltrim($path, '/');
    $response = wa_http_json_request($method, $url, $payload, $timeoutSeconds);

    if (!$response['ok']) {
        $bodyMessage = is_array($response['body']) ? (string)($response['body']['error'] ?? '') : '';
        $fallback = $bodyMessage !== '' ? $bodyMessage : ('Gateway respondeu com HTTP ' . $response['status'] . '.');
        throw new RuntimeException($fallback);
    }

    return $response['body'] ?? [];
}

function wa_gateway_health(): ?array
{
    static $cached = false;
    static $data = null;

    if ($cached) {
        return $data;
    }

    $cached = true;

    if (!wa_gateway_is_enabled()) {
        $data = null;
        return $data;
    }

    try {
        $data = wa_gateway_request('GET', '/health', null, 4);
    } catch (Throwable $e) {
        $data = [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }

    return $data;
}

function wa_gateway_sessions(): array
{
    static $cached = false;
    static $items = [];

    if ($cached) {
        return $items;
    }

    $cached = true;

    if (!wa_gateway_is_enabled()) {
        $items = [];
        return $items;
    }

    try {
        $response = wa_gateway_request('GET', '/api/sessions', null, 6);
        $items = isset($response['items']) && is_array($response['items']) ? $response['items'] : [];
    } catch (Throwable $e) {
        $items = [];
    }

    return $items;
}

function wa_gateway_sessions_by_key(): array
{
    $indexed = [];
    foreach (wa_gateway_sessions() as $item) {
        if (!is_array($item)) {
            continue;
        }
        $sessionKey = (string)($item['session_key'] ?? '');
        if ($sessionKey === '') {
            continue;
        }
        $indexed[$sessionKey] = $item;
    }

    return $indexed;
}

function wa_page(): string
{
    $page = trim((string)($_GET['page'] ?? 'dashboard'));
    if (in_array($page, ['inboxes', 'departments', 'users', 'quick_replies'], true)) {
        return 'settings';
    }

    return $page !== '' ? $page : 'dashboard';
}

function wa_settings_tab(): string
{
    $legacyPage = trim((string)($_GET['page'] ?? ''));
    if (in_array($legacyPage, ['inboxes', 'departments', 'users', 'quick_replies'], true)) {
        return $legacyPage;
    }

    $tab = trim((string)($_GET['tab'] ?? 'inboxes'));
    return in_array($tab, ['inboxes', 'departments', 'users', 'quick_replies'], true) ? $tab : 'inboxes';
}

function wa_is_logged_in(): bool
{
    return !empty($_SESSION['wa_user_id']);
}

function wa_logout(): void
{
    unset($_SESSION['wa_user_id'], $_SESSION['wa_user']);
}

function wa_login(string $login, string $password): bool
{
    wa_ensure_core_permission_columns();

    $login = trim($login);
    if ($login === '' || $password === '') {
        return false;
    }

    $columns = wa_core_user_columns();
    $loginColumns = array_values(array_filter(
        ['email', 'login', 'loguin', 'usuario', 'username', 'user'],
        static fn (string $column): bool => in_array($column, $columns, true)
    ));
    if ($loginColumns === []) {
        $loginColumns = ['email'];
    }

    $where = implode(' OR ', array_map(static fn (string $column): string => $column . ' = :login', $loginColumns));
    $stmt = wa_pdo()->prepare('SELECT * FROM usuarios WHERE (' . $where . ') LIMIT 1');
    $normalizedLogin = wa_normalize_email($login);
    $stmt->execute([':login' => $normalizedLogin]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user && $normalizedLogin !== $login) {
        $stmt->execute([':login' => $login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$user || !wa_core_user_is_active($user) || !wa_can_access_chat_from_row($user)) {
        return false;
    }

    if (!wa_verify_core_password($user, $password)) {
        return false;
    }

    session_regenerate_id(true);
    $chatProfileId = wa_sync_chat_profile_from_core_user($user);
    $displayName = (string)($user['nome'] ?? $user['nome_completo'] ?? $user['name'] ?? $user['email'] ?? $login);
    $email = (string)($user['email'] ?? '');
    $canManageSettings = wa_boolish($user['perm_superadmin'] ?? false) || wa_boolish($user['perm_smile_chat_admin'] ?? false);

    $_SESSION['wa_user_id'] = (int)($user['id'] ?? 0);
    $_SESSION['wa_user'] = [
        'id' => (int)($user['id'] ?? 0),
        'chat_profile_id' => $chatProfileId,
        'display_name' => $displayName,
        'email' => $email,
        'status' => 'available',
        'is_super_admin' => $canManageSettings,
        'can_manage_settings' => $canManageSettings,
    ];

    return true;
}

function wa_auth_user(): array
{
    if (!wa_is_logged_in()) {
        throw new RuntimeException('Usuário não autenticado.');
    }

    return (array)($_SESSION['wa_user'] ?? []);
}

function wa_user_can_manage_settings(?array $user = null): bool
{
    $user ??= wa_auth_user();
    return wa_boolish($user['is_super_admin'] ?? false) || wa_boolish($user['can_manage_settings'] ?? false);
}

function wa_require_settings_access(): void
{
    if (!wa_user_can_manage_settings()) {
        throw new RuntimeException('Somente superadmin do Smile Chat pode alterar configurações.');
    }
}

function wa_core_user_columns(): array
{
    static $columns = null;
    if (is_array($columns)) {
        return $columns;
    }

    $stmt = wa_pdo()->query("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_name = 'usuarios'
        ORDER BY ordinal_position
    ");
    $columns = array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    return $columns;
}

function wa_ensure_core_permission_columns(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    wa_pdo()->exec("
        ALTER TABLE usuarios
            ADD COLUMN IF NOT EXISTS perm_smile_chat BOOLEAN DEFAULT FALSE,
            ADD COLUMN IF NOT EXISTS perm_smile_chat_admin BOOLEAN DEFAULT FALSE
    ");
    $ready = true;
}

function wa_sync_access_users(): void
{
    if (!wa_table_exists('wa_users')) {
        return;
    }

    wa_ensure_core_permission_columns();

    $rows = wa_pdo()->query("
        SELECT *
        FROM usuarios
        WHERE COALESCE(perm_smile_chat, FALSE) = TRUE
           OR COALESCE(perm_smile_chat_admin, FALSE) = TRUE
           OR COALESCE(perm_superadmin, FALSE) = TRUE
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        wa_sync_chat_profile_from_core_user($row);
    }
}

function wa_sync_chat_profile_from_core_user(array $user): int
{
    if (!wa_table_exists('wa_users')) {
        throw new RuntimeException('Tabela de perfis do chat indisponível.');
    }

    $email = wa_normalize_email((string)($user['email'] ?? ''));
    if ($email === '') {
        throw new RuntimeException('Usuário sem email não pode ser sincronizado para o chat.');
    }

    $displayName = trim((string)($user['nome'] ?? $user['nome_completo'] ?? $user['name'] ?? $email));
    $isActive = wa_core_user_is_active($user);
    $isSuperAdmin = wa_boolish($user['perm_superadmin'] ?? false) || wa_boolish($user['perm_smile_chat_admin'] ?? false);
    $status = $isActive ? 'available' : 'offline';
    $placeholderHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

    $existingStmt = wa_pdo()->prepare('SELECT id FROM wa_users WHERE email = :email LIMIT 1');
    $existingStmt->execute([':email' => $email]);
    $existingId = (int)($existingStmt->fetchColumn() ?: 0);

    if ($existingId > 0) {
        $updateStmt = wa_pdo()->prepare(
            'UPDATE wa_users
                SET display_name = :display_name,
                    status = :status,
                    is_super_admin = :is_super_admin,
                    is_active = :is_active,
                    updated_at = NOW()
              WHERE id = :id'
        );
        $updateStmt->execute([
            ':id' => $existingId,
            ':display_name' => $displayName,
            ':status' => $status,
            ':is_super_admin' => $isSuperAdmin,
            ':is_active' => $isActive,
        ]);

        return $existingId;
    }

    $insertStmt = wa_pdo()->prepare(
        'INSERT INTO wa_users (display_name, email, password_hash, status, is_super_admin, is_active)
         VALUES (:display_name, :email, :password_hash, :status, :is_super_admin, :is_active)'
    );
    $insertStmt->execute([
        ':display_name' => $displayName,
        ':email' => $email,
        ':password_hash' => $placeholderHash,
        ':status' => $status,
        ':is_super_admin' => $isSuperAdmin,
        ':is_active' => $isActive,
    ]);

    return (int)wa_pdo()->lastInsertId();
}

function wa_can_access_chat_from_row(array $user): bool
{
    foreach (WA_CORE_CHAT_PERMISSIONS as $permission) {
        if (wa_boolish($user[$permission] ?? false)) {
            return true;
        }
    }

    return false;
}

function wa_core_user_is_active(array $user): bool
{
    if (!array_key_exists('ativo', $user)) {
        return true;
    }

    $value = $user['ativo'];
    return !($value === false || $value === 0 || $value === '0' || $value === 'f' || $value === 'false' || $value === null);
}

function wa_verify_core_password(array $user, string $password): bool
{
    foreach (['senha', 'senha_hash', 'password', 'pass'] as $column) {
        if (!array_key_exists($column, $user)) {
            continue;
        }

        $stored = (string)$user[$column];
        if ($stored === '') {
            continue;
        }

        if (preg_match('/^\$2[ayb]\$|\$argon2/i', $stored) === 1) {
            return password_verify($password, $stored);
        }

        if (preg_match('/^[a-f0-9]{32}$/i', $stored) === 1) {
            return strtolower($stored) === md5($password);
        }

        return hash_equals($stored, $password);
    }

    return false;
}

function wa_boolish(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 't', 'yes', 'y', 'on'], true);
}

function wa_core_user_field_expr(array $availableColumns, array $candidates, string $fallback = "''"): string
{
    $valid = array_values(array_filter($candidates, static fn (string $column): bool => in_array($column, $availableColumns, true)));
    if ($valid === []) {
        return $fallback;
    }

    return 'COALESCE(' . implode(', ', $valid) . ')';
}

function wa_status_label(string $status): string
{
    return match ($status) {
        'available' => 'Disponível',
        'busy' => 'Ocupado',
        'away' => 'Ausente',
        'offline' => 'Offline',
        'connected' => 'Conectado',
        'connecting' => 'Conectando',
        'disconnected' => 'Desconectado',
        'error' => 'Erro',
        'open' => 'Aberta',
        'waiting' => 'Aguardando',
        'pending' => 'Finalizada',
        'closed' => 'Fechada',
        default => ucfirst($status),
    };
}

function wa_e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function wa_fetch_all_departments(): array
{
    if (!wa_table_exists('wa_departments')) {
        return [];
    }

    $stmt = wa_pdo()->query(
        'SELECT id, name, description, color, sort_order, is_active
           FROM wa_departments
          ORDER BY sort_order ASC, name ASC'
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function wa_fetch_users(): array
{
    wa_ensure_core_permission_columns();
    wa_sync_access_users();

    if (!wa_table_exists('wa_users')) {
        return [];
    }

    $sql = "
        SELECT
            u.id,
            u.display_name,
            u.email,
            u.status,
            u.is_active,
            u.is_super_admin,
            COALESCE(string_agg(d.name || ' (' || ud.role || ')', ', ' ORDER BY d.name), '') AS departments
        FROM wa_users u
        LEFT JOIN wa_user_departments ud ON ud.user_id = u.id
        LEFT JOIN wa_departments d ON d.id = ud.department_id
        GROUP BY u.id
        ORDER BY u.display_name ASC
    ";

    return wa_pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function wa_fetch_inboxes(): array
{
    if (!wa_table_exists('wa_inboxes')) {
        return [];
    }

    $sql = "
        SELECT
            i.id,
            i.name,
            i.session_key,
            i.phone_number,
            i.provider,
            i.connection_mode,
            i.status,
            d.name AS department_name,
            i.updated_at
        FROM wa_inboxes i
        LEFT JOIN wa_departments d ON d.id = i.department_id
        ORDER BY i.name ASC
    ";

    $rows = wa_pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $gatewaySessions = wa_gateway_sessions_by_key();

    foreach ($rows as &$row) {
        $sessionKey = (string)($row['session_key'] ?? '');
        $gateway = $gatewaySessions[$sessionKey] ?? null;
        if (!is_array($gateway)) {
            $row['gateway_status'] = null;
            $row['runtime_meta'] = null;
            continue;
        }

        $row['gateway_status'] = (string)($gateway['status'] ?? '');
        $row['runtime_meta'] = is_array($gateway['runtime_meta'] ?? null) ? $gateway['runtime_meta'] : null;
        $row['credential_updated_at'] = $gateway['credential_updated_at'] ?? null;
    }
    unset($row);

    return $rows;
}

function wa_fetch_quick_replies(): array
{
    if (!wa_table_exists('wa_quick_replies')) {
        return [];
    }

    $sql = "
        SELECT
            qr.id,
            qr.title,
            qr.shortcut,
            qr.body,
            qr.is_active,
            d.name AS department_name
        FROM wa_quick_replies qr
        LEFT JOIN wa_departments d ON d.id = qr.department_id
        ORDER BY qr.shortcut ASC
    ";

    return wa_pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function wa_fetch_conversations(): array
{
    if (!wa_table_exists('wa_conversations')) {
        return [];
    }

    $sql = "
        SELECT
            c.id,
            c.status,
            c.priority,
            c.subject,
            c.last_message_preview,
            c.unread_count,
            c.last_message_at,
            ct.full_name AS contact_name,
            d.name AS department_name,
            u.display_name AS assigned_name,
            i.name AS inbox_name
        FROM wa_conversations c
        JOIN wa_contacts ct ON ct.id = c.contact_id
        JOIN wa_inboxes i ON i.id = c.inbox_id
        LEFT JOIN wa_departments d ON d.id = c.department_id
        LEFT JOIN wa_users u ON u.id = c.assigned_user_id
        ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
        LIMIT 40
    ";

    return wa_pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function wa_fetch_conversation_detail(int $conversationId): ?array
{
    if ($conversationId <= 0 || !wa_table_exists('wa_conversations')) {
        return null;
    }

    $stmt = wa_pdo()->prepare("
        SELECT
            c.id,
            c.inbox_id,
            c.contact_id,
            c.department_id,
            c.assigned_user_id,
            c.status,
            c.priority,
            c.subject,
            c.last_message_preview,
            c.unread_count,
            c.started_at,
            c.last_message_at,
            c.closed_at,
            ct.full_name AS contact_name,
            ct.phone_e164,
            d.name AS department_name,
            u.display_name AS assigned_name,
            i.name AS inbox_name,
            i.session_key
        FROM wa_conversations c
        JOIN wa_contacts ct ON ct.id = c.contact_id
        JOIN wa_inboxes i ON i.id = c.inbox_id
        LEFT JOIN wa_departments d ON d.id = c.department_id
        LEFT JOIN wa_users u ON u.id = c.assigned_user_id
        WHERE c.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $conversationId]);
    $conversation = $stmt->fetch(PDO::FETCH_ASSOC);

    return $conversation ?: null;
}

function wa_fetch_conversation_messages(int $conversationId): array
{
    if ($conversationId <= 0 || !wa_table_exists('wa_messages')) {
        return [];
    }

    $stmt = wa_pdo()->prepare("
        SELECT
            m.id,
            m.direction,
            m.message_type,
            m.body,
            m.media_url,
            m.author_user_id,
            m.external_message_id,
            m.created_at,
            u.display_name AS author_name
        FROM wa_messages m
        LEFT JOIN wa_users u ON u.id = m.author_user_id
        WHERE m.conversation_id = :conversation_id
        ORDER BY m.created_at ASC, m.id ASC
    ");
    $stmt->execute([':conversation_id' => $conversationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function wa_transfer_targets(): array
{
    return array_values(array_filter(
        wa_fetch_users(),
        static fn (array $user): bool => !empty($user['is_active'])
    ));
}

function wa_accept_conversation(int $conversationId, array $authUser): void
{
    $chatProfileId = (int)($authUser['chat_profile_id'] ?? 0);
    if ($conversationId <= 0 || $chatProfileId <= 0) {
        throw new RuntimeException('Conversa inválida para aceite.');
    }

    $stmt = wa_pdo()->prepare("
        UPDATE wa_conversations
        SET status = 'open',
            assigned_user_id = :assigned_user_id,
            unread_count = 0,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $conversationId,
        ':assigned_user_id' => $chatProfileId,
    ]);
}

function wa_finalize_conversation(int $conversationId): void
{
    if ($conversationId <= 0) {
        throw new RuntimeException('Conversa inválida para finalização.');
    }

    $stmt = wa_pdo()->prepare("
        UPDATE wa_conversations
        SET status = 'pending',
            unread_count = 0,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([':id' => $conversationId]);
}

function wa_end_conversation(int $conversationId): void
{
    if ($conversationId <= 0) {
        throw new RuntimeException('Conversa inválida para encerramento.');
    }

    $stmt = wa_pdo()->prepare("
        UPDATE wa_conversations
        SET status = 'closed',
            unread_count = 0,
            closed_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([':id' => $conversationId]);
}

function wa_reopen_conversation(int $conversationId, array $authUser): void
{
    $chatProfileId = (int)($authUser['chat_profile_id'] ?? 0);
    if ($conversationId <= 0 || $chatProfileId <= 0) {
        throw new RuntimeException('Conversa inválida para reabertura.');
    }

    $stmt = wa_pdo()->prepare("
        UPDATE wa_conversations
        SET status = 'open',
            assigned_user_id = COALESCE(assigned_user_id, :assigned_user_id),
            closed_at = NULL,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $conversationId,
        ':assigned_user_id' => $chatProfileId,
    ]);
}

function wa_transfer_conversation(int $conversationId, int $targetUserId, int $departmentId = 0): void
{
    if ($conversationId <= 0 || $targetUserId <= 0) {
        throw new RuntimeException('Destino inválido para transferência.');
    }

    $stmt = wa_pdo()->prepare("
        UPDATE wa_conversations
        SET assigned_user_id = :assigned_user_id,
            department_id = CASE WHEN :department_id > 0 THEN :department_id ELSE department_id END,
            status = 'open',
            unread_count = 0,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $conversationId,
        ':assigned_user_id' => $targetUserId,
        ':department_id' => $departmentId,
    ]);
}

function wa_send_conversation_reply(int $conversationId, string $body, array $authUser): void
{
    $body = trim($body);
    if ($conversationId <= 0 || $body === '') {
        throw new RuntimeException('Mensagem inválida.');
    }

    $conversation = wa_fetch_conversation_detail($conversationId);
    if (!$conversation) {
        throw new RuntimeException('Conversa não encontrada.');
    }

    $phoneE164 = trim((string)($conversation['phone_e164'] ?? ''));
    $sessionKey = trim((string)($conversation['session_key'] ?? ''));
    if ($phoneE164 === '' || $sessionKey === '') {
        throw new RuntimeException('Conversa sem sessão ou telefone para envio.');
    }

    wa_gateway_request('POST', '/api/sessions/' . rawurlencode($sessionKey) . '/messages/send', [
        'phoneE164' => $phoneE164,
        'body' => $body,
        'contactName' => (string)($conversation['contact_name'] ?? $phoneE164),
        'authorUserId' => (int)($authUser['chat_profile_id'] ?? 0),
    ]);

    wa_pdo()->prepare("
        UPDATE wa_conversations
        SET status = 'open',
            assigned_user_id = COALESCE(assigned_user_id, :assigned_user_id),
            updated_at = NOW()
        WHERE id = :id
    ")->execute([
        ':id' => $conversationId,
        ':assigned_user_id' => (int)($authUser['chat_profile_id'] ?? 0),
    ]);
}

function wa_fetch_dashboard_counts(): array
{
    $counts = [
        'departments' => 0,
        'users' => 0,
        'inboxes' => 0,
        'inboxes_connected' => 0,
        'conversations_open' => 0,
        'conversations_waiting' => 0,
        'quick_replies' => 0,
    ];

    if (!wa_tables_ready()) {
        return $counts;
    }

    $counts['departments'] = (int)wa_pdo()->query('SELECT COUNT(*) FROM wa_departments WHERE is_active = TRUE')->fetchColumn();
    wa_ensure_core_permission_columns();
    $counts['users'] = (int)wa_pdo()->query("
        SELECT COUNT(*)
        FROM usuarios
        WHERE COALESCE(ativo, TRUE) = TRUE
          AND (
            COALESCE(perm_smile_chat, FALSE) = TRUE
            OR COALESCE(perm_smile_chat_admin, FALSE) = TRUE
            OR COALESCE(perm_superadmin, FALSE) = TRUE
          )
    ")->fetchColumn();
    $counts['inboxes'] = (int)wa_pdo()->query('SELECT COUNT(*) FROM wa_inboxes')->fetchColumn();
    $counts['inboxes_connected'] = (int)wa_pdo()->query("SELECT COUNT(*) FROM wa_inboxes WHERE status = 'connected'")->fetchColumn();
    $counts['conversations_open'] = (int)wa_pdo()->query("SELECT COUNT(*) FROM wa_conversations WHERE status = 'open'")->fetchColumn();
    $counts['conversations_waiting'] = (int)wa_pdo()->query("SELECT COUNT(*) FROM wa_conversations WHERE status = 'waiting'")->fetchColumn();
    $counts['quick_replies'] = (int)wa_pdo()->query('SELECT COUNT(*) FROM wa_quick_replies WHERE is_active = TRUE')->fetchColumn();

    return $counts;
}

function wa_save_department(array $input): void
{
    wa_require_settings_access();

    $id = (int)($input['id'] ?? 0);
    $name = trim((string)($input['name'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $color = trim((string)($input['color'] ?? '#1d4ed8'));
    $sortOrder = (int)($input['sort_order'] ?? 0);

    if ($name === '') {
        throw new RuntimeException('Nome do departamento é obrigatório.');
    }

    if ($id > 0) {
        $stmt = wa_pdo()->prepare(
            'UPDATE wa_departments
                SET name = :name,
                    description = :description,
                    color = :color,
                    sort_order = :sort_order,
                    updated_at = NOW()
              WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':description' => $description !== '' ? $description : null,
            ':color' => $color !== '' ? $color : '#1d4ed8',
            ':sort_order' => $sortOrder,
        ]);
        return;
    }

    $stmt = wa_pdo()->prepare(
        'INSERT INTO wa_departments (name, description, color, sort_order, is_active)
         VALUES (:name, :description, :color, :sort_order, TRUE)'
    );
    $stmt->execute([
        ':name' => $name,
        ':description' => $description !== '' ? $description : null,
        ':color' => $color !== '' ? $color : '#1d4ed8',
        ':sort_order' => $sortOrder,
    ]);
}

function wa_toggle_department(int $id): void
{
    wa_require_settings_access();

    $stmt = wa_pdo()->prepare(
        'UPDATE wa_departments
            SET is_active = NOT is_active,
                updated_at = NOW()
          WHERE id = :id'
    );
    $stmt->execute([':id' => $id]);
}

function wa_save_user(array $input): void
{
    wa_require_settings_access();
    throw new RuntimeException('Os acessos do Smile Chat agora são gerenciados na tela principal de usuários do Painel Smile.');
}

function wa_toggle_user(int $id): void
{
    wa_require_settings_access();
    throw new RuntimeException('Os acessos do Smile Chat agora são gerenciados na tela principal de usuários do Painel Smile.');
}

function wa_save_inbox(array $input): void
{
    wa_require_settings_access();

    $id = (int)($input['id'] ?? 0);
    $name = trim((string)($input['name'] ?? ''));
    $sessionKey = trim((string)($input['session_key'] ?? ''));
    $phoneNumber = trim((string)($input['phone_number'] ?? ''));
    $provider = trim((string)($input['provider'] ?? 'mock'));
    $connectionMode = trim((string)($input['connection_mode'] ?? 'qr'));
    $departmentId = (int)($input['department_id'] ?? 0);
    $notes = trim((string)($input['notes'] ?? ''));

    if ($name === '' || $sessionKey === '') {
        throw new RuntimeException('Nome e chave da sessão são obrigatórios.');
    }

    $params = [
        ':name' => $name,
        ':session_key' => $sessionKey,
        ':phone_number' => $phoneNumber !== '' ? $phoneNumber : null,
        ':provider' => $provider !== '' ? $provider : 'mock',
        ':connection_mode' => $connectionMode !== '' ? $connectionMode : 'qr',
        ':department_id' => $departmentId > 0 ? $departmentId : null,
        ':notes' => $notes !== '' ? $notes : null,
    ];

    if ($id > 0) {
        $stmt = wa_pdo()->prepare(
            'UPDATE wa_inboxes
                SET name = :name,
                    session_key = :session_key,
                    phone_number = :phone_number,
                    provider = :provider,
                    connection_mode = :connection_mode,
                    department_id = :department_id,
                    notes = :notes,
                    updated_at = NOW()
              WHERE id = :id'
        );
        $params[':id'] = $id;
        $stmt->execute($params);
        return;
    }

    $stmt = wa_pdo()->prepare(
        'INSERT INTO wa_inboxes
            (name, session_key, phone_number, provider, connection_mode, department_id, notes, status)
         VALUES
            (:name, :session_key, :phone_number, :provider, :connection_mode, :department_id, :notes, :status)'
    );
    $params[':status'] = 'disconnected';
    $stmt->execute($params);
}

function wa_save_quick_reply(array $input): void
{
    wa_require_settings_access();

    $id = (int)($input['id'] ?? 0);
    $title = trim((string)($input['title'] ?? ''));
    $shortcut = trim((string)($input['shortcut'] ?? ''));
    $body = trim((string)($input['body'] ?? ''));
    $departmentId = (int)($input['department_id'] ?? 0);

    if ($title === '' || $shortcut === '' || $body === '') {
        throw new RuntimeException('Título, atalho e conteúdo são obrigatórios.');
    }

    $params = [
        ':title' => $title,
        ':shortcut' => $shortcut,
        ':body' => $body,
        ':department_id' => $departmentId > 0 ? $departmentId : null,
    ];

    if ($id > 0) {
        $stmt = wa_pdo()->prepare(
            'UPDATE wa_quick_replies
                SET title = :title,
                    shortcut = :shortcut,
                    body = :body,
                    department_id = :department_id,
                    updated_at = NOW()
              WHERE id = :id'
        );
        $params[':id'] = $id;
        $stmt->execute($params);
        return;
    }

    $stmt = wa_pdo()->prepare(
        'INSERT INTO wa_quick_replies (title, shortcut, body, department_id, is_active)
         VALUES (:title, :shortcut, :body, :department_id, TRUE)'
    );
    $stmt->execute($params);
}

function wa_connect_inbox_session(string $sessionKey): array
{
    wa_require_settings_access();
    return wa_gateway_request('POST', '/api/sessions/' . rawurlencode($sessionKey) . '/connect');
}

function wa_disconnect_inbox_session(string $sessionKey): array
{
    wa_require_settings_access();
    return wa_gateway_request('POST', '/api/sessions/' . rawurlencode($sessionKey) . '/disconnect');
}
