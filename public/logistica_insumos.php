<?php
// logistica_insumos.php — Cadastro de insumos
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

$can_manage = !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_logistico']);
$can_see_cost = !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_logistico_financeiro']);

if (!$can_manage) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

$errors = [];
$messages = [];
$duplicate_warning = null;

function find_duplicates(PDO $pdo, string $nome, array $sinonimos, int $excludeId = 0): array {
    $conditions = [];
    $params = [];

    $conditions[] = "LOWER(nome_oficial) LIKE LOWER(:nome)";
    $params[':nome'] = '%' . $nome . '%';

    foreach ($sinonimos as $i => $syn) {
        $conditions[] = "LOWER(sinonimos) LIKE LOWER(:syn{$i})";
        $params[":syn{$i}"] = '%' . $syn . '%';
    }

    $sql = "SELECT id, nome_oficial FROM logistica_insumos WHERE (" . implode(' OR ', $conditions) . ")";
    if ($excludeId > 0) {
        $sql .= " AND id <> :exclude_id";
        $params[':exclude_id'] = $excludeId;
    }
    $sql .= " ORDER BY nome_oficial";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim((string)($_POST['nome_oficial'] ?? ''));
        $sinonimos_raw = trim((string)($_POST['sinonimos'] ?? ''));
        $sinonimos = array_values(array_filter(array_map('trim', preg_split('/\R/', $sinonimos_raw))));
        $confirm_duplicate = !empty($_POST['confirm_duplicate']);

        if ($nome === '') {
            $errors[] = 'Nome oficial é obrigatório.';
        }

        if (!$errors && !$confirm_duplicate) {
            $dups = find_duplicates($pdo, $nome, $sinonimos ?: [$nome], $id);
            if (!empty($dups)) {
                $duplicate_warning = $dups;
            }
        }

        if (!$errors && !$duplicate_warning) {
            $dados = [
                ':nome_oficial' => $nome,
                ':foto_url' => trim((string)($_POST['foto_url'] ?? '')) ?: null,
                ':unidade_medida' => trim((string)($_POST['unidade_medida'] ?? '')) ?: null,
                ':tipologia_insumo_id' => !empty($_POST['tipologia_insumo_id']) ? (int)$_POST['tipologia_insumo_id'] : null,
                ':visivel_na_lista' => !empty($_POST['visivel_na_lista']) ? 'TRUE' : 'FALSE',
                ':ativo' => !empty($_POST['ativo']) ? 'TRUE' : 'FALSE',
                ':sinonimos' => $sinonimos_raw !== '' ? $sinonimos_raw : null,
                ':barcode' => trim((string)($_POST['barcode'] ?? '')) ?: null,
                ':fracionavel' => !empty($_POST['fracionavel']) ? 'TRUE' : 'FALSE',
                ':tamanho_embalagem' => $_POST['tamanho_embalagem'] !== '' ? (float)$_POST['tamanho_embalagem'] : null,
                ':unidade_embalagem' => trim((string)($_POST['unidade_embalagem'] ?? '')) ?: null,
                ':observacoes' => trim((string)($_POST['observacoes'] ?? '')) ?: null
            ];

            if ($can_see_cost) {
                $dados[':custo_padrao'] = $_POST['custo_padrao'] !== '' ? (float)$_POST['custo_padrao'] : null;
            }

            if ($id > 0) {
                $sql = "
                    UPDATE logistica_insumos
                    SET nome_oficial = :nome_oficial,
                        foto_url = :foto_url,
                        unidade_medida = :unidade_medida,
                        tipologia_insumo_id = :tipologia_insumo_id,
                        visivel_na_lista = {$dados[':visivel_na_lista']},
                        ativo = {$dados[':ativo']},
                        sinonimos = :sinonimos,
                        barcode = :barcode,
                        fracionavel = {$dados[':fracionavel']},
                        tamanho_embalagem = :tamanho_embalagem,
                        unidade_embalagem = :unidade_embalagem,
                        observacoes = :observacoes,
                        updated_at = NOW()
                ";
                if ($can_see_cost) {
                    $sql .= ", custo_padrao = :custo_padrao";
                }
                $sql .= " WHERE id = :id";
                $dados[':id'] = $id;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($dados);
                $messages[] = 'Insumo atualizado.';
            } else {
                $sql = "
                    INSERT INTO logistica_insumos
                    (nome_oficial, foto_url, unidade_medida, tipologia_insumo_id, visivel_na_lista, ativo,
                     sinonimos, barcode, fracionavel, tamanho_embalagem, unidade_embalagem, custo_padrao, observacoes)
                    VALUES
                    (:nome_oficial, :foto_url, :unidade_medida, :tipologia_insumo_id, {$dados[':visivel_na_lista']}, {$dados[':ativo']},
                     :sinonimos, :barcode, {$dados[':fracionavel']}, :tamanho_embalagem, :unidade_embalagem, :custo_padrao, :observacoes)
                ";
                if (!$can_see_cost) {
                    $sql = str_replace(':custo_padrao', 'NULL', $sql);
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($dados);
                $messages[] = 'Insumo criado.';
            }
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE logistica_insumos SET ativo = NOT ativo, updated_at = NOW() WHERE id = :id")
                ->execute([':id' => $id]);
        }
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [];
if ($search !== '') {
    $where = "WHERE LOWER(nome_oficial) LIKE LOWER(:q) OR LOWER(sinonimos) LIKE LOWER(:q)";
    $params[':q'] = '%' . $search . '%';
}

$stmt = $pdo->prepare("
    SELECT i.*, t.nome AS tipologia_nome
    FROM logistica_insumos i
    LEFT JOIN logistica_tipologias_insumo t ON t.id = i.tipologia_insumo_id
    {$where}
    ORDER BY i.nome_oficial
");
$stmt->execute($params);
$insumos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tipologias = $pdo->query("SELECT id, nome FROM logistica_tipologias_insumo WHERE ativo IS TRUE ORDER BY ordem, nome")->fetchAll(PDO::FETCH_ASSOC);

$edit_id = (int)($_GET['edit_id'] ?? 0);
$edit_item = null;
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM logistica_insumos WHERE id = :id");
    $stmt->execute([':id' => $edit_id]);
    $edit_item = $stmt->fetch(PDO::FETCH_ASSOC);
}

includeSidebar('Insumos - Logística');
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
    <h1>Insumos</h1>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-success"><?= h($message) ?></div>
    <?php endforeach; ?>

    <?php if ($duplicate_warning): ?>
        <div class="alert alert-error">
            Possível duplicado encontrado:
            <ul>
                <?php foreach ($duplicate_warning as $dup): ?>
                    <li><?= h($dup['nome_oficial']) ?> (ID <?= (int)$dup['id'] ?>)</li>
                <?php endforeach; ?>
            </ul>
            Reenvie salvando para confirmar.
        </div>
    <?php endif; ?>

    <div class="section-card">
        <h2>Novo / Editar Insumo</h2>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $edit_item ? (int)$edit_item['id'] : '' ?>">
            <input type="hidden" name="confirm_duplicate" value="<?= $duplicate_warning ? '1' : '' ?>">
            <div class="form-grid">
                <div>
                    <label>Nome oficial *</label>
                    <input class="form-input" name="nome_oficial" required value="<?= h($edit_item['nome_oficial'] ?? '') ?>">
                </div>
                <div>
                    <label>Unidade medida</label>
                    <input class="form-input" name="unidade_medida" placeholder="kg, un, pacote" value="<?= h($edit_item['unidade_medida'] ?? '') ?>">
                </div>
                <div>
                    <label>Tipologia</label>
                    <select class="form-input" name="tipologia_insumo_id">
                        <option value="">Selecione...</option>
                        <?php foreach ($tipologias as $tip): ?>
                            <option value="<?= (int)$tip['id'] ?>" <?= (int)($edit_item['tipologia_insumo_id'] ?? 0) === (int)$tip['id'] ? 'selected' : '' ?>>
                                <?= h($tip['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Barcode</label>
                    <input class="form-input" name="barcode" value="<?= h($edit_item['barcode'] ?? '') ?>">
                </div>
                <div>
                    <label>Fracionável</label>
                    <input type="checkbox" name="fracionavel" <?= !isset($edit_item) || !empty($edit_item['fracionavel']) ? 'checked' : '' ?>>
                </div>
                <div>
                    <label>Tamanho embalagem</label>
                    <input class="form-input" name="tamanho_embalagem" type="number" step="0.0001" value="<?= h($edit_item['tamanho_embalagem'] ?? '') ?>">
                </div>
                <div>
                    <label>Unidade embalagem</label>
                    <input class="form-input" name="unidade_embalagem" value="<?= h($edit_item['unidade_embalagem'] ?? '') ?>">
                </div>
                <?php if ($can_see_cost): ?>
                <div>
                    <label>Custo padrão</label>
                    <input class="form-input" name="custo_padrao" type="number" step="0.0001" value="<?= h($edit_item['custo_padrao'] ?? '') ?>">
                </div>
                <?php endif; ?>
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
                <label>Sinônimos (1 por linha)</label>
                <textarea class="form-input" name="sinonimos" rows="4"><?= h($edit_item['sinonimos'] ?? '') ?></textarea>
            </div>
            <div style="margin-top:1rem;">
                <label>Observações</label>
                <textarea class="form-input" name="observacoes" rows="3"><?= h($edit_item['observacoes'] ?? '') ?></textarea>
            </div>
            <div style="margin-top:1rem;">
                <button class="btn-primary" type="submit">Salvar</button>
            </div>
        </form>
    </div>

    <div class="section-card">
        <h2>Lista de Insumos</h2>
        <form method="GET" style="margin-bottom:1rem;">
            <input type="hidden" name="page" value="logistica_insumos">
            <input class="form-input" name="q" placeholder="Buscar por nome ou sinônimo" value="<?= h($search) ?>">
        </form>
        <table class="table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Tipologia</th>
                    <th>Unidade</th>
                    <th>Status</th>
                    <?php if ($can_see_cost): ?>
                        <th>Custo</th>
                    <?php endif; ?>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($insumos as $insumo): ?>
                    <tr>
                        <td><?= h($insumo['nome_oficial']) ?></td>
                        <td><?= h($insumo['tipologia_nome'] ?? '') ?></td>
                        <td><?= h($insumo['unidade_medida'] ?? '') ?></td>
                        <td>
                            <span class="status-pill <?= $insumo['ativo'] ? 'status-ativo' : 'status-inativo' ?>">
                                <?= $insumo['ativo'] ? 'Ativo' : 'Inativo' ?>
                            </span>
                        </td>
                        <?php if ($can_see_cost): ?>
                            <td><?= $insumo['custo_padrao'] !== null ? number_format((float)$insumo['custo_padrao'], 2, ',', '.') : '-' ?></td>
                        <?php endif; ?>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$insumo['id'] ?>">
                                <button class="btn-secondary" type="submit">Ativar/Desativar</button>
                            </form>
                            <a class="btn-secondary" href="index.php?page=logistica_insumos&edit_id=<?= (int)$insumo['id'] ?>">Editar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($insumos)): ?>
                    <tr><td colspan="<?= $can_see_cost ? '6' : '5' ?>">Nenhum insumo encontrado.</td></tr>
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
    formData.append('context', 'insumo');

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
