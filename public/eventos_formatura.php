<?php
/**
 * eventos_formatura.php
 * Gestão de formandos vinculados a eventos do tipo Formatura.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';
require_once __DIR__ . '/eventos_financeiro_helper.php';

if (empty($_SESSION['perm_agenda_eventos']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

function eventos_formatura_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function eventos_formatura_money($value): float
{
    return eventos_financeiro_money($value);
}

function eventos_formatura_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eventos_formatura_formandos (
            id BIGSERIAL PRIMARY KEY,
            evento_id BIGINT NOT NULL REFERENCES logistica_eventos_espelho(id) ON DELETE CASCADE,
            cliente_cadastro_id BIGINT NOT NULL REFERENCES comercial_cadastro_clientes(id) ON DELETE RESTRICT,
            nome_formando VARCHAR(180) NOT NULL,
            convidados INTEGER NOT NULL DEFAULT 0,
            mesas INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
            deleted_at TIMESTAMP NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eventos_formatura_financeiro (
            id BIGSERIAL PRIMARY KEY,
            formando_id BIGINT NOT NULL REFERENCES eventos_formatura_formandos(id) ON DELETE CASCADE,
            evento_id BIGINT NOT NULL REFERENCES logistica_eventos_espelho(id) ON DELETE CASCADE,
            descricao TEXT NOT NULL,
            valor NUMERIC(12,2) NOT NULL DEFAULT 0,
            vencimento DATE NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pendente',
            forma_pagamento VARCHAR(30) NOT NULL DEFAULT 'manual',
            created_by INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_formatura_formandos_evento ON eventos_formatura_formandos(evento_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_formatura_formandos_cliente ON eventos_formatura_formandos(cliente_cadastro_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_formatura_financeiro_formando ON eventos_formatura_financeiro(formando_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_formatura_financeiro_evento ON eventos_formatura_financeiro(evento_id)");

    $done = true;
}

function eventos_formatura_evento(PDO $pdo, int $eventoId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            e.id,
            e.me_event_id,
            e.data_evento::text AS data_evento,
            COALESCE(TO_CHAR(e.hora_inicio, 'HH24:MI'), '') AS hora_inicio,
            COALESCE(TO_CHAR(e.hora_fim, 'HH24:MI'), '') AS hora_fim,
            COALESCE(NULLIF(TRIM(e.nome_evento), ''), 'Evento') AS nome_evento,
            COALESCE(NULLIF(TRIM(e.localevento), ''), 'Local não informado') AS local_evento,
            COALESCE(NULLIF(TRIM(e.space_visivel), ''), 'Não mapeado') AS space_visivel,
            COALESCE(e.convidados, 0) AS convidados,
            COALESCE(
                NULLIF(TRIM(cep.tipo_evento_real), ''),
                NULLIF(TRIM(er.tipo_evento_real), ''),
                NULLIF(TRIM(er.me_event_snapshot->>'tipo_evento_real'), ''),
                ''
            ) AS tipo_evento_real
        FROM logistica_eventos_espelho e
        LEFT JOIN LATERAL (
            SELECT tipo_evento_real
            FROM comercial_eventos_painel
            WHERE espelho_evento_id = e.id
            ORDER BY updated_at DESC NULLS LAST, id DESC
            LIMIT 1
        ) cep ON TRUE
        LEFT JOIN LATERAL (
            SELECT tipo_evento_real, me_event_snapshot
            FROM eventos_reunioes
            WHERE me_event_id = e.me_event_id
            ORDER BY updated_at DESC NULLS LAST, id DESC
            LIMIT 1
        ) er ON TRUE
        WHERE e.id = :id
          AND COALESCE(e.arquivado, FALSE) = FALSE
        LIMIT 1
    ");
    $stmt->execute([':id' => $eventoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return is_array($row) ? $row : null;
}

function eventos_formatura_clientes(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            id,
            COALESCE(NULLIF(TRIM(nome_completo), ''), 'Cliente sem nome') AS nome,
            COALESCE(NULLIF(TRIM(documento_numero), ''), '') AS documento,
            COALESCE(NULLIF(TRIM(email), ''), '') AS email,
            COALESCE(NULLIF(TRIM(telefone_whatsapp), ''), '') AS telefone
        FROM comercial_cadastro_clientes
        WHERE COALESCE(ativo, TRUE) = TRUE
        ORDER BY nome_completo ASC NULLS LAST, id ASC
        LIMIT 1200
    ");
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function eventos_formatura_formandos(PDO $pdo, int $eventoId): array
{
    $stmt = $pdo->prepare("
        SELECT
            f.*,
            c.nome_completo AS cliente_nome,
            c.documento_numero AS cliente_documento,
            c.email AS cliente_email,
            c.telefone_whatsapp AS cliente_telefone,
            COALESCE(fin.total_lancado, 0) AS total_lancado,
            COALESCE(fin.total_pago, 0) AS total_pago
        FROM eventos_formatura_formandos f
        JOIN comercial_cadastro_clientes c ON c.id = f.cliente_cadastro_id
        LEFT JOIN (
            SELECT
                formando_id,
                SUM(CASE WHEN status <> 'cancelado' THEN valor ELSE 0 END) AS total_lancado,
                SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END) AS total_pago
            FROM eventos_formatura_financeiro
            GROUP BY formando_id
        ) fin ON fin.formando_id = f.id
        WHERE f.evento_id = :evento_id
          AND f.deleted_at IS NULL
        ORDER BY f.nome_formando ASC, f.id ASC
    ");
    $stmt->execute([':evento_id' => $eventoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function eventos_formatura_financeiro(PDO $pdo, int $eventoId): array
{
    $stmt = $pdo->prepare("
        SELECT fin.*, f.nome_formando
        FROM eventos_formatura_financeiro fin
        JOIN eventos_formatura_formandos f ON f.id = fin.formando_id
        WHERE fin.evento_id = :evento_id
          AND f.deleted_at IS NULL
        ORDER BY COALESCE(fin.vencimento, fin.created_at::date) DESC, fin.id DESC
    ");
    $stmt->execute([':evento_id' => $eventoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function eventos_formatura_redirect(int $eventoId): void
{
    header('Location: index.php?page=eventos_formatura&evento_id=' . $eventoId);
    exit;
}

$eventoId = (int)($_GET['evento_id'] ?? $_POST['evento_id'] ?? 0);
$userId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
$messages = [];
$errors = [];

eventos_formatura_ensure_schema($pdo);
$evento = $eventoId > 0 ? eventos_formatura_evento($pdo, $eventoId) : null;

if (!$evento) {
    includeSidebar('Formatura');
    echo '<div style="padding:2rem"><div class="alert alert-error">Evento não encontrado.</div></div>';
    endSidebar();
    return;
}

$tipoEvento = eventos_reuniao_normalizar_tipo_evento_real((string)($evento['tipo_evento_real'] ?? ''), $pdo);
if ($tipoEvento !== 'formatura') {
    $errors[] = 'Este evento não está marcado como Formatura.';
}

$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($requestMethod === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_formando') {
        $formandoId = (int)($_POST['formando_id'] ?? 0);
        $clienteId = (int)($_POST['cliente_cadastro_id'] ?? 0);
        $nomeFormando = trim((string)($_POST['nome_formando'] ?? ''));
        $convidados = max(0, (int)($_POST['convidados'] ?? 0));
        $mesas = max(0, (int)($_POST['mesas'] ?? 0));

        if ($clienteId <= 0) {
            $errors[] = 'Selecione o cliente do formando.';
        }
        if ($nomeFormando === '') {
            $errors[] = 'Informe o nome do formando.';
        }

        if (empty($errors)) {
            $stmtCliente = $pdo->prepare("SELECT id FROM comercial_cadastro_clientes WHERE id = :id LIMIT 1");
            $stmtCliente->execute([':id' => $clienteId]);
            if (!$stmtCliente->fetchColumn()) {
                $errors[] = 'Cliente selecionado não encontrado.';
            }
        }

        if (empty($errors)) {
            if ($formandoId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE eventos_formatura_formandos
                    SET cliente_cadastro_id = :cliente_id,
                        nome_formando = :nome_formando,
                        convidados = :convidados,
                        mesas = :mesas,
                        updated_at = NOW()
                    WHERE id = :id
                      AND evento_id = :evento_id
                      AND deleted_at IS NULL
                ");
                $stmt->execute([
                    ':cliente_id' => $clienteId,
                    ':nome_formando' => $nomeFormando,
                    ':convidados' => $convidados,
                    ':mesas' => $mesas,
                    ':id' => $formandoId,
                    ':evento_id' => $eventoId,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO eventos_formatura_formandos
                        (evento_id, cliente_cadastro_id, nome_formando, convidados, mesas, created_by, updated_at)
                    VALUES
                        (:evento_id, :cliente_id, :nome_formando, :convidados, :mesas, :created_by, NOW())
                ");
                $stmt->execute([
                    ':evento_id' => $eventoId,
                    ':cliente_id' => $clienteId,
                    ':nome_formando' => $nomeFormando,
                    ':convidados' => $convidados,
                    ':mesas' => $mesas,
                    ':created_by' => $userId > 0 ? $userId : null,
                ]);
            }

            $_SESSION['eventos_formatura_message'] = $formandoId > 0 ? 'Formando atualizado.' : 'Formando cadastrado.';
            eventos_formatura_redirect($eventoId);
        }
    }

    if ($action === 'delete_formando') {
        $formandoId = (int)($_POST['formando_id'] ?? 0);
        if ($formandoId > 0) {
            $stmt = $pdo->prepare("
                UPDATE eventos_formatura_formandos
                SET deleted_at = NOW(), updated_at = NOW()
                WHERE id = :id
                  AND evento_id = :evento_id
            ");
            $stmt->execute([':id' => $formandoId, ':evento_id' => $eventoId]);
            $_SESSION['eventos_formatura_message'] = 'Formando excluído.';
            eventos_formatura_redirect($eventoId);
        }
    }

    if ($action === 'save_financeiro') {
        $formandoId = (int)($_POST['formando_id'] ?? 0);
        $descricao = trim((string)($_POST['descricao'] ?? ''));
        $valor = eventos_formatura_money($_POST['valor'] ?? 0);
        $vencimento = trim((string)($_POST['vencimento'] ?? ''));
        $status = (string)($_POST['status'] ?? 'pendente');
        if (!in_array($status, ['pendente', 'pago', 'cancelado'], true)) {
            $status = 'pendente';
        }

        if ($formandoId <= 0) {
            $errors[] = 'Selecione o formando para o lançamento.';
        }
        if ($descricao === '') {
            $errors[] = 'Informe a descrição do lançamento.';
        }
        if ($valor <= 0) {
            $errors[] = 'Informe um valor maior que zero.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT id FROM eventos_formatura_formandos WHERE id = :id AND evento_id = :evento_id AND deleted_at IS NULL");
            $stmt->execute([':id' => $formandoId, ':evento_id' => $eventoId]);
            if (!$stmt->fetchColumn()) {
                $errors[] = 'Formando não encontrado.';
            }
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("
                INSERT INTO eventos_formatura_financeiro
                    (formando_id, evento_id, descricao, valor, vencimento, status, forma_pagamento, created_by, updated_at)
                VALUES
                    (:formando_id, :evento_id, :descricao, :valor, CAST(NULLIF(:vencimento, '') AS DATE), :status, 'manual', :created_by, NOW())
            ");
            $stmt->execute([
                ':formando_id' => $formandoId,
                ':evento_id' => $eventoId,
                ':descricao' => $descricao,
                ':valor' => $valor,
                ':vencimento' => $vencimento,
                ':status' => $status,
                ':created_by' => $userId > 0 ? $userId : null,
            ]);

            $_SESSION['eventos_formatura_message'] = 'Lançamento financeiro criado.';
            eventos_formatura_redirect($eventoId);
        }
    }
}

if (!empty($_SESSION['eventos_formatura_message'])) {
    $messages[] = (string)$_SESSION['eventos_formatura_message'];
    unset($_SESSION['eventos_formatura_message']);
}

$clientes = eventos_formatura_clientes($pdo);
$formandos = eventos_formatura_formandos($pdo, $eventoId);
$financeiro = eventos_formatura_financeiro($pdo, $eventoId);
$clientesJson = [];
foreach ($clientes as $cliente) {
    $labelParts = [(string)$cliente['nome']];
    if (!empty($cliente['telefone'])) {
        $labelParts[] = (string)$cliente['telefone'];
    }
    if (!empty($cliente['documento'])) {
        $labelParts[] = (string)$cliente['documento'];
    }
    $clientesJson[] = [
        'id' => (int)$cliente['id'],
        'nome' => (string)$cliente['nome'],
        'documento' => (string)$cliente['documento'],
        'email' => (string)$cliente['email'],
        'telefone' => (string)$cliente['telefone'],
        'label' => implode(' - ', array_filter($labelParts)),
    ];
}

$totalConvidados = array_sum(array_map(static fn($item) => (int)$item['convidados'], $formandos));
$totalMesas = array_sum(array_map(static fn($item) => (int)$item['mesas'], $formandos));
$totalLancado = array_sum(array_map(static fn($item) => (float)$item['valor'], array_filter($financeiro, static fn($item) => (string)$item['status'] !== 'cancelado')));
$totalPago = array_sum(array_map(static fn($item) => (float)$item['valor'], array_filter($financeiro, static fn($item) => (string)$item['status'] === 'pago')));

includeSidebar('Formatura');
?>

<style>
.formatura-page {
    max-width: 1260px;
    margin: 0 auto;
    padding: 1.5rem;
    background: #f8fafc;
}
.formatura-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1.2rem;
    flex-wrap: wrap;
}
.formatura-title {
    margin: 0;
    color: #1e3a8a;
    font-size: 1.8rem;
    font-weight: 900;
}
.formatura-subtitle {
    margin: 0.35rem 0 0;
    color: #64748b;
    font-weight: 650;
}
.formatura-btn {
    border: 0;
    border-radius: 8px;
    padding: 0.72rem 1rem;
    font-weight: 850;
    color: #fff;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    cursor: pointer;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
}
.formatura-btn--primary { background: #16c784; }
.formatura-btn--info { background: #33b7c9; }
.formatura-btn--warning { background: #f5ba28; color: #fff; }
.formatura-btn--dark { background: #2f4050; }
.formatura-btn--light { background: #fff; color: #1f2a44; border: 1px solid #d6e0ec; }
.formatura-panel {
    background: #fff;
    border: 1px solid #dbe3ef;
    border-radius: 8px;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
    overflow: hidden;
}
.formatura-panel-head {
    padding: 1.2rem 1.4rem;
    border-bottom: 1px solid #e5edf6;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}
.formatura-panel-head h2 {
    margin: 0;
    color: #334155;
    font-size: 1.2rem;
    font-weight: 900;
}
.formatura-toolbar {
    padding: 1.2rem 1.4rem;
    display: flex;
    gap: 0.7rem;
    align-items: center;
    flex-wrap: wrap;
}
.formatura-search {
    margin-left: auto;
    min-width: 240px;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    padding: 0.72rem 0.85rem;
    font-size: 0.95rem;
}
.formatura-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(140px, 1fr));
    gap: 0.8rem;
    margin-bottom: 1rem;
}
.formatura-stat {
    background: #fff;
    border: 1px solid #dbe3ef;
    border-radius: 8px;
    padding: 1rem;
}
.formatura-stat strong {
    display: block;
    color: #1e3a8a;
    font-size: 1.25rem;
}
.formatura-stat span {
    color: #64748b;
    font-weight: 750;
}
.formatura-table-wrap {
    padding: 0 1.4rem 1.4rem;
    overflow-x: auto;
}
.formatura-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 900px;
}
.formatura-table th {
    background: #e5e7eb;
    color: #4b5563;
    text-align: left;
    padding: 0.9rem;
    font-weight: 900;
    border-right: 2px solid #d6d6d6;
}
.formatura-table td {
    padding: 0.9rem;
    border-bottom: 1px solid #dbe3ef;
    border-right: 2px solid #eeeeee;
    color: #334155;
    vertical-align: middle;
}
.formatura-table strong {
    display: block;
    color: #1f2937;
}
.formatura-muted {
    color: #64748b;
    font-size: 0.88rem;
}
.formatura-actions {
    display: flex;
    gap: 0.45rem;
    align-items: center;
}
.formatura-icon-btn {
    width: 34px;
    height: 34px;
    border-radius: 999px;
    border: 1px solid #d1d5db;
    background: #fff;
    color: #334155;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-weight: 900;
    text-decoration: none;
}
.formatura-icon-btn:hover {
    background: #f8fafc;
    border-color: #94a3b8;
}
.formatura-empty {
    padding: 2rem 1.4rem;
    color: #64748b;
    font-weight: 700;
}
.alert {
    margin-bottom: 1rem;
    padding: 0.9rem 1rem;
    border-radius: 8px;
    font-weight: 750;
}
.alert-success {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #86efac;
}
.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}
.formatura-modal {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1.2rem;
    background: rgba(15, 23, 42, 0.5);
    z-index: 1000;
}
.formatura-modal.is-open {
    display: flex;
}
.formatura-modal-dialog {
    width: min(860px, 96vw);
    max-height: 90vh;
    overflow: auto;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.28);
}
.formatura-modal-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e5edf6;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.formatura-modal-head h3 {
    margin: 0;
    color: #1f2937;
    font-size: 1.15rem;
    font-weight: 900;
}
.formatura-close {
    border: 0;
    background: #eef2f7;
    color: #1f2937;
    width: 34px;
    height: 34px;
    border-radius: 999px;
    font-size: 1.25rem;
    cursor: pointer;
}
.formatura-modal-body {
    padding: 1.2rem;
}
.formatura-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.9rem;
}
.formatura-field label {
    display: block;
    margin-bottom: 0.35rem;
    color: #334155;
    font-weight: 850;
}
.formatura-field input,
.formatura-field select,
.formatura-field textarea {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 0.72rem 0.8rem;
    font: inherit;
}
.formatura-field textarea {
    min-height: 92px;
    resize: vertical;
}
.formatura-field.full {
    grid-column: 1 / -1;
}
.cliente-preview {
    margin-top: 0.55rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 0.75rem;
    color: #475569;
    font-weight: 650;
}
.formatura-modal-actions {
    padding: 1rem 1.2rem;
    border-top: 1px solid #e5edf6;
    display: flex;
    justify-content: flex-end;
    gap: 0.7rem;
}
@media (max-width: 860px) {
    .formatura-stats,
    .formatura-grid {
        grid-template-columns: 1fr;
    }
    .formatura-search {
        width: 100%;
        margin-left: 0;
    }
}
</style>

<div class="formatura-page">
    <div class="formatura-header">
        <div>
            <h1 class="formatura-title">Formatura</h1>
            <p class="formatura-subtitle">
                <?= eventos_formatura_e((string)$evento['nome_evento']) ?> ·
                <?= eventos_formatura_e((string)$evento['local_evento']) ?> ·
                <?= eventos_formatura_e((string)$evento['data_evento']) ?>
            </p>
        </div>
        <a class="formatura-btn formatura-btn--light" href="index.php?page=agenda_eventos&evento_id=<?= (int)$eventoId ?>">← Voltar ao evento</a>
    </div>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-success"><?= eventos_formatura_e($message) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= eventos_formatura_e($error) ?></div>
    <?php endforeach; ?>

    <div class="formatura-stats">
        <div class="formatura-stat"><strong><?= count($formandos) ?></strong><span>Formandos</span></div>
        <div class="formatura-stat"><strong><?= (int)$totalConvidados ?></strong><span>Convidados</span></div>
        <div class="formatura-stat"><strong><?= (int)$totalMesas ?></strong><span>Mesas</span></div>
        <div class="formatura-stat"><strong>R$ <?= number_format($totalPago, 2, ',', '.') ?></strong><span>Recebido de R$ <?= number_format($totalLancado, 2, ',', '.') ?></span></div>
    </div>

    <section class="formatura-panel">
        <div class="formatura-panel-head">
            <h2>Formandos Cadastrados</h2>
        </div>
        <div class="formatura-toolbar">
            <button type="button" class="formatura-btn formatura-btn--primary" id="btnNovoFormando">👥 Cadastrar Formando</button>
            <button type="button" class="formatura-btn formatura-btn--info" disabled>🖨️ Imprimir Formandos</button>
            <button type="button" class="formatura-btn formatura-btn--warning" disabled>📊 Relatório de Pagamentos</button>
            <button type="button" class="formatura-btn formatura-btn--dark" disabled>⚙️ Configurações</button>
            <input class="formatura-search" id="formaturaBusca" type="search" placeholder="Pesquisar...">
        </div>

        <?php if (empty($formandos)): ?>
            <div class="formatura-empty">Nenhum formando cadastrado ainda.</div>
        <?php else: ?>
            <div class="formatura-table-wrap">
                <table class="formatura-table" id="formaturaTabela">
                    <thead>
                        <tr>
                            <th>Nome/CPF</th>
                            <th>Contato</th>
                            <th>Convidados/Mesas</th>
                            <th>Financeiro</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($formandos as $formando): ?>
                            <?php
                            $saldo = (float)$formando['total_lancado'] - (float)$formando['total_pago'];
                            $rowSearch = strtolower(implode(' ', [
                                $formando['nome_formando'] ?? '',
                                $formando['cliente_nome'] ?? '',
                                $formando['cliente_documento'] ?? '',
                                $formando['cliente_email'] ?? '',
                                $formando['cliente_telefone'] ?? '',
                            ]));
                            ?>
                            <tr data-search="<?= eventos_formatura_e($rowSearch) ?>">
                                <td>
                                    <strong><?= eventos_formatura_e((string)$formando['nome_formando']) ?></strong>
                                    <div class="formatura-muted"><?= eventos_formatura_e((string)$formando['cliente_nome']) ?></div>
                                    <?php if (!empty($formando['cliente_documento'])): ?>
                                        <div class="formatura-muted"><?= eventos_formatura_e((string)$formando['cliente_documento']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><?= eventos_formatura_e((string)($formando['cliente_telefone'] ?? '-')) ?></div>
                                    <div class="formatura-muted"><?= eventos_formatura_e((string)($formando['cliente_email'] ?? '')) ?></div>
                                </td>
                                <td>
                                    <strong><?= (int)$formando['convidados'] ?> convidados</strong>
                                    <div class="formatura-muted"><?= (int)$formando['mesas'] ?> mesas</div>
                                </td>
                                <td>
                                    <strong>R$ <?= number_format((float)$formando['total_lancado'], 2, ',', '.') ?></strong>
                                    <div class="formatura-muted">Pago: R$ <?= number_format((float)$formando['total_pago'], 2, ',', '.') ?></div>
                                    <div class="formatura-muted">Saldo: R$ <?= number_format($saldo, 2, ',', '.') ?></div>
                                </td>
                                <td>
                                    <div class="formatura-actions">
                                        <button type="button" class="formatura-icon-btn" title="Gerar documento" disabled>📄</button>
                                        <button type="button" class="formatura-icon-btn btnFinanceiro" title="Financeiro" data-formando-id="<?= (int)$formando['id'] ?>" data-formando-nome="<?= eventos_formatura_e((string)$formando['nome_formando']) ?>">$</button>
                                        <button
                                            type="button"
                                            class="formatura-icon-btn btnEditarFormando"
                                            title="Editar"
                                            data-id="<?= (int)$formando['id'] ?>"
                                            data-cliente-id="<?= (int)$formando['cliente_cadastro_id'] ?>"
                                            data-nome-formando="<?= eventos_formatura_e((string)$formando['nome_formando']) ?>"
                                            data-convidados="<?= (int)$formando['convidados'] ?>"
                                            data-mesas="<?= (int)$formando['mesas'] ?>"
                                        >✎</button>
                                        <form method="post" onsubmit="return confirm('Excluir este formando?');">
                                            <input type="hidden" name="action" value="delete_formando">
                                            <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
                                            <input type="hidden" name="formando_id" value="<?= (int)$formando['id'] ?>">
                                            <button type="submit" class="formatura-icon-btn" title="Excluir">🗑️</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<div class="formatura-modal" id="modalFormando" aria-hidden="true">
    <div class="formatura-modal-dialog">
        <div class="formatura-modal-head">
            <h3 id="modalFormandoTitulo">Cadastrar Formando</h3>
            <button type="button" class="formatura-close" data-close-modal>×</button>
        </div>
        <form method="post" id="formFormando">
            <div class="formatura-modal-body">
                <input type="hidden" name="action" value="save_formando">
                <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
                <input type="hidden" name="formando_id" id="formandoId" value="">
                <input type="hidden" name="cliente_cadastro_id" id="clienteCadastroId" value="">
                <div class="formatura-grid">
                    <div class="formatura-field full">
                        <label for="clienteBusca">Cliente</label>
                        <input id="clienteBusca" type="text" list="clientesList" placeholder="Digite para buscar cliente" autocomplete="off" required>
                        <datalist id="clientesList">
                            <?php foreach ($clientesJson as $cliente): ?>
                                <option value="<?= eventos_formatura_e((string)$cliente['label']) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <div class="cliente-preview" id="clientePreview">Selecione um cliente cadastrado.</div>
                    </div>
                    <div class="formatura-field">
                        <label for="nomeFormando">Nome do formando</label>
                        <input id="nomeFormando" name="nome_formando" type="text" required>
                    </div>
                    <div class="formatura-field">
                        <label for="convidados">Quantia de convidados</label>
                        <input id="convidados" name="convidados" type="number" min="0" step="1" value="0" required>
                    </div>
                    <div class="formatura-field">
                        <label for="mesas">Quantia de mesas</label>
                        <input id="mesas" name="mesas" type="number" min="0" step="1" value="0" required>
                    </div>
                </div>
            </div>
            <div class="formatura-modal-actions">
                <button type="button" class="formatura-btn formatura-btn--light" data-close-modal>Cancelar</button>
                <button type="submit" class="formatura-btn formatura-btn--primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<div class="formatura-modal" id="modalFinanceiro" aria-hidden="true">
    <div class="formatura-modal-dialog">
        <div class="formatura-modal-head">
            <h3 id="modalFinanceiroTitulo">Financeiro do formando</h3>
            <button type="button" class="formatura-close" data-close-modal>×</button>
        </div>
        <form method="post">
            <div class="formatura-modal-body">
                <input type="hidden" name="action" value="save_financeiro">
                <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
                <input type="hidden" name="formando_id" id="financeiroFormandoId" value="">
                <div class="formatura-grid">
                    <div class="formatura-field full">
                        <label for="financeiroDescricao">Descrição</label>
                        <textarea id="financeiroDescricao" name="descricao" placeholder="Ex.: Entrada do formando, parcela, adicional..." required></textarea>
                    </div>
                    <div class="formatura-field">
                        <label for="financeiroValor">Valor</label>
                        <input id="financeiroValor" name="valor" type="text" inputmode="decimal" placeholder="0,00" required>
                    </div>
                    <div class="formatura-field">
                        <label for="financeiroVencimento">Vencimento</label>
                        <input id="financeiroVencimento" name="vencimento" type="date">
                    </div>
                    <div class="formatura-field">
                        <label for="financeiroStatus">Status</label>
                        <select id="financeiroStatus" name="status">
                            <option value="pendente">Pendente</option>
                            <option value="pago">Pago</option>
                            <option value="cancelado">Cancelado</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="formatura-modal-actions">
                <button type="button" class="formatura-btn formatura-btn--light" data-close-modal>Cancelar</button>
                <button type="submit" class="formatura-btn formatura-btn--primary">Salvar lançamento</button>
            </div>
        </form>
    </div>
</div>

<script>
const clientesFormatura = <?= json_encode($clientesJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function openModal(modal) {
    if (!modal) return;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
}

function closeModal(modal) {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
}

function clienteById(id) {
    return clientesFormatura.find((cliente) => Number(cliente.id) === Number(id)) || null;
}

function clienteByLabel(label) {
    const normalized = String(label || '').trim().toLowerCase();
    return clientesFormatura.find((cliente) => String(cliente.label || '').trim().toLowerCase() === normalized) || null;
}

function setCliente(cliente) {
    const hidden = document.getElementById('clienteCadastroId');
    const input = document.getElementById('clienteBusca');
    const preview = document.getElementById('clientePreview');
    if (!cliente) {
        hidden.value = '';
        preview.textContent = 'Selecione um cliente cadastrado.';
        return;
    }
    hidden.value = cliente.id;
    input.value = cliente.label;
    preview.innerHTML = `<strong>${cliente.nome}</strong><br>${cliente.telefone || '-'} ${cliente.email ? ' · ' + cliente.email : ''} ${cliente.documento ? ' · CPF: ' + cliente.documento : ''}`;
}

document.querySelectorAll('[data-close-modal]').forEach((btn) => {
    btn.addEventListener('click', () => closeModal(btn.closest('.formatura-modal')));
});

document.querySelectorAll('.formatura-modal').forEach((modal) => {
    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal(modal);
    });
});

const modalFormando = document.getElementById('modalFormando');
const formFormando = document.getElementById('formFormando');

document.getElementById('btnNovoFormando')?.addEventListener('click', () => {
    formFormando.reset();
    document.getElementById('formandoId').value = '';
    document.getElementById('clienteCadastroId').value = '';
    document.getElementById('clientePreview').textContent = 'Selecione um cliente cadastrado.';
    document.getElementById('modalFormandoTitulo').textContent = 'Cadastrar Formando';
    openModal(modalFormando);
});

document.getElementById('clienteBusca')?.addEventListener('input', (event) => {
    setCliente(clienteByLabel(event.target.value));
});

document.querySelectorAll('.btnEditarFormando').forEach((btn) => {
    btn.addEventListener('click', () => {
        formFormando.reset();
        document.getElementById('modalFormandoTitulo').textContent = 'Editar Formando';
        document.getElementById('formandoId').value = btn.dataset.id || '';
        document.getElementById('nomeFormando').value = btn.dataset.nomeFormando || '';
        document.getElementById('convidados').value = btn.dataset.convidados || '0';
        document.getElementById('mesas').value = btn.dataset.mesas || '0';
        setCliente(clienteById(btn.dataset.clienteId || 0));
        openModal(modalFormando);
    });
});

const modalFinanceiro = document.getElementById('modalFinanceiro');
document.querySelectorAll('.btnFinanceiro').forEach((btn) => {
    btn.addEventListener('click', () => {
        document.getElementById('financeiroFormandoId').value = btn.dataset.formandoId || '';
        document.getElementById('modalFinanceiroTitulo').textContent = `Financeiro - ${btn.dataset.formandoNome || 'Formando'}`;
        document.getElementById('financeiroDescricao').value = '';
        document.getElementById('financeiroValor').value = '';
        document.getElementById('financeiroVencimento').value = '';
        document.getElementById('financeiroStatus').value = 'pendente';
        openModal(modalFinanceiro);
    });
});

document.getElementById('financeiroValor')?.addEventListener('blur', (event) => {
    const raw = String(event.target.value || '').replace(/[R$\s]/g, '').replace(/\./g, '').replace(',', '.');
    const value = Number(raw);
    if (!Number.isFinite(value) || value <= 0) return;
    event.target.value = value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
});

document.getElementById('formaturaBusca')?.addEventListener('input', (event) => {
    const term = String(event.target.value || '').trim().toLowerCase();
    document.querySelectorAll('#formaturaTabela tbody tr').forEach((row) => {
        row.style.display = !term || String(row.dataset.search || '').includes(term) ? '' : 'none';
    });
});
</script>

<?php endSidebar(); ?>
