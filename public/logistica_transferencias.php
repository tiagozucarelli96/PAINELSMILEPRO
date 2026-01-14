<?php
// logistica_transferencias.php — Transferências Garden → unidades
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

if (empty($_SESSION['perm_superadmin']) && empty($_SESSION['perm_logistico'])) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

function parse_decimal_local(string $value): float {
    $raw = trim($value);
    if ($raw === '') return 0.0;
    $normalized = preg_replace('/[^0-9,\\.]/', '', $raw);
    if (strpos($normalized, ',') !== false && strpos($normalized, '.') !== false) {
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);
    } else {
        $normalized = str_replace(',', '.', $normalized);
    }
    return $normalized === '' ? 0.0 : (float)$normalized;
}

$errors = [];
$messages = [];

$unidades = $pdo->query("SELECT id, codigo, nome FROM logistica_unidades WHERE ativo IS TRUE")->fetchAll(PDO::FETCH_ASSOC);
$unidades_by_codigo = [];
foreach ($unidades as $u) {
    $unidades_by_codigo[$u['codigo']] = $u;
}

$origin = $unidades_by_codigo['GardenCentral'] ?? null;
if (!$origin) {
    $errors[] = 'Unidade GardenCentral não encontrada.';
}

$destinos = [
    ['label' => 'Lisbon 1', 'codigo' => 'Lisbon1', 'space' => 'Lisbon 1'],
    ['label' => 'DiverKids', 'codigo' => 'DiverKids', 'space' => 'DiverKids'],
    ['label' => 'Cristal', 'codigo' => 'GardenCentral', 'space' => 'Cristal']
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create' && $origin) {
        $destino_key = (string)($_POST['destino'] ?? '');
        $destino = null;
        foreach ($destinos as $d) {
            if ($d['label'] === $destino_key) {
                $destino = $d;
                break;
            }
        }

        if (!$destino || empty($unidades_by_codigo[$destino['codigo']])) {
            $errors[] = 'Destino inválido.';
        } else {
            $insumos = $_POST['insumo_id'] ?? [];
            $quantidades = $_POST['quantidade'] ?? [];
            $unidades_medida = $_POST['unidade_medida_id'] ?? [];
            $obs = trim((string)($_POST['observacao'] ?? ''));
            $linhas = 0;

            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("
                    INSERT INTO logistica_transferencias
                        (unidade_origem_id, unidade_destino_id, status, criado_por, criado_em, observacao, space_destino)
                    VALUES
                        (:origem, :destino, 'rascunho', :criado_por, NOW(), :observacao, :space_destino)
                    RETURNING id
                ");
                $stmt->execute([
                    ':origem' => (int)$origin['id'],
                    ':destino' => (int)$unidades_by_codigo[$destino['codigo']]['id'],
                    ':criado_por' => (int)($_SESSION['id'] ?? 0),
                    ':observacao' => $obs !== '' ? $obs : null,
                    ':space_destino' => $destino['space']
                ]);
                $transfer_id = (int)$stmt->fetchColumn();

                $stmt_item = $pdo->prepare("
                    INSERT INTO logistica_transferencias_itens
                        (transferencia_id, insumo_id, quantidade, unidade_medida_id, check_carregado, check_recebido)
                    VALUES
                        (:tid, :insumo_id, :quantidade, :unidade_medida_id, FALSE, FALSE)
                ");

                foreach ($insumos as $idx => $insumo_id_raw) {
                    $insumo_id = (int)$insumo_id_raw;
                    if ($insumo_id <= 0) { continue; }
                    $quantidade = parse_decimal_local((string)($quantidades[$idx] ?? ''));
                    if ($quantidade <= 0) { continue; }
                    $un_medida_id = (int)($unidades_medida[$idx] ?? 0);

                    $stmt_item->execute([
                        ':tid' => $transfer_id,
                        ':insumo_id' => $insumo_id,
                        ':quantidade' => $quantidade,
                        ':unidade_medida_id' => $un_medida_id ?: null
                    ]);
                    $linhas++;
                }

                if ($linhas === 0) {
                    throw new RuntimeException('Informe ao menos um item.');
                }

                $pdo->commit();
                $messages[] = 'Transferência criada.';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Erro ao criar transferência: ' . $e->getMessage();
            }
        }
    }
}

$transferencias = $pdo->query("
    SELECT t.*, u.nome AS destino_nome
    FROM logistica_transferencias t
    LEFT JOIN logistica_unidades u ON u.id = t.unidade_destino_id
    ORDER BY t.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$insumos = $pdo->query("
    SELECT i.id, i.nome_oficial, i.unidade_medida_padrao_id, u.nome AS unidade_nome
    FROM logistica_insumos i
    LEFT JOIN logistica_unidades_medida u ON u.id = i.unidade_medida_padrao_id
    WHERE i.ativo IS TRUE
    ORDER BY i.nome_oficial
")->fetchAll(PDO::FETCH_ASSOC);

includeSidebar('Transferências - Logística');
?>

<style>
.transfer-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 1.5rem;
}
.card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.btn {
    padding: .55rem .9rem;
    border-radius: 8px;
    border: none;
    cursor: pointer;
}
.btn-primary { background: #2563eb; color: #fff; }
.btn-secondary { background: #e2e8f0; color: #0f172a; }
.table {
    width: 100%;
    border-collapse: collapse;
}
.table th, .table td {
    padding: .6rem;
    border-bottom: 1px solid #e5e7eb;
}
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,.4);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}
.modal {
    background: #fff;
    border-radius: 12px;
    padding: 1rem;
    width: min(720px, 92vw);
    max-height: 90vh;
    overflow: auto;
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: .5rem;
}
</style>

<div class="transfer-container">
    <h1>Transferências</h1>

    <?php foreach ($errors as $e): ?>
        <div class="alert-error"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
    <?php foreach ($messages as $m): ?>
        <div class="alert-success"><?= htmlspecialchars($m) ?></div>
    <?php endforeach; ?>

    <div class="card" style="margin-bottom:1.5rem;">
        <h3>Nova transferência</h3>
        <form method="post">
            <input type="hidden" name="action" value="create">
            <div style="display:flex;gap:1rem;flex-wrap:wrap;">
                <div style="min-width:240px;">
                    <label>Destino</label>
                    <select name="destino" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($destinos as $d): ?>
                            <option value="<?= htmlspecialchars($d['label']) ?>"><?= htmlspecialchars($d['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex:1;min-width:260px;">
                    <label>Observação</label>
                    <input type="text" name="observacao">
                </div>
            </div>

            <table class="table" style="margin-top:1rem;" id="transfer-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th style="width:140px;">Quantidade</th>
                        <th style="width:160px;">Unidade</th>
                        <th style="width:80px;"></th>
                    </tr>
                </thead>
                <tbody id="transfer-body">
                    <tr>
                        <td>
                            <input type="text" class="item-nome" readonly>
                            <input type="hidden" name="insumo_id[]" class="item-id">
                            <button class="btn btn-secondary open-modal" type="button">🔍</button>
                        </td>
                        <td><input type="text" name="quantidade[]" placeholder="0,00"></td>
                        <td>
                            <input type="text" class="item-unidade" readonly>
                            <input type="hidden" name="unidade_medida_id[]" class="item-unidade-id">
                        </td>
                        <td><button class="btn btn-secondary remove-row" type="button">X</button></td>
                    </tr>
                </tbody>
            </table>

            <div style="margin-top:.75rem;">
                <button class="btn btn-secondary" type="button" id="add-row">Adicionar linha</button>
            </div>
            <div style="margin-top:1rem;">
                <button class="btn btn-primary" type="submit">Salvar transferência</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Histórico</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Destino</th>
                    <th>Status</th>
                    <th>Criado em</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$transferencias): ?>
                    <tr><td colspan="5">Nenhuma transferência encontrada.</td></tr>
                <?php else: ?>
                    <?php foreach ($transferencias as $t): ?>
                        <tr>
                            <td><?= (int)$t['id'] ?></td>
                            <td><?= htmlspecialchars($t['space_destino'] ?: ($t['destino_nome'] ?? '')) ?></td>
                            <td><?= htmlspecialchars($t['status']) ?></td>
                            <td><?= format_date($t['criado_em'] ?? null) ?></td>
                            <td>
                                <a class="btn btn-secondary" href="index.php?page=logistica_transferencia_ver&id=<?= (int)$t['id'] ?>">Ver</a>
                                <?php if ($t['status'] === 'em_transito'): ?>
                                    <a class="btn btn-secondary" href="index.php?page=logistica_transferencia_receber&id=<?= (int)$t['id'] ?>">Receber</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-overlay" id="modal-insumos">
    <div class="modal">
        <div class="modal-header">
            <strong>Selecionar insumo</strong>
            <button class="btn btn-secondary" type="button" id="close-modal">Fechar</button>
        </div>
        <input type="text" id="search-insumo" placeholder="Buscar por nome">
        <div id="insumo-list" style="margin-top:.75rem;"></div>
        <div style="margin-top:.75rem; text-align:right;">
            <button class="btn btn-primary" type="button" id="select-insumo">Selecionar</button>
        </div>
    </div>
</div>

<script>
const INSUMOS = <?= json_encode($insumos, JSON_UNESCAPED_UNICODE) ?>;
let currentRow = null;
let selectedInsumo = null;

function renderInsumos() {
    const term = document.getElementById('search-insumo').value.toLowerCase();
    const list = document.getElementById('insumo-list');
    list.innerHTML = '';
    const filtered = INSUMOS.filter(i => (i.nome_oficial || '').toLowerCase().includes(term));
    filtered.forEach(item => {
        const div = document.createElement('div');
        div.style.padding = '.5rem';
        div.style.borderBottom = '1px solid #e5e7eb';
        div.style.cursor = 'pointer';
        div.textContent = item.nome_oficial;
        div.onclick = () => {
            selectedInsumo = item;
            [...list.querySelectorAll('.selected')].forEach(n => n.classList.remove('selected'));
            div.classList.add('selected');
            div.style.background = '#eff6ff';
        };
        list.appendChild(div);
    });
}

function openModal(row) {
    currentRow = row;
    selectedInsumo = null;
    document.getElementById('search-insumo').value = '';
    renderInsumos();
    document.getElementById('modal-insumos').style.display = 'flex';
}

function closeModal() {
    document.getElementById('modal-insumos').style.display = 'none';
    currentRow = null;
}

document.getElementById('close-modal').addEventListener('click', closeModal);
document.getElementById('modal-insumos').addEventListener('click', (e) => {
    if (e.target.id === 'modal-insumos') closeModal();
});
document.getElementById('search-insumo').addEventListener('input', renderInsumos);

document.getElementById('select-insumo').addEventListener('click', () => {
    if (!currentRow || !selectedInsumo) return;
    currentRow.querySelector('.item-nome').value = selectedInsumo.nome_oficial;
    currentRow.querySelector('.item-id').value = selectedInsumo.id;
    currentRow.querySelector('.item-unidade').value = selectedInsumo.unidade_nome || 'Sem unidade';
    currentRow.querySelector('.item-unidade-id').value = selectedInsumo.unidade_medida_padrao_id || '';
    closeModal();
});

document.getElementById('transfer-body').addEventListener('click', (e) => {
    if (e.target.classList.contains('open-modal')) {
        openModal(e.target.closest('tr'));
    }
    if (e.target.classList.contains('remove-row')) {
        const rows = document.querySelectorAll('#transfer-body tr');
        if (rows.length > 1) {
            e.target.closest('tr').remove();
        }
    }
});

document.getElementById('add-row').addEventListener('click', () => {
    const tbody = document.getElementById('transfer-body');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td>
            <input type="text" class="item-nome" readonly>
            <input type="hidden" name="insumo_id[]" class="item-id">
            <button class="btn btn-secondary open-modal" type="button">🔍</button>
        </td>
        <td><input type="text" name="quantidade[]" placeholder="0,00"></td>
        <td>
            <input type="text" class="item-unidade" readonly>
            <input type="hidden" name="unidade_medida_id[]" class="item-unidade-id">
        </td>
        <td><button class="btn btn-secondary remove-row" type="button">X</button></td>
    `;
    tbody.appendChild(tr);
});
</script>

<?php endSidebar(); ?>
