<?php
// logistica_receitas.php — Cadastro de receitas/fichas técnicas
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

$can_manage = !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_logistico']);

if (!$can_manage) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

$errors = [];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim((string)($_POST['nome'] ?? ''));
        if ($nome === '') {
            $errors[] = 'Nome é obrigatório.';
        } else {
            $dados = [
                ':nome' => $nome,
                ':foto_url' => trim((string)($_POST['foto_url'] ?? '')) ?: null,
                ':tipologia_receita_id' => !empty($_POST['tipologia_receita_id']) ? (int)$_POST['tipologia_receita_id'] : null,
                ':ativo' => !empty($_POST['ativo']) ? 'TRUE' : 'FALSE',
                ':visivel_na_lista' => !empty($_POST['visivel_na_lista']) ? 'TRUE' : 'FALSE',
                ':rendimento_base_pessoas' => (int)($_POST['rendimento_base_pessoas'] ?? 1)
            ];

            if ($id > 0) {
                $sql = "
                    UPDATE logistica_receitas
                    SET nome = :nome,
                        foto_url = :foto_url,
                        tipologia_receita_id = :tipologia_receita_id,
                        ativo = {$dados[':ativo']},
                        visivel_na_lista = {$dados[':visivel_na_lista']},
                        rendimento_base_pessoas = :rendimento_base_pessoas,
                        updated_at = NOW()
                    WHERE id = :id
                ";
                $dados[':id'] = $id;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($dados);
                $messages[] = 'Receita atualizada.';
            } else {
                $sql = "
                    INSERT INTO logistica_receitas
                    (nome, foto_url, tipologia_receita_id, ativo, visivel_na_lista, rendimento_base_pessoas)
                    VALUES
                    (:nome, :foto_url, :tipologia_receita_id, {$dados[':ativo']}, {$dados[':visivel_na_lista']}, :rendimento_base_pessoas)
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($dados);
                $messages[] = 'Receita criada.';
            }
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE logistica_receitas SET ativo = NOT ativo, updated_at = NOW() WHERE id = :id")
                ->execute([':id' => $id]);
        }
    }

    if ($action === 'add_component') {
        $receita_id = (int)($_POST['receita_id'] ?? 0);
        $insumo_id = (int)($_POST['insumo_id'] ?? 0);
        $quantidade = $_POST['quantidade_base'] ?? '';
        if ($receita_id > 0 && $insumo_id > 0 && $quantidade !== '') {
            $stmt = $pdo->prepare("
                INSERT INTO logistica_receita_componentes (receita_id, insumo_id, quantidade_base, unidade)
                VALUES (:receita_id, :insumo_id, :quantidade_base, :unidade)
            ");
            $stmt->execute([
                ':receita_id' => $receita_id,
                ':insumo_id' => $insumo_id,
                ':quantidade_base' => (float)$quantidade,
                ':unidade' => trim((string)($_POST['unidade'] ?? '')) ?: null
            ]);
            $messages[] = 'Componente adicionado.';
        }
    }

    if ($action === 'remove_component') {
        $id = (int)($_POST['component_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM logistica_receita_componentes WHERE id = :id")
                ->execute([':id' => $id]);
            $messages[] = 'Componente removido.';
        }
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [];
if ($search !== '') {
    $where = "WHERE LOWER(r.nome) LIKE LOWER(:q)";
    $params[':q'] = '%' . $search . '%';
}

$stmt = $pdo->prepare("
    SELECT r.*, t.nome AS tipologia_nome
    FROM logistica_receitas r
    LEFT JOIN logistica_tipologias_receita t ON t.id = r.tipologia_receita_id
    {$where}
    ORDER BY r.nome
");
$stmt->execute($params);
$receitas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tipologias = $pdo->query("SELECT id, nome FROM logistica_tipologias_receita WHERE ativo IS TRUE ORDER BY ordem, nome")->fetchAll(PDO::FETCH_ASSOC);
$insumos = $pdo->query("SELECT id, nome_oficial FROM logistica_insumos WHERE ativo IS TRUE ORDER BY nome_oficial")->fetchAll(PDO::FETCH_ASSOC);

$edit_id = (int)($_GET['edit_id'] ?? 0);
$edit_item = null;
$componentes = [];
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM logistica_receitas WHERE id = :id");
    $stmt->execute([':id' => $edit_id]);
    $edit_item = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT c.*, i.nome_oficial
        FROM logistica_receita_componentes c
        JOIN logistica_insumos i ON i.id = c.insumo_id
        WHERE c.receita_id = :id
        ORDER BY i.nome_oficial
    ");
    $stmt->execute([':id' => $edit_id]);
    $componentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

includeSidebar('Receitas - Logística');
?>

<style>
.page-container {
    max-width: 1300px;
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
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
}
.form-input {
    width: 100%;
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
.alert {
    padding: 0.75rem 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}
.alert-error { background: #fee2e2; color: #991b1b; }
.alert-success { background: #dcfce7; color: #166534; }
.upload-preview img {
    max-height: 120px;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}
</style>

<div class="page-container">
    <h1>Receitas / Fichas Técnicas</h1>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-success"><?= h($message) ?></div>
    <?php endforeach; ?>

    <div class="section-card">
        <h2>Nova / Editar Receita</h2>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $edit_item ? (int)$edit_item['id'] : '' ?>">
            <div class="form-grid">
                <div>
                    <label>Nome *</label>
                    <input class="form-input" name="nome" required value="<?= h($edit_item['nome'] ?? '') ?>">
                </div>
                <div>
                    <label>Tipologia</label>
                    <select class="form-input" name="tipologia_receita_id">
                        <option value="">Selecione...</option>
                        <?php foreach ($tipologias as $tip): ?>
                            <option value="<?= (int)$tip['id'] ?>" <?= (int)($edit_item['tipologia_receita_id'] ?? 0) === (int)$tip['id'] ? 'selected' : '' ?>>
                                <?= h($tip['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Rendimento base (pessoas)</label>
                    <input class="form-input" name="rendimento_base_pessoas" type="number" value="<?= h($edit_item['rendimento_base_pessoas'] ?? 1) ?>">
                </div>
                <div>
                    <label>Visível na lista</label>
                    <input type="checkbox" name="visivel_na_lista" <?= !isset($edit_item) || !empty($edit_item['visivel_na_lista']) ? 'checked' : '' ?>>
                </div>
                <div>
                    <label>Ativo</label>
                    <input type="checkbox" name="ativo" <?= !isset($edit_item) || !empty($edit_item['ativo']) ? 'checked' : '' ?>>
                </div>
            </div>
            <div style="margin-top:1rem;">
                <label>Foto (URL)</label>
                <input class="form-input" name="foto_url" id="foto_url" placeholder="Cole a URL ou faça upload" value="<?= h($edit_item['foto_url'] ?? '') ?>">
                <div class="upload-preview" style="margin-top:0.5rem;">
                    <?php if (!empty($edit_item['foto_url'])): ?>
                        <img src="<?= h($edit_item['foto_url']) ?>" alt="Preview">
                    <?php endif; ?>
                </div>
                <div style="margin-top:0.5rem;">
                    <input type="file" id="foto_file" accept="image/*">
                    <button type="button" class="btn-secondary" onclick="uploadFoto()">Upload Magalu</button>
                </div>
            </div>
            <div style="margin-top:1rem;">
                <button class="btn-primary" type="submit">Salvar</button>
            </div>
        </form>
    </div>

    <?php if ($edit_item): ?>
    <div class="section-card">
        <h2>Componentes</h2>
        <form method="POST" style="margin-bottom: 1rem;">
            <input type="hidden" name="action" value="add_component">
            <input type="hidden" name="receita_id" value="<?= (int)$edit_item['id'] ?>">
            <div class="form-grid">
                <div>
                    <label>Insumo</label>
                    <select class="form-input" name="insumo_id" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($insumos as $ins): ?>
                            <option value="<?= (int)$ins['id'] ?>"><?= h($ins['nome_oficial']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Quantidade base</label>
                    <input class="form-input" name="quantidade_base" type="number" step="0.0001" required>
                </div>
                <div>
                    <label>Unidade</label>
                    <input class="form-input" name="unidade" placeholder="kg, un, maço">
                </div>
            </div>
            <div style="margin-top:1rem;">
                <button class="btn-primary" type="submit">Adicionar componente</button>
            </div>
        </form>

        <table class="table">
            <thead>
                <tr>
                    <th>Insumo</th>
                    <th>Quantidade base</th>
                    <th>Unidade</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($componentes as $comp): ?>
                    <tr>
                        <td><?= h($comp['nome_oficial']) ?></td>
                        <td><?= h($comp['quantidade_base']) ?></td>
                        <td><?= h($comp['unidade'] ?? '') ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="remove_component">
                                <input type="hidden" name="component_id" value="<?= (int)$comp['id'] ?>">
                                <button class="btn-secondary" type="submit">Remover</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($componentes)): ?>
                    <tr><td colspan="4">Nenhum componente adicionado.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="section-card">
        <h2>Lista de Receitas</h2>
        <form method="GET" style="margin-bottom:1rem;">
            <input type="hidden" name="page" value="logistica_receitas">
            <input class="form-input" name="q" placeholder="Buscar por nome" value="<?= h($search) ?>">
        </form>
        <table class="table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Tipologia</th>
                    <th>Rendimento</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($receitas as $rec): ?>
                    <tr>
                        <td><?= h($rec['nome']) ?></td>
                        <td><?= h($rec['tipologia_nome'] ?? '') ?></td>
                        <td><?= (int)($rec['rendimento_base_pessoas'] ?? 0) ?></td>
                        <td>
                            <span class="status-pill <?= $rec['ativo'] ? 'status-ativo' : 'status-inativo' ?>">
                                <?= $rec['ativo'] ? 'Ativo' : 'Inativo' ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$rec['id'] ?>">
                                <button class="btn-secondary" type="submit">Ativar/Desativar</button>
                            </form>
                            <a class="btn-secondary" href="index.php?page=logistica_receitas&edit_id=<?= (int)$rec['id'] ?>">Editar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($receitas)): ?>
                    <tr><td colspan="5">Nenhuma receita encontrada.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
async function uploadFoto() {
    const fileInput = document.getElementById('foto_file');
    if (!fileInput.files.length) {
        alert('Selecione um arquivo.');
        return;
    }
    const formData = new FormData();
    formData.append('file', fileInput.files[0]);
    formData.append('context', 'receita');

    const response = await fetch('index.php?page=logistica_upload', {
        method: 'POST',
        body: formData
    });
    const result = await response.json();
    if (!result.ok) {
        alert('Erro no upload: ' + (result.error || ''));
        return;
    }
    const urlInput = document.getElementById('foto_url');
    urlInput.value = result.url;
    const preview = document.querySelector('.upload-preview');
    preview.innerHTML = '<img src="' + result.url + '" alt="Preview">';
}
</script>

<?php endSidebar(); ?>
