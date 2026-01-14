<?php
// logistica_entrada.php — Entrada de mercadoria
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $unidade_id = (int)($_POST['unidade_id'] ?? 0);
        $insumos = $_POST['insumo_id'] ?? [];
        $quantidades = $_POST['quantidade'] ?? [];
        $unidades = $_POST['unidade_medida_id'] ?? [];
        $obs = trim((string)($_POST['observacao'] ?? ''));

        if ($unidade_id <= 0) {
            $errors[] = 'Selecione a unidade.';
        } else {
            $linhas_validas = 0;
            try {
                $pdo->beginTransaction();
                foreach ($insumos as $idx => $insumo_id_raw) {
                    $insumo_id = (int)$insumo_id_raw;
                    if ($insumo_id <= 0) { continue; }
                    $quantidade = parse_decimal_local((string)($quantidades[$idx] ?? ''));
                    if ($quantidade <= 0) { continue; }
                    $unidade_medida_id = (int)($unidades[$idx] ?? 0);

                    $stmt = $pdo->prepare("
                        INSERT INTO logistica_estoque_saldos (unidade_id, insumo_id, quantidade_atual, unidade_medida_id, updated_at)
                        VALUES (:unidade_id, :insumo_id, :quantidade, :unidade_medida_id, NOW())
                        ON CONFLICT (unidade_id, insumo_id)
                        DO UPDATE SET quantidade_atual = logistica_estoque_saldos.quantidade_atual + EXCLUDED.quantidade_atual,
                                     unidade_medida_id = COALESCE(EXCLUDED.unidade_medida_id, logistica_estoque_saldos.unidade_medida_id),
                                     updated_at = NOW()
                    ");
                    $stmt->execute([
                        ':unidade_id' => $unidade_id,
                        ':insumo_id' => $insumo_id,
                        ':quantidade' => $quantidade,
                        ':unidade_medida_id' => $unidade_medida_id ?: null
                    ]);

                    $mov = $pdo->prepare("
                        INSERT INTO logistica_estoque_movimentos
                            (unidade_id_origem, unidade_id_destino, insumo_id, tipo, quantidade, referencia_tipo, referencia_id, criado_por, criado_em, observacao)
                        VALUES
                            (NULL, :destino, :insumo_id, 'entrada', :quantidade, 'entrada_manual', NULL, :criado_por, NOW(), :observacao)
                    ");
                    $mov->execute([
                        ':destino' => $unidade_id,
                        ':insumo_id' => $insumo_id,
                        ':quantidade' => $quantidade,
                        ':criado_por' => (int)($_SESSION['id'] ?? 0),
                        ':observacao' => $obs !== '' ? $obs : null
                    ]);
                    $linhas_validas++;
                }
                $pdo->commit();
                if ($linhas_validas > 0) {
                    $messages[] = 'Entrada registrada com sucesso.';
                } else {
                    $errors[] = 'Informe pelo menos um item com quantidade.';
                }
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Erro ao salvar entrada: ' . $e->getMessage();
            }
        }
    }
}

$unidades = $pdo->query("SELECT id, nome FROM logistica_unidades WHERE ativo IS TRUE ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$insumos = $pdo->query("
    SELECT i.id, i.nome_oficial, i.unidade_medida_padrao_id, u.nome AS unidade_nome
    FROM logistica_insumos i
    LEFT JOIN logistica_unidades_medida u ON u.id = i.unidade_medida_padrao_id
    WHERE i.ativo IS TRUE
    ORDER BY i.nome_oficial
")->fetchAll(PDO::FETCH_ASSOC);

includeSidebar('Entrada de Mercadoria - Logística');
?>

<style>
.entrada-container {
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
.entrada-table {
    width: 100%;
    border-collapse: collapse;
}
.entrada-table th, .entrada-table td {
    padding: .5rem;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: middle;
}
.entrada-table input {
    width: 100%;
}
.btn {
    padding: .55rem .9rem;
    border-radius: 8px;
    border: none;
    cursor: pointer;
}
.btn-primary { background: #2563eb; color: #fff; }
.btn-secondary { background: #e2e8f0; color: #0f172a; }
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

<div class="entrada-container">
    <h1>Entrada de mercadoria</h1>
    <?php foreach ($errors as $e): ?>
        <div class="alert-error"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
    <?php foreach ($messages as $m): ?>
        <div class="alert-success"><?= htmlspecialchars($m) ?></div>
    <?php endforeach; ?>

    <form method="post" class="card">
        <input type="hidden" name="action" value="save">
        <div style="display:flex;gap:1rem;flex-wrap:wrap;">
            <div style="min-width:280px;">
                <label>Unidade destino</label>
                <select name="unidade_id" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($unidades as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1;min-width:260px;">
                <label>Observação</label>
                <input type="text" name="observacao" placeholder="Compra mercado, fornecedor, etc">
            </div>
        </div>

        <div style="margin-top:1rem;">
            <table class="entrada-table" id="entrada-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th style="width:140px;">Quantidade</th>
                        <th style="width:160px;">Unidade</th>
                        <th style="width:90px;"></th>
                    </tr>
                </thead>
                <tbody id="entrada-body">
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
            <div style="margin-top:0.75rem;">
                <button class="btn btn-secondary" type="button" id="add-row">Adicionar linha</button>
            </div>
        </div>

        <div style="margin-top:1.5rem;">
            <button class="btn btn-primary" type="submit">Salvar entrada</button>
        </div>
    </form>
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
        div.className = 'list-item';
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

document.getElementById('entrada-body').addEventListener('click', (e) => {
    if (e.target.classList.contains('open-modal')) {
        const row = e.target.closest('tr');
        openModal(row);
    }
    if (e.target.classList.contains('remove-row')) {
        const rows = document.querySelectorAll('#entrada-body tr');
        if (rows.length > 1) {
            e.target.closest('tr').remove();
        }
    }
});

document.getElementById('add-row').addEventListener('click', () => {
    const tbody = document.getElementById('entrada-body');
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
