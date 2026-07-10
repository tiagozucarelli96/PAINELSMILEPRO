<?php
/**
 * comercial_cadastro_cliente.php
 * Cadastro interno de clientes para contratos e eventos.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

if (empty($_SESSION['perm_comercial']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

function comercial_cadastro_cliente_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function comercial_cadastro_cliente_digits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?: '';
}

function comercial_cadastro_cliente_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_name = :table
              AND column_name = :column
              AND table_schema = ANY (current_schemas(FALSE))
            LIMIT 1
        ");
        $stmt->execute([
            ':table' => $table,
            ':column' => $column,
        ]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function comercial_cadastro_cliente_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (function_exists('painel_runtime_schema_setup_enabled') && !painel_runtime_schema_setup_enabled()) {
        $done = true;
        return;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS comercial_cadastro_clientes (
                id BIGSERIAL PRIMARY KEY,
                tipo_pessoa VARCHAR(2) NOT NULL DEFAULT 'PF',
                nome_completo VARCHAR(180) NOT NULL,
                email VARCHAR(180) NOT NULL,
                telefone_whatsapp VARCHAR(40) NOT NULL,
                documento_tipo VARCHAR(8) NOT NULL DEFAULT 'CPF',
                documento_numero VARCHAR(20) NOT NULL,
                rg VARCHAR(30) NULL,
                cep VARCHAR(12) NULL,
                endereco_logradouro VARCHAR(180) NULL,
                endereco_numero VARCHAR(30) NULL,
                endereco_complemento VARCHAR(120) NULL,
                endereco_bairro VARCHAR(120) NULL,
                endereco_cidade VARCHAR(120) NULL,
                endereco_estado VARCHAR(2) NULL,
                origem_cliente VARCHAR(60) NULL,
                responsavel_usuario_id INTEGER NULL,
                tipo_interesse VARCHAR(40) NULL,
                data_desejada DATE NULL,
                unidade_interesse VARCHAR(120) NULL,
                observacoes TEXT NULL,
                ativo BOOLEAN NOT NULL DEFAULT TRUE,
                created_by INTEGER NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $alterColumns = [
            "ADD COLUMN IF NOT EXISTS tipo_pessoa VARCHAR(2) NOT NULL DEFAULT 'PF'",
            "ADD COLUMN IF NOT EXISTS nome_completo VARCHAR(180)",
            "ADD COLUMN IF NOT EXISTS email VARCHAR(180)",
            "ADD COLUMN IF NOT EXISTS telefone_whatsapp VARCHAR(40)",
            "ADD COLUMN IF NOT EXISTS documento_tipo VARCHAR(8) NOT NULL DEFAULT 'CPF'",
            "ADD COLUMN IF NOT EXISTS documento_numero VARCHAR(20)",
            "ADD COLUMN IF NOT EXISTS rg VARCHAR(30) NULL",
            "ADD COLUMN IF NOT EXISTS cep VARCHAR(12) NULL",
            "ADD COLUMN IF NOT EXISTS endereco_logradouro VARCHAR(180) NULL",
            "ADD COLUMN IF NOT EXISTS endereco_numero VARCHAR(30) NULL",
            "ADD COLUMN IF NOT EXISTS endereco_complemento VARCHAR(120) NULL",
            "ADD COLUMN IF NOT EXISTS endereco_bairro VARCHAR(120) NULL",
            "ADD COLUMN IF NOT EXISTS endereco_cidade VARCHAR(120) NULL",
            "ADD COLUMN IF NOT EXISTS endereco_estado VARCHAR(2) NULL",
            "ADD COLUMN IF NOT EXISTS origem_cliente VARCHAR(60) NULL",
            "ADD COLUMN IF NOT EXISTS responsavel_usuario_id INTEGER NULL",
            "ADD COLUMN IF NOT EXISTS tipo_interesse VARCHAR(40) NULL",
            "ADD COLUMN IF NOT EXISTS data_desejada DATE NULL",
            "ADD COLUMN IF NOT EXISTS unidade_interesse VARCHAR(120) NULL",
            "ADD COLUMN IF NOT EXISTS observacoes TEXT NULL",
            "ADD COLUMN IF NOT EXISTS ativo BOOLEAN NOT NULL DEFAULT TRUE",
            "ADD COLUMN IF NOT EXISTS created_by INTEGER NULL",
            "ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW()",
            "ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW()",
        ];
        foreach ($alterColumns as $alterColumn) {
            $pdo->exec("ALTER TABLE IF EXISTS comercial_cadastro_clientes {$alterColumn}");
        }
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comercial_cadastro_clientes_nome ON comercial_cadastro_clientes(LOWER(nome_completo))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comercial_cadastro_clientes_documento ON comercial_cadastro_clientes(documento_numero)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comercial_cadastro_clientes_created ON comercial_cadastro_clientes(created_at DESC)");
    } catch (Throwable $e) {
        error_log('comercial_cadastro_cliente_ensure_schema: ' . $e->getMessage());
    }

    $done = true;
}

function comercial_cadastro_cliente_usuarios(PDO $pdo): array
{
    try {
        $nomeExpr = comercial_cadastro_cliente_has_column($pdo, 'usuarios', 'nome')
            ? "NULLIF(TRIM(nome), '')"
            : "NULL";
        $emailExpr = comercial_cadastro_cliente_has_column($pdo, 'usuarios', 'email')
            ? "NULLIF(TRIM(email), '')"
            : "NULL";

        $stmt = $pdo->query("
            SELECT id, COALESCE({$nomeExpr}, {$emailExpr}, 'Usuário #' || id::text) AS nome
            FROM usuarios
            ORDER BY nome ASC
            LIMIT 200
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function comercial_cadastro_cliente_recentes(PDO $pdo, string $search = ''): array
{
    try {
        $responsavelExpr = comercial_cadastro_cliente_has_column($pdo, 'usuarios', 'nome')
            ? "u.nome"
            : (comercial_cadastro_cliente_has_column($pdo, 'usuarios', 'email') ? "u.email" : "NULL");
        $where = ['c.ativo IS TRUE'];
        $params = [];
        if ($search !== '') {
            $where[] = "(c.nome_completo ILIKE :search OR c.email ILIKE :search OR c.telefone_whatsapp ILIKE :search OR c.documento_numero ILIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        $stmt = $pdo->prepare("
            SELECT c.*, {$responsavelExpr} AS responsavel_nome
            FROM comercial_cadastro_clientes c
            LEFT JOIN usuarios u ON u.id = c.responsavel_usuario_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY c.created_at DESC, c.id DESC
            LIMIT 80
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('comercial_cadastro_cliente_recentes: ' . $e->getMessage());
        return [];
    }
}

function comercial_cadastro_cliente_buscar(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM comercial_cadastro_clientes
            WHERE id = :id
              AND ativo IS TRUE
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        error_log('comercial_cadastro_cliente_buscar: ' . $e->getMessage());
        return null;
    }
}

function comercial_cadastro_cliente_buscar_por_search(PDO $pdo, string $search): ?array
{
    $search = trim($search);
    if ($search === '') {
        return null;
    }

    $digits = comercial_cadastro_cliente_digits($search);

    try {
        $conditions = [
            'LOWER(TRIM(nome_completo)) = LOWER(TRIM(:search))',
            'LOWER(TRIM(email)) = LOWER(TRIM(:search))',
        ];
        $params = [':search' => $search];

        if ($digits !== '') {
            $conditions[] = "REGEXP_REPLACE(COALESCE(telefone_whatsapp, ''), '\\D', '', 'g') = :digits";
            $conditions[] = "REGEXP_REPLACE(COALESCE(documento_numero, ''), '\\D', '', 'g') = :digits";
            $params[':digits'] = $digits;
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM comercial_cadastro_clientes
            WHERE ativo IS TRUE
              AND (" . implode(' OR ', $conditions) . ")
            ORDER BY updated_at DESC NULLS LAST, id DESC
            LIMIT 1
        ");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM comercial_cadastro_clientes
            WHERE ativo IS TRUE
              AND (
                  nome_completo ILIKE :partial
                  OR email ILIKE :partial
                  OR telefone_whatsapp ILIKE :partial
                  OR documento_numero ILIKE :partial
              )
            ORDER BY updated_at DESC NULLS LAST, id DESC
            LIMIT 2
        ");
        $stmt->execute([':partial' => '%' . $search . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return count($rows) === 1 ? $rows[0] : null;
    } catch (Throwable $e) {
        error_log('comercial_cadastro_cliente_buscar_por_search: ' . $e->getMessage());
        return null;
    }
}

function comercial_cadastro_cliente_old(string $key, string $default = ''): string
{
    return (string)($_POST[$key] ?? $default);
}

function comercial_cadastro_cliente_value(string $key, ?array $clienteAtual = null, string $default = ''): string
{
    if (array_key_exists($key, $_POST)) {
        return (string)$_POST[$key];
    }
    if (is_array($clienteAtual) && array_key_exists($key, $clienteAtual) && $clienteAtual[$key] !== null) {
        return (string)$clienteAtual[$key];
    }
    return $default;
}

function comercial_cadastro_cliente_request_value(string $key, string $default = ''): string
{
    if (isset($_POST[$key]) && is_scalar($_POST[$key])) {
        return (string)$_POST[$key];
    }
    if (isset($_GET[$key]) && is_scalar($_GET[$key])) {
        return (string)$_GET[$key];
    }
    if (
        isset($GLOBALS['PAINEL_CURRENT_ROUTE_QUERY'][$key])
        && is_scalar($GLOBALS['PAINEL_CURRENT_ROUTE_QUERY'][$key])
    ) {
        return (string)$GLOBALS['PAINEL_CURRENT_ROUTE_QUERY'][$key];
    }

    $queryString = (string)($GLOBALS['PAINEL_CURRENT_ROUTE_QUERY_STRING'] ?? ($_SERVER['QUERY_STRING'] ?? ''));
    if ($queryString !== '') {
        parse_str(str_replace('&amp;', '&', $queryString), $queryParams);
        if (isset($queryParams[$key]) && is_scalar($queryParams[$key])) {
            return (string)$queryParams[$key];
        }
    }

    return $default;
}

function comercial_cadastro_cliente_request_id(): int
{
    foreach (['id', 'cliente_id', 'edit_id'] as $key) {
        $value = comercial_cadastro_cliente_request_value($key);
        if ($value !== '') {
            return max(0, (int)$value);
        }
    }
    return 0;
}

comercial_cadastro_cliente_ensure_schema($pdo);

$userId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
$search = trim(comercial_cadastro_cliente_request_value('search'));
$clienteId = comercial_cadastro_cliente_request_id();
$clienteAtual = comercial_cadastro_cliente_buscar($pdo, $clienteId);
if (!$clienteAtual && $clienteId <= 0 && $search !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $clienteAtual = comercial_cadastro_cliente_buscar_por_search($pdo, $search);
    if ($clienteAtual) {
        $clienteId = (int)$clienteAtual['id'];
    }
}
$isEditing = $clienteAtual !== null;
$errors = [];
$success = '';

if ($clienteId > 0 && !$isEditing && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $errors[] = 'Cliente não encontrado ou inativo.';
}

$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($requestMethod === 'POST') {
    $tipoPessoa = strtoupper(trim((string)($_POST['tipo_pessoa'] ?? 'PF')));
    $tipoPessoa = in_array($tipoPessoa, ['PF', 'PJ'], true) ? $tipoPessoa : 'PF';
    $documentoTipo = $tipoPessoa === 'PJ' ? 'CNPJ' : 'CPF';
    $documentoNumero = comercial_cadastro_cliente_digits((string)($_POST['documento_numero'] ?? ''));
    $cep = comercial_cadastro_cliente_digits((string)($_POST['cep'] ?? ''));

    $payload = [
        'tipo_pessoa' => $tipoPessoa,
        'nome_completo' => trim((string)($_POST['nome_completo'] ?? '')),
        'email' => trim((string)($_POST['email'] ?? '')),
        'telefone_whatsapp' => trim((string)($_POST['telefone_whatsapp'] ?? '')),
        'documento_tipo' => $documentoTipo,
        'documento_numero' => $documentoNumero,
        'rg' => trim((string)($_POST['rg'] ?? '')),
        'cep' => $cep,
        'endereco_logradouro' => trim((string)($_POST['endereco_logradouro'] ?? '')),
        'endereco_numero' => trim((string)($_POST['endereco_numero'] ?? '')),
        'endereco_complemento' => trim((string)($_POST['endereco_complemento'] ?? '')),
        'endereco_bairro' => trim((string)($_POST['endereco_bairro'] ?? '')),
        'endereco_cidade' => trim((string)($_POST['endereco_cidade'] ?? '')),
        'endereco_estado' => strtoupper(trim((string)($_POST['endereco_estado'] ?? ''))),
        'origem_cliente' => trim((string)($_POST['origem_cliente'] ?? ($isEditing ? ($clienteAtual['origem_cliente'] ?? '') : ''))),
        'responsavel_usuario_id' => (int)($_POST['responsavel_usuario_id'] ?? ($isEditing ? ($clienteAtual['responsavel_usuario_id'] ?? 0) : 0)),
        'tipo_interesse' => trim((string)($_POST['tipo_interesse'] ?? ($isEditing ? ($clienteAtual['tipo_interesse'] ?? '') : ''))),
        'data_desejada' => trim((string)($_POST['data_desejada'] ?? ($isEditing ? ($clienteAtual['data_desejada'] ?? '') : ''))),
        'unidade_interesse' => trim((string)($_POST['unidade_interesse'] ?? ($isEditing ? ($clienteAtual['unidade_interesse'] ?? '') : ''))),
        'observacoes' => trim((string)($_POST['observacoes'] ?? ($isEditing ? ($clienteAtual['observacoes'] ?? '') : ''))),
    ];

    if ($payload['nome_completo'] === '') {
        $errors[] = 'Informe o nome completo do cliente.';
    }
    if ($payload['email'] !== '' && !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Informe um e-mail válido.';
    }
    if ($documentoTipo === 'CPF' && $documentoNumero !== '' && strlen($documentoNumero) !== 11) {
        $errors[] = 'Informe um CPF com 11 dígitos.';
    }
    if ($documentoTipo === 'CNPJ' && $documentoNumero !== '' && strlen($documentoNumero) !== 14) {
        $errors[] = 'Informe um CNPJ com 14 dígitos.';
    }
    if ($cep !== '' && strlen($cep) !== 8) {
        $errors[] = 'Informe um CEP com 8 dígitos.';
    }
    if ($payload['endereco_estado'] !== '' && strlen($payload['endereco_estado']) !== 2) {
        $errors[] = 'Informe o estado com 2 letras.';
    }
    if ($payload['data_desejada'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['data_desejada'])) {
        $errors[] = 'Informe uma data desejada válida.';
    }

    if (empty($errors)) {
        try {
            $params = [
                ':tipo_pessoa' => $payload['tipo_pessoa'],
                ':nome_completo' => $payload['nome_completo'],
                ':email' => $payload['email'],
                ':telefone_whatsapp' => $payload['telefone_whatsapp'],
                ':documento_tipo' => $payload['documento_tipo'],
                ':documento_numero' => $payload['documento_numero'],
                ':rg' => $payload['rg'] !== '' ? $payload['rg'] : null,
                ':cep' => $payload['cep'] !== '' ? $payload['cep'] : null,
                ':endereco_logradouro' => $payload['endereco_logradouro'] !== '' ? $payload['endereco_logradouro'] : null,
                ':endereco_numero' => $payload['endereco_numero'] !== '' ? $payload['endereco_numero'] : null,
                ':endereco_complemento' => $payload['endereco_complemento'] !== '' ? $payload['endereco_complemento'] : null,
                ':endereco_bairro' => $payload['endereco_bairro'] !== '' ? $payload['endereco_bairro'] : null,
                ':endereco_cidade' => $payload['endereco_cidade'] !== '' ? $payload['endereco_cidade'] : null,
                ':endereco_estado' => $payload['endereco_estado'] !== '' ? $payload['endereco_estado'] : null,
                ':origem_cliente' => $payload['origem_cliente'] !== '' ? $payload['origem_cliente'] : null,
                ':responsavel_usuario_id' => $payload['responsavel_usuario_id'] > 0 ? $payload['responsavel_usuario_id'] : null,
                ':tipo_interesse' => $payload['tipo_interesse'] !== '' ? $payload['tipo_interesse'] : null,
                ':data_desejada' => $payload['data_desejada'] !== '' ? $payload['data_desejada'] : null,
                ':unidade_interesse' => $payload['unidade_interesse'] !== '' ? $payload['unidade_interesse'] : null,
                ':observacoes' => $payload['observacoes'] !== '' ? $payload['observacoes'] : null,
            ];

            if ($isEditing) {
                $stmt = $pdo->prepare("
                    UPDATE comercial_cadastro_clientes
                    SET tipo_pessoa = :tipo_pessoa,
                        nome_completo = :nome_completo,
                        email = :email,
                        telefone_whatsapp = :telefone_whatsapp,
                        documento_tipo = :documento_tipo,
                        documento_numero = :documento_numero,
                        rg = :rg,
                        cep = :cep,
                        endereco_logradouro = :endereco_logradouro,
                        endereco_numero = :endereco_numero,
                        endereco_complemento = :endereco_complemento,
                        endereco_bairro = :endereco_bairro,
                        endereco_cidade = :endereco_cidade,
                        endereco_estado = :endereco_estado,
                        origem_cliente = :origem_cliente,
                        responsavel_usuario_id = :responsavel_usuario_id,
                        tipo_interesse = :tipo_interesse,
                        data_desejada = :data_desejada,
                        unidade_interesse = :unidade_interesse,
                        observacoes = :observacoes,
                        updated_at = NOW()
                    WHERE id = :id
                      AND ativo IS TRUE
                ");
                $params[':id'] = (int)$clienteAtual['id'];
                $stmt->execute($params);

                $_SESSION['comercial_cadastro_cliente_success'] = 'Cliente atualizado com sucesso.';
                header('Location: index.php?page=comercial_cadastro_cliente&id=' . (int)$clienteAtual['id']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO comercial_cadastro_clientes (
                    tipo_pessoa, nome_completo, email, telefone_whatsapp, documento_tipo, documento_numero, rg,
                    cep, endereco_logradouro, endereco_numero, endereco_complemento, endereco_bairro,
                    endereco_cidade, endereco_estado, origem_cliente, responsavel_usuario_id, tipo_interesse,
                    data_desejada, unidade_interesse, observacoes, created_by, created_at, updated_at
                ) VALUES (
                    :tipo_pessoa, :nome_completo, :email, :telefone_whatsapp, :documento_tipo, :documento_numero, :rg,
                    :cep, :endereco_logradouro, :endereco_numero, :endereco_complemento, :endereco_bairro,
                    :endereco_cidade, :endereco_estado, :origem_cliente, :responsavel_usuario_id, :tipo_interesse,
                    :data_desejada, :unidade_interesse, :observacoes, :created_by, NOW(), NOW()
                )
            ");
            $params[':created_by'] = $userId > 0 ? $userId : null;
            $stmt->execute($params);

            $_SESSION['comercial_cadastro_cliente_success'] = 'Cliente cadastrado com sucesso.';
            header('Location: index.php?page=comercial_cadastro_cliente');
            exit;
        } catch (Throwable $e) {
            error_log('comercial_cadastro_cliente POST: ' . $e->getMessage());
            $errors[] = 'Não foi possível salvar o cliente.';
        }
    }
}

if (!empty($_SESSION['comercial_cadastro_cliente_success'])) {
    $success = (string)$_SESSION['comercial_cadastro_cliente_success'];
    unset($_SESSION['comercial_cadastro_cliente_success']);
}

$clientes = comercial_cadastro_cliente_recentes($pdo, $search);

includeSidebar('Cadastro do cliente');
?>

<style>
.cliente-page {
    padding: 1.5rem;
    max-width: 1360px;
    margin: 0 auto;
    background: #f8fafc;
}
.cliente-header {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: flex-start;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}
.cliente-title {
    margin: 0;
    color: #1e3a8a;
    font-size: 1.8rem;
    font-weight: 800;
}
.cliente-subtitle {
    margin: 0.35rem 0 0;
    color: #64748b;
}
.cliente-back {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    border: 1px solid #dbe3ef;
    background: #fff;
    color: #1e293b;
    text-decoration: none;
    font-weight: 700;
    padding: 0.72rem 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.cliente-alert {
    margin-bottom: 1rem;
    padding: 0.85rem 1rem;
    border-radius: 10px;
    border: 1px solid transparent;
    font-weight: 700;
}
.cliente-alert.success {
    background: #ecfdf5;
    color: #166534;
    border-color: #a7f3d0;
}
.cliente-alert.error {
    background: #fef2f2;
    color: #991b1b;
    border-color: #fecaca;
}
.cliente-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.15fr) minmax(340px, 0.85fr);
    gap: 1rem;
    align-items: start;
}
.cliente-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 14px 34px rgba(15, 23, 42, 0.07);
    overflow: hidden;
}
.cliente-card-header {
    padding: 1rem 1.1rem;
    border-bottom: 1px solid #e2e8f0;
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
}
.cliente-card-title {
    margin: 0;
    color: #1e293b;
    font-size: 1.05rem;
    font-weight: 800;
}
.cliente-card-subtitle {
    margin: 0.25rem 0 0;
    color: #64748b;
    font-size: 0.86rem;
}
.cliente-form {
    padding: 1.1rem;
    display: grid;
    gap: 1rem;
}
.cliente-section {
    display: grid;
    gap: 0.85rem;
}
.cliente-section-title {
    color: #1e3a8a;
    font-weight: 800;
    font-size: 0.9rem;
    padding-bottom: 0.4rem;
    border-bottom: 1px solid #e2e8f0;
}
.cliente-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.8rem;
}
.cliente-grid.three {
    grid-template-columns: 1fr 0.6fr 1fr;
}
.cliente-field {
    display: grid;
    gap: 0.35rem;
}
.cliente-field.full {
    grid-column: 1 / -1;
}
.cliente-field label {
    color: #334155;
    font-size: 0.82rem;
    font-weight: 800;
}
.cliente-field input,
.cliente-field select,
.cliente-field textarea {
    width: 100%;
    border: 1px solid #d1d9e6;
    border-radius: 10px;
    padding: 0.68rem 0.78rem;
    color: #1e293b;
    background: #fff;
    font-size: 0.92rem;
}
.cliente-field textarea {
    min-height: 92px;
    resize: vertical;
}
.cliente-field input:focus,
.cliente-field select:focus,
.cliente-field textarea:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
}
.cliente-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.7rem;
    border-top: 1px solid #e2e8f0;
    padding-top: 1rem;
}
.cliente-btn {
    border: none;
    border-radius: 10px;
    padding: 0.74rem 1rem;
    font-weight: 800;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
}
.cliente-btn.primary {
    background: #1e3a8a;
    color: #fff;
}
.cliente-btn.secondary {
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #dbe3ef;
}
.cep-status {
    color: #64748b;
    font-size: 0.78rem;
    min-height: 1rem;
}
.clientes-list {
    padding: 1rem;
    display: grid;
    gap: 0.75rem;
}
.cliente-search {
    display: flex;
    gap: 0.55rem;
    padding: 1rem 1rem 0;
}
.cliente-search input {
    flex: 1;
    border: 1px solid #d1d9e6;
    border-radius: 10px;
    padding: 0.68rem 0.78rem;
}
.cliente-list-item {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.85rem;
    background: #fbfdff;
    color: inherit;
    display: block;
    text-decoration: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.cliente-list-item:hover {
    border-color: #bfdbfe;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.cliente-list-name {
    color: #1e293b;
    font-weight: 800;
}
.cliente-list-meta {
    margin-top: 0.28rem;
    color: #64748b;
    font-size: 0.84rem;
    line-height: 1.45;
}
.cliente-empty {
    padding: 1rem;
    color: #64748b;
}
@media (max-width: 960px) {
    .cliente-layout,
    .cliente-grid,
    .cliente-grid.three {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="cliente-page">
    <div class="cliente-header">
        <div>
            <h1 class="cliente-title"><?= $isEditing ? 'Editar cliente' : 'Cadastro do cliente' ?></h1>
            <p class="cliente-subtitle">Dados principais para contrato, atendimento comercial e organização do evento.</p>
        </div>
        <div style="display:flex; gap:.6rem; flex-wrap:wrap;">
            <a class="cliente-back" href="index.php?page=comercial_clientes_cadastrados">Clientes cadastrados</a>
            <a class="cliente-back" href="index.php?page=comercial">← Comercial</a>
        </div>
    </div>

    <?php if ($success !== ''): ?>
        <div class="cliente-alert success"><?= comercial_cadastro_cliente_e($success) ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <div class="cliente-alert error"><?= comercial_cadastro_cliente_e($error) ?></div>
    <?php endforeach; ?>

    <div class="cliente-layout">
        <section class="cliente-card">
            <div class="cliente-card-header">
                <h2 class="cliente-card-title"><?= $isEditing ? 'Editar cadastro' : 'Novo cliente' ?></h2>
                <p class="cliente-card-subtitle"><?= $isEditing ? 'Altere os dados necessários e salve o cadastro.' : 'Preencha somente os dados necessários para contrato e contato.' ?></p>
            </div>

            <form method="post" class="cliente-form" id="clienteForm" autocomplete="off">
                <?php if ($isEditing): ?>
                    <input type="hidden" name="id" value="<?= (int)$clienteAtual['id'] ?>">
                <?php endif; ?>
                <div class="cliente-section">
                    <div class="cliente-section-title">Dados do cliente</div>
                    <div class="cliente-grid">
                        <div class="cliente-field">
                            <label for="tipo_pessoa">Tipo</label>
                            <select name="tipo_pessoa" id="tipo_pessoa" required>
                                <option value="PF" <?= comercial_cadastro_cliente_value('tipo_pessoa', $clienteAtual, 'PF') === 'PF' ? 'selected' : '' ?>>Pessoa física</option>
                                <option value="PJ" <?= comercial_cadastro_cliente_value('tipo_pessoa', $clienteAtual) === 'PJ' ? 'selected' : '' ?>>Pessoa jurídica</option>
                            </select>
                        </div>
                        <div class="cliente-field">
                            <label for="documento_numero" id="documentoLabel">CPF</label>
                            <input type="text" name="documento_numero" id="documento_numero" value="<?= comercial_cadastro_cliente_e(comercial_cadastro_cliente_value('documento_numero', $clienteAtual)) ?>">
                        </div>
                        <div class="cliente-field full">
                            <label for="nome_completo">Nome completo</label>
                            <input type="text" name="nome_completo" id="nome_completo" value="<?= comercial_cadastro_cliente_e(comercial_cadastro_cliente_value('nome_completo', $clienteAtual)) ?>" maxlength="180" required>
                        </div>
                        <div class="cliente-field">
                            <label for="rg">RG</label>
                            <input type="text" name="rg" id="rg" value="<?= comercial_cadastro_cliente_e(comercial_cadastro_cliente_value('rg', $clienteAtual)) ?>" maxlength="30">
                        </div>
                        <div class="cliente-field">
                            <label for="telefone_whatsapp">Telefone / WhatsApp</label>
                            <input type="text" name="telefone_whatsapp" id="telefone_whatsapp" value="<?= comercial_cadastro_cliente_e(comercial_cadastro_cliente_value('telefone_whatsapp', $clienteAtual)) ?>">
                        </div>
                        <div class="cliente-field full">
                            <label for="email">E-mail</label>
                            <input type="email" name="email" id="email" value="<?= comercial_cadastro_cliente_e(comercial_cadastro_cliente_value('email', $clienteAtual)) ?>" maxlength="180">
                        </div>
                    </div>
                </div>

                <div class="cliente-section">
                    <div class="cliente-section-title">Endereço</div>
                    <div class="cliente-grid three">
                        <div class="cliente-field">
                            <label for="cep">CEP</label>
                            <input type="text" name="cep" id="cep" value="<?= comercial_cadastro_cliente_e(comercial_cadastro_cliente_value('cep', $clienteAtual)) ?>">
                            <div class="cep-status" id="cepStatus"></div>
                        </div>
                        <div class="cliente-field">
                            <label for="endereco_numero">Número</label>
                            <input type="text" name="endereco_numero" id="endereco_numero" value="<?= comercial_cadastro_cliente_e(comercial_cadastro_cliente_value('endereco_numero', $clienteAtual)) ?>" maxlength="30">
                        </div>
                        <div class="cliente-field">
                            <label for="endereco_complemento">Complemento</label>
                            <input type="text" name="endereco_complemento" id="endereco_complemento" value="<?= comercial_cadastro_cliente_e(comercial_cadastro_cliente_value('endereco_complemento', $clienteAtual)) ?>" maxlength="120">
                        </div>
                    </div>
                    <div class="cliente-grid">
                        <div class="cliente-field full">
                            <label for="endereco_logradouro">Rua</label>
                            <input type="text" name="endereco_logradouro" id="endereco_logradouro" value="<?= comercial_cadastro_cliente_e(comercial_cadastro_cliente_value('endereco_logradouro', $clienteAtual)) ?>" maxlength="180">
                        </div>
                        <div class="cliente-field">
                            <label for="endereco_bairro">Bairro</label>
                            <input type="text" name="endereco_bairro" id="endereco_bairro" value="<?= comercial_cadastro_cliente_e(comercial_cadastro_cliente_value('endereco_bairro', $clienteAtual)) ?>" maxlength="120">
                        </div>
                        <div class="cliente-field">
                            <label for="endereco_cidade">Cidade</label>
                            <input type="text" name="endereco_cidade" id="endereco_cidade" value="<?= comercial_cadastro_cliente_e(comercial_cadastro_cliente_value('endereco_cidade', $clienteAtual)) ?>" maxlength="120">
                        </div>
                        <div class="cliente-field">
                            <label for="endereco_estado">Estado</label>
                            <input type="text" name="endereco_estado" id="endereco_estado" value="<?= comercial_cadastro_cliente_e(comercial_cadastro_cliente_value('endereco_estado', $clienteAtual)) ?>" maxlength="2">
                        </div>
                    </div>
                </div>

                <div class="cliente-actions">
                    <a class="cliente-btn secondary" href="<?= $isEditing ? 'index.php?page=comercial_clientes_cadastrados' : 'index.php?page=comercial' ?>">Cancelar</a>
                    <button type="submit" class="cliente-btn primary"><?= $isEditing ? 'Salvar alterações' : 'Salvar cliente' ?></button>
                </div>
            </form>
        </section>

        <aside class="cliente-card">
            <div class="cliente-card-header">
                <h2 class="cliente-card-title">Clientes recentes</h2>
                <p class="cliente-card-subtitle">Últimos cadastros feitos no Comercial.</p>
            </div>
            <form method="get" class="cliente-search">
                <input type="hidden" name="page" value="comercial_cadastro_cliente">
                <input type="text" name="search" value="<?= comercial_cadastro_cliente_e($search) ?>" placeholder="Buscar por nome, e-mail, telefone ou documento">
                <button class="cliente-btn secondary" type="submit">Buscar</button>
            </form>
            <div class="clientes-list">
                <?php if (empty($clientes)): ?>
                    <div class="cliente-empty">Nenhum cliente cadastrado ainda.</div>
                <?php else: ?>
                    <?php foreach ($clientes as $cliente): ?>
                        <?php
                        $clienteHref = 'index.php?page=comercial_cadastro_cliente&id=' . (int)$cliente['id'];
                        ?>
                        <a class="cliente-list-item" href="<?= comercial_cadastro_cliente_e($clienteHref) ?>">
                            <div class="cliente-list-name"><?= comercial_cadastro_cliente_e((string)$cliente['nome_completo']) ?></div>
                            <div class="cliente-list-meta">
                                <?= comercial_cadastro_cliente_e((string)$cliente['documento_tipo']) ?> <?= comercial_cadastro_cliente_e((string)$cliente['documento_numero']) ?><br>
                                <?= comercial_cadastro_cliente_e((string)$cliente['telefone_whatsapp']) ?> · <?= comercial_cadastro_cliente_e((string)$cliente['email']) ?><br>
                                <?php if (!empty($cliente['tipo_interesse'])): ?>
                                    Interesse: <?= comercial_cadastro_cliente_e((string)$cliente['tipo_interesse']) ?><br>
                                <?php endif; ?>
                                <?php if (!empty($cliente['responsavel_nome'])): ?>
                                    Responsável: <?= comercial_cadastro_cliente_e((string)$cliente['responsavel_nome']) ?>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</div>

<script>
function onlyDigits(value) {
    return String(value || '').replace(/\D/g, '');
}

function formatCpf(value) {
    let digits = onlyDigits(value).slice(0, 11);
    digits = digits.replace(/(\d{3})(\d)/, '$1.$2');
    digits = digits.replace(/(\d{3})(\d)/, '$1.$2');
    digits = digits.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
    return digits;
}

function formatCnpj(value) {
    let digits = onlyDigits(value).slice(0, 14);
    digits = digits.replace(/(\d{2})(\d)/, '$1.$2');
    digits = digits.replace(/(\d{3})(\d)/, '$1.$2');
    digits = digits.replace(/(\d{3})(\d)/, '$1/$2');
    digits = digits.replace(/(\d{4})(\d{1,2})$/, '$1-$2');
    return digits;
}

function formatPhone(value) {
    let digits = onlyDigits(value).slice(0, 11);
    if (digits.length <= 10) {
        digits = digits.replace(/(\d{2})(\d)/, '($1) $2');
        digits = digits.replace(/(\d{4})(\d)/, '$1-$2');
    } else {
        digits = digits.replace(/(\d{2})(\d)/, '($1) $2');
        digits = digits.replace(/(\d{5})(\d)/, '$1-$2');
    }
    return digits;
}

function formatCep(value) {
    return onlyDigits(value).slice(0, 8).replace(/(\d{5})(\d)/, '$1-$2');
}

function syncDocumentoMask() {
    const tipo = document.getElementById('tipo_pessoa');
    const doc = document.getElementById('documento_numero');
    const label = document.getElementById('documentoLabel');
    if (!tipo || !doc || !label) return;

    if (tipo.value === 'PJ') {
        label.textContent = 'CNPJ';
        doc.value = formatCnpj(doc.value);
    } else {
        label.textContent = 'CPF';
        doc.value = formatCpf(doc.value);
    }
}

async function buscarCep(cepDigits) {
    const status = document.getElementById('cepStatus');
    if (!status) return;
    if (cepDigits.length !== 8) {
        status.textContent = '';
        return;
    }

    status.textContent = 'Buscando CEP...';
    try {
        const response = await fetch(`buscar_cep_endpoint.php?cep=${encodeURIComponent(cepDigits)}`);
        const data = await response.json();
        if (!data.success) {
            status.textContent = data.message || 'CEP não encontrado.';
            return;
        }
        const endereco = data.data || {};
        document.getElementById('endereco_logradouro').value = endereco.logradouro || '';
        document.getElementById('endereco_bairro').value = endereco.bairro || '';
        document.getElementById('endereco_cidade').value = endereco.cidade || '';
        document.getElementById('endereco_estado').value = endereco.estado || '';
        status.textContent = 'Endereço preenchido pelo CEP.';
        const numero = document.getElementById('endereco_numero');
        if (numero) numero.focus();
    } catch (err) {
        status.textContent = 'Não foi possível buscar o CEP agora.';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const tipo = document.getElementById('tipo_pessoa');
    const doc = document.getElementById('documento_numero');
    const phone = document.getElementById('telefone_whatsapp');
    const cep = document.getElementById('cep');
    let cepTimer = null;

    if (tipo) {
        tipo.addEventListener('change', syncDocumentoMask);
    }
    if (doc) {
        doc.addEventListener('input', syncDocumentoMask);
        syncDocumentoMask();
    }
    if (phone) {
        phone.addEventListener('input', () => {
            phone.value = formatPhone(phone.value);
        });
        phone.value = formatPhone(phone.value);
    }
    if (cep) {
        cep.addEventListener('input', () => {
            cep.value = formatCep(cep.value);
            clearTimeout(cepTimer);
            const digits = onlyDigits(cep.value);
            cepTimer = setTimeout(() => buscarCep(digits), 350);
        });
        cep.value = formatCep(cep.value);
    }
});
</script>
