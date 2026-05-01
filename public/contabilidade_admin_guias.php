<?php
// contabilidade_admin_guias.php — Gestão administrativa de Guias
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado']) || empty($_SESSION['perm_administrativo'])) {
    header('Location: index.php?page=login');
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/notificacoes_helper.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/magalu_storage_helper.php';

function normalizarStatusGuia(?string $status): string
{
    return trim((string)$status) === 'pago' ? 'pago' : 'aberto';
}

function determinarAbaGuia(?string $dataVencimento, ?string $status, DateTimeImmutable $hoje): string
{
    if (normalizarStatusGuia($status) === 'pago') {
        return 'pagas';
    }

    if (empty($dataVencimento)) {
        return 'a_vencer';
    }

    try {
        $vencimento = new DateTimeImmutable($dataVencimento);
    } catch (Throwable $e) {
        return 'a_vencer';
    }

    $vencimento = $vencimento->setTime(0, 0, 0);
    $limiteProximos7Dias = $hoje->modify('+7 days');

    if ($vencimento < $hoje) {
        return 'vencidas';
    }
    if ($vencimento == $hoje) {
        return 'vencem_hoje';
    }
    if ($vencimento <= $limiteProximos7Dias) {
        return 'proximos_7_dias';
    }

    return 'a_vencer';
}

function classeLinhaGuia(string $aba): string
{
    $classes = [
        'vencidas' => 'row-vencidas',
        'vencem_hoje' => 'row-vencem-hoje',
        'proximos_7_dias' => 'row-proximos-7-dias',
        'a_vencer' => 'row-a-vencer',
        'pagas' => 'row-pagas',
    ];

    return $classes[$aba] ?? 'row-a-vencer';
}

function rotuloStatusGuia(?string $status): string
{
    return normalizarStatusGuia($status) === 'pago' ? 'Pago' : 'Aberto';
}

function escapeAttr(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function ordenarGuiasPorAba(array &$guias, string $aba): void
{
    usort($guias, static function (array $a, array $b) use ($aba): int {
        if ($aba === 'pagas') {
            $aTime = strtotime((string)($a['atualizado_em'] ?? $a['data_vencimento'] ?? '')) ?: 0;
            $bTime = strtotime((string)($b['atualizado_em'] ?? $b['data_vencimento'] ?? '')) ?: 0;

            if ($aTime !== $bTime) {
                return $bTime <=> $aTime;
            }
        } else {
            $aTime = strtotime((string)($a['data_vencimento'] ?? '9999-12-31')) ?: PHP_INT_MAX;
            $bTime = strtotime((string)($b['data_vencimento'] ?? '9999-12-31')) ?: PHP_INT_MAX;

            if ($aTime !== $bTime) {
                return $aTime <=> $bTime;
            }
        }

        return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
    });
}

function renderGuiaRow(array $guia, DateTimeImmutable $hoje): string
{
    $aba = determinarAbaGuia($guia['data_vencimento'] ?? null, $guia['status'] ?? null, $hoje);
    $statusNormalizado = normalizarStatusGuia($guia['status'] ?? null);
    $empresaNome = trim((string)($guia['empresa_nome'] ?? ''));
    $descricao = trim((string)($guia['descricao'] ?? ''));
    $arquivoNome = trim((string)($guia['arquivo_nome'] ?? 'arquivo'));
    $vencimentoIso = trim((string)($guia['data_vencimento'] ?? ''));
    $vencimentoExibicao = $vencimentoIso !== '' ? date('d/m/Y', strtotime($vencimentoIso)) : '-';
    $parcelaTexto = '-';

    if (!empty($guia['e_parcela']) && !empty($guia['numero_parcela'])) {
        $parcelaTexto = 'Parcela ' . (int)$guia['numero_parcela'] . '/' . (int)($guia['total_parcelas'] ?? 0);
    }

    $arquivoHtml = '-';
    if (!empty($guia['chave_storage']) || !empty($guia['arquivo_url'])) {
        $arquivoHtml = '<button type="button" class="btn-action btn-download btn-ver-arquivo"'
            . ' data-guia-id="' . (int)$guia['id'] . '"'
            . ' data-arquivo-nome="' . escapeAttr($arquivoNome) . '">📎 Ver</button>';
    }

    ob_start();
    ?>
    <tr
        id="guia-row-<?= (int)$guia['id'] ?>"
        class="guia-row <?= classeLinhaGuia($aba) ?>"
        data-id="<?= (int)$guia['id'] ?>"
        data-tab="<?= escapeAttr($aba) ?>"
        data-due-date="<?= escapeAttr($vencimentoIso) ?>"
        data-updated-at="<?= escapeAttr((string)($guia['atualizado_em'] ?? '')) ?>"
    >
        <td><?= $empresaNome !== '' ? htmlspecialchars($empresaNome) : '-' ?></td>
        <td><?= htmlspecialchars($descricao) ?></td>
        <td><?= htmlspecialchars($vencimentoExibicao) ?></td>
        <td><?= htmlspecialchars($parcelaTexto) ?></td>
        <td>
            <span class="badge badge-<?= $statusNormalizado ?>">
                <?= rotuloStatusGuia($statusNormalizado) ?>
            </span>
        </td>
        <td><?= $arquivoHtml ?></td>
        <td>
            <form method="POST" class="status-form" style="display: inline;">
                <input type="hidden" name="acao" value="alterar_status">
                <input type="hidden" name="id" value="<?= (int)$guia['id'] ?>">
                <select name="status" class="status-select js-status-select">
                    <option value="aberto" <?= $statusNormalizado === 'aberto' ? 'selected' : '' ?>>Aberto</option>
                    <option value="pago" <?= $statusNormalizado === 'pago' ? 'selected' : '' ?>>Pago</option>
                </select>
            </form>
            <form method="POST" style="display: inline;" onsubmit="return confirm('Tem certeza que deseja excluir esta guia? Esta ação remove a guia definitivamente.');">
                <input type="hidden" name="acao" value="excluir">
                <input type="hidden" name="id" value="<?= (int)$guia['id'] ?>">
                <button type="submit" class="btn-action btn-delete">🗑️ Excluir</button>
            </form>
        </td>
    </tr>
    <?php

    return (string)ob_get_clean();
}

function renderEmptyRow(string $mensagem): string
{
    return '<tr class="empty-state"><td colspan="7" style="text-align:center; padding:2.5rem; color:#64748b;">'
        . htmlspecialchars($mensagem)
        . '</td></tr>';
}

$mensagem = '';
$erro = '';
$hoje = new DateTimeImmutable('today');
$isAjax = ($_SERVER['REQUEST_METHOD'] === 'POST' && (
    (!empty($_POST['ajax']) && $_POST['ajax'] === '1')
    || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'alterar_status') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $status = normalizarStatusGuia($_POST['status'] ?? '');

            if ($id <= 0) {
                throw new Exception('Guia inválida.');
            }

            $stmtBusca = $pdo->prepare("
                SELECT id, data_vencimento
                FROM contabilidade_guias
                WHERE id = :id
            ");
            $stmtBusca->execute([':id' => $id]);
            $guiaAtual = $stmtBusca->fetch(PDO::FETCH_ASSOC);

            if (!$guiaAtual) {
                throw new Exception('Guia não encontrada.');
            }

            $stmt = $pdo->prepare("
                UPDATE contabilidade_guias
                SET status = :status, atualizado_em = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':status' => $status,
                ':id' => $id,
            ]);

            try {
                $notificacoes = new NotificacoesHelper();
                $notificacoes->registrarNotificacao(
                    'contabilidade',
                    'alteracao_status',
                    'guia',
                    $id,
                    'Status da guia alterado para: ' . rotuloStatusGuia($status),
                    '',
                    'ambos'
                );
            } catch (Exception $e) {
            }

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'id' => $id,
                    'status' => $status,
                    'tab' => determinarAbaGuia($guiaAtual['data_vencimento'] ?? null, $status, $hoje),
                    'updated_at' => date('c'),
                    'message' => 'Status atualizado com sucesso.',
                ]);
                exit;
            }

            $mensagem = 'Status atualizado com sucesso!';
        } catch (Exception $e) {
            if ($isAjax) {
                http_response_code(422);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => $e->getMessage(),
                ]);
                exit;
            }

            $erro = $e->getMessage();
        }
    }

    if ($acao === 'excluir') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Guia inválida.');
            }

            $stmtBusca = $pdo->prepare("
                SELECT chave_storage
                FROM contabilidade_guias
                WHERE id = :id
            ");
            $stmtBusca->execute([':id' => $id]);
            $guiaAtual = $stmtBusca->fetch(PDO::FETCH_ASSOC);

            if (!$guiaAtual) {
                throw new Exception('Guia não encontrada.');
            }

            $stmt = $pdo->prepare("DELETE FROM contabilidade_guias WHERE id = :id");
            $stmt->execute([':id' => $id]);

            $alertaStorage = '';
            $chaveStorage = trim((string)($guiaAtual['chave_storage'] ?? ''));
            if ($chaveStorage !== '') {
                try {
                    $storage = new MagaluStorageHelper();
                    $resultadoStorage = $storage->deleteFile($chaveStorage);
                    if (empty($resultadoStorage['success'])) {
                        $alertaStorage = ' A guia foi excluída, mas o arquivo no storage não pôde ser removido.';
                    }
                } catch (Throwable $e) {
                    $alertaStorage = ' A guia foi excluída, mas houve falha ao remover o arquivo do storage.';
                }
            }

            $mensagem = 'Guia excluída com sucesso!' . $alertaStorage;
        } catch (Exception $e) {
            $erro = $e->getMessage();
        }
    }
}

$empresas = [];
try {
    $stmt = $pdo->query("
        SELECT *
        FROM contabilidade_empresas
        WHERE ativo = TRUE
        ORDER BY nome ASC
    ");
    $empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

$filtroMes = trim((string)($_GET['mes'] ?? ''));
$filtroTipo = trim((string)($_GET['tipo'] ?? ''));
$filtroEmpresa = trim((string)($_GET['empresa'] ?? ''));

$whereConditions = [];
$params = [];

if ($filtroMes !== '') {
    $whereConditions[] = "TO_CHAR(g.data_vencimento, 'YYYY-MM') = :mes";
    $params[':mes'] = $filtroMes;
}

if ($filtroTipo !== '') {
    if ($filtroTipo === 'parcela') {
        $whereConditions[] = 'g.e_parcela = TRUE';
    } elseif ($filtroTipo === 'unica') {
        $whereConditions[] = 'g.e_parcela = FALSE';
    }
}

if ($filtroEmpresa !== '') {
    $whereConditions[] = 'g.empresa_id = :empresa';
    $params[':empresa'] = (int)$filtroEmpresa;
}

$whereSql = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

$guias = [];
try {
    $sql = "
        SELECT g.*, p.total_parcelas, e.nome AS empresa_nome, e.documento AS empresa_documento
        FROM contabilidade_guias g
        LEFT JOIN contabilidade_parcelamentos p ON p.id = g.parcelamento_id
        LEFT JOIN contabilidade_empresas e ON e.id = g.empresa_id
        $whereSql
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $guias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $erro = 'Erro ao buscar guias: ' . $e->getMessage();
}

$abasInfo = [
    'vencidas' => ['label' => 'Vencidas', 'empty' => 'Nenhuma guia vencida em aberto.'],
    'vencem_hoje' => ['label' => 'Vencem hoje', 'empty' => 'Nenhuma guia vence hoje.'],
    'proximos_7_dias' => ['label' => 'Próximos 7 dias', 'empty' => 'Nenhuma guia vence nos próximos 7 dias.'],
    'a_vencer' => ['label' => 'A vencer', 'empty' => 'Nenhuma guia futura em aberto.'],
    'pagas' => ['label' => 'Pagas', 'empty' => 'Nenhuma guia paga encontrada.'],
];

$guiasPorAba = array_fill_keys(array_keys($abasInfo), []);
foreach ($guias as $guia) {
    $aba = determinarAbaGuia($guia['data_vencimento'] ?? null, $guia['status'] ?? null, $hoje);
    $guiasPorAba[$aba][] = $guia;
}

foreach ($guiasPorAba as $aba => &$lista) {
    ordenarGuiasPorAba($lista, $aba);
}
unset($lista);

$contadores = [];
foreach ($guiasPorAba as $aba => $lista) {
    $contadores[$aba] = count($lista);
}

$abaSolicitada = trim((string)($_GET['aba'] ?? ''));
$abaPadrao = 'a_vencer';
foreach (['vencidas', 'vencem_hoje', 'proximos_7_dias', 'a_vencer'] as $abaPrioritaria) {
    if (!empty($contadores[$abaPrioritaria])) {
        $abaPadrao = $abaPrioritaria;
        break;
    }
}
if ($abaPadrao === 'a_vencer' && empty($contadores['a_vencer']) && !empty($contadores['pagas'])) {
    $abaPadrao = 'pagas';
}

$abaAtiva = array_key_exists($abaSolicitada, $abasInfo) ? $abaSolicitada : $abaPadrao;

function montarQueryAba(string $aba, string $filtroMes, string $filtroTipo, string $filtroEmpresa): string
{
    $params = ['aba' => $aba];
    if ($filtroMes !== '') {
        $params['mes'] = $filtroMes;
    }
    if ($filtroTipo !== '') {
        $params['tipo'] = $filtroTipo;
    }
    if ($filtroEmpresa !== '') {
        $params['empresa'] = $filtroEmpresa;
    }

    return http_build_query($params);
}

ob_start();
?>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #f5f7fb;
}
.container { max-width: 1440px; margin: 0 auto; padding: 2rem; }
.header {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    color: #fff;
    padding: 1.5rem 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.5rem;
    border-radius: 14px;
}
.header h1 { font-size: 1.45rem; font-weight: 700; }
.header p { margin-top: 0.35rem; color: rgba(255,255,255,0.82); font-size: 0.95rem; }
.btn-back {
    background: rgba(255,255,255,0.16);
    color: #fff;
    padding: 0.65rem 1rem;
    border-radius: 8px;
    text-decoration: none;
    white-space: nowrap;
}
.alert {
    padding: 1rem 1.1rem;
    border-radius: 10px;
    margin-bottom: 1rem;
    font-size: 0.95rem;
}
.alert-success { background: #dcfce7; color: #166534; }
.alert-error { background: #fee2e2; color: #991b1b; }
.toolbar-card,
.table-card {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.07);
}
.toolbar-card {
    padding: 1.25rem;
    margin-bottom: 1rem;
}
.tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-bottom: 1.25rem;
}
.tab-link {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    padding: 0.8rem 1rem;
    border-radius: 10px;
    border: 1px solid #dbeafe;
    background: #eff6ff;
    color: #1d4ed8;
    text-decoration: none;
    font-weight: 600;
    transition: 0.2s ease;
}
.tab-link:hover { background: #dbeafe; }
.tab-link.active {
    background: #1d4ed8;
    color: #fff;
    border-color: #1d4ed8;
}
.tab-count {
    min-width: 1.75rem;
    padding: 0.15rem 0.45rem;
    border-radius: 999px;
    background: rgba(255,255,255,0.65);
    color: inherit;
    text-align: center;
    font-size: 0.82rem;
}
.tab-link.active .tab-count {
    background: rgba(255,255,255,0.2);
}
.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
}
.form-group { display: flex; flex-direction: column; }
.form-label {
    font-weight: 600;
    color: #334155;
    margin-bottom: 0.5rem;
}
.form-input,
.form-select,
.status-select {
    width: 100%;
    padding: 0.75rem 0.85rem;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.95rem;
    background: #fff;
}
.filters-actions {
    display: flex;
    align-items: end;
    gap: 0.75rem;
}
.btn-filter-clear {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.75rem 1rem;
    border-radius: 8px;
    background: #e2e8f0;
    color: #334155;
    text-decoration: none;
    font-weight: 600;
}
.table-card { overflow: hidden; }
.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #e2e8f0;
}
.table-header h2 {
    font-size: 1.1rem;
    color: #0f172a;
}
.table-header span {
    color: #64748b;
    font-size: 0.92rem;
}
.table-responsive { overflow-x: auto; }
.table {
    width: 100%;
    border-collapse: collapse;
    min-width: 980px;
}
.table th {
    background: #1e40af;
    color: #fff;
    padding: 0.95rem 1rem;
    text-align: left;
    font-weight: 700;
    font-size: 0.92rem;
}
.table td {
    padding: 0.95rem 1rem;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: middle;
}
.guia-row { transition: background 0.2s ease, opacity 0.2s ease; }
.row-vencidas { background: #fee2e2; }
.row-vencem-hoje { background: #ffedd5; }
.row-proximos-7-dias { background: #fef9c3; }
.row-a-vencer { background: #fff; }
.row-pagas { background: #dcfce7; }
.badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.35rem 0.8rem;
    border-radius: 999px;
    font-size: 0.85rem;
    font-weight: 700;
}
.badge-aberto { background: #dbeafe; color: #1e3a8a; }
.badge-pago { background: #bbf7d0; color: #166534; }
.btn-action {
    padding: 0.6rem 0.85rem;
    border: none;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    margin-right: 0.45rem;
}
.btn-download { background: #334155; color: #fff; }
.btn-delete { background: #dc2626; color: #fff; }
.tab-pane { display: none; }
.tab-pane.active { display: block; }
.table-note {
    padding: 0 1.25rem 1rem;
    color: #64748b;
    font-size: 0.9rem;
}
@media (max-width: 768px) {
    .container { padding: 1rem; }
    .header {
        flex-direction: column;
        align-items: flex-start;
    }
    .tabs { flex-direction: column; }
    .tab-link { width: 100%; justify-content: space-between; }
    .filters-actions { align-items: stretch; }
}
</style>

<div class="container">
    <div class="header">
        <div>
            <h1>Guias para Pagamento - Gestão Administrativa</h1>
            <p>Organização por prioridade de vencimento, com foco no que precisa ser pago primeiro.</p>
        </div>
        <a href="index.php?page=contabilidade" class="btn-back">← Voltar</a>
    </div>

    <div id="page-alerts">
        <?php if ($mensagem): ?>
        <div class="alert alert-success"><?= htmlspecialchars($mensagem) ?></div>
        <?php endif; ?>

        <?php if ($erro): ?>
        <div class="alert alert-error"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>
    </div>

    <div class="toolbar-card">
        <div class="tabs" id="tabs-guias">
            <?php foreach ($abasInfo as $aba => $info): ?>
                <a
                    href="?<?= htmlspecialchars(montarQueryAba($aba, $filtroMes, $filtroTipo, $filtroEmpresa)) ?>"
                    class="tab-link <?= $aba === $abaAtiva ? 'active' : '' ?>"
                    data-tab-link="<?= htmlspecialchars($aba) ?>"
                >
                    <span><?= htmlspecialchars($info['label']) ?></span>
                    <span class="tab-count" data-tab-count="<?= htmlspecialchars($aba) ?>"><?= (int)$contadores[$aba] ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="GET" id="filtros-form">
            <input type="hidden" name="aba" id="aba-input" value="<?= htmlspecialchars($abaAtiva) ?>">
            <div class="filters-grid">
                <div class="form-group">
                    <label class="form-label">Empresa</label>
                    <select name="empresa" class="form-select" onchange="this.form.submit()">
                        <option value="">Todas</option>
                        <?php foreach ($empresas as $empresa): ?>
                            <option value="<?= (int)$empresa['id'] ?>" <?= $filtroEmpresa !== '' && (int)$filtroEmpresa === (int)$empresa['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)$empresa['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Mês</label>
                    <input type="month" name="mes" class="form-input" value="<?= htmlspecialchars($filtroMes) ?>" onchange="this.form.submit()">
                </div>
                <div class="form-group">
                    <label class="form-label">Tipo</label>
                    <select name="tipo" class="form-select" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <option value="parcela" <?= $filtroTipo === 'parcela' ? 'selected' : '' ?>>Parcelada</option>
                        <option value="unica" <?= $filtroTipo === 'unica' ? 'selected' : '' ?>>Única</option>
                    </select>
                </div>
                <div class="filters-actions">
                    <a href="?<?= htmlspecialchars(http_build_query(['aba' => $abaPadrao])) ?>" class="btn-filter-clear">Limpar filtros</a>
                </div>
            </div>
        </form>
    </div>

    <?php foreach ($abasInfo as $aba => $info): ?>
        <section class="table-card tab-pane <?= $aba === $abaAtiva ? 'active' : '' ?>" data-tab-pane="<?= htmlspecialchars($aba) ?>">
            <div class="table-header">
                <div>
                    <h2><?= htmlspecialchars($info['label']) ?></h2>
                    <span><?= (int)$contadores[$aba] ?> guia(s) nesta aba</span>
                </div>
            </div>
            <div class="table-note">
                <?php if ($aba === 'pagas'): ?>
                    Ordenação: pagamento mais recente primeiro.
                <?php else: ?>
                    Ordenação: vencimento mais próximo primeiro.
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Empresa</th>
                            <th>Descrição</th>
                            <th>Vencimento</th>
                            <th>Parcela</th>
                            <th>Status</th>
                            <th>Arquivo</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody data-tab-body="<?= htmlspecialchars($aba) ?>">
                        <?php if (empty($guiasPorAba[$aba])): ?>
                            <?= renderEmptyRow($info['empty']) ?>
                        <?php else: ?>
                            <?php foreach ($guiasPorAba[$aba] as $guia): ?>
                                <?= renderGuiaRow($guia, $hoje) ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<div id="modal-arquivo" class="modal-arquivo" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 10000; overflow: auto;">
    <div class="modal-arquivo-header" style="position: fixed; top: 0; left: 0; right: 0; background: rgba(0,0,0,0.8); padding: 1rem; display: flex; justify-content: space-between; align-items: center; z-index: 10001;">
        <h3 id="modal-arquivo-titulo" style="color: white; margin: 0; font-size: 1.1rem;"></h3>
        <div class="modal-arquivo-toolbar" style="display: flex; gap: 0.5rem;">
            <button id="btn-zoom-in" class="btn-toolbar" style="display: none; background: #1e40af; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer;">🔍+</button>
            <button id="btn-zoom-out" class="btn-toolbar" style="display: none; background: #1e40af; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer;">🔍-</button>
            <button id="btn-zoom-reset" class="btn-toolbar" style="display: none; background: #1e40af; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer;">↺</button>
            <button id="btn-download" class="btn-toolbar" style="background: #10b981; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer;">⬇️ Download</button>
            <button id="btn-fechar-modal" class="btn-toolbar" style="background: #ef4444; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer;">✕ Fechar</button>
        </div>
    </div>
    <div class="modal-arquivo-content" style="margin-top: 80px; padding: 2rem; display: flex; justify-content: center; align-items: center; min-height: calc(100vh - 80px);">
        <img id="modal-arquivo-img" style="display: none; max-width: 100%; max-height: calc(100vh - 120px); object-fit: contain; transition: transform 0.3s ease; cursor: zoom-in;" />
        <iframe id="modal-arquivo-pdf" style="display: none; width: 100%; height: calc(100vh - 120px); border: none; background: white;"></iframe>
        <div id="modal-arquivo-loading" style="color: white; font-size: 1.2rem;">Carregando...</div>
    </div>
</div>

<script>
    (function() {
        const emptyMessages = <?= json_encode(array_map(static function ($aba) { return $aba['empty']; }, $abasInfo)) ?>;
        const alertContainer = document.getElementById('page-alerts');
        const abaInput = document.getElementById('aba-input');

        function showAlert(type, message) {
            if (!alertContainer) return;
            alertContainer.innerHTML = `<div class="alert ${type === 'error' ? 'alert-error' : 'alert-success'}">${message}</div>`;
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function updateTabCount(tab, delta) {
            const counter = document.querySelector(`[data-tab-count="${tab}"]`);
            const pane = document.querySelector(`[data-tab-pane="${tab}"] .table-header span`);
            if (!counter) return;
            const nextValue = Math.max(0, (parseInt(counter.textContent, 10) || 0) + delta);
            counter.textContent = String(nextValue);
            if (pane) {
                pane.textContent = `${nextValue} guia(s) nesta aba`;
            }
        }

        function ensureEmptyState(tab) {
            const tbody = document.querySelector(`[data-tab-body="${tab}"]`);
            if (!tbody) return;
            const rows = tbody.querySelectorAll('tr.guia-row');
            const emptyState = tbody.querySelector('.empty-state');

            if (rows.length === 0 && !emptyState) {
                tbody.insertAdjacentHTML('beforeend', `<tr class="empty-state"><td colspan="7" style="text-align:center; padding:2.5rem; color:#64748b;">${escapeHtml(emptyMessages[tab] || 'Nenhum registro encontrado.')}</td></tr>`);
            }

            if (rows.length > 0 && emptyState) {
                emptyState.remove();
            }
        }

        function compareRows(rowA, rowB, targetTab) {
            if (targetTab === 'pagas') {
                const aUpdated = new Date(rowA.dataset.updatedAt || rowA.dataset.dueDate || 0).getTime() || 0;
                const bUpdated = new Date(rowB.dataset.updatedAt || rowB.dataset.dueDate || 0).getTime() || 0;
                if (aUpdated !== bUpdated) {
                    return bUpdated - aUpdated;
                }
            } else {
                const aDue = new Date(rowA.dataset.dueDate || '9999-12-31').getTime();
                const bDue = new Date(rowB.dataset.dueDate || '9999-12-31').getTime();
                if (aDue !== bDue) {
                    return aDue - bDue;
                }
            }

            const aId = parseInt(rowA.dataset.id || '0', 10);
            const bId = parseInt(rowB.dataset.id || '0', 10);
            return bId - aId;
        }

        function insertSortedRow(row, targetTab) {
            const tbody = document.querySelector(`[data-tab-body="${targetTab}"]`);
            if (!tbody) return;

            const emptyState = tbody.querySelector('.empty-state');
            if (emptyState) {
                emptyState.remove();
            }

            const rows = Array.from(tbody.querySelectorAll('tr.guia-row'));
            const nextRow = rows.find(existingRow => compareRows(row, existingRow, targetTab) < 0);

            if (nextRow) {
                tbody.insertBefore(row, nextRow);
            } else {
                tbody.appendChild(row);
            }
        }

        function updateRowVisual(row, status, targetTab, updatedAt) {
            row.dataset.tab = targetTab;
            if (updatedAt) {
                row.dataset.updatedAt = updatedAt;
            }

            row.classList.remove('row-vencidas', 'row-vencem-hoje', 'row-proximos-7-dias', 'row-a-vencer', 'row-pagas');
            row.classList.add(
                targetTab === 'vencidas' ? 'row-vencidas' :
                targetTab === 'vencem_hoje' ? 'row-vencem-hoje' :
                targetTab === 'proximos_7_dias' ? 'row-proximos-7-dias' :
                targetTab === 'pagas' ? 'row-pagas' : 'row-a-vencer'
            );

            const badge = row.querySelector('.badge');
            if (badge) {
                badge.className = `badge badge-${status}`;
                badge.textContent = status === 'pago' ? 'Pago' : 'Aberto';
            }

            const select = row.querySelector('.js-status-select');
            if (select) {
                select.value = status;
            }
        }

        async function handleStatusChange(select) {
            const form = select.closest('form');
            const row = select.closest('tr.guia-row');
            if (!form || !row) return;

            const previousTab = row.dataset.tab;
            const previousStatus = row.querySelector('.badge')?.textContent?.trim().toLowerCase() === 'pago' ? 'pago' : 'aberto';
            const formData = new FormData(form);
            formData.append('ajax', '1');

            select.disabled = true;

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Erro ao atualizar status.');
                }

                updateRowVisual(row, data.status, data.tab, data.updated_at || '');

                if (previousTab !== data.tab) {
                    row.remove();
                    insertSortedRow(row, data.tab);
                    updateTabCount(previousTab, -1);
                    updateTabCount(data.tab, 1);
                    ensureEmptyState(previousTab);
                    ensureEmptyState(data.tab);
                } else {
                    row.remove();
                    insertSortedRow(row, data.tab);
                    ensureEmptyState(data.tab);
                }

                showAlert('success', escapeHtml(data.message || 'Status atualizado com sucesso.'));
            } catch (error) {
                select.value = previousStatus;
                showAlert('error', escapeHtml(error.message || 'Erro ao atualizar status.'));
            } finally {
                select.disabled = false;
            }
        }

        document.addEventListener('change', function(event) {
            const select = event.target.closest('.js-status-select');
            if (select) {
                handleStatusChange(select);
            }
        });

        document.querySelectorAll('[data-tab-link]').forEach(link => {
            link.addEventListener('click', function(event) {
                event.preventDefault();
                const tab = this.getAttribute('data-tab-link');
                if (!tab) return;

                document.querySelectorAll('[data-tab-link]').forEach(item => item.classList.remove('active'));
                document.querySelectorAll('[data-tab-pane]').forEach(item => item.classList.remove('active'));

                this.classList.add('active');
                const pane = document.querySelector(`[data-tab-pane="${tab}"]`);
                if (pane) {
                    pane.classList.add('active');
                }

                if (abaInput) {
                    abaInput.value = tab;
                }

                const url = new URL(window.location.href);
                url.searchParams.set('aba', tab);
                window.history.replaceState({}, '', url);
            });
        });

        const modal = document.getElementById('modal-arquivo');
        const modalImg = document.getElementById('modal-arquivo-img');
        const modalPdf = document.getElementById('modal-arquivo-pdf');
        const modalTitulo = document.getElementById('modal-arquivo-titulo');
        const modalLoading = document.getElementById('modal-arquivo-loading');
        const btnFechar = document.getElementById('btn-fechar-modal');
        const btnDownload = document.getElementById('btn-download');
        const btnZoomIn = document.getElementById('btn-zoom-in');
        const btnZoomOut = document.getElementById('btn-zoom-out');
        const btnZoomReset = document.getElementById('btn-zoom-reset');

        let currentZoom = 1;
        let currentDownloadUrl = null;
        let isImage = false;

        function abrirModal(guiaId, arquivoNome) {
            currentDownloadUrl = `contabilidade_download.php?tipo=guia&id=${guiaId}`;
            modalTitulo.textContent = arquivoNome || 'Visualizar Arquivo';
            modal.style.display = 'block';
            modalLoading.style.display = 'block';
            modalLoading.textContent = 'Carregando...';
            modalImg.style.display = 'none';
            modalPdf.style.display = 'none';

            const extensao = arquivoNome ? arquivoNome.split('.').pop().toLowerCase() : '';
            isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(extensao);
            const isPdf = extensao === 'pdf';

            if (isImage) {
                btnZoomIn.style.display = 'inline-block';
                btnZoomOut.style.display = 'inline-block';
                btnZoomReset.style.display = 'inline-block';
            } else {
                btnZoomIn.style.display = 'none';
                btnZoomOut.style.display = 'none';
                btnZoomReset.style.display = 'none';
            }

            if (isImage) {
                modalImg.src = currentDownloadUrl;
                modalImg.onload = function() {
                    modalLoading.style.display = 'none';
                    modalImg.style.display = 'block';
                    currentZoom = 1;
                    modalImg.style.transform = `scale(${currentZoom})`;
                };
                modalImg.onerror = function() {
                    modalLoading.textContent = 'Erro ao carregar imagem';
                };
            } else if (isPdf) {
                modalPdf.src = currentDownloadUrl;
                modalPdf.onload = function() {
                    modalLoading.style.display = 'none';
                    modalPdf.style.display = 'block';
                };
            } else {
                modalLoading.textContent = 'Este tipo de arquivo não pode ser visualizado. Use o botão Download.';
            }
        }

        function fecharModal() {
            modal.style.display = 'none';
            modalImg.src = '';
            modalPdf.src = '';
            currentZoom = 1;
            modalImg.style.transform = 'scale(1)';
        }

        document.addEventListener('click', function(e) {
            const botaoVer = e.target.closest('.btn-ver-arquivo');
            if (botaoVer) {
                const guiaId = botaoVer.getAttribute('data-guia-id');
                const arquivoNome = botaoVer.getAttribute('data-arquivo-nome');
                abrirModal(guiaId, arquivoNome);
            }
        });

        if (btnFechar) {
            btnFechar.addEventListener('click', fecharModal);
        }
        if (btnDownload) {
            btnDownload.addEventListener('click', function() {
                if (currentDownloadUrl) {
                    window.open(currentDownloadUrl, '_blank');
                }
            });
        }
        if (btnZoomIn) {
            btnZoomIn.addEventListener('click', function() {
                if (isImage && modalImg) {
                    currentZoom = Math.min(currentZoom + 0.25, 3);
                    modalImg.style.transform = `scale(${currentZoom})`;
                }
            });
        }
        if (btnZoomOut) {
            btnZoomOut.addEventListener('click', function() {
                if (isImage && modalImg) {
                    currentZoom = Math.max(currentZoom - 0.25, 0.5);
                    modalImg.style.transform = `scale(${currentZoom})`;
                }
            });
        }
        if (btnZoomReset) {
            btnZoomReset.addEventListener('click', function() {
                if (isImage && modalImg) {
                    currentZoom = 1;
                    modalImg.style.transform = `scale(${currentZoom})`;
                }
            });
        }

        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    fecharModal();
                }
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal && modal.style.display === 'block') {
                fecharModal();
            }
        });

        if (modalImg) {
            modalImg.addEventListener('wheel', function(e) {
                if (isImage) {
                    e.preventDefault();
                    const delta = e.deltaY > 0 ? -0.1 : 0.1;
                    currentZoom = Math.max(0.5, Math.min(3, currentZoom + delta));
                    modalImg.style.transform = `scale(${currentZoom})`;
                }
            }, { passive: false });
        }
    })();
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Contabilidade - Guias');
echo $conteudo;
endSidebar();
?>
