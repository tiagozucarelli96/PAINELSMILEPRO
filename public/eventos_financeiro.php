<?php
/**
 * eventos_financeiro.php
 * Financeiro do evento na Agenda Geral.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/eventos_financeiro_helper.php';
require_once __DIR__ . '/pacotes_evento_helper.php';

if (empty($_SESSION['perm_agenda_eventos']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

$eventoId = (int)($_GET['evento_id'] ?? $_POST['evento_id'] ?? 0);
$userId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
$evento = $eventoId > 0 ? eventos_financeiro_evento($pdo, $eventoId) : null;
$errors = [];
$messages = [];

if (!$evento) {
    includeSidebar('Financeiro do Evento');
    echo '<div style="padding:2rem"><div class="alert alert-error">Evento não encontrado.</div></div>';
    endSidebar();
    return;
}

eventos_financeiro_ensure_schema($pdo);
pacotes_evento_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_pedido') {
        $result = eventos_financeiro_salvar_pedido_detalhado(
            $pdo,
            $eventoId,
            (int)($_POST['pedido_id'] ?? 0),
            (int)($_POST['pacote_evento_id'] ?? 0),
            (string)($_POST['descricao'] ?? ''),
            eventos_financeiro_money($_POST['valor_base'] ?? 0),
            (int)($_POST['quantidade'] ?? 1),
            eventos_financeiro_money($_POST['valor_adicional'] ?? 0),
            eventos_financeiro_money($_POST['desconto'] ?? 0),
            trim((string)($_POST['data_venda'] ?? '')) ?: null,
            (string)($_POST['detalhes_html'] ?? ''),
            $userId
        );
        $messages[] = !empty($result['ok']) ? (((string)($result['mode'] ?? '') === 'updated') ? 'Pedido atualizado no contratado.' : 'Pedido lançado no contratado.') : '';
        if (empty($result['ok'])) {
            $errors[] = (string)($result['error'] ?? 'Não foi possível lançar o pedido.');
        }
    }

    if ($action === 'delete_pedido') {
        if (eventos_financeiro_excluir_pedido($pdo, $eventoId, (int)($_POST['pedido_id'] ?? 0), $userId)) {
            $messages[] = 'Pedido removido do contratado.';
        } else {
            $errors[] = 'Não foi possível remover o pedido.';
        }
    }

    if ($action === 'add_receita') {
        $carteira = (string)($_POST['carteira'] ?? 'manual');
        if ($carteira === 'asaas') {
            $result = eventos_financeiro_criar_pix_asaas($pdo, $eventoId, $evento, $_POST, $userId);
            if (!empty($result['ok'])) {
                $messages[] = 'Cobrança PIX Asaas criada em ' . (int)($result['created'] ?? 0) . ' parcela(s).';
                if (!empty($result['warning'])) {
                    $errors[] = (string)$result['warning'];
                }
            } else {
                $errors[] = (string)($result['error'] ?? 'Não foi possível criar a cobrança no Asaas.');
            }
        } else {
            $result = eventos_financeiro_salvar_receitas_manual_lote($pdo, $eventoId, $_POST, $userId);
            if (!empty($result['ok'])) {
                $messages[] = 'Receita manual lançada em ' . (int)($result['created'] ?? 0) . ' parcela(s).';
            } else {
                $errors[] = (string)($result['error'] ?? 'Não foi possível lançar a receita.');
            }
        }
    }

    if ($action === 'sync_asaas') {
        require_once __DIR__ . '/asaas_helper.php';
        $asaas = new AsaasHelper();
        $synced = 0;
        foreach (eventos_financeiro_listar_receitas($pdo, $eventoId) as $receita) {
            $paymentId = trim((string)($receita['asaas_payment_id'] ?? ''));
            if ($paymentId === '' || in_array((string)$receita['status'], ['pago', 'cancelado'], true)) {
                continue;
            }
            try {
                $payment = $asaas->getPaymentStatus($paymentId);
                if (is_array($payment) && eventos_financeiro_atualizar_asaas_payment($pdo, $paymentId, $payment)) {
                    $synced++;
                }
            } catch (Throwable $e) {
                $errors[] = 'Falha ao consultar Asaas: ' . $e->getMessage();
                break;
            }
        }
        if ($synced > 0) {
            $messages[] = $synced . ' cobrança(s) atualizada(s) pelo Asaas.';
        }
    }
}

$messages = array_values(array_filter($messages));
$resumo = eventos_financeiro_resumo($pdo, $eventoId);
$pedidos = eventos_financeiro_listar_pedidos($pdo, $eventoId);
$receitas = eventos_financeiro_listar_receitas($pdo, $eventoId);
$pacotes = pacotes_evento_listar($pdo, false);
$pacotesJson = array_map(static function (array $pacote): array {
    $categoria = trim((string)($pacote['categoria'] ?? 'Pacote'));
    $valorBase = $categoria === 'Pacote'
        ? (float)($pacote['valor_pacote'] ?? 0)
        : (float)($pacote['valor_venda'] ?? 0);
    return [
        'id' => (int)($pacote['id'] ?? 0),
        'nome' => (string)($pacote['nome'] ?? ''),
        'categoria' => $categoria,
        'descricao' => (string)($pacote['descricao'] ?? ''),
        'valorBase' => $valorBase,
        'valorAdicional' => (float)($pacote['valor_convidado_adicional'] ?? 0),
        'pessoasBase' => (int)($pacote['pessoas_base'] ?? 0),
    ];
}, $pacotes);

$unidades = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT TRIM(space_visivel) AS nome FROM logistica_me_locais WHERE TRIM(COALESCE(space_visivel, '')) <> '' ORDER BY TRIM(space_visivel)");
    $unidades = array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
} catch (Throwable $e) {
    $unidades = [];
}
$eventoUnidade = trim((string)($evento['space_visivel'] ?? ''));
if ($eventoUnidade !== '' && !in_array($eventoUnidade, $unidades, true)) {
    array_unshift($unidades, $eventoUnidade);
}

function eventos_financeiro_label_modo(string $forma): string
{
    return [
        'pix_asaas' => 'PIX Asaas',
        'pix' => 'Pix',
        'cartao_credito' => 'Cartão de crédito',
        'dinheiro' => 'Dinheiro',
        'cartao_debito' => 'Cartão de débito',
        'nao_informado' => 'Não informado',
        'cartao' => 'Cartão',
        'transferencia' => 'Transferência',
        'outro' => 'Outro',
    ][$forma] ?? $forma;
}

function eventos_financeiro_badge_status(string $status): string
{
    return [
        'pendente' => 'Pendente',
        'pago' => 'Pago',
        'vencido' => 'Vencido',
        'cancelado' => 'Cancelado',
    ][$status] ?? $status;
}

function eventos_financeiro_format_weekday(string $date): string
{
    if ($date === '') {
        return '';
    }
    try {
        $day = (int)(new DateTimeImmutable($date))->format('w');
    } catch (Throwable $e) {
        return '';
    }
    return ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'][$day] ?? '';
}

function eventos_financeiro_format_time(array $evento): string
{
    $inicio = trim((string)($evento['hora_inicio'] ?? ''));
    $fim = trim((string)($evento['hora_fim'] ?? ''));
    if ($inicio === '') {
        return 'Não informado';
    }
    if ($fim === '') {
        try {
            $fim = (new DateTimeImmutable('2000-01-01 ' . $inicio))->modify('+6 hours')->format('H:i');
        } catch (Throwable $e) {
            $fim = '';
        }
    }
    return $fim !== '' ? "{$inicio} às {$fim}" : $inicio;
}

function eventos_financeiro_user_initials(): string
{
    $name = trim((string)($_SESSION['nome'] ?? $_SESSION['usuario_nome'] ?? $_SESSION['name'] ?? $_SESSION['email'] ?? ''));
    if ($name === '') {
        return 'TI';
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    $first = mb_substr((string)($parts[0] ?? ''), 0, 1, 'UTF-8');
    $second = count($parts) > 1 ? mb_substr((string)end($parts), 0, 1, 'UTF-8') : '';
    return mb_strtoupper($first . $second, 'UTF-8') ?: 'TI';
}

$clienteCadastroId = (int)($evento['cliente_cadastro_id'] ?? 0);
$clienteNome = trim((string)($evento['cliente_nome'] ?? ''));
if ($clienteNome === '') {
    $clienteNome = 'Cliente não identificado';
}
$clienteCadastroHref = $clienteCadastroId > 0
    ? 'comercial_cadastro_cliente.php?edit_id=' . $clienteCadastroId
    : 'index.php?page=comercial_clientes_cadastrados';
$clienteEmail = trim((string)($evento['cliente_email'] ?? ''));
$clienteTelefone = trim((string)($evento['cliente_telefone'] ?? ''));
$clienteDocumentoTipo = trim((string)($evento['cliente_documento_tipo'] ?? ''));
$clienteDocumentoNumero = trim((string)($evento['cliente_documento_numero'] ?? ''));
$clienteRg = trim((string)($evento['cliente_rg'] ?? ''));
$eventoData = (string)($evento['data_evento'] ?? '');
$eventoWeekday = eventos_financeiro_format_weekday($eventoData);
$eventoHorario = eventos_financeiro_format_time($evento);
$eventoInitials = eventos_financeiro_user_initials();
$eventoFotoUrl = trim((string)($evento['foto_evento_url'] ?? ''));

includeSidebar('Financeiro do Evento');
?>

<style>
.finance-page{max-width:1500px;margin:0 auto;padding:1.5rem;background:#f7f9fc;color:#334155}
.finance-layout{display:grid;grid-template-columns:minmax(280px,330px) minmax(0,1fr);gap:1.25rem;align-items:start}
.finance-main{min-width:0}
.finance-event-card{position:sticky;top:1rem;background:#fff;border:1px solid #dbe3ef;border-radius:16px;box-shadow:0 18px 42px rgba(15,23,42,.08);padding:1.2rem}
.finance-event-title{margin:0 0 1rem;text-align:center;color:#df5f4e;font-size:1.5rem;font-weight:900}
.finance-event-avatar{width:128px;height:128px;border-radius:999px;margin:0 auto 1rem;background:#cbd5e1;position:relative}
.finance-event-avatar::before{content:"";position:absolute;top:28px;left:50%;width:42px;height:42px;border-radius:999px;background:#fff;transform:translateX(-50%)}
.finance-event-avatar::after{content:"";position:absolute;left:50%;bottom:28px;width:74px;height:42px;border-radius:42px 42px 18px 18px;background:#fff;transform:translateX(-50%)}
.finance-event-avatar.has-photo{overflow:hidden;background:#e2e8f0;box-shadow:0 10px 24px rgba(15,23,42,.12)}
.finance-event-avatar.has-photo::before,.finance-event-avatar.has-photo::after{display:none}
.finance-event-avatar img{width:100%;height:100%;display:block;object-fit:cover}
.finance-event-summary{text-align:center;color:#475569;font-size:1rem;line-height:1.45;margin-bottom:1rem}
.finance-event-initials{width:46px;height:46px;border-radius:999px;margin:.9rem auto 0;background:#2f7d32;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;box-shadow:0 10px 20px rgba(47,125,50,.25)}
.finance-info-block{background:#f8fafc;border-left:4px solid #58c786;border-radius:10px;padding:.9rem;margin-top:.8rem;color:#334155;line-height:1.45}
.finance-info-block--place{background:#f3fbf2}
.finance-info-block--client{border-left-color:#5ebfd4}
.finance-info-label{display:block;font-weight:900;color:#1f2937;margin-bottom:.35rem}
.finance-client-link{color:#1f77b4;font-weight:900;text-decoration:none}
.finance-top{display:flex;justify-content:space-between;gap:1rem;align-items:center;margin-bottom:1rem}
.finance-title{margin:0;font-size:1.85rem;color:#1e3a8a;font-weight:900}
.finance-subtitle{margin:.25rem 0 0;color:#64748b}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;border:0;border-radius:10px;padding:.72rem 1rem;font-weight:900;text-decoration:none;cursor:pointer;white-space:nowrap}
.btn-blue{background:#5ebfd4;color:#fff}.btn-green{background:#20c985;color:#fff}.btn-red{background:#e94c42;color:#fff}.btn-slate{background:#e2e8f0;color:#334155}.btn-yellow{background:#f4c44e;color:#fff}.btn-ghost{background:#fff;color:#334155;border:1px solid #dbe3ef}
.alert{padding:.85rem 1rem;border-radius:10px;margin-bottom:1rem;background:#fff;border-left:4px solid #5ebfd4;box-shadow:0 8px 20px rgba(15,23,42,.04)}.alert-error{border-left-color:#e05a43}.alert-success{border-left-color:#58c786}
.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem;margin:1.25rem 0}
.summary-card{background:#fff;border:1px solid #e2e8f0;border-left:4px solid #5ebfd4;box-shadow:0 14px 34px rgba(15,23,42,.07);border-radius:12px;padding:1.15rem;min-height:124px}
.summary-card h3{margin:0 0 .6rem;font-size:.86rem;text-transform:uppercase;color:#64748b}.summary-card .value{font-size:1.55rem;font-weight:900;color:#334155}.summary-card .hint{margin-top:.45rem;color:#64748b;font-size:.84rem}
.summary-card.contratado{border-left-color:#f4c44e}.summary-card.receitas{border-left-color:#58c786}.summary-card.falta{border-left-color:#5ebfd4}.summary-card.resultado.ok{border-left-color:#58c786}.summary-card.resultado.warn{border-left-color:#e05a43}
.finance-shell{background:#fff;border:1px solid #e2e8f0;box-shadow:0 16px 38px rgba(15,23,42,.08);border-radius:14px;overflow:hidden}
.tabs{display:flex;gap:.5rem;flex-wrap:wrap;padding:1rem 1rem 0;background:#fff}.tab-btn{border:1px solid #e2e8f0;background:#f3f4f6;color:#64748b;border-radius:10px 10px 0 0;padding:.86rem 1.2rem;font-weight:900;cursor:pointer}.tab-btn.active{background:#5ebfd4;color:#fff;border-color:#5ebfd4;box-shadow:inset 0 -3px 0 rgba(15,23,42,.25)}
.tab-panel{display:none;padding:1.25rem}.tab-panel.active{display:block}
.panel-toolbar{display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem}
.panel-title{margin:0;color:#1f2937;font-size:1.3rem;font-weight:900}
.filters{display:flex;gap:.7rem;flex-wrap:wrap;align-items:center}.filters input,.filters select{min-width:170px}
label{font-weight:800;color:#475569;font-size:.86rem}input,select,textarea{width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:.72rem .78rem;font:inherit;background:#fff}textarea{min-height:78px}
.table-wrap{overflow:auto;border:1px solid #e2e8f0;border-radius:10px}.finance-table{width:100%;border-collapse:collapse;background:#fff;min-width:920px}.finance-table th{background:#64727f;color:#fff;text-align:left;padding:.85rem;font-size:.82rem;text-transform:uppercase}.finance-table td{border-bottom:1px solid #e2e8f0;padding:.85rem;vertical-align:middle}
.badge{display:inline-flex;border-radius:999px;padding:.28rem .65rem;font-size:.76rem;font-weight:900}.badge-pendente{background:#fef3c7;color:#92400e}.badge-pago{background:#dcfce7;color:#166534}.badge-vencido{background:#fee2e2;color:#991b1b}.badge-cancelado{background:#e2e8f0;color:#475569}
.badge-receita{background:#58c786;color:#fff}.wallet{font-weight:900;color:#475569}.wallet small{display:block;color:#64748b;font-weight:700;margin-top:.25rem}.muted{color:#64748b;font-size:.88rem}.money{font-weight:900;color:#374151}
.pedido-summary-line{display:flex;justify-content:space-between;gap:.75rem;align-items:center;padding:.42rem 0;border-bottom:1px dashed #dbe3ef}.pedido-summary-line:last-child{border-bottom:0}.pedido-total-line{font-size:1.15rem;font-weight:900;color:#1e3a8a}.pedido-meta{display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.35rem}.pedido-chip{display:inline-flex;border-radius:999px;background:#eef6ff;color:#1d4ed8;font-weight:900;font-size:.76rem;padding:.22rem .55rem}.pedido-actions{display:flex;gap:.4rem;align-items:center;flex-wrap:wrap}.icon-btn{width:34px;height:34px;border:0;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;font-weight:900;text-decoration:none}.icon-btn.edit{background:#f4c44e;color:#fff}.icon-btn.delete{background:#e94c42;color:#fff}.pedido-total-bar{margin-top:1rem;background:#5ebfd4;color:#fff;border-radius:10px;padding:1rem;text-align:right;font-weight:900;font-size:1.05rem}
.modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1000;display:none;align-items:center;justify-content:center;padding:1rem}.modal-backdrop.open{display:flex}
.modal{width:min(1040px,100%);max-height:calc(100vh - 2rem);overflow:auto;background:#fff;border-radius:16px;box-shadow:0 24px 70px rgba(15,23,42,.28)}
.modal-header{display:flex;justify-content:space-between;gap:1rem;align-items:center;padding:1rem 1.15rem;border-bottom:1px solid #e2e8f0}.modal-title{margin:0;color:#1e293b;font-weight:900}.modal-close{width:38px;height:38px;border:0;border-radius:999px;background:#f1f5f9;color:#334155;font-size:1.25rem;cursor:pointer}
.modal-body{padding:1rem;display:grid;gap:1rem}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.9rem}.field.full{grid-column:1/-1}.choice-row{display:flex;gap:.65rem;flex-wrap:wrap}.choice{border:1px solid #dbe3ef;background:#fff;border-radius:999px;padding:.62rem .9rem;font-weight:900;cursor:pointer}.choice.active{background:#1e3a8a;color:#fff;border-color:#1e3a8a}.pedido-modal-grid{display:grid;grid-template-columns:minmax(0,1fr) 310px;gap:1rem;align-items:start}.pedido-side{background:#fff8db;border-left:4px solid #f4c44e;border-radius:12px;padding:1rem;position:sticky;top:0}.pedido-side h3{margin:0 0 .7rem;color:#374151;font-size:1rem}.pedido-details-editor textarea{min-height:260px}
.parcelas-box{border:1px solid #dbe3ef;border-radius:12px;padding:1rem;background:#f8fafc}.parcelas-head{display:flex;justify-content:space-between;gap:1rem;align-items:center;margin-bottom:.8rem}.parcelas-table{width:100%;border-collapse:collapse}.parcelas-table th{font-size:.76rem;text-transform:uppercase;color:#64748b;text-align:left;padding:.4rem}.parcelas-table td{padding:.4rem}.parcelas-table input{padding:.58rem .65rem}
.modal-actions{display:flex;justify-content:flex-end;gap:.7rem;padding:1rem;border-top:1px solid #e2e8f0}
.hidden{display:none!important}
@media(max-width:1050px){.finance-layout{grid-template-columns:1fr}.finance-event-card{position:static}.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.form-grid{grid-template-columns:1fr}.pedido-modal-grid{grid-template-columns:1fr}.pedido-side{position:static}}
@media(max-width:650px){.summary-grid{grid-template-columns:1fr}.finance-top{align-items:flex-start;flex-direction:column}.tab-panel{padding:1rem .75rem}.tabs{padding:.75rem .75rem 0}}
</style>

<div class="finance-page">
    <div class="finance-layout">
        <aside class="finance-event-card">
            <h2 class="finance-event-title"><?= h((string)$evento['nome_evento']) ?></h2>
            <div class="finance-event-avatar<?= $eventoFotoUrl !== '' ? ' has-photo' : '' ?>" aria-hidden="true">
                <?php if ($eventoFotoUrl !== ''): ?>
                    <img src="<?= h($eventoFotoUrl) ?>" alt="">
                <?php endif; ?>
            </div>

            <div class="finance-event-summary">
                <div>★ <?= h((string)$evento['space_visivel']) ?></div>
                <div>● <?= (int)($evento['convidados'] ?? 0) ?> participantes</div>
                <div class="finance-event-initials"><?= h($eventoInitials) ?></div>
            </div>

            <div class="finance-info-block">
                <span class="finance-info-label">📅 <?= h(brDateOnly($eventoData)) ?></span>
                <?php if ($eventoWeekday !== ''): ?><div><?= h($eventoWeekday) ?></div><?php endif; ?>
                <div><strong>Horário:</strong> <?= h($eventoHorario) ?></div>
                <div><strong>Local:</strong> <?= h((string)$evento['local_evento']) ?></div>
            </div>

            <div class="finance-info-block finance-info-block--place">
                <span class="finance-info-label">📍 Local do Evento</span>
                <div><?= h((string)$evento['local_evento']) ?></div>
                <div><?= h((string)$evento['space_visivel']) ?></div>
            </div>

            <div class="finance-info-block finance-info-block--client">
                <span class="finance-info-label">ℹ️ Dados do Cliente</span>
                <div>👤 <a class="finance-client-link" href="<?= h($clienteCadastroHref) ?>"><?= h($clienteNome) ?></a></div>
                <?php if ($clienteEmail !== ''): ?><div>✉️ <?= h($clienteEmail) ?></div><?php endif; ?>
                <?php if ($clienteTelefone !== ''): ?><div>☎ <?= h($clienteTelefone) ?></div><?php endif; ?>
                <?php if ($clienteDocumentoNumero !== ''): ?><div><?= h($clienteDocumentoTipo !== '' ? $clienteDocumentoTipo : 'Documento') ?>: <?= h($clienteDocumentoNumero) ?></div><?php endif; ?>
                <?php if ($clienteRg !== ''): ?><div>RG: <?= h($clienteRg) ?></div><?php endif; ?>
            </div>
        </aside>

        <main class="finance-main">
            <div class="finance-top">
                <div>
                    <h1 class="finance-title">Financeiro do Evento</h1>
                    <p class="finance-subtitle"><?= h((string)$evento['nome_evento']) ?> · <?= h((string)$evento['space_visivel']) ?></p>
                </div>
                <a class="btn btn-yellow" href="index.php?page=agenda_eventos&evento_id=<?= (int)$eventoId ?>">← Voltar ao evento</a>
            </div>

            <?php foreach ($errors as $error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endforeach; ?>
            <?php foreach ($messages as $message): ?><div class="alert alert-success"><?= h($message) ?></div><?php endforeach; ?>

    <div class="summary-grid">
        <div class="summary-card contratado"><h3>Contratado</h3><div class="value"><?= h(format_currency($resumo['contratado'])) ?></div><div class="hint">Soma dos pedidos</div></div>
        <div class="summary-card receitas"><h3>Receitas</h3><div class="value"><?= h(format_currency($resumo['receitas'])) ?></div><div class="hint">Total lançado para receber</div></div>
        <div class="summary-card falta"><h3>Falta receber</h3><div class="value"><?= h(format_currency($resumo['falta_receber'])) ?></div><div class="hint">Receitas pendentes</div></div>
        <div class="summary-card resultado <?= abs($resumo['resultado']) < 0.01 ? 'ok' : 'warn' ?>">
            <h3>Resultado</h3>
            <div class="value"><?= h(format_currency($resumo['resultado'])) ?></div>
            <div class="hint"><?= abs($resumo['resultado']) < 0.01 ? 'Contratado e receitas coerentes' : ($resumo['resultado'] > 0 ? 'Falta lançar receita' : 'Receita acima do contratado') ?></div>
        </div>
    </div>

    <section class="finance-shell">
        <div class="tabs">
            <button type="button" class="tab-btn active" data-tab="movimentacoes">⇄ Movimentações</button>
            <button type="button" class="tab-btn" data-tab="pedidos">▣ Pedidos</button>
        </div>

        <div class="tab-panel active" id="tab-movimentacoes">
            <div class="panel-toolbar">
                <div class="filters">
                    <button class="btn btn-green" type="button" data-open-receita>↑ Nova Receita</button>
                </div>
                <form method="post">
                    <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
                    <input type="hidden" name="action" value="sync_asaas">
                    <button class="btn btn-slate" type="submit">Atualizar Asaas</button>
                </form>
            </div>

            <div class="table-wrap">
                <table class="finance-table">
                    <thead><tr><th>Ações</th><th>Data</th><th>Descrição</th><th>Tipo</th><th>Valor</th><th>Carteira/Banco</th><th>Unidade</th><th>Status</th><th>Link</th></tr></thead>
                    <tbody>
                    <?php foreach ($receitas as $receita): ?>
                        <?php
                        $status = (string)($receita['status'] ?? 'pendente');
                        $carteira = (string)($receita['carteira'] ?? (!empty($receita['asaas_payment_id']) ? 'asaas' : 'manual'));
                        $modo = (string)($receita['modo_pagamento'] ?? $receita['forma_pagamento'] ?? '');
                        ?>
                        <tr>
                            <td><span class="badge <?= $status === 'pago' ? 'badge-pago' : 'badge-pendente' ?>"><?= $status === 'pago' ? '✓' : '…' ?></span></td>
                            <td><strong><?= h(brDateOnly((string)($receita['vencimento'] ?? ''))) ?></strong></td>
                            <td>
                                <?= h((string)$receita['descricao']) ?>
                                <?php if ((int)$receita['parcelas_total'] > 1): ?><div><span class="badge"><?= (int)$receita['parcela_numero'] ?>/<?= (int)$receita['parcelas_total'] ?></span></div><?php endif; ?>
                            </td>
                            <td><span class="badge badge-receita">↑ Receita</span></td>
                            <td><span class="money"><?= h(format_currency($receita['valor'])) ?></span></td>
                            <td><span class="wallet"><?= h(ucfirst($carteira)) ?><small><?= h(eventos_financeiro_label_modo($modo)) ?></small></span></td>
                            <td><?= h((string)($receita['unidade'] ?? $eventoUnidade)) ?></td>
                            <td><span class="badge badge-<?= h($status) ?>"><?= h(eventos_financeiro_badge_status($status)) ?></span></td>
                            <td>
                                <?php if (!empty($receita['asaas_invoice_url'])): ?>
                                    <a href="<?= h((string)$receita['asaas_invoice_url']) ?>" target="_blank" rel="noopener">Abrir</a>
                                <?php else: ?>
                                    <span class="muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$receitas): ?><tr><td colspan="9" class="muted">Nenhuma receita lançada.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="tab-panel" id="tab-pedidos">
            <div class="panel-toolbar">
                <h2 class="panel-title">Pedidos</h2>
                <button class="btn btn-blue" type="button" data-open-pedido>+ Adicionar produto e serviço</button>
            </div>

            <div class="table-wrap">
                <table class="finance-table">
                    <thead><tr><th>Item</th><th>Data</th><th>Qtd.</th><th>Valores</th><th>Total</th><th>Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($pedidos as $pedido): ?>
                        <?php
                        $quantidadePedido = max(1, (int)($pedido['quantidade'] ?? 1));
                        $valorBasePedido = (float)($pedido['valor_base'] ?? $pedido['valor'] ?? 0);
                        $valorAdicionalPedido = (float)($pedido['valor_adicional'] ?? 0);
                        $descontoPedido = (float)($pedido['desconto'] ?? 0);
                        $pedidoData = (string)($pedido['data_venda'] ?? '');
                        ?>
                        <tr>
                            <td>
                                <strong><?= h((string)$pedido['descricao']) ?></strong>
                                <div class="muted"><?= h((string)($pedido['pacote_nome'] ?? 'Item manual')) ?></div>
                                <div class="pedido-meta">
                                    <?php if (!empty($pedido['pacote_categoria'])): ?><span class="pedido-chip"><?= h((string)$pedido['pacote_categoria']) ?></span><?php endif; ?>
                                    <?php if ((int)($pedido['pacote_pessoas_base'] ?? 0) > 0): ?><span class="pedido-chip"><?= (int)$pedido['pacote_pessoas_base'] ?> convidados base</span><?php endif; ?>
                                </div>
                            </td>
                            <td><?= h($pedidoData !== '' ? brDateOnly($pedidoData) : brDateOnly((string)($pedido['created_at'] ?? ''))) ?></td>
                            <td><?= (int)$quantidadePedido ?></td>
                            <td>
                                <div>Base: <strong><?= h(format_currency($valorBasePedido)) ?></strong></div>
                                <?php if ($valorAdicionalPedido > 0): ?><div>Adicional: <strong><?= h(format_currency($valorAdicionalPedido)) ?></strong></div><?php endif; ?>
                                <?php if ($descontoPedido > 0): ?><div>Desconto: <strong><?= h(format_currency($descontoPedido)) ?></strong></div><?php endif; ?>
                            </td>
                            <td><span class="money"><?= h(format_currency($pedido['valor'])) ?></span></td>
                            <td>
                                <div class="pedido-actions">
                                    <button class="icon-btn edit" type="button" title="Editar"
                                        data-edit-pedido
                                        data-id="<?= (int)$pedido['id'] ?>"
                                        data-pacote-id="<?= (int)($pedido['pacote_evento_id'] ?? 0) ?>"
                                        data-descricao="<?= h((string)$pedido['descricao']) ?>"
                                        data-quantidade="<?= (int)$quantidadePedido ?>"
                                        data-valor-base="<?= h((string)$valorBasePedido) ?>"
                                        data-valor-adicional="<?= h((string)$valorAdicionalPedido) ?>"
                                        data-desconto="<?= h((string)$descontoPedido) ?>"
                                        data-data-venda="<?= h($pedidoData) ?>"
                                        data-detalhes="<?= h((string)($pedido['detalhes_html'] ?? '')) ?>">✎</button>
                                    <form method="post" onsubmit="return confirm('Remover este pedido do contratado?')">
                                        <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
                                        <input type="hidden" name="action" value="delete_pedido">
                                        <input type="hidden" name="pedido_id" value="<?= (int)$pedido['id'] ?>">
                                        <button class="icon-btn delete" type="submit" title="Excluir">×</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$pedidos): ?><tr><td colspan="6" class="muted">Nenhum pedido lançado.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="pedido-total-bar">TOTAL CONTRATADO: <?= h(format_currency($resumo['contratado'])) ?></div>
        </div>
            </section>
        </main>
    </div>
</div>

<div class="modal-backdrop" id="pedido-modal" role="dialog" aria-modal="true" aria-labelledby="pedido-title">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title" id="pedido-title">Adicionar produto e serviço</h2>
            <button class="modal-close" type="button" data-close-pedido>×</button>
        </div>
        <form method="post" id="pedido-modal-form">
            <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
            <input type="hidden" name="action" value="add_pedido">
            <input type="hidden" name="pedido_id" id="pedido-id" value="0">
            <div class="modal-body">
                <div class="pedido-modal-grid">
                    <div class="form-grid">
                        <div class="field"><label>Data da venda</label><input type="date" name="data_venda" id="pedido-data-venda" value="<?= h(date('Y-m-d')) ?>"></div>
                        <div class="field"><label>Pacote, serviço ou produto</label><select name="pacote_evento_id" id="pedido-pacote">
                            <option value="0">Selecione o item</option>
                            <?php foreach ($pacotes as $pacote): ?>
                                <option value="<?= (int)$pacote['id'] ?>"><?= h((string)$pacote['nome']) ?> · <?= h((string)($pacote['categoria'] ?? 'Pacote')) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                        <div class="field full"><label>Descrição</label><input name="descricao" id="pedido-descricao" placeholder="Ex: Pacote Encanto, painel adicional, serviço extra"></div>
                        <div class="field"><label>Quantidade</label><input type="number" name="quantidade" id="pedido-quantidade" min="1" step="1" value="1"></div>
                        <div class="field"><label>Valor Base</label><input name="valor_base" id="pedido-valor-base" inputmode="decimal" placeholder="R$ 0,00"></div>
                        <div class="field"><label>Convidado adicional / valor adicional</label><input name="valor_adicional" id="pedido-valor-adicional" inputmode="decimal" placeholder="R$ 0,00"></div>
                        <div class="field"><label>Desconto</label><input name="desconto" id="pedido-desconto" inputmode="decimal" placeholder="R$ 0,00"></div>
                        <div class="field full pedido-details-editor"><label>Prévia dos detalhes</label><textarea name="detalhes_html" id="pedido-detalhes" placeholder="Detalhes do pacote, serviço ou produto"></textarea></div>
                    </div>
                    <aside class="pedido-side">
                        <h3>Resumo</h3>
                        <div class="pedido-summary-line"><span>Valor base</span><strong id="pedido-resumo-base">R$ 0,00</strong></div>
                        <div class="pedido-summary-line"><span>Quantidade</span><strong id="pedido-resumo-qtd">1</strong></div>
                        <div class="pedido-summary-line"><span>Adicional</span><strong id="pedido-resumo-adicional">R$ 0,00</strong></div>
                        <div class="pedido-summary-line"><span>Desconto</span><strong id="pedido-resumo-desconto">R$ 0,00</strong></div>
                        <div class="pedido-summary-line pedido-total-line"><span>Total</span><strong id="pedido-resumo-total">R$ 0,00</strong></div>
                    </aside>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-ghost" type="button" data-close-pedido>Cancelar</button>
                <button class="btn btn-green" type="submit">Salvar pedido</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="receita-modal" role="dialog" aria-modal="true" aria-labelledby="receita-title">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title" id="receita-title">Nova Receita</h2>
            <button class="modal-close" type="button" data-close-receita>×</button>
        </div>
        <form method="post" id="receita-form">
            <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
            <input type="hidden" name="action" value="add_receita">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="field full">
                        <label>Tipo de valor</label>
                        <div class="choice-row">
                            <button class="choice active" type="button" data-receita-tipo="avista">Valor à vista</button>
                            <button class="choice" type="button" data-receita-tipo="parcelado">Valor parcelado</button>
                        </div>
                    </div>
                    <div class="field"><label>Descrição</label><input name="descricao" id="receita-descricao" value="Receita do evento"></div>
                    <div class="field"><label>Unidade</label><select name="unidade" id="receita-unidade">
                        <?php foreach ($unidades as $unidade): ?><option value="<?= h($unidade) ?>" <?= $unidade === $eventoUnidade ? 'selected' : '' ?>><?= h($unidade) ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="field"><label>Carteira</label><select name="carteira" id="receita-carteira"><option value="manual">Manual</option><option value="asaas">Asaas</option></select></div>
                    <div class="field"><label>Modo de pagamento</label><select name="modo_pagamento" id="receita-modo">
                        <option value="pix">Pix</option><option value="cartao_credito">Cartão de crédito</option><option value="dinheiro">Dinheiro</option><option value="cartao_debito">Cartão de débito</option><option value="nao_informado">Não informado</option>
                    </select></div>
                    <div class="field" id="status-field"><label>Status de pagamento</label><select name="status_pagamento"><option value="pendente">Pendente</option><option value="pago">Pago</option></select></div>
                    <div class="field" id="valor-total-field"><label>Valor total</label><input name="valor_total" id="receita-valor-total" inputmode="decimal" placeholder="R$ 0,00"></div>
                    <div class="field" id="parcelas-qtd-field"><label>Quantidade de parcelas</label><input type="number" name="parcelas" id="receita-parcelas" min="1" max="24" value="1"></div>
                    <div class="field"><label>Primeiro vencimento</label><input type="date" name="primeiro_vencimento" id="receita-primeiro-vencimento" value="<?= h(date('Y-m-d')) ?>"></div>
                    <div class="field asaas-field hidden"><label>Cliente</label><input name="cliente_nome" value="<?= h((string)$evento['nome_evento']) ?>"></div>
                    <div class="field asaas-field hidden"><label>Email</label><input type="email" name="cliente_email"></div>
                    <div class="field asaas-field hidden"><label>Telefone</label><input name="cliente_telefone"></div>
                    <div class="field asaas-field hidden"><label>CPF/CNPJ</label><input name="cliente_cpf_cnpj"></div>
                </div>

                <div class="parcelas-box">
                    <div class="parcelas-head">
                        <strong>Parcelas</strong>
                        <span class="muted">Datas e valores podem ser alterados antes de salvar.</span>
                    </div>
                    <table class="parcelas-table">
                        <thead><tr><th>Parcela</th><th>Vencimento</th><th>Valor</th></tr></thead>
                        <tbody id="parcelas-body"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-ghost" type="button" data-close-receita>Cancelar</button>
                <button class="btn btn-green" type="submit">Salvar receita</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js"></script>
<script>
function moneyToNumber(value) {
    let raw = String(value || '').replace(/R\$/g, '').trim();
    if (raw.includes(',')) raw = raw.replace(/\./g, '').replace(',', '.');
    const number = Number(raw);
    return Number.isFinite(number) ? number : 0;
}
function numberToMoney(value) {
    return Number(value || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function addDays(dateString, days) {
    const date = new Date(`${dateString}T00:00:00`);
    date.setDate(date.getDate() + days);
    return date.toISOString().slice(0, 10);
}

document.querySelectorAll('.tab-btn').forEach((button) => {
    button.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach((b) => b.classList.remove('active'));
        document.querySelectorAll('.tab-panel').forEach((p) => p.classList.remove('active'));
        button.classList.add('active');
        document.getElementById('tab-' + button.dataset.tab)?.classList.add('active');
    });
});

const pacotesFinanceiro = <?= json_encode($pacotesJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const eventoConvidados = <?= (int)($evento['convidados'] ?? 0) ?>;
const pacoteMap = new Map(pacotesFinanceiro.map((item) => [String(item.id), item]));
const pedidoModal = document.getElementById('pedido-modal');
const pedidoForm = document.getElementById('pedido-modal-form');
const pedidoId = document.getElementById('pedido-id');
const pacoteSelect = document.getElementById('pedido-pacote');
const pedidoDescricao = document.getElementById('pedido-descricao');
const pedidoQuantidade = document.getElementById('pedido-quantidade');
const pedidoValorBase = document.getElementById('pedido-valor-base');
const pedidoValorAdicional = document.getElementById('pedido-valor-adicional');
const pedidoDesconto = document.getElementById('pedido-desconto');
const pedidoDataVenda = document.getElementById('pedido-data-venda');
const pedidoDetalhes = document.getElementById('pedido-detalhes');
const pedidoTitle = document.getElementById('pedido-title');

function pedidoDetalhesEditor() {
    return typeof tinymce !== 'undefined' ? tinymce.get('pedido-detalhes') : null;
}
function getPedidoDetalhes() {
    const editor = pedidoDetalhesEditor();
    return editor ? editor.getContent() : (pedidoDetalhes?.value || '');
}
function setPedidoDetalhes(html) {
    const safeHtml = String(html || '');
    if (pedidoDetalhes) pedidoDetalhes.value = safeHtml;
    const editor = pedidoDetalhesEditor();
    if (editor) editor.setContent(safeHtml);
}
function initPedidoDetalhesEditor() {
    if (typeof tinymce === 'undefined' || !pedidoDetalhes || pedidoDetalhesEditor()) return;
    tinymce.init({
        selector: '#pedido-detalhes',
        base_url: 'https://cdn.jsdelivr.net/npm/tinymce@6',
        height: 310,
        menubar: false,
        branding: false,
        plugins: 'lists link image table code fullscreen',
        toolbar: 'undo redo | bold italic underline strikethrough | alignleft aligncenter alignright | bullist numlist | link image table | removeformat code fullscreen',
        content_style: 'body{font-family:Inter,Arial,sans-serif;font-size:14px;color:#1f2937;line-height:1.45} img{max-width:100%;height:auto}',
        setup(editor) {
            editor.on('input change keyup undo redo SetContent', () => {
                editor.save();
            });
        }
    });
}

function formatCurrencyLabel(value) {
    return 'R$ ' + numberToMoney(value);
}
function syncPedidoResumo() {
    const base = moneyToNumber(pedidoValorBase?.value);
    const qtd = Math.max(1, Number(pedidoQuantidade?.value || 1));
    const adicionalUnitario = moneyToNumber(pedidoValorAdicional?.value);
    const desconto = moneyToNumber(pedidoDesconto?.value);
    const item = pacoteMap.get(String(pacoteSelect?.value || '0'));
    const isPacote = item && String(item.categoria || '').toLowerCase() === 'pacote' && Number(item.pessoasBase || 0) > 0;
    const convidadosBase = isPacote ? Number(item.pessoasBase || 0) : 0;
    const convidadosAdicionais = isPacote ? Math.max(0, qtd - convidadosBase) : 0;
    const adicionalTotal = isPacote ? convidadosAdicionais * adicionalUnitario : adicionalUnitario;
    const total = isPacote
        ? Math.max(0, base + adicionalTotal - desconto)
        : Math.max(0, (base * qtd) + adicionalTotal - desconto);
    document.getElementById('pedido-resumo-base').textContent = formatCurrencyLabel(base);
    document.getElementById('pedido-resumo-qtd').textContent = String(qtd);
    document.getElementById('pedido-resumo-adicional').textContent = isPacote
        ? `${formatCurrencyLabel(adicionalTotal)} (${convidadosAdicionais} convidados)`
        : formatCurrencyLabel(adicionalTotal);
    document.getElementById('pedido-resumo-desconto').textContent = formatCurrencyLabel(desconto);
    document.getElementById('pedido-resumo-total').textContent = formatCurrencyLabel(total);
}
function fillPedidoFromPacote(overrideExisting = false) {
    const item = pacoteMap.get(String(pacoteSelect?.value || '0'));
    if (!item) {
        syncPedidoResumo();
        return;
    }
    if (overrideExisting || !pedidoDescricao.value.trim()) pedidoDescricao.value = item.nome || '';
    if (String(item.categoria || '').toLowerCase() === 'pacote' && Number(item.pessoasBase || 0) > 0) {
        pedidoQuantidade.value = String(Math.max(eventoConvidados || 0, Number(item.pessoasBase || 0), 1));
    }
    if (overrideExisting || moneyToNumber(pedidoValorBase.value) <= 0) pedidoValorBase.value = numberToMoney(item.valorBase || 0);
    if (overrideExisting || moneyToNumber(pedidoValorAdicional.value) <= 0) pedidoValorAdicional.value = numberToMoney(item.valorAdicional || 0);
    if (overrideExisting || !getPedidoDetalhes().trim()) setPedidoDetalhes(item.descricao || '');
    syncPedidoResumo();
}
function resetPedidoForm() {
    pedidoForm?.reset();
    if (pedidoId) pedidoId.value = '0';
    if (pedidoTitle) pedidoTitle.textContent = 'Adicionar produto e serviço';
    if (pedidoDataVenda) pedidoDataVenda.value = '<?= h(date('Y-m-d')) ?>';
    if (pedidoQuantidade) pedidoQuantidade.value = '1';
    if (pedidoValorBase) pedidoValorBase.value = '0,00';
    if (pedidoValorAdicional) pedidoValorAdicional.value = '0,00';
    if (pedidoDesconto) pedidoDesconto.value = '0,00';
    setPedidoDetalhes('');
    syncPedidoResumo();
}
function openPedidoModal(data = null) {
    resetPedidoForm();
    if (data) {
        if (pedidoTitle) pedidoTitle.textContent = 'Editar produto e serviço';
        pedidoId.value = data.id || '0';
        pacoteSelect.value = data.pacoteId || '0';
        pedidoDescricao.value = data.descricao || '';
        pedidoQuantidade.value = data.quantidade || '1';
        pedidoValorBase.value = numberToMoney(data.valorBase || 0);
        pedidoValorAdicional.value = numberToMoney(data.valorAdicional || 0);
        pedidoDesconto.value = numberToMoney(data.desconto || 0);
        pedidoDataVenda.value = data.dataVenda || '<?= h(date('Y-m-d')) ?>';
        setPedidoDetalhes(data.detalhes || '');
    }
    pedidoModal?.classList.add('open');
    initPedidoDetalhesEditor();
    syncPedidoResumo();
}
document.querySelector('[data-open-pedido]')?.addEventListener('click', () => openPedidoModal());
document.querySelectorAll('[data-close-pedido]').forEach((button) => button.addEventListener('click', () => pedidoModal?.classList.remove('open')));
pedidoModal?.addEventListener('click', (event) => { if (event.target === pedidoModal) pedidoModal.classList.remove('open'); });
pacoteSelect?.addEventListener('change', () => fillPedidoFromPacote(true));
[pedidoQuantidade, pedidoValorBase, pedidoValorAdicional, pedidoDesconto].forEach((el) => el?.addEventListener('input', syncPedidoResumo));
[pedidoValorBase, pedidoValorAdicional, pedidoDesconto].forEach((el) => el?.addEventListener('blur', () => { el.value = numberToMoney(moneyToNumber(el.value)); syncPedidoResumo(); }));
pedidoForm?.addEventListener('submit', () => {
    if (typeof tinymce !== 'undefined') {
        tinymce.triggerSave();
    }
});
document.querySelectorAll('[data-edit-pedido]').forEach((button) => {
    button.addEventListener('click', () => openPedidoModal({
        id: button.dataset.id || '0',
        pacoteId: button.dataset.pacoteId || '0',
        descricao: button.dataset.descricao || '',
        quantidade: button.dataset.quantidade || '1',
        valorBase: Number(button.dataset.valorBase || 0),
        valorAdicional: Number(button.dataset.valorAdicional || 0),
        desconto: Number(button.dataset.desconto || 0),
        dataVenda: button.dataset.dataVenda || '',
        detalhes: button.dataset.detalhes || '',
    }));
});

const receitaModal = document.getElementById('receita-modal');
const receitaValor = document.getElementById('receita-valor-total');
const receitaParcelas = document.getElementById('receita-parcelas');
const receitaVencimento = document.getElementById('receita-primeiro-vencimento');
const parcelasBody = document.getElementById('parcelas-body');
const carteiraSelect = document.getElementById('receita-carteira');
const modoSelect = document.getElementById('receita-modo');
let receitaTipo = 'avista';

function renderParcelas() {
    const total = moneyToNumber(receitaValor.value);
    const qtd = receitaTipo === 'parcelado' ? Math.max(1, Math.min(24, Number(receitaParcelas.value || 1))) : 1;
    receitaParcelas.value = qtd;
    const cents = Math.round(total * 100);
    const base = Math.floor(cents / qtd);
    const resto = cents - (base * qtd);
    parcelasBody.innerHTML = '';
    for (let i = 1; i <= qtd; i++) {
        const valorCents = base + (i <= resto ? 1 : 0);
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><strong>${i}/${qtd}</strong></td>
            <td><input type="date" name="parcela_vencimento[]" value="${addDays(receitaVencimento.value, (i - 1) * 30)}"></td>
            <td><input type="text" name="parcela_valor[]" inputmode="decimal" value="${numberToMoney(valorCents / 100)}"></td>
        `;
        parcelasBody.appendChild(tr);
    }
}
function updateReceitaMode() {
    document.querySelectorAll('[data-receita-tipo]').forEach((btn) => btn.classList.toggle('active', btn.dataset.receitaTipo === receitaTipo));
    document.getElementById('parcelas-qtd-field').classList.toggle('hidden', receitaTipo !== 'parcelado');
    const isAsaas = carteiraSelect.value === 'asaas';
    document.querySelectorAll('.asaas-field').forEach((el) => el.classList.toggle('hidden', !isAsaas));
    document.getElementById('status-field').classList.toggle('hidden', isAsaas);
    if (isAsaas) {
        modoSelect.value = 'pix';
        [...modoSelect.options].forEach((option) => option.disabled = option.value !== 'pix');
    } else {
        [...modoSelect.options].forEach((option) => option.disabled = false);
    }
    renderParcelas();
}
document.querySelector('[data-open-receita]')?.addEventListener('click', () => { receitaModal.classList.add('open'); renderParcelas(); });
document.querySelectorAll('[data-close-receita]').forEach((button) => button.addEventListener('click', () => receitaModal.classList.remove('open')));
receitaModal?.addEventListener('click', (event) => { if (event.target === receitaModal) receitaModal.classList.remove('open'); });
document.querySelectorAll('[data-receita-tipo]').forEach((button) => button.addEventListener('click', () => { receitaTipo = button.dataset.receitaTipo; updateReceitaMode(); }));
[receitaValor, receitaParcelas, receitaVencimento, carteiraSelect].forEach((el) => el?.addEventListener('input', updateReceitaMode));
carteiraSelect?.addEventListener('change', updateReceitaMode);
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        receitaModal?.classList.remove('open');
        pedidoModal?.classList.remove('open');
    }
});
updateReceitaMode();
</script>

<?php endSidebar(); ?>
