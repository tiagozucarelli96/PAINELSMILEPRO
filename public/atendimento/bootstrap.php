<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/env_bootstrap.php';
require_once dirname(__DIR__) . '/conexao.php';

const WA_APP_TITLE = 'Smile Chat';
const WA_BASE_PATH = '/atendimento/';

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
        'path' => '/atendimento',
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
    return wa_table_exists('wa_users')
        && wa_table_exists('wa_departments')
        && wa_table_exists('wa_conversations');
}

function wa_has_users(): bool
{
    if (!wa_table_exists('wa_users')) {
        return false;
    }

    return (int)wa_pdo()->query('SELECT COUNT(*) FROM wa_users')->fetchColumn() > 0;
}

function wa_schema_file(): string
{
    return dirname(__DIR__, 2) . '/sql/060_atendimento_whatsapp_base.sql';
}

function wa_install_module(string $name, string $email, string $password): void
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

        $email = wa_normalize_email($email);
        $stmt = $pdo->prepare(
            'INSERT INTO wa_users (display_name, email, password_hash, status, is_super_admin, is_active)
             VALUES (:display_name, :email, :password_hash, :status, TRUE, TRUE)'
        );
        $stmt->execute([
            ':display_name' => trim($name),
            ':email' => $email,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':status' => 'available',
        ]);

        $seedDepartments = [
            ['name' => 'COMERCIAL', 'description' => 'Atendimento comercial e orçamentos', 'color' => '#0f766e'],
            ['name' => 'PÓS VENDA', 'description' => 'Suporte após fechamento e operação', 'color' => '#7c3aed'],
            ['name' => 'ADMINISTRATIVO', 'description' => 'Financeiro, contratos e apoio interno', 'color' => '#1d4ed8'],
        ];

        $seedStmt = $pdo->prepare(
            'INSERT INTO wa_departments (name, description, color, sort_order, is_active)
             VALUES (:name, :description, :color, :sort_order, TRUE)
             ON CONFLICT (name) DO NOTHING'
        );

        foreach ($seedDepartments as $index => $department) {
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
    $base = rtrim(WA_BASE_PATH, '/');
    $path = ltrim($path, '/');
    return $path === '' ? $base . '/' : $base . '/' . $path;
}

function wa_page(): string
{
    $page = trim((string)($_GET['page'] ?? 'dashboard'));
    return $page !== '' ? $page : 'dashboard';
}

function wa_is_logged_in(): bool
{
    return !empty($_SESSION['wa_user_id']);
}

function wa_logout(): void
{
    unset($_SESSION['wa_user_id'], $_SESSION['wa_user']);
}

function wa_login(string $email, string $password): bool
{
    $stmt = wa_pdo()->prepare(
        'SELECT id, display_name, email, password_hash, status, is_super_admin, is_active
           FROM wa_users
          WHERE email = :email
          LIMIT 1'
    );
    $stmt->execute([':email' => wa_normalize_email($email)]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['is_active'])) {
        return false;
    }

    if (!password_verify($password, (string)$user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['wa_user_id'] = (int)$user['id'];
    $_SESSION['wa_user'] = [
        'id' => (int)$user['id'],
        'display_name' => (string)$user['display_name'],
        'email' => (string)$user['email'],
        'status' => (string)$user['status'],
        'is_super_admin' => !empty($user['is_super_admin']),
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
        'pending' => 'Pendente',
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

    return wa_pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
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

function wa_fetch_dashboard_counts(): array
{
    $counts = [
        'departments' => 0,
        'users' => 0,
        'inboxes' => 0,
        'conversations_open' => 0,
        'conversations_waiting' => 0,
        'quick_replies' => 0,
    ];

    if (!wa_tables_ready()) {
        return $counts;
    }

    $counts['departments'] = (int)wa_pdo()->query('SELECT COUNT(*) FROM wa_departments WHERE is_active = TRUE')->fetchColumn();
    $counts['users'] = (int)wa_pdo()->query('SELECT COUNT(*) FROM wa_users WHERE is_active = TRUE')->fetchColumn();
    $counts['inboxes'] = (int)wa_pdo()->query('SELECT COUNT(*) FROM wa_inboxes')->fetchColumn();
    $counts['conversations_open'] = (int)wa_pdo()->query("SELECT COUNT(*) FROM wa_conversations WHERE status = 'open'")->fetchColumn();
    $counts['conversations_waiting'] = (int)wa_pdo()->query("SELECT COUNT(*) FROM wa_conversations WHERE status = 'waiting'")->fetchColumn();
    $counts['quick_replies'] = (int)wa_pdo()->query('SELECT COUNT(*) FROM wa_quick_replies WHERE is_active = TRUE')->fetchColumn();

    return $counts;
}

function wa_save_department(array $input): void
{
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
    $pdo = wa_pdo();
    $id = (int)($input['id'] ?? 0);
    $name = trim((string)($input['display_name'] ?? ''));
    $email = wa_normalize_email((string)($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $status = (string)($input['status'] ?? 'offline');
    $isSuperAdmin = !empty($input['is_super_admin']);
    $departments = array_map('intval', (array)($input['department_ids'] ?? []));
    $roles = (array)($input['department_roles'] ?? []);

    if ($name === '' || $email === '') {
        throw new RuntimeException('Nome e email do atendente são obrigatórios.');
    }

    if ($id === 0 && $password === '') {
        throw new RuntimeException('Senha é obrigatória para novos atendentes.');
    }

    $pdo->beginTransaction();

    try {
        if ($id > 0) {
            if ($password !== '') {
                $stmt = $pdo->prepare(
                    'UPDATE wa_users
                        SET display_name = :display_name,
                            email = :email,
                            password_hash = :password_hash,
                            status = :status,
                            is_super_admin = :is_super_admin,
                            updated_at = NOW()
                      WHERE id = :id'
                );
                $stmt->execute([
                    ':id' => $id,
                    ':display_name' => $name,
                    ':email' => $email,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ':status' => $status,
                    ':is_super_admin' => $isSuperAdmin,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE wa_users
                        SET display_name = :display_name,
                            email = :email,
                            status = :status,
                            is_super_admin = :is_super_admin,
                            updated_at = NOW()
                      WHERE id = :id'
                );
                $stmt->execute([
                    ':id' => $id,
                    ':display_name' => $name,
                    ':email' => $email,
                    ':status' => $status,
                    ':is_super_admin' => $isSuperAdmin,
                ]);
            }
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO wa_users (display_name, email, password_hash, status, is_super_admin, is_active)
                 VALUES (:display_name, :email, :password_hash, :status, :is_super_admin, TRUE)'
            );
            $stmt->execute([
                ':display_name' => $name,
                ':email' => $email,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':status' => $status,
                ':is_super_admin' => $isSuperAdmin,
            ]);
            $id = (int)$pdo->lastInsertId();
        }

        $deleteStmt = $pdo->prepare('DELETE FROM wa_user_departments WHERE user_id = :user_id');
        $deleteStmt->execute([':user_id' => $id]);

        if ($departments !== []) {
            $insertStmt = $pdo->prepare(
                'INSERT INTO wa_user_departments (user_id, department_id, role)
                 VALUES (:user_id, :department_id, :role)'
            );
            foreach ($departments as $departmentId) {
                if ($departmentId <= 0) {
                    continue;
                }
                $role = (string)($roles[$departmentId] ?? 'agent');
                $insertStmt->execute([
                    ':user_id' => $id,
                    ':department_id' => $departmentId,
                    ':role' => in_array($role, ['agent', 'supervisor'], true) ? $role : 'agent',
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function wa_toggle_user(int $id): void
{
    $stmt = wa_pdo()->prepare(
        'UPDATE wa_users
            SET is_active = NOT is_active,
                updated_at = NOW()
          WHERE id = :id'
    );
    $stmt->execute([':id' => $id]);
}

function wa_save_inbox(array $input): void
{
    $id = (int)($input['id'] ?? 0);
    $name = trim((string)($input['name'] ?? ''));
    $sessionKey = trim((string)($input['session_key'] ?? ''));
    $phoneNumber = trim((string)($input['phone_number'] ?? ''));
    $provider = trim((string)($input['provider'] ?? 'baileys'));
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
        ':provider' => $provider !== '' ? $provider : 'baileys',
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
