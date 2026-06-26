<?php
/**
 * financeiro.php
 * Modulo financeiro geral: entradas vindas dos eventos e saidas manuais/OFX.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/eventos_financeiro_helper.php';

if (empty($_SESSION['perm_financeiro']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

$userId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
$errors = [];
$messages = [];

function financeiro_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    eventos_financeiro_ensure_schema($pdo);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_despesas (
            id BIGSERIAL PRIMARY KEY,
            data_movimento DATE NOT NULL,
            descricao TEXT NOT NULL,
            valor NUMERIC(12,2) NOT NULL DEFAULT 0,
            banco VARCHAR(120) NULL,
            conta VARCHAR(120) NULL,
            categoria VARCHAR(120) NULL,
            centro_custo VARCHAR(120) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pendente',
            origem VARCHAR(30) NOT NULL DEFAULT 'manual',
            ofx_fitid VARCHAR(180) NULL,
            ofx_payload JSONB NULL,
            created_by INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_financeiro_despesas_data ON financeiro_despesas(data_movimento)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_financeiro_despesas_status ON financeiro_despesas(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_financeiro_despesas_ofx_fitid ON financeiro_despesas(banco, conta, ofx_fitid) WHERE ofx_fitid IS NOT NULL");
    $done = true;
}

function financeiro_money($value): float
{
    return eventos_financeiro_money($value);
}

function financeiro_status_label(string $status): string
{
    return [
        'pendente' => 'Pendente',
        'pago' => 'Pago',
        'conciliado' => 'Conciliado',
        'cancelado' => 'Cancelado',
        'vencido' => 'Vencido',
    ][$status] ?? ucfirst($status);
}

function financeiro_modo_label(string $forma): string
{
    return [
        'pix_asaas' => 'PIX Asaas',
        'pix' => 'Pix',
        'cartao_credito' => 'Cartao de credito',
        'dinheiro' => 'Dinheiro',
        'cartao_debito' => 'Cartao de debito',
        'nao_informado' => 'Nao informado',
        'cartao' => 'Cartao',
        'transferencia' => 'Transferencia',
        'outro' => 'Outro',
    ][$forma] ?? ucfirst(str_replace('_', ' ', $forma));
}

function financeiro_ofx_tag(string $block, string $tag): string
{
    if (preg_match('/<' . preg_quote($tag, '/') . '>([^<\r\n]*)/i', $block, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return '';
}

function financeiro_parse_ofx_date(string $value): ?string
{
    $digits = preg_replace('/\D+/', '', $value);
    if (strlen($digits) < 8) {
        return null;
    }
    $ymd = substr($digits, 0, 8);
    $dt = DateTimeImmutable::createFromFormat('Ymd', $ymd);
    return $dt ? $dt->format('Y-m-d') : null;
}

function financeiro_parse_ofx_amount(string $value): float
{
    $value = str_replace(',', '.', trim($value));
    return round((float)$value, 2);
}

function financeiro_parse_ofx(string $content): array
{
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/is', $content, $matches);
    $items = [];
    foreach ($matches[1] ?? [] as $idx => $block) {
        $amount = financeiro_parse_ofx_amount(financeiro_ofx_tag($block, 'TRNAMT'));
        $date = financeiro_parse_ofx_date(financeiro_ofx_tag($block, 'DTPOSTED') ?: financeiro_ofx_tag($block, 'DTUSER'));
        $name = financeiro_ofx_tag($block, 'NAME');
        $memo = financeiro_ofx_tag($block, 'MEMO');
        $fitid = financeiro_ofx_tag($block, 'FITID');
        $description = trim($memo !== '' ? $memo : $name);

        if ($date === null || abs($amount) < 0.01) {
            continue;
        }

        $items[] = [
            'data' => $date,
            'descricao' => $description !== '' ? $description : 'Lancamento OFX',
            'valor_original' => $amount,
            'valor' => abs($amount),
            'tipo' => $amount < 0 ? 'despesa' : 'receita',
            'fitid' => $fitid !== '' ? $fitid : 'ofx_' . sha1($date . '|' . $amount . '|' . $description . '|' . $idx),
        ];
    }
    return $items;
}

function financeiro_listar_receitas(PDO $pdo, array $filters): array
{
    eventos_financeiro_ensure_schema($pdo);
    $where = ["r.status <> 'cancelado'"];
    $params = [];

    if (($filters['status'] ?? '') !== '') {
        $where[] = 'r.status = :status';
        $params[':status'] = $filters['status'];
    }
    if (($filters['unidade'] ?? '') !== '') {
        $where[] = 'COALESCE(r.unidade, e.space_visivel, \'\') = :unidade';
        $params[':unidade'] = $filters['unidade'];
    }
    if (($filters['q'] ?? '') !== '') {
        $where[] = '(r.descricao ILIKE :q OR e.nome_evento ILIKE :q)';
        $params[':q'] = '%' . $filters['q'] . '%';
    }

    $stmt = $pdo->prepare("
        SELECT r.*,
               e.nome_evento,
               e.space_visivel AS evento_unidade,
               e.data_evento::text AS data_evento
        FROM eventos_financeiro_receitas r
        LEFT JOIN logistica_eventos_espelho e ON e.id = r.evento_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY COALESCE(r.vencimento, r.created_at::date) DESC, r.id DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function financeiro_listar_despesas(PDO $pdo, array $filters): array
{
    financeiro_ensure_schema($pdo);
    $where = ["status <> 'cancelado'"];
    $params = [];

    if (($filters['status'] ?? '') !== '') {
        $where[] = 'status = :status';
        $params[':status'] = $filters['status'];
    }
    if (($filters['banco'] ?? '') !== '') {
        $where[] = 'COALESCE(banco, \'\') = :banco';
        $params[':banco'] = $filters['banco'];
    }
    if (($filters['categoria'] ?? '') !== '') {
        $where[] = 'COALESCE(categoria, \'\') = :categoria';
        $params[':categoria'] = $filters['categoria'];
    }
    if (($filters['q'] ?? '') !== '') {
        $where[] = 'descricao ILIKE :q';
        $params[':q'] = '%' . $filters['q'] . '%';
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM financeiro_despesas
        WHERE " . implode(' AND ', $where) . "
        ORDER BY data_movimento DESC, id DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function financeiro_resumo(PDO $pdo): array
{
    financeiro_ensure_schema($pdo);
    $receitasTotal = (float)$pdo->query("SELECT COALESCE(SUM(valor), 0) FROM eventos_financeiro_receitas WHERE status <> 'cancelado'")->fetchColumn();
    $recebido = (float)$pdo->query("SELECT COALESCE(SUM(valor), 0) FROM eventos_financeiro_receitas WHERE status = 'pago'")->fetchColumn();
    $despesasTotal = (float)$pdo->query("SELECT COALESCE(SUM(valor), 0) FROM financeiro_despesas WHERE status <> 'cancelado'")->fetchColumn();
    $despesasPagas = (float)$pdo->query("SELECT COALESCE(SUM(valor), 0) FROM financeiro_despesas WHERE status IN ('pago', 'conciliado')")->fetchColumn();
    return [
        'receitas' => $receitasTotal,
        'recebido' => $recebido,
        'despesas' => $despesasTotal,
        'despesas_pagas' => $despesasPagas,
        'saldo_previsto' => $receitasTotal - $despesasTotal,
        'saldo_realizado' => $recebido - $despesasPagas,
        'a_receber' => max(0, $receitasTotal - $recebido),
        'a_pagar' => max(0, $despesasTotal - $despesasPagas),
    ];
}

financeiro_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_despesa') {
        $descricao = trim((string)($_POST['descricao'] ?? ''));
        $valor = financeiro_money($_POST['valor'] ?? 0);
        $data = trim((string)($_POST['data_movimento'] ?? ''));
        $status = (string)($_POST['status'] ?? 'pendente');
        $status = in_array($status, ['pendente', 'pago', 'conciliado'], true) ? $status : 'pendente';

        if ($descricao === '') {
            $errors[] = 'Informe a descricao da despesa.';
        } elseif ($valor <= 0) {
            $errors[] = 'Informe um valor maior que zero.';
        } elseif ($data === '') {
            $errors[] = 'Informe a data da despesa.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO financeiro_despesas
                    (data_movimento, descricao, valor, banco, conta, categoria, centro_custo, status, origem, created_by)
                VALUES
                    (:data_movimento, :descricao, :valor, :banco, :conta, :categoria, :centro_custo, :status, 'manual', :created_by)
            ");
            $stmt->execute([
                ':data_movimento' => $data,
                ':descricao' => $descricao,
                ':valor' => $valor,
                ':banco' => trim((string)($_POST['banco'] ?? '')) ?: null,
                ':conta' => trim((string)($_POST['conta'] ?? '')) ?: null,
                ':categoria' => trim((string)($_POST['categoria'] ?? '')) ?: null,
                ':centro_custo' => trim((string)($_POST['centro_custo'] ?? '')) ?: null,
                ':status' => $status,
                ':created_by' => $userId > 0 ? $userId : null,
            ]);
            $messages[] = 'Despesa lancada com sucesso.';
        }
    }

    if ($action === 'import_ofx') {
        $banco = trim((string)($_POST['banco'] ?? ''));
        $conta = trim((string)($_POST['conta'] ?? ''));
        if (empty($_FILES['ofx_file']['tmp_name']) || !is_uploaded_file($_FILES['ofx_file']['tmp_name'])) {
            $errors[] = 'Selecione um arquivo OFX.';
        } else {
            $content = (string)file_get_contents($_FILES['ofx_file']['tmp_name']);
            $items = financeiro_parse_ofx($content);
            $imported = 0;
            $skipped = 0;

            foreach ($items as $item) {
                if ($item['tipo'] !== 'despesa') {
                    $skipped++;
                    continue;
                }
                $existsStmt = $pdo->prepare("
                    SELECT 1
                    FROM financeiro_despesas
                    WHERE COALESCE(banco, '') = COALESCE(:banco, '')
                      AND COALESCE(conta, '') = COALESCE(:conta, '')
                      AND ofx_fitid = :ofx_fitid
                    LIMIT 1
                ");
                $existsStmt->execute([
                    ':banco' => $banco !== '' ? $banco : null,
                    ':conta' => $conta !== '' ? $conta : null,
                    ':ofx_fitid' => $item['fitid'],
                ]);
                if ($existsStmt->fetchColumn()) {
                    $skipped++;
                    continue;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO financeiro_despesas
                        (data_movimento, descricao, valor, banco, conta, categoria, centro_custo, status, origem, ofx_fitid, ofx_payload, created_by)
                    VALUES
                        (:data_movimento, :descricao, :valor, :banco, :conta, :categoria, :centro_custo, 'conciliado', 'ofx', :ofx_fitid, CAST(:payload AS JSONB), :created_by)
                ");
                $stmt->execute([
                    ':data_movimento' => $item['data'],
                    ':descricao' => $item['descricao'],
                    ':valor' => $item['valor'],
                    ':banco' => $banco !== '' ? $banco : null,
                    ':conta' => $conta !== '' ? $conta : null,
                    ':categoria' => trim((string)($_POST['categoria'] ?? '')) ?: null,
                    ':centro_custo' => trim((string)($_POST['centro_custo'] ?? '')) ?: null,
                    ':ofx_fitid' => $item['fitid'],
                    ':payload' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':created_by' => $userId > 0 ? $userId : null,
                ]);
                $imported++;
            }
            $messages[] = $imported . ' despesa(s) importada(s) do OFX. ' . $skipped . ' lancamento(s) ignorado(s).';
        }
    }
}

$filters = [
    'status' => trim((string)($_GET['status'] ?? '')),
    'unidade' => trim((string)($_GET['unidade'] ?? '')),
    'banco' => trim((string)($_GET['banco'] ?? '')),
    'categoria' => trim((string)($_GET['categoria'] ?? '')),
    'q' => trim((string)($_GET['q'] ?? '')),
];
$activeTab = (string)($_GET['tab'] ?? 'receitas');
$activeTab = in_array($activeTab, ['receitas', 'despesas'], true) ? $activeTab : 'receitas';

$resumo = financeiro_resumo($pdo);
$receitas = financeiro_listar_receitas($pdo, $filters);
$despesas = financeiro_listar_despesas($pdo, $filters);

$unidades = [];
try {
    $stmt = $pdo->query("
        SELECT DISTINCT COALESCE(r.unidade, e.space_visivel) AS nome
        FROM eventos_financeiro_receitas r
        LEFT JOIN logistica_eventos_espelho e ON e.id = r.evento_id
        WHERE TRIM(COALESCE(r.unidade, e.space_visivel, '')) <> ''
        ORDER BY 1
    ");
    $unidades = array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
} catch (Throwable $e) {
    $unidades = [];
}

$bancos = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT banco FROM financeiro_despesas WHERE TRIM(COALESCE(banco, '')) <> '' ORDER BY banco");
    $bancos = array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
} catch (Throwable $e) {
    $bancos = [];
}

$categorias = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT categoria FROM financeiro_despesas WHERE TRIM(COALESCE(categoria, '')) <> '' ORDER BY categoria");
    $categorias = array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
} catch (Throwable $e) {
    $categorias = [];
}

includeSidebar('Financeiro');
?>

<style>
.finance-page{max-width:1540px;margin:0 auto;padding:1.5rem;background:#f7f9fc;color:#334155}
.finance-top{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;margin-bottom:1rem}
.finance-title{margin:0;font-size:2rem;color:#1e3a8a;font-weight:900}
.finance-subtitle{margin:.3rem 0 0;color:#64748b}
.finance-actions{display:flex;gap:.7rem;flex-wrap:wrap;justify-content:flex-end}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;border:0;border-radius:8px;padding:.72rem 1rem;font-weight:900;text-decoration:none;cursor:pointer;white-space:nowrap;font:inherit}
.btn-blue{background:#5ebfd4;color:#fff}.btn-green{background:#20c985;color:#fff}.btn-red{background:#e94c42;color:#fff}.btn-slate{background:#e2e8f0;color:#334155}.btn-yellow{background:#f4c44e;color:#fff}.btn-ghost{background:#fff;color:#334155;border:1px solid #dbe3ef}
.alert{padding:.85rem 1rem;border-radius:8px;margin-bottom:1rem;background:#fff;border-left:4px solid #5ebfd4;box-shadow:0 8px 20px rgba(15,23,42,.04)}.alert-error{border-left-color:#e05a43}.alert-success{border-left-color:#58c786}
.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem;margin:1.25rem 0}
.summary-card{background:#fff;border:1px solid #e2e8f0;border-left:4px solid #5ebfd4;box-shadow:0 14px 34px rgba(15,23,42,.07);border-radius:8px;padding:1.15rem;min-height:116px}
.summary-card h3{margin:0 0 .6rem;font-size:.82rem;text-transform:uppercase;color:#64748b}.summary-card .value{font-size:1.55rem;font-weight:900;color:#334155}.summary-card .hint{margin-top:.45rem;color:#64748b;font-size:.84rem}
.summary-card.receitas{border-left-color:#58c786}.summary-card.despesas{border-left-color:#e05a43}.summary-card.receber{border-left-color:#5ebfd4}.summary-card.saldo{border-left-color:#f4c44e}
.finance-shell{background:#fff;border:1px solid #e2e8f0;box-shadow:0 16px 38px rgba(15,23,42,.08);border-radius:10px;overflow:hidden}
.tabs{display:grid;grid-template-columns:1fr 1fr;background:#eef1f5;border-bottom:1px solid #dbe3ef}
.tab-link{display:flex;align-items:center;justify-content:center;gap:.45rem;padding:1rem;text-decoration:none;color:#64748b;font-weight:900;border-top:3px solid transparent}
.tab-link.active{background:#5ebfd4;color:#fff;border-top-color:#20c985}
.panel{padding:1.25rem}
.filters{display:grid;grid-template-columns:140px 1fr 1fr 1fr auto;gap:.7rem;align-items:end;margin-bottom:1rem}
label{font-weight:800;color:#475569;font-size:.84rem}input,select,textarea{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:.72rem .78rem;font:inherit;background:#fff}textarea{min-height:86px}
.filter-button{height:43px}.table-wrap{overflow:auto;border:1px solid #e2e8f0;border-radius:8px}.finance-table{width:100%;border-collapse:collapse;background:#fff;min-width:1060px}.finance-table th{background:#64727f;color:#fff;text-align:left;padding:.85rem;font-size:.8rem;text-transform:uppercase}.finance-table td{border-bottom:1px solid #e2e8f0;padding:.85rem;vertical-align:middle}
.badge{display:inline-flex;border-radius:999px;padding:.28rem .65rem;font-size:.76rem;font-weight:900}.badge-pendente{background:#fef3c7;color:#92400e}.badge-pago,.badge-conciliado{background:#dcfce7;color:#166534}.badge-vencido{background:#fee2e2;color:#991b1b}.badge-cancelado{background:#e2e8f0;color:#475569}.badge-receita{background:#58c786;color:#fff}.badge-despesa{background:#e05a43;color:#fff}
.wallet{font-weight:900;color:#475569}.wallet small{display:block;color:#64748b;font-weight:700;margin-top:.25rem}.muted{color:#64748b;font-size:.88rem}.money{font-weight:900;color:#374151}.money.out{color:#b42318}.event-name{font-weight:900;color:#1d4ed8}.event-meta{display:block;color:#64748b;font-size:.82rem;margin-top:.25rem}
.modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1000;display:none;align-items:center;justify-content:center;padding:1rem}.modal-backdrop.open{display:flex}
.modal{width:min(860px,100%);max-height:calc(100vh - 2rem);overflow:auto;background:#fff;border-radius:12px;box-shadow:0 24px 70px rgba(15,23,42,.28)}
.modal-header{display:flex;justify-content:space-between;gap:1rem;align-items:center;padding:1rem 1.15rem;border-bottom:1px solid #e2e8f0}.modal-title{margin:0;color:#1e293b;font-weight:900}.modal-close{width:38px;height:38px;border:0;border-radius:999px;background:#f1f5f9;color:#334155;font-size:1.25rem;cursor:pointer}
.modal-body{padding:1rem;display:grid;gap:1rem}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.9rem}.field.full{grid-column:1/-1}.modal-actions{display:flex;justify-content:flex-end;gap:.7rem;padding:1rem;border-top:1px solid #e2e8f0}
@media(max-width:1050px){.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.filters{grid-template-columns:1fr 1fr}.finance-top{flex-direction:column}.finance-actions{justify-content:flex-start}.form-grid{grid-template-columns:1fr}}
@media(max-width:650px){.summary-grid{grid-template-columns:1fr}.filters{grid-template-columns:1fr}.panel{padding:1rem .75rem}.finance-page{padding:1rem}}
</style>

<div class="finance-page">
    <div class="finance-top">
        <div>
            <h1 class="finance-title">Financeiro</h1>
            <p class="finance-subtitle">Entradas dos eventos, despesas operacionais e importacao de OFX bancario.</p>
        </div>
        <div class="finance-actions">
            <button class="btn btn-green" type="button" data-open-modal="despesa-modal">+ Nova despesa</button>
            <button class="btn btn-blue" type="button" data-open-modal="ofx-modal">Importar OFX</button>
        </div>
    </div>

    <?php foreach ($errors as $error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endforeach; ?>
    <?php foreach ($messages as $message): ?><div class="alert alert-success"><?= h($message) ?></div><?php endforeach; ?>

    <div class="summary-grid">
        <div class="summary-card receitas"><h3>Receitas</h3><div class="value"><?= h(format_currency($resumo['receitas'])) ?></div><div class="hint">Lancadas nos eventos</div></div>
        <div class="summary-card despesas"><h3>Despesas</h3><div class="value"><?= h(format_currency($resumo['despesas'])) ?></div><div class="hint">Manuais e OFX</div></div>
        <div class="summary-card receber"><h3>A receber / pagar</h3><div class="value"><?= h(format_currency($resumo['a_receber'])) ?></div><div class="hint">A pagar: <?= h(format_currency($resumo['a_pagar'])) ?></div></div>
        <div class="summary-card saldo"><h3>Saldo previsto</h3><div class="value"><?= h(format_currency($resumo['saldo_previsto'])) ?></div><div class="hint">Realizado: <?= h(format_currency($resumo['saldo_realizado'])) ?></div></div>
    </div>

    <section class="finance-shell">
        <div class="tabs">
            <a class="tab-link <?= $activeTab === 'receitas' ? 'active' : '' ?>" href="index.php?page=financeiro&tab=receitas">↑ Receitas</a>
            <a class="tab-link <?= $activeTab === 'despesas' ? 'active' : '' ?>" href="index.php?page=financeiro&tab=despesas">↓ Despesas</a>
        </div>

        <div class="panel">
            <form method="get" class="filters">
                <input type="hidden" name="page" value="financeiro">
                <input type="hidden" name="tab" value="<?= h($activeTab) ?>">
                <div>
                    <label>Status</label>
                    <select name="status">
                        <option value="">Todos</option>
                        <?php foreach (['pendente', 'pago', 'conciliado', 'vencido'] as $status): ?>
                            <option value="<?= h($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= h(financeiro_status_label($status)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><?= $activeTab === 'receitas' ? 'Unidade' : 'Banco' ?></label>
                    <?php if ($activeTab === 'receitas'): ?>
                        <select name="unidade">
                            <option value="">Todas unidades</option>
                            <?php foreach ($unidades as $unidade): ?><option value="<?= h($unidade) ?>" <?= $filters['unidade'] === $unidade ? 'selected' : '' ?>><?= h($unidade) ?></option><?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <select name="banco">
                            <option value="">Todos bancos</option>
                            <?php foreach ($bancos as $banco): ?><option value="<?= h($banco) ?>" <?= $filters['banco'] === $banco ? 'selected' : '' ?>><?= h($banco) ?></option><?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
                <div>
                    <label>Categoria</label>
                    <?php if ($activeTab === 'despesas'): ?>
                        <select name="categoria">
                            <option value="">Todas categorias</option>
                            <?php foreach ($categorias as $categoria): ?><option value="<?= h($categoria) ?>" <?= $filters['categoria'] === $categoria ? 'selected' : '' ?>><?= h($categoria) ?></option><?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <select disabled><option>Todas categorias</option></select>
                    <?php endif; ?>
                </div>
                <div>
                    <label>Pesquisar</label>
                    <input name="q" value="<?= h($filters['q']) ?>" placeholder="Descricao ou evento">
                </div>
                <button class="btn btn-slate filter-button" type="submit">Filtrar</button>
            </form>

            <?php if ($activeTab === 'receitas'): ?>
                <div class="table-wrap">
                    <table class="finance-table">
                        <thead><tr><th>Acoes</th><th>Data</th><th>Descricao</th><th>Responsavel</th><th>Valor</th><th>Categoria/Banco</th><th>Status</th><th>Link</th></tr></thead>
                        <tbody>
                        <?php foreach ($receitas as $receita): ?>
                            <?php
                            $status = (string)($receita['status'] ?? 'pendente');
                            $modo = (string)($receita['modo_pagamento'] ?? $receita['forma_pagamento'] ?? '');
                            $unidade = (string)($receita['unidade'] ?? $receita['evento_unidade'] ?? '');
                            ?>
                            <tr>
                                <td><span class="badge badge-receita">↑</span></td>
                                <td><strong><?= h(brDateOnly((string)($receita['vencimento'] ?? ''))) ?></strong></td>
                                <td>
                                    <?= h((string)$receita['descricao']) ?>
                                    <?php if ((int)($receita['parcelas_total'] ?? 1) > 1): ?><span class="badge"><?= (int)$receita['parcela_numero'] ?>/<?= (int)$receita['parcelas_total'] ?></span><?php endif; ?>
                                </td>
                                <td><span class="event-name"><?= h((string)($receita['nome_evento'] ?? 'Evento')) ?></span><span class="event-meta"><?= h($unidade) ?></span></td>
                                <td><span class="money"><?= h(format_currency($receita['valor'])) ?></span></td>
                                <td><span class="wallet">Receita<small><?= h(financeiro_modo_label($modo)) ?> · <?= h(ucfirst((string)($receita['carteira'] ?? 'manual'))) ?></small></span></td>
                                <td><span class="badge badge-<?= h($status) ?>"><?= h(financeiro_status_label($status)) ?></span></td>
                                <td>
                                    <?php if (!empty($receita['asaas_invoice_url'])): ?>
                                        <a href="<?= h((string)$receita['asaas_invoice_url']) ?>" target="_blank" rel="noopener">Abrir</a>
                                    <?php else: ?>
                                        <a href="index.php?page=eventos_financeiro&evento_id=<?= (int)$receita['evento_id'] ?>">Evento</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$receitas): ?><tr><td colspan="8" class="muted">Nenhuma receita encontrada.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="finance-table">
                        <thead><tr><th>Acoes</th><th>Data</th><th>Descricao</th><th>Origem</th><th>Valor</th><th>Categoria/Banco</th><th>Centro de custo</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($despesas as $despesa): ?>
                            <?php $status = (string)($despesa['status'] ?? 'pendente'); ?>
                            <tr>
                                <td><span class="badge badge-despesa">↓</span></td>
                                <td><strong><?= h(brDateOnly((string)$despesa['data_movimento'])) ?></strong></td>
                                <td><?= h((string)$despesa['descricao']) ?></td>
                                <td><?= h(strtoupper((string)$despesa['origem'])) ?></td>
                                <td><span class="money out"><?= h(format_currency($despesa['valor'])) ?></span></td>
                                <td><span class="wallet"><?= h((string)($despesa['categoria'] ?: 'Nao informado')) ?><small><?= h((string)($despesa['banco'] ?: 'Sem banco')) ?><?= !empty($despesa['conta']) ? ' · ' . h((string)$despesa['conta']) : '' ?></small></span></td>
                                <td><?= h((string)($despesa['centro_custo'] ?: '-')) ?></td>
                                <td><span class="badge badge-<?= h($status) ?>"><?= h(financeiro_status_label($status)) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$despesas): ?><tr><td colspan="8" class="muted">Nenhuma despesa encontrada.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="modal-backdrop" id="despesa-modal" role="dialog" aria-modal="true" aria-labelledby="despesa-title">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title" id="despesa-title">Nova despesa</h2>
            <button class="modal-close" type="button" data-close-modal>×</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="add_despesa">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="field full"><label>Descricao</label><input name="descricao" required placeholder="Ex: Pagamento fornecedor"></div>
                    <div><label>Data</label><input type="date" name="data_movimento" value="<?= h(date('Y-m-d')) ?>" required></div>
                    <div><label>Valor</label><input name="valor" inputmode="decimal" placeholder="R$ 0,00" required></div>
                    <div><label>Banco</label><input name="banco" placeholder="Ex: Santander"></div>
                    <div><label>Conta</label><input name="conta" placeholder="Ex: Conta principal"></div>
                    <div><label>Categoria</label><input name="categoria" placeholder="Ex: Fornecedores"></div>
                    <div><label>Centro de custo</label><input name="centro_custo" placeholder="Ex: Eventos"></div>
                    <div><label>Status</label><select name="status"><option value="pendente">Pendente</option><option value="pago">Pago</option><option value="conciliado">Conciliado</option></select></div>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-ghost" type="button" data-close-modal>Cancelar</button>
                <button class="btn btn-green" type="submit">Salvar despesa</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="ofx-modal" role="dialog" aria-modal="true" aria-labelledby="ofx-title">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title" id="ofx-title">Importar OFX bancario</h2>
            <button class="modal-close" type="button" data-close-modal>×</button>
        </div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import_ofx">
            <div class="modal-body">
                <div class="form-grid">
                    <div><label>Banco</label><input name="banco" placeholder="Ex: Santander" required></div>
                    <div><label>Conta</label><input name="conta" placeholder="Ex: 0358 Smile"></div>
                    <div><label>Categoria padrao</label><input name="categoria" placeholder="Ex: Despesas bancarias"></div>
                    <div><label>Centro de custo padrao</label><input name="centro_custo" placeholder="Ex: Administrativo"></div>
                    <div class="field full"><label>Arquivo OFX</label><input type="file" name="ofx_file" accept=".ofx,.OFX,application/x-ofx,text/plain,application/octet-stream" required></div>
                    <div class="field full muted">Somente lancamentos negativos do OFX entram como despesas. Lancamentos repetidos sao ignorados pelo banco, conta e FITID.</div>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-ghost" type="button" data-close-modal>Cancelar</button>
                <button class="btn btn-blue" type="submit">Importar despesas</button>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('[data-open-modal]').forEach((button) => {
    button.addEventListener('click', () => {
        document.getElementById(button.dataset.openModal)?.classList.add('open');
    });
});
document.querySelectorAll('[data-close-modal]').forEach((button) => {
    button.addEventListener('click', () => button.closest('.modal-backdrop')?.classList.remove('open'));
});
document.querySelectorAll('.modal-backdrop').forEach((modal) => {
    modal.addEventListener('click', (event) => {
        if (event.target === modal) modal.classList.remove('open');
    });
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.open').forEach((modal) => modal.classList.remove('open'));
    }
});
</script>

<?php endSidebar(); ?>
