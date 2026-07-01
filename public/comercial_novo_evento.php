<?php
/**
 * comercial_novo_evento.php
 * Cadastro inicial de eventos criados direto pelo painel.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

if (empty($_SESSION['perm_comercial']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

function comercial_novo_evento_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function comercial_novo_evento_digits($value): string
{
    return preg_replace('/\D+/', '', (string)$value) ?: '';
}

function comercial_novo_evento_ensure_schema(PDO $pdo): void
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
        $pdo->exec("ALTER TABLE IF EXISTS logistica_eventos_espelho ALTER COLUMN me_event_id DROP NOT NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_eventos_espelho ADD COLUMN IF NOT EXISTS hora_fim TIME NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_eventos_espelho ADD COLUMN IF NOT EXISTS cliente_cadastro_id BIGINT NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_eventos_espelho ADD COLUMN IF NOT EXISTS nome_evento TEXT NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_eventos_espelho ADD COLUMN IF NOT EXISTS whatsapp_cliente VARCHAR(40) NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_eventos_espelho ADD COLUMN IF NOT EXISTS telefone_cliente VARCHAR(40) NULL");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS comercial_eventos_painel (
                id BIGSERIAL PRIMARY KEY,
                espelho_evento_id INTEGER NULL REFERENCES logistica_eventos_espelho(id) ON DELETE SET NULL,
                cliente_cadastro_id BIGINT NULL REFERENCES comercial_cadastro_clientes(id) ON DELETE SET NULL,
                local_evento TEXT NOT NULL,
                nome_evento TEXT NOT NULL,
                tipo_evento_real VARCHAR(24) NULL,
                data_venda DATE NOT NULL DEFAULT CURRENT_DATE,
                data_evento DATE NOT NULL,
                hora_inicio TIME NOT NULL,
                hora_fim TIME NOT NULL,
                como_conheceu VARCHAR(120) NULL,
                convidados INTEGER NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'criado_painel',
                created_by INTEGER NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $pdo->exec("ALTER TABLE IF EXISTS comercial_eventos_painel ADD COLUMN IF NOT EXISTS tipo_evento_real VARCHAR(24) NULL");
        $pdo->exec("ALTER TABLE IF EXISTS comercial_eventos_painel ADD COLUMN IF NOT EXISTS data_venda DATE NOT NULL DEFAULT CURRENT_DATE");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comercial_eventos_painel_espelho ON comercial_eventos_painel (espelho_evento_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comercial_eventos_painel_cliente ON comercial_eventos_painel (cliente_cadastro_id)");
    } catch (Throwable $e) {
        error_log('Falha ao preparar schema de comercial_novo_evento: ' . $e->getMessage());
    }

    $done = true;
}

function comercial_novo_evento_locais(PDO $pdo): array
{
    $locais = [];

    try {
        $stmt = $pdo->query("
            SELECT
                me_local_id AS idlocalevento,
                TRIM(me_local_nome) AS localevento,
                TRIM(COALESCE(space_visivel, '')) AS space_visivel,
                unidade_interna_id
            FROM logistica_me_locais
            WHERE TRIM(COALESCE(me_local_nome, '')) <> ''
              AND COALESCE(status_mapeamento, 'MAPEADO') = 'MAPEADO'
            ORDER BY
                CASE TRIM(COALESCE(space_visivel, ''))
                    WHEN 'Lisbon 1' THEN 1
                    WHEN 'Lisbon Garden' THEN 2
                    WHEN 'DiverKids' THEN 3
                    WHEN 'Cristal' THEN 4
                    ELSE 99
                END,
                TRIM(COALESCE(space_visivel, me_local_nome)) ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $label = trim($row['space_visivel']) !== '' ? trim($row['space_visivel']) : trim($row['localevento']);
            $key = sha1(($row['idlocalevento'] ?? '') . '|' . ($row['localevento'] ?? '') . '|' . ($row['space_visivel'] ?? ''));
            $locais[$key] = [
                'key' => $key,
                'label' => $label,
                'idlocalevento' => $row['idlocalevento'] !== null ? (int)$row['idlocalevento'] : null,
                'localevento' => trim($row['localevento']),
                'space_visivel' => trim($row['space_visivel']),
                'unidade_interna_id' => $row['unidade_interna_id'] !== null ? (int)$row['unidade_interna_id'] : null,
            ];
        }
    } catch (Throwable $e) {
        error_log('Falha ao carregar locais para novo evento: ' . $e->getMessage());
    }

    if (!$locais) {
        $fallback = [
            ['lisbon1', 1, 'LISBON BUFFET - UNIDADE 1 - PARQUE DOS SINOS', 'Lisbon 1', 2],
            ['diverkids', 3, 'DIVERKIDS', 'DiverKids', 3],
            ['cristal', 7, 'LISBON GARDEN - ESPAÇO CRISTAL', 'Cristal', 1],
            ['garden', 2, 'LISBON GARDEN - ESPAÇO GARDEN', 'Lisbon Garden', 1],
        ];
        foreach ($fallback as $item) {
            [$key, $id, $local, $space, $unidade] = $item;
            $locais[$key] = [
                'key' => $key,
                'label' => $local . ' - ' . $space,
                'idlocalevento' => $id,
                'localevento' => $local,
                'space_visivel' => $space,
                'unidade_interna_id' => $unidade,
            ];
        }
    }

    return array_values($locais);
}

function comercial_novo_evento_clientes(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("
            SELECT id, nome_completo, email, telefone_whatsapp, documento_numero
            FROM comercial_cadastro_clientes
            WHERE COALESCE(ativo, TRUE) = TRUE
            ORDER BY LOWER(nome_completo) ASC
            LIMIT 1500
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Falha ao carregar clientes para novo evento: ' . $e->getMessage());
        return [];
    }
}

function comercial_novo_evento_tipos_evento(PDO $pdo): array
{
    try {
        return eventos_reuniao_tipos_evento_real_options($pdo, false);
    } catch (Throwable $e) {
        error_log('Falha ao carregar tipos de evento no novo evento: ' . $e->getMessage());
        $fallback = [];
        foreach (eventos_reuniao_tipos_evento_real_defaults() as $key => $meta) {
            if (!empty($meta['ativo'])) {
                $fallback[$key] = (string)($meta['label'] ?? $key);
            }
        }
        return $fallback;
    }
}

function comercial_novo_evento_cliente(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, nome_completo, email, telefone_whatsapp, documento_numero
        FROM comercial_cadastro_clientes
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    return $cliente ?: null;
}

function comercial_novo_evento_find_local(array $locais, string $key): ?array
{
    foreach ($locais as $local) {
        if ($local['key'] === $key) {
            return $local;
        }
    }
    return null;
}

function comercial_novo_evento_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function comercial_novo_evento_client_payload(array $source): array
{
    return [
        'nome' => trim((string)($source['nome_completo'] ?? '')),
        'telefone' => trim((string)($source['telefone_whatsapp'] ?? '')),
        'email' => trim((string)($source['email'] ?? '')),
        'documento' => comercial_novo_evento_digits($source['documento_numero'] ?? ''),
        'rg' => trim((string)($source['rg'] ?? '')),
        'cep' => trim((string)($source['cep'] ?? '')),
        'endereco_numero' => trim((string)($source['endereco_numero'] ?? '')),
        'endereco_complemento' => trim((string)($source['endereco_complemento'] ?? '')),
        'endereco_logradouro' => trim((string)($source['endereco_logradouro'] ?? '')),
        'endereco_bairro' => trim((string)($source['endereco_bairro'] ?? '')),
        'endereco_cidade' => trim((string)($source['endereco_cidade'] ?? '')),
        'endereco_estado' => mb_strtoupper(trim((string)($source['endereco_estado'] ?? '')), 'UTF-8'),
    ];
}

function comercial_novo_evento_insert_client(PDO $pdo, array $data): array
{
    $stmt = $pdo->prepare("
        INSERT INTO comercial_cadastro_clientes (
            tipo_pessoa, nome_completo, email, telefone_whatsapp, documento_tipo,
            documento_numero, rg, cep, endereco_logradouro, endereco_numero,
            endereco_complemento, endereco_bairro, endereco_cidade, endereco_estado,
            origem_cliente, origem_importacao, created_by, created_at, updated_at
        ) VALUES (
            'PF', :nome, :email, :telefone, 'CPF',
            :documento, :rg, :cep, :endereco_logradouro, :endereco_numero,
            :endereco_complemento, :endereco_bairro, :endereco_cidade, :endereco_estado,
            'Novo evento', 'painel', :created_by, NOW(), NOW()
        )
        RETURNING id, nome_completo, email, telefone_whatsapp, documento_numero
    ");
    $stmt->execute([
        ':nome' => $data['nome'],
        ':email' => $data['email'],
        ':telefone' => $data['telefone'],
        ':documento' => $data['documento'],
        ':rg' => $data['rg'],
        ':cep' => $data['cep'],
        ':endereco_logradouro' => $data['endereco_logradouro'],
        ':endereco_numero' => $data['endereco_numero'],
        ':endereco_complemento' => $data['endereco_complemento'],
        ':endereco_bairro' => $data['endereco_bairro'],
        ':endereco_cidade' => $data['endereco_cidade'],
        ':endereco_estado' => $data['endereco_estado'],
        ':created_by' => $_SESSION['usuario_id'] ?? $_SESSION['id'] ?? null,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

comercial_novo_evento_ensure_schema($pdo);

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'POST' && ($_POST['action'] ?? '') === 'ajax_create_client') {
    $clientData = comercial_novo_evento_client_payload($_POST);

    if ($clientData['nome'] === '') {
        comercial_novo_evento_json(['ok' => false, 'message' => 'Informe o nome do cliente.'], 422);
    }

    try {
        comercial_novo_evento_json(['ok' => true, 'client' => comercial_novo_evento_insert_client($pdo, $clientData)]);
    } catch (Throwable $e) {
        error_log('Falha ao cadastrar cliente no novo evento: ' . $e->getMessage());
        comercial_novo_evento_json(['ok' => false, 'message' => 'Não foi possível cadastrar o cliente.'], 500);
    }
}

if ($requestMethod === 'POST' && ($_POST['action'] ?? '') === 'create_client_inline') {
    $clientData = comercial_novo_evento_client_payload($_POST);

    if ($clientData['nome'] !== '') {
        try {
            $clienteNovo = comercial_novo_evento_insert_client($pdo, $clientData);
            $clienteNovoId = (int)($clienteNovo['id'] ?? 0);
            header('Location: index.php?page=comercial_novo_evento&cliente_id=' . $clienteNovoId);
            exit;
        } catch (Throwable $e) {
            error_log('Falha ao cadastrar cliente inline no novo evento: ' . $e->getMessage());
        }
    }
}

$locais = comercial_novo_evento_locais($pdo);
$clientes = comercial_novo_evento_clientes($pdo);
$tiposEvento = comercial_novo_evento_tipos_evento($pdo);
$comoConheceuOptions = [
    'Instagram',
    'Google',
    'Indicação',
    'WhatsApp',
    'Evento ou degustação',
    'Já é cliente',
    'Passou na frente',
    'Outro',
];

$clientesOptions = [];
foreach ($clientes as $cliente) {
    $label = trim((string)($cliente['nome_completo'] ?? ''));
    if (!empty($cliente['telefone_whatsapp'])) {
        $label .= ' - ' . trim((string)$cliente['telefone_whatsapp']);
    }
    $clientesOptions[] = [
        'id' => (int)$cliente['id'],
        'label' => $label,
        'search' => mb_strtolower(trim(
            (string)($cliente['nome_completo'] ?? '') . ' ' .
            (string)($cliente['email'] ?? '') . ' ' .
            (string)($cliente['telefone_whatsapp'] ?? '') . ' ' .
            (string)($cliente['documento_numero'] ?? '')
        ), 'UTF-8'),
        'nome_completo' => (string)($cliente['nome_completo'] ?? ''),
        'email' => (string)($cliente['email'] ?? ''),
        'telefone_whatsapp' => (string)($cliente['telefone_whatsapp'] ?? ''),
        'documento_numero' => (string)($cliente['documento_numero'] ?? ''),
    ];
}

$errors = [];
$old = [
    'local_key' => $_POST['local_key'] ?? '',
    'nome_evento' => trim((string)($_POST['nome_evento'] ?? '')),
    'tipo_evento_real' => eventos_reuniao_normalizar_tipo_evento_real((string)($_POST['tipo_evento_real'] ?? ''), $pdo),
    'data_venda' => $_POST['data_venda'] ?? date('Y-m-d'),
    'data_evento' => $_POST['data_evento'] ?? '',
    'hora_inicio' => $_POST['hora_inicio'] ?? '',
    'hora_fim' => $_POST['hora_fim'] ?? '',
    'cliente_id' => $_POST['cliente_id'] ?? $_GET['cliente_id'] ?? '',
    'cliente_label' => trim((string)($_POST['cliente_label'] ?? '')),
    'como_conheceu' => $_POST['como_conheceu'] ?? '',
    'convidados' => $_POST['convidados'] ?? '',
];

$selectedClientLabel = '';
$clientesByLabel = [];
foreach ($clientesOptions as $clienteOption) {
    $clientesByLabel[$clienteOption['label']] = (int)$clienteOption['id'];
    if ((string)$old['cliente_id'] === (string)$clienteOption['id']) {
        $selectedClientLabel = $clienteOption['label'];
        break;
    }
}
if ($selectedClientLabel === '' && $old['cliente_label'] !== '') {
    $selectedClientLabel = $old['cliente_label'];
}

if ($requestMethod === 'POST' && ($_POST['action'] ?? '') === 'create_event') {
    $local = comercial_novo_evento_find_local($locais, (string)$old['local_key']);
    $clienteId = (int)$old['cliente_id'];
    if ($clienteId <= 0 && $old['cliente_label'] !== '' && isset($clientesByLabel[$old['cliente_label']])) {
        $clienteId = (int)$clientesByLabel[$old['cliente_label']];
        $old['cliente_id'] = (string)$clienteId;
    }
    $cliente = $clienteId > 0 ? comercial_novo_evento_cliente($pdo, $clienteId) : null;
    $convidados = $old['convidados'] !== '' ? (int)$old['convidados'] : null;

    if (!$local) {
        $errors[] = 'Selecione o local do evento.';
    }
    if ($old['nome_evento'] === '') {
        $errors[] = 'Informe o nome do evento.';
    }
    if ($old['tipo_evento_real'] === '' || !isset($tiposEvento[$old['tipo_evento_real']])) {
        $errors[] = 'Selecione o tipo de evento.';
    }
    if ($old['data_venda'] === '') {
        $errors[] = 'Informe a data da venda.';
    }
    if ($old['data_evento'] === '') {
        $errors[] = 'Informe a data do evento.';
    }
    if ($old['hora_inicio'] === '' || $old['hora_fim'] === '') {
        $errors[] = 'Informe o horário de início e término.';
    } elseif (strtotime('2000-01-01 ' . $old['hora_fim']) <= strtotime('2000-01-01 ' . $old['hora_inicio'])) {
        $errors[] = 'O horário de término deve ser maior que o horário de início.';
    }
    if (!$cliente) {
        $errors[] = 'Selecione o cliente.';
    }
    if ($convidados !== null && $convidados < 0) {
        $errors[] = 'A quantidade de convidados não pode ser negativa.';
    }

    if (!$errors && $local && $cliente) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO logistica_eventos_espelho (
                    me_event_id, data_evento, hora_inicio, hora_fim, convidados,
                    idlocalevento, localevento, unidade_interna_id, space_visivel,
                    status_mapeamento, arquivado, synced_at, updated_at,
                    nome_evento, whatsapp_cliente, telefone_cliente, cliente_cadastro_id
                ) VALUES (
                    NULL, :data_evento, :hora_inicio, :hora_fim, :convidados,
                    :idlocalevento, :localevento, :unidade_interna_id, :space_visivel,
                    'LOCAL', FALSE, NOW(), NOW(),
                    :nome_evento, :telefone, :telefone, :cliente_id
                )
                RETURNING id
            ");
            $stmt->execute([
                ':data_evento' => $old['data_evento'],
                ':hora_inicio' => $old['hora_inicio'],
                ':hora_fim' => $old['hora_fim'],
                ':convidados' => $convidados,
                ':idlocalevento' => $local['idlocalevento'],
                ':localevento' => $local['localevento'],
                ':unidade_interna_id' => $local['unidade_interna_id'],
                ':space_visivel' => $local['space_visivel'],
                ':nome_evento' => $old['nome_evento'],
                ':telefone' => $cliente['telefone_whatsapp'] ?? '',
                ':cliente_id' => $clienteId,
            ]);
            $espelhoId = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("
                INSERT INTO comercial_eventos_painel (
                    espelho_evento_id, cliente_cadastro_id, local_evento, nome_evento,
                    tipo_evento_real, data_venda, data_evento, hora_inicio, hora_fim, como_conheceu, convidados,
                    created_by, updated_at
                ) VALUES (
                    :espelho_evento_id, :cliente_id, :local_evento, :nome_evento,
                    :tipo_evento_real, :data_venda, :data_evento, :hora_inicio, :hora_fim, :como_conheceu, :convidados,
                    :created_by, NOW()
                )
            ");
            $stmt->execute([
                ':espelho_evento_id' => $espelhoId,
                ':cliente_id' => $clienteId,
                ':local_evento' => $local['localevento'],
                ':nome_evento' => $old['nome_evento'],
                ':tipo_evento_real' => $old['tipo_evento_real'],
                ':data_venda' => $old['data_venda'],
                ':data_evento' => $old['data_evento'],
                ':hora_inicio' => $old['hora_inicio'],
                ':hora_fim' => $old['hora_fim'],
                ':como_conheceu' => $old['como_conheceu'],
                ':convidados' => $convidados,
                ':created_by' => $_SESSION['usuario_id'] ?? $_SESSION['id'] ?? null,
            ]);

            $pdo->commit();
            $mes = date('Y-m', strtotime($old['data_evento']));
            header('Location: index.php?page=agenda_eventos&evento_id=' . $espelhoId . '&mes=' . urlencode($mes));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Falha ao criar evento pelo painel: ' . $e->getMessage());
            $errors[] = 'Não foi possível salvar o evento agora.';
        }
    }
}

includeSidebar('Novo Evento');
?>

<style>
.novo-evento-page {
    max-width: 1120px;
    margin: 0 auto;
    padding: 28px 20px 48px;
}
.novo-evento-header {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: flex-start;
    margin-bottom: 18px;
}
.novo-evento-title {
    margin: 0;
    color: #1e3a8a;
    font-size: 2rem;
    font-weight: 800;
}
.novo-evento-subtitle {
    margin: 6px 0 0;
    color: #64748b;
    font-size: 0.98rem;
}
.novo-evento-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.btn {
    border: 0;
    border-radius: 12px;
    padding: 12px 18px;
    font-weight: 800;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: transform 0.18s ease, box-shadow 0.18s ease;
}
.btn:hover {
    transform: translateY(-1px);
}
.btn-primary {
    background: #1e3a8a;
    color: #fff;
    box-shadow: 0 10px 22px rgba(30, 58, 138, 0.18);
}
.btn-secondary {
    background: #fff;
    color: #1f2937;
    border: 1px solid #dbe3ef;
}
.btn-link {
    background: transparent;
    color: #2563eb;
    padding: 8px 0;
}
.novo-evento-card {
    background: #fff;
    border: 1px solid #dbe3ef;
    border-radius: 14px;
    box-shadow: 0 18px 42px rgba(15, 23, 42, 0.08);
    overflow: hidden;
}
.novo-evento-card-header {
    padding: 18px 22px;
    border-bottom: 1px solid #e5edf6;
}
.novo-evento-card-header h2 {
    margin: 0;
    font-size: 1.15rem;
    color: #1f2937;
}
.novo-evento-card-header p {
    margin: 4px 0 0;
    color: #64748b;
}
.novo-evento-form {
    padding: 22px;
}
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}
.field {
    display: flex;
    flex-direction: column;
    gap: 7px;
}
.field.full {
    grid-column: 1 / -1;
}
.form-section {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 0 4px;
    margin-top: 4px;
    color: #1e3a8a;
    font-weight: 900;
    font-size: 0.98rem;
}
.form-section::after {
    content: "";
    height: 1px;
    flex: 1;
    background: #dbe3ef;
}
.field label {
    color: #334155;
    font-weight: 800;
    font-size: 0.92rem;
}
.field input,
.field select {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 12px 14px;
    min-height: 46px;
    color: #111827;
    font-size: 0.95rem;
    background: #fff;
}
.field input:focus,
.field select:focus {
    outline: 3px solid rgba(37, 99, 235, 0.16);
    border-color: #2563eb;
}
.time-range {
    display: grid;
    grid-template-columns: 0.8fr 1.2fr;
    gap: 10px;
}
.form-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding-top: 22px;
    margin-top: 20px;
    border-top: 1px solid #e5edf6;
}
.novo-evento-help {
    display: block;
    margin-top: 4px;
    color: #64748b;
    font-size: 0.84rem;
    font-weight: 600;
}
.alert {
    margin-bottom: 14px;
    padding: 12px 14px;
    border-radius: 10px;
    font-weight: 700;
}
.alert-error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.54);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 22px;
    z-index: 99999;
}
.modal-backdrop.open,
.modal-backdrop:target {
    display: flex;
}
.cliente-modal {
    width: min(860px, 100%);
    max-height: calc(100vh - 44px);
    display: flex;
    flex-direction: column;
    background: #fff;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 28px 80px rgba(15, 23, 42, 0.28);
}
.cliente-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 18px 20px;
    border-bottom: 1px solid #e5edf6;
}
.cliente-modal-header h3 {
    margin: 0;
    font-size: 1.12rem;
    color: #1f2937;
}
.modal-close {
    border: 0;
    background: #eef2f7;
    color: #1f2937;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    font-size: 24px;
    line-height: 1;
    cursor: pointer;
}
.cliente-modal-body {
    padding: 20px;
    overflow-y: auto;
}
.cliente-modal-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}
.cliente-modal-section {
    grid-column: 1 / -1;
    color: #1e3a8a;
    font-weight: 900;
    border-bottom: 1px solid #e5edf6;
    padding-top: 4px;
    padding-bottom: 8px;
}
.cliente-modal-grid .span-2 {
    grid-column: span 2;
}
.cliente-modal-grid .span-3 {
    grid-column: span 3;
}
.cliente-modal-grid.is-address {
    grid-template-columns: 1fr 0.6fr 1fr;
    margin-top: 16px;
}
.cliente-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 16px 20px;
    border-top: 1px solid #e5edf6;
}
@media (max-width: 760px) {
    .novo-evento-header,
    .form-footer {
        flex-direction: column;
        align-items: stretch;
    }
    .form-grid,
    .time-range,
    .cliente-modal-grid.is-address,
    .cliente-modal-grid {
        grid-template-columns: 1fr;
    }
    .cliente-modal-grid .span-2,
    .cliente-modal-grid .span-3 {
        grid-column: 1;
    }
}
</style>

<div class="novo-evento-page">
    <div class="novo-evento-header">
        <div>
            <h1 class="novo-evento-title">Novo evento</h1>
            <p class="novo-evento-subtitle">Cadastro inicial criado pelo painel, mantendo a agenda integrada com os dados atuais.</p>
        </div>
        <div class="novo-evento-actions">
            <a class="btn btn-secondary" href="index.php?page=comercial">← Comercial</a>
            <a class="btn btn-secondary" href="index.php?page=agenda_eventos">Agenda Geral</a>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-error">
            <?= comercial_novo_evento_e(implode(' ', $errors)) ?>
        </div>
    <?php endif; ?>

    <section class="novo-evento-card">
        <div class="novo-evento-card-header">
            <h2>Dados do evento</h2>
            <p>Preencha os dados básicos para o evento aparecer na Agenda Geral.</p>
        </div>
        <form class="novo-evento-form" method="post" autocomplete="off">
            <input type="hidden" name="action" value="create_event">
            <div class="form-grid">
                <div class="form-section">Evento</div>
                <div class="field">
                    <label for="local_key">Local do evento</label>
                    <select id="local_key" name="local_key" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($locais as $local): ?>
                            <option value="<?= comercial_novo_evento_e($local['key']) ?>" <?= $old['local_key'] === $local['key'] ? 'selected' : '' ?>>
                                <?= comercial_novo_evento_e($local['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="nome_evento">Nome do evento</label>
                    <input id="nome_evento" name="nome_evento" type="text" value="<?= comercial_novo_evento_e($old['nome_evento']) ?>" required>
                </div>
                <div class="field">
                    <label for="tipo_evento_real">Tipo de evento</label>
                    <select id="tipo_evento_real" name="tipo_evento_real" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($tiposEvento as $tipoKey => $tipoLabel): ?>
                            <option value="<?= comercial_novo_evento_e($tipoKey) ?>" <?= $old['tipo_evento_real'] === (string)$tipoKey ? 'selected' : '' ?>>
                                <?= comercial_novo_evento_e($tipoLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="novo-evento-help">Usa os tipos cadastrados em Configurações.</span>
                </div>
                <div class="field">
                    <label for="data_venda">Data da venda</label>
                    <input id="data_venda" name="data_venda" type="date" value="<?= comercial_novo_evento_e($old['data_venda']) ?>" required>
                </div>
                <div class="field">
                    <label for="data_evento">Data do evento</label>
                    <input id="data_evento" name="data_evento" type="date" value="<?= comercial_novo_evento_e($old['data_evento']) ?>" required>
                </div>
                <div class="field">
                    <label>Horário de início e término</label>
                    <div class="time-range">
                        <input name="hora_inicio" type="time" value="<?= comercial_novo_evento_e($old['hora_inicio']) ?>" required aria-label="Horário de início">
                        <input name="hora_fim" type="time" value="<?= comercial_novo_evento_e($old['hora_fim']) ?>" required aria-label="Horário de término">
                    </div>
                </div>
                <div class="form-section">Cliente</div>
                <div class="field full">
                    <label for="cliente_search">Cliente</label>
                    <div>
                        <input id="cliente_search" name="cliente_label" type="search" list="clientes-list" placeholder="Clique aqui para selecione o cliente" value="<?= comercial_novo_evento_e($selectedClientLabel) ?>" autocomplete="off" required>
                        <input id="cliente_id" name="cliente_id" type="hidden" value="<?= comercial_novo_evento_e($old['cliente_id']) ?>">
                        <datalist id="clientes-list">
                            <?php foreach ($clientesOptions as $clienteOption): ?>
                                <option value="<?= comercial_novo_evento_e($clienteOption['label']) ?>" data-id="<?= (int)$clienteOption['id'] ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <a class="btn btn-link" href="#cliente-modal" id="open-client-modal">+ Adicionar novo cliente</a>
                </div>
                <div class="form-section">Comercial</div>
                <div class="field">
                    <label for="como_conheceu">Como nos conheceu?</label>
                    <select id="como_conheceu" name="como_conheceu">
                        <option value="">Selecione...</option>
                        <?php foreach ($comoConheceuOptions as $option): ?>
                            <option value="<?= comercial_novo_evento_e($option) ?>" <?= $old['como_conheceu'] === $option ? 'selected' : '' ?>>
                                <?= comercial_novo_evento_e($option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="convidados">Quantia de convidados</label>
                    <input id="convidados" name="convidados" type="number" min="0" step="1" value="<?= comercial_novo_evento_e($old['convidados']) ?>">
                </div>
            </div>
            <div class="form-footer">
                <a class="btn btn-secondary" href="index.php?page=comercial">Cancelar</a>
                <button class="btn btn-primary" type="submit">Salvar evento</button>
            </div>
        </form>
    </section>
</div>

<div class="modal-backdrop" id="cliente-modal" aria-hidden="true">
    <div class="cliente-modal" role="dialog" aria-modal="true" aria-labelledby="client-modal-title">
        <div class="cliente-modal-header">
            <h3 id="client-modal-title">Adicionar novo cliente</h3>
            <a class="modal-close" href="#" id="close-client-modal" aria-label="Fechar">×</a>
        </div>
        <form id="quick-client-form" method="post">
            <input type="hidden" name="action" value="create_client_inline">
            <div class="cliente-modal-body">
                <div class="alert alert-error" id="client-modal-error" style="display: none;"></div>
                <div class="cliente-modal-grid">
                    <div class="cliente-modal-section">Dados do cliente</div>
                    <div class="field full">
                        <label for="modal_nome_completo">Nome completo</label>
                        <input id="modal_nome_completo" name="nome_completo" type="text" required>
                    </div>
                    <div class="field">
                        <label for="modal_documento">CPF</label>
                        <input id="modal_documento" name="documento_numero" type="text">
                    </div>
                    <div class="field">
                        <label for="modal_rg">RG</label>
                        <input id="modal_rg" name="rg" type="text">
                    </div>
                    <div class="field">
                        <label for="modal_telefone">Telefone / WhatsApp</label>
                        <input id="modal_telefone" name="telefone_whatsapp" type="text">
                    </div>
                    <div class="field">
                        <label for="modal_email">E-mail</label>
                        <input id="modal_email" name="email" type="email">
                    </div>
                </div>
                <div class="cliente-modal-grid is-address">
                    <div class="cliente-modal-section">Endereço</div>
                    <div class="field">
                        <label for="modal_cep">CEP</label>
                        <input id="modal_cep" name="cep" type="text">
                    </div>
                    <div class="field">
                        <label for="modal_endereco_numero">Número</label>
                        <input id="modal_endereco_numero" name="endereco_numero" type="text">
                    </div>
                    <div class="field">
                        <label for="modal_endereco_complemento">Complemento</label>
                        <input id="modal_endereco_complemento" name="endereco_complemento" type="text">
                    </div>
                    <div class="field span-3">
                        <label for="modal_endereco_logradouro">Rua</label>
                        <input id="modal_endereco_logradouro" name="endereco_logradouro" type="text">
                    </div>
                    <div class="field">
                        <label for="modal_endereco_bairro">Bairro</label>
                        <input id="modal_endereco_bairro" name="endereco_bairro" type="text">
                    </div>
                    <div class="field">
                        <label for="modal_endereco_cidade">Cidade</label>
                        <input id="modal_endereco_cidade" name="endereco_cidade" type="text">
                    </div>
                    <div class="field">
                        <label for="modal_endereco_estado">Estado</label>
                        <input id="modal_endereco_estado" name="endereco_estado" type="text" maxlength="2">
                    </div>
                </div>
            </div>
            <div class="cliente-modal-footer">
                <a class="btn btn-secondary" href="#" id="cancel-client-modal">Cancelar</a>
                <button class="btn btn-primary" type="submit">Salvar cliente</button>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const clientSearch = document.getElementById('cliente_search');
    const clientId = document.getElementById('cliente_id');
    const clientOptions = Array.from(document.querySelectorAll('#clientes-list option'));

    function syncClientId() {
        const typed = clientSearch.value.trim();
        const option = clientOptions.find((item) => item.value === typed);
        clientId.value = option ? option.dataset.id : '';
    }

    clientSearch.addEventListener('input', syncClientId);
    clientSearch.addEventListener('change', syncClientId);

    const backdrop = document.getElementById('cliente-modal');
    const form = document.getElementById('quick-client-form');
    const errorBox = document.getElementById('client-modal-error');

    function openModal() {
        if (errorBox) {
            errorBox.style.display = 'none';
        }
        backdrop.classList.add('open');
        backdrop.setAttribute('aria-hidden', 'false');
        if (window.location.hash !== '#cliente-modal') {
            window.location.hash = 'cliente-modal';
        }
        setTimeout(() => document.getElementById('modal_nome_completo').focus(), 0);
    }

    function closeModal() {
        backdrop.classList.remove('open');
        backdrop.setAttribute('aria-hidden', 'true');
        form.reset();
        if (window.location.hash === '#cliente-modal') {
            history.replaceState(null, '', window.location.pathname + window.location.search);
        }
    }

    document.addEventListener('click', (event) => {
        if (event.target.closest('#open-client-modal')) {
            event.preventDefault();
            openModal();
        }
    });
    document.getElementById('close-client-modal').addEventListener('click', (event) => {
        event.preventDefault();
        closeModal();
    });
    document.getElementById('cancel-client-modal').addEventListener('click', (event) => {
        event.preventDefault();
        closeModal();
    });
    backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) {
            closeModal();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && backdrop.classList.contains('open')) {
            closeModal();
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (errorBox) {
            errorBox.style.display = 'none';
        }

        try {
            const formData = new FormData(form);
            formData.set('action', 'ajax_create_client');
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json();
            if (!data.ok) {
                throw new Error(data.message || 'Não foi possível salvar o cliente.');
            }

            const client = data.client;
            const label = client.telefone_whatsapp
                ? `${client.nome_completo} - ${client.telefone_whatsapp}`
                : client.nome_completo;
            const option = document.createElement('option');
            option.value = label;
            option.dataset.id = String(client.id);
            document.getElementById('clientes-list').appendChild(option);
            clientOptions.push(option);
            clientSearch.value = label;
            clientId.value = String(client.id);
            closeModal();
        } catch (error) {
            if (errorBox) {
                errorBox.textContent = error.message;
                errorBox.style.display = 'block';
            }
        }
    });

    document.querySelector('.novo-evento-form').addEventListener('submit', (event) => {
        syncClientId();
        if (!clientId.value) {
            event.preventDefault();
            clientSearch.focus();
            clientSearch.setCustomValidity('Selecione um cliente da lista.');
            clientSearch.reportValidity();
            setTimeout(() => clientSearch.setCustomValidity(''), 0);
        }
    });

    if (window.location.hash === '#cliente-modal') {
        openModal();
    }
})();
</script>

<?php
endSidebar();
?>
