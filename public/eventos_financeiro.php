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
        $result = eventos_financeiro_salvar_pedido(
            $pdo,
            $eventoId,
            (int)($_POST['pacote_evento_id'] ?? 0),
            (string)($_POST['descricao'] ?? ''),
            eventos_financeiro_money($_POST['valor'] ?? 0),
            $userId
        );
        if (!empty($result['ok'])) {
            $messages[] = 'Pedido lançado no contratado.';
        } else {
            $errors[] = (string)($result['error'] ?? 'Não foi possível lançar o pedido.');
        }
    }

    if ($action === 'add_receita_manual') {
        $result = eventos_financeiro_salvar_receita_manual(
            $pdo,
            $eventoId,
            (string)($_POST['descricao'] ?? ''),
            (string)($_POST['forma_pagamento'] ?? ''),
            eventos_financeiro_money($_POST['valor'] ?? 0),
            (string)($_POST['vencimento'] ?? ''),
            (string)($_POST['status'] ?? 'pendente'),
            $userId
        );
        if (!empty($result['ok'])) {
            $messages[] = 'Receita lançada.';
        } else {
            $errors[] = (string)($result['error'] ?? 'Não foi possível lançar a receita.');
        }
    }

    if ($action === 'add_receita_asaas') {
        $result = eventos_financeiro_criar_pix_asaas($pdo, $eventoId, $evento, $_POST, $userId);
        if (!empty($result['ok'])) {
            $messages[] = 'Cobrança PIX Asaas criada em ' . (int)($result['created'] ?? 0) . ' parcela(s).';
        } else {
            $errors[] = (string)($result['error'] ?? 'Não foi possível criar a cobrança no Asaas.');
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

$resumo = eventos_financeiro_resumo($pdo, $eventoId);
$pedidos = eventos_financeiro_listar_pedidos($pdo, $eventoId);
$receitas = eventos_financeiro_listar_receitas($pdo, $eventoId);
$pacotes = pacotes_evento_listar($pdo, false);

function eventos_financeiro_label_forma(string $forma): string
{
    return [
        'pix_asaas' => 'PIX Asaas',
        'cartao' => 'Cartão',
        'dinheiro' => 'Dinheiro',
        'transferencia' => 'Transferência',
        'outro' => 'Outro',
    ][$forma] ?? $forma;
}

function eventos_financeiro_badge_status(string $status): string
{
    $labels = [
        'pendente' => 'Pendente',
        'pago' => 'Pago',
        'vencido' => 'Vencido',
        'cancelado' => 'Cancelado',
    ];
    return $labels[$status] ?? $status;
}

includeSidebar('Financeiro do Evento');
?>

<style>
.finance-page{max-width:1480px;margin:0 auto;padding:1.5rem;background:#f8fafc;color:#334155}
.finance-top{display:flex;justify-content:space-between;gap:1rem;align-items:center;margin-bottom:1rem}
.finance-title{margin:0;font-size:1.8rem;color:#1e3a8a;font-weight:800}
.finance-subtitle{margin:.25rem 0 0;color:#64748b}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;border:0;border-radius:8px;padding:.7rem 1rem;font-weight:800;text-decoration:none;cursor:pointer}
.btn-blue{background:#5ebfd4;color:#fff}.btn-green{background:#58c786;color:#fff}.btn-red{background:#e05a43;color:#fff}.btn-slate{background:#e2e8f0;color:#334155}.btn-yellow{background:#f4c44e;color:#fff}
.alert{padding:.85rem 1rem;border-radius:8px;margin-bottom:1rem;background:#fff;border-left:4px solid #5ebfd4}.alert-error{border-left-color:#e05a43}.alert-success{border-left-color:#58c786}
.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem;margin:1.25rem 0}
.summary-card{background:#fff;border:1px solid #e2e8f0;border-left:4px solid #5ebfd4;box-shadow:0 12px 28px rgba(15,23,42,.06);padding:1.15rem;min-height:120px}
.summary-card h3{margin:0 0 .6rem;font-size:.9rem;text-transform:uppercase;color:#64748b}.summary-card .value{font-size:1.6rem;font-weight:800;color:#334155}.summary-card .hint{margin-top:.45rem;color:#64748b;font-size:.86rem}
.summary-card.contratado{border-left-color:#f4c44e}.summary-card.receitas{border-left-color:#58c786}.summary-card.falta{border-left-color:#5ebfd4}.summary-card.resultado.ok{border-left-color:#58c786}.summary-card.resultado.warn{border-left-color:#e05a43}
.finance-grid{display:grid;grid-template-columns:minmax(320px,420px) minmax(0,1fr);gap:1.25rem;align-items:start}
.panel{background:#fff;border:1px solid #e2e8f0;box-shadow:0 12px 28px rgba(15,23,42,.06);padding:1rem}
.panel h2{margin:0 0 1rem;color:#1f2937;font-size:1.15rem}.form-grid{display:grid;gap:.75rem}.form-grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}
label{font-weight:700;color:#475569;font-size:.86rem}input,select,textarea{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:.68rem .75rem;font:inherit;background:#fff}textarea{min-height:78px}
.tabs{display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap}.tab-btn{border:1px solid #dbe3ef;background:#f8fafc;color:#475569;border-radius:8px;padding:.65rem .9rem;font-weight:800;cursor:pointer}.tab-btn.active{background:#5ebfd4;color:#fff;border-color:#5ebfd4}.tab-panel{display:none}.tab-panel.active{display:block}
.table-wrap{overflow:auto}.finance-table{width:100%;border-collapse:collapse;background:#fff}.finance-table th{background:#64748b;color:#fff;text-align:left;padding:.8rem;font-size:.82rem}.finance-table td{border-bottom:1px solid #e2e8f0;padding:.8rem;vertical-align:top}
.badge{display:inline-flex;border-radius:999px;padding:.25rem .6rem;font-size:.76rem;font-weight:800}.badge-pendente{background:#fef3c7;color:#92400e}.badge-pago{background:#dcfce7;color:#166534}.badge-vencido{background:#fee2e2;color:#991b1b}.badge-cancelado{background:#e2e8f0;color:#475569}
.event-link{margin-bottom:1rem}.muted{color:#64748b;font-size:.86rem}
@media(max-width:900px){.summary-grid,.finance-grid,.form-grid.two{grid-template-columns:1fr}.finance-top{align-items:flex-start;flex-direction:column}}
</style>

<div class="finance-page">
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
        <div class="summary-card contratado"><h3>Contratado</h3><div class="value"><?= h(format_currency($resumo['contratado'])) ?></div><div class="hint">Pedidos lançados</div></div>
        <div class="summary-card receitas"><h3>Receitas</h3><div class="value"><?= h(format_currency($resumo['receitas'])) ?></div><div class="hint">Total lançado para receber</div></div>
        <div class="summary-card falta"><h3>Falta receber</h3><div class="value"><?= h(format_currency($resumo['falta_receber'])) ?></div><div class="hint">Receitas ainda não pagas</div></div>
        <div class="summary-card resultado <?= abs($resumo['resultado']) < 0.01 ? 'ok' : 'warn' ?>">
            <h3>Resultado</h3>
            <div class="value"><?= h(format_currency($resumo['resultado'])) ?></div>
            <div class="hint"><?= abs($resumo['resultado']) < 0.01 ? 'Contratado e receitas coerentes' : ($resumo['resultado'] > 0 ? 'Falta lançar receita' : 'Receita acima do contratado') ?></div>
        </div>
    </div>

    <div class="finance-grid">
        <aside class="panel">
            <h2>Pedidos</h2>
            <form method="post" class="form-grid">
                <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
                <input type="hidden" name="action" value="add_pedido">
                <label>Pacote cadastrado</label>
                <select name="pacote_evento_id">
                    <option value="0">Selecionar pacote</option>
                    <?php foreach ($pacotes as $pacote): ?>
                        <option value="<?= (int)$pacote['id'] ?>"><?= h((string)$pacote['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Descrição</label>
                <input name="descricao" placeholder="Ex: Pacote Prata 100">
                <label>Valor</label>
                <input name="valor" inputmode="decimal" placeholder="R$ 0,00">
                <button class="btn btn-green" type="submit">Adicionar pedido</button>
            </form>

            <div class="table-wrap" style="margin-top:1rem">
                <table class="finance-table">
                    <thead><tr><th>Item</th><th>Valor</th></tr></thead>
                    <tbody>
                    <?php foreach ($pedidos as $pedido): ?>
                        <tr><td><?= h((string)$pedido['descricao']) ?></td><td><?= h(format_currency($pedido['valor'])) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$pedidos): ?><tr><td colspan="2" class="muted">Nenhum pedido lançado.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </aside>

        <main class="panel">
            <div class="finance-top" style="margin-bottom:1rem">
                <h2>Movimentações</h2>
                <form method="post">
                    <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
                    <input type="hidden" name="action" value="sync_asaas">
                    <button class="btn btn-slate" type="submit">Atualizar Asaas</button>
                </form>
            </div>

            <div class="tabs">
                <button type="button" class="tab-btn active" data-tab="manual">Nova receita</button>
                <button type="button" class="tab-btn" data-tab="asaas">PIX Asaas</button>
            </div>

            <section class="tab-panel active" id="tab-manual">
                <form method="post" class="form-grid two">
                    <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
                    <input type="hidden" name="action" value="add_receita_manual">
                    <div><label>Descrição</label><input name="descricao" placeholder="Ex: Entrada"></div>
                    <div><label>Forma</label><select name="forma_pagamento"><option value="cartao">Cartão</option><option value="dinheiro">Dinheiro</option><option value="transferencia">Transferência</option><option value="outro">Outro</option></select></div>
                    <div><label>Valor</label><input name="valor" inputmode="decimal" placeholder="R$ 0,00"></div>
                    <div><label>Data</label><input type="date" name="vencimento" value="<?= h(date('Y-m-d')) ?>"></div>
                    <div><label>Status</label><select name="status"><option value="pago">Pago</option><option value="pendente">Pendente</option></select></div>
                    <div style="align-self:end"><button class="btn btn-green" type="submit">Lançar receita</button></div>
                </form>
            </section>

            <section class="tab-panel" id="tab-asaas">
                <form method="post" class="form-grid two">
                    <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
                    <input type="hidden" name="action" value="add_receita_asaas">
                    <div><label>Descrição</label><input name="descricao" value="Receita do evento"></div>
                    <div><label>Valor total</label><input name="valor_total" inputmode="decimal" placeholder="R$ 0,00"></div>
                    <div><label>Parcelas</label><input type="number" min="1" max="24" name="parcelas" value="1"></div>
                    <div><label>Primeiro vencimento</label><input type="date" name="primeiro_vencimento" value="<?= h(date('Y-m-d')) ?>"></div>
                    <div><label>Cliente</label><input name="cliente_nome" value="<?= h((string)$evento['nome_evento']) ?>"></div>
                    <div><label>Email</label><input type="email" name="cliente_email"></div>
                    <div><label>Telefone</label><input name="cliente_telefone"></div>
                    <div><label>CPF/CNPJ</label><input name="cliente_cpf_cnpj"></div>
                    <div style="align-self:end"><button class="btn btn-blue" type="submit">Gerar PIX Asaas</button></div>
                </form>
            </section>

            <div class="table-wrap" style="margin-top:1.25rem">
                <table class="finance-table">
                    <thead><tr><th>Data</th><th>Descrição</th><th>Forma</th><th>Parcela</th><th>Valor</th><th>Status</th><th>Link</th></tr></thead>
                    <tbody>
                    <?php foreach ($receitas as $receita): ?>
                        <?php $status = (string)($receita['status'] ?? 'pendente'); ?>
                        <tr>
                            <td><?= h(brDateOnly((string)($receita['vencimento'] ?? ''))) ?></td>
                            <td><?= h((string)$receita['descricao']) ?></td>
                            <td><?= h(eventos_financeiro_label_forma((string)$receita['forma_pagamento'])) ?></td>
                            <td><?= (int)$receita['parcela_numero'] ?>/<?= (int)$receita['parcelas_total'] ?></td>
                            <td><?= h(format_currency($receita['valor'])) ?></td>
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
                    <?php if (!$receitas): ?><tr><td colspan="7" class="muted">Nenhuma receita lançada.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<script>
document.querySelectorAll('.tab-btn').forEach((button) => {
    button.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach((b) => b.classList.remove('active'));
        document.querySelectorAll('.tab-panel').forEach((p) => p.classList.remove('active'));
        button.classList.add('active');
        document.getElementById('tab-' + button.dataset.tab)?.classList.add('active');
    });
});
</script>

<?php endSidebar(); ?>
