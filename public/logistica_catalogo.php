<?php
require_once __DIR__ . '/logistica_tz.php';
// logistica_catalogo.php — Catálogo unificado (Insumos + Receitas)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';

$can_manage = !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_logistico']);

if (!$can_manage) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

$tipo = trim((string)($_GET['tipo'] ?? 'todos'));
$status = trim((string)($_GET['status'] ?? 'todos'));
$search = trim((string)($_GET['q'] ?? ''));

$tipo = in_array($tipo, ['todos', 'insumos', 'receitas'], true) ? $tipo : 'todos';
$status = in_array($status, ['todos', 'ativos', 'inativos'], true) ? $status : 'todos';

$itens = [];
$catalog_messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        if ($action === 'delete_insumo') {
            $pdo->prepare("DELETE FROM logistica_insumos WHERE id = :id")->execute([':id' => $id]);
            $catalog_messages[] = 'Insumo excluído.';
        } elseif ($action === 'delete_receita') {
            $pdo->prepare("DELETE FROM logistica_receitas WHERE id = :id")->execute([':id' => $id]);
            $catalog_messages[] = 'Receita excluída.';
        }
    }
}

if ($tipo === 'todos' || $tipo === 'insumos') {
    $sql = "
        SELECT i.id,
               i.nome_oficial AS nome,
               i.ativo,
               t.nome AS tipologia,
               u.nome AS unidade
        FROM logistica_insumos i
        LEFT JOIN logistica_tipologias_insumo t ON t.id = i.tipologia_insumo_id
        LEFT JOIN logistica_unidades_medida u ON u.id = i.unidade_medida_padrao_id
    ";
    $conds = [];
    $params = [];
    if ($search !== '') {
        $conds[] = "(LOWER(i.nome_oficial) LIKE LOWER(:q) OR LOWER(COALESCE(i.sinonimos, '')) LIKE LOWER(:q))";
        $params[':q'] = '%' . $search . '%';
    }
    if ($status === 'ativos') {
        $conds[] = "i.ativo IS TRUE";
    } elseif ($status === 'inativos') {
        $conds[] = "i.ativo IS FALSE";
    }
    if ($conds) {
        $sql .= " WHERE " . implode(' AND ', $conds);
    }
    $sql .= " ORDER BY i.nome_oficial";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $itens[] = [
            'tipo' => 'Insumo',
            'nome' => $row['nome'] ?? '',
            'tipologia' => $row['tipologia'] ?? '',
            'extra' => $row['unidade'] ?? '',
            'ativo' => !empty($row['ativo']),
            'id' => (int)$row['id'],
            'page' => 'logistica_insumos'
        ];
    }
}

if ($tipo === 'todos' || $tipo === 'receitas') {
    $sql = "
        SELECT r.id,
               r.nome,
               r.ativo,
               r.rendimento_base_pessoas,
               t.nome AS tipologia
        FROM logistica_receitas r
        LEFT JOIN logistica_tipologias_receita t ON t.id = r.tipologia_receita_id
    ";
    $conds = [];
    $params = [];
    if ($search !== '') {
        $conds[] = "LOWER(r.nome) LIKE LOWER(:q)";
        $params[':q'] = '%' . $search . '%';
    }
    if ($status === 'ativos') {
        $conds[] = "r.ativo IS TRUE";
    } elseif ($status === 'inativos') {
        $conds[] = "r.ativo IS FALSE";
    }
    if ($conds) {
        $sql .= " WHERE " . implode(' AND ', $conds);
    }
    $sql .= " ORDER BY r.nome";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rendimento = (int)($row['rendimento_base_pessoas'] ?? 0);
        $itens[] = [
            'tipo' => 'Receita',
            'nome' => $row['nome'] ?? '',
            'tipologia' => $row['tipologia'] ?? '',
            'extra' => $rendimento > 0 ? ('Rendimento: ' . $rendimento) : '',
            'ativo' => !empty($row['ativo']),
            'id' => (int)$row['id'],
            'page' => 'logistica_receitas'
        ];
    }
}

usort($itens, static function ($a, $b) {
    return strcmp($a['nome'], $b['nome']);
});

includeSidebar('Catálogo Logístico');
?>

<style>
.catalogo-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 1.5rem;
}
.section-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.form-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: center;
}
.form-input {
    padding: 0.6rem 0.75rem;
    border: 1px solid #cbd5f5;
    border-radius: 8px;
}
.btn-primary {
    background: #2563eb;
    color: #fff;
    border: none;
    padding: 0.6rem 1rem;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
}
.btn-secondary {
    background: #e2e8f0;
    color: #1f2937;
    border: none;
    padding: 0.5rem 0.9rem;
    border-radius: 8px;
    cursor: pointer;
}
.table {
    width: 100%;
    border-collapse: collapse;
}
.table th, .table td {
    text-align: left;
    padding: 0.6rem;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.95rem;
}
.status-pill {
    display: inline-block;
    padding: 0.15rem 0.6rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
    color: #fff;
}
.status-ativo { background: #16a34a; }
.status-inativo { background: #f97316; }
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.65);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}
.modal {
    background: #fff;
    border-radius: 12px;
    width: min(1200px, 96vw);
    height: 90vh;
    overflow: hidden;
    box-shadow: 0 20px 50px rgba(0,0,0,0.25);
    display: flex;
    flex-direction: column;
}
.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #e5e7eb;
    background: #f8fafc;
}
.modal-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #0f172a;
}
.modal-close {
    border: none;
    background: #e2e8f0;
    color: #0f172a;
    border-radius: 8px;
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-weight: 700;
}
.modal-body {
    flex: 1;
    background: #ffffff;
}
.modal-iframe {
    width: 100%;
    height: 100%;
    border: none;
}
</style>

<div class="catalogo-container">
    <div class="section-card">
        <?php foreach ($catalog_messages as $msg): ?>
            <div class="alert alert-success"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <div class="form-row" style="justify-content: space-between;">
            <div class="form-row">
                <button class="btn-primary" type="button" onclick="openCatalogModal('insumo')">+ Insumo</button>
                <button class="btn-primary" type="button" onclick="openCatalogModal('receita')">+ Receita</button>
                <button class="btn-secondary" type="button" onclick="location.reload()">Atualizar</button>
            </div>
            <form method="GET" class="form-row">
                <input type="hidden" name="page" value="logistica_catalogo">
                <select class="form-input" name="tipo">
                    <option value="todos" <?= $tipo === 'todos' ? 'selected' : '' ?>>Todos</option>
                    <option value="insumos" <?= $tipo === 'insumos' ? 'selected' : '' ?>>Insumos</option>
                    <option value="receitas" <?= $tipo === 'receitas' ? 'selected' : '' ?>>Receitas</option>
                </select>
                <select class="form-input" name="status">
                    <option value="todos" <?= $status === 'todos' ? 'selected' : '' ?>>Status: todos</option>
                    <option value="ativos" <?= $status === 'ativos' ? 'selected' : '' ?>>Ativos</option>
                    <option value="inativos" <?= $status === 'inativos' ? 'selected' : '' ?>>Inativos</option>
                </select>
                <input class="form-input" name="q" placeholder="Buscar por nome" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                <button class="btn-secondary" type="submit">Filtrar</button>
            </form>
        </div>
    </div>

    <div class="section-card">
        <h2>Catálogo (Insumos + Receitas)</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Nome</th>
                    <th>Tipologia</th>
                    <th>Unidade / Rendimento</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($itens as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['tipo'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($item['nome'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($item['tipologia'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($item['extra'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <span class="status-pill <?= $item['ativo'] ? 'status-ativo' : 'status-inativo' ?>">
                                <?= $item['ativo'] ? 'Ativo' : 'Inativo' ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn-secondary" type="button" onclick="openCatalogModal('<?= $item['page'] === 'logistica_insumos' ? 'insumo' : 'receita' ?>', <?= (int)$item['id'] ?>)">Editar</button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir este item?');">
                                <input type="hidden" name="action" value="<?= $item['page'] === 'logistica_insumos' ? 'delete_insumo' : 'delete_receita' ?>">
                                <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                <button class="btn-secondary" type="submit">Excluir</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($itens)): ?>
                    <tr><td colspan="6">Nenhum item encontrado.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-overlay" id="modal-catalogo">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title" id="modal-catalogo-title">Cadastro</div>
            <button class="modal-close" type="button" onclick="closeCatalogModal()">X</button>
        </div>
        <div class="modal-body">
            <iframe class="modal-iframe" id="modal-catalogo-iframe" src="about:blank"></iframe>
        </div>
    </div>
</div>

<script>
function openCatalogModal(tipo, id) {
    const iframe = document.getElementById('modal-catalogo-iframe');
    const modal = document.getElementById('modal-catalogo');
    const title = document.getElementById('modal-catalogo-title');
    let page = tipo === 'insumo' ? 'logistica_insumos' : 'logistica_receitas';
    let url = `index.php?page=${page}&modal=1`;
    if (id) {
        url += `&edit_id=${id}`;
    }
    url += `&nochecks=1`;
    if (title) {
        title.textContent = tipo === 'insumo' ? 'Cadastro de Insumo' : 'Cadastro de Receita';
    }
    if (iframe) {
        iframe.src = url;
    }
    if (modal) {
        modal.style.display = 'flex';
    }
}

function closeCatalogModal() {
    const modal = document.getElementById('modal-catalogo');
    const iframe = document.getElementById('modal-catalogo-iframe');
    if (iframe) {
        iframe.src = 'about:blank';
    }
    if (modal) {
        modal.style.display = 'none';
    }
}

document.getElementById('modal-catalogo')?.addEventListener('click', (event) => {
    if (event.target.id === 'modal-catalogo') {
        closeCatalogModal();
    }
});
</script>

<?php endSidebar(); ?>
