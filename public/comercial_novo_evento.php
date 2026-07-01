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

comercial_novo_evento_ensure_schema($pdo);

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'POST' && ($_POST['action'] ?? '') === 'ajax_create_client') {
    $nome = trim((string)($_POST['nome_completo'] ?? ''));
    $telefone = trim((string)($_POST['telefone_whatsapp'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $documento = comercial_novo_evento_digits($_POST['documento_numero'] ?? '');

    if ($nome === '') {
        comercial_novo_evento_json(['ok' => false, 'message' => 'Informe o nome do cliente.'], 422);
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO comercial_cadastro_clientes (
                tipo_pessoa, nome_completo, email, telefone_whatsapp, documento_tipo,
                documento_numero, origem_cliente, origem_importacao, created_by,
                created_at, updated_at
            ) VALUES (
                'PF', :nome, :email, :telefone, 'CPF',
                :documento, 'Novo evento', 'painel', :created_by,
                NOW(), NOW()
            )
            RETURNING id, nome_completo, email, telefone_whatsapp, documento_numero
        ");
        $stmt->execute([
            ':nome' => $nome,
            ':email' => $email,
            ':telefone' => $telefone,
            ':documento' => $documento,
            ':created_by' => $_SESSION['usuario_id'] ?? $_SESSION['id'] ?? null,
        ]);
        comercial_novo_evento_json(['ok' => true, 'client' => $stmt->fetch(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) {
        error_log('Falha ao cadastrar cliente no novo evento: ' . $e->getMessage());
        comercial_novo_evento_json(['ok' => false, 'message' => 'Não foi possível cadastrar o cliente.'], 500);
    }
}

$locais = comercial_novo_evento_locais($pdo);
$clientes = comercial_novo_evento_clientes($pdo);
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

$errors = [];
$old = [
    'local_key' => $_POST['local_key'] ?? '',
    'nome_evento' => trim((string)($_POST['nome_evento'] ?? '')),
    'data_evento' => $_POST['data_evento'] ?? '',
    'hora_inicio' => $_POST['hora_inicio'] ?? '',
    'hora_fim' => $_POST['hora_fim'] ?? '',
    'cliente_id' => $_POST['cliente_id'] ?? '',
    'como_conheceu' => $_POST['como_conheceu'] ?? '',
    'convidados' => $_POST['convidados'] ?? '',
];

if ($requestMethod === 'POST' && ($_POST['action'] ?? '') === 'create_event') {
    $local = comercial_novo_evento_find_local($locais, (string)$old['local_key']);
    $clienteId = (int)$old['cliente_id'];
    $cliente = $clienteId > 0 ? comercial_novo_evento_cliente($pdo, $clienteId) : null;
    $convidados = $old['convidados'] !== '' ? (int)$old['convidados'] : null;

    if (!$local) {
        $errors[] = 'Selecione o local do evento.';
    }
    if ($old['nome_evento'] === '') {
        $errors[] = 'Informe o nome do evento.';
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
                    data_evento, hora_inicio, hora_fim, como_conheceu, convidados,
                    created_by, updated_at
                ) VALUES (
                    :espelho_evento_id, :cliente_id, :local_evento, :nome_evento,
                    :data_evento, :hora_inicio, :hora_fim, :como_conheceu, :convidados,
                    :created_by, NOW()
                )
            ");
            $stmt->execute([
                ':espelho_evento_id' => $espelhoId,
                ':cliente_id' => $clienteId,
                ':local_evento' => $local['localevento'],
                ':nome_evento' => $old['nome_evento'],
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
.client-picker {
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
    z-index: 2000;
}
.modal-backdrop.open {
    display: flex;
}
.cliente-modal {
    width: min(720px, 100%);
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
}
.cliente-modal-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
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
    .client-picker,
    .cliente-modal-grid {
        grid-template-columns: 1fr;
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
                    <label for="data_evento">Data do evento</label>
                    <input id="data_evento" name="data_evento" type="date" value="<?= comercial_novo_evento_e($old['data_evento']) ?>" required>
                </div>
                <div class="field">
                    <label>Horário de início e término</label>
                    <div class="client-picker">
                        <input name="hora_inicio" type="time" value="<?= comercial_novo_evento_e($old['hora_inicio']) ?>" required aria-label="Horário de início">
                        <input name="hora_fim" type="time" value="<?= comercial_novo_evento_e($old['hora_fim']) ?>" required aria-label="Horário de término">
                    </div>
                </div>
                <div class="field full">
                    <label for="cliente_id">Cliente</label>
                    <div class="client-picker">
                        <input id="cliente-busca" type="search" placeholder="Digite para buscar cliente">
                        <select id="cliente_id" name="cliente_id" required>
                            <option value="">Clique aqui para selecione o cliente</option>
                            <?php foreach ($clientes as $cliente): ?>
                                <?php
                                $buscaCliente = trim(($cliente['nome_completo'] ?? '') . ' ' . ($cliente['email'] ?? '') . ' ' . ($cliente['telefone_whatsapp'] ?? '') . ' ' . ($cliente['documento_numero'] ?? ''));
                                ?>
                                <option
                                    value="<?= (int)$cliente['id'] ?>"
                                    data-search="<?= comercial_novo_evento_e(mb_strtolower($buscaCliente, 'UTF-8')) ?>"
                                    <?= (string)$old['cliente_id'] === (string)$cliente['id'] ? 'selected' : '' ?>
                                >
                                    <?= comercial_novo_evento_e($cliente['nome_completo']) ?>
                                    <?php if (!empty($cliente['telefone_whatsapp'])): ?>
                                        - <?= comercial_novo_evento_e($cliente['telefone_whatsapp']) ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="button" class="btn btn-link" id="open-client-modal">+ Adicionar novo cliente</button>
                </div>
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

<div class="modal-backdrop" id="client-modal-backdrop" aria-hidden="true">
    <div class="cliente-modal" role="dialog" aria-modal="true" aria-labelledby="client-modal-title">
        <div class="cliente-modal-header">
            <h3 id="client-modal-title">Adicionar novo cliente</h3>
            <button class="modal-close" type="button" id="close-client-modal" aria-label="Fechar">×</button>
        </div>
        <form id="quick-client-form">
            <input type="hidden" name="action" value="ajax_create_client">
            <div class="cliente-modal-body">
                <div class="alert alert-error" id="client-modal-error" style="display: none;"></div>
                <div class="cliente-modal-grid">
                    <div class="field full">
                        <label for="modal_nome_completo">Nome completo</label>
                        <input id="modal_nome_completo" name="nome_completo" type="text" required>
                    </div>
                    <div class="field">
                        <label for="modal_telefone">Telefone / WhatsApp</label>
                        <input id="modal_telefone" name="telefone_whatsapp" type="text">
                    </div>
                    <div class="field">
                        <label for="modal_email">E-mail</label>
                        <input id="modal_email" name="email" type="email">
                    </div>
                    <div class="field">
                        <label for="modal_documento">CPF</label>
                        <input id="modal_documento" name="documento_numero" type="text">
                    </div>
                </div>
            </div>
            <div class="cliente-modal-footer">
                <button class="btn btn-secondary" type="button" id="cancel-client-modal">Cancelar</button>
                <button class="btn btn-primary" type="submit">Salvar cliente</button>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const searchInput = document.getElementById('cliente-busca');
    const clientSelect = document.getElementById('cliente_id');
    const allOptions = Array.from(clientSelect.options).map((option) => ({
        value: option.value,
        text: option.text,
        search: option.dataset.search || '',
        selected: option.selected,
    }));

    function renderClientOptions(term) {
        const selectedValue = clientSelect.value;
        const normalized = String(term || '').trim().toLowerCase();
        clientSelect.innerHTML = '';
        allOptions.forEach((item, index) => {
            if (index > 0 && normalized && !item.search.includes(normalized)) {
                return;
            }
            const option = new Option(item.text, item.value, false, item.value === selectedValue);
            option.dataset.search = item.search;
            clientSelect.add(option);
        });
    }

    searchInput.addEventListener('input', () => renderClientOptions(searchInput.value));

    const backdrop = document.getElementById('client-modal-backdrop');
    const form = document.getElementById('quick-client-form');
    const errorBox = document.getElementById('client-modal-error');
    const openModal = () => {
        errorBox.style.display = 'none';
        backdrop.classList.add('open');
        backdrop.setAttribute('aria-hidden', 'false');
        setTimeout(() => document.getElementById('modal_nome_completo').focus(), 0);
    };
    const closeModal = () => {
        backdrop.classList.remove('open');
        backdrop.setAttribute('aria-hidden', 'true');
        form.reset();
    };

    document.getElementById('open-client-modal').addEventListener('click', openModal);
    document.getElementById('close-client-modal').addEventListener('click', closeModal);
    document.getElementById('cancel-client-modal').addEventListener('click', closeModal);
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
        errorBox.style.display = 'none';

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: new FormData(form),
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
            const search = `${client.nome_completo || ''} ${client.email || ''} ${client.telefone_whatsapp || ''} ${client.documento_numero || ''}`.toLowerCase();
            allOptions.push({
                value: String(client.id),
                text: label,
                search,
                selected: true,
            });
            clientSelect.value = '';
            renderClientOptions('');
            clientSelect.value = String(client.id);
            searchInput.value = client.nome_completo || '';
            closeModal();
        } catch (error) {
            errorBox.textContent = error.message;
            errorBox.style.display = 'block';
        }
    });
})();
</script>

<?php
endSidebar();
?>
