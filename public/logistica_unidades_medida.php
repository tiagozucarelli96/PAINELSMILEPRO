<?php
// logistica_unidades_medida.php — Cadastro de unidades de medida
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

$errors = [];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim((string)($_POST['nome'] ?? ''));
        $ordem = (int)($_POST['ordem'] ?? 0);
        $ativo = !empty($_POST['ativo']);

        if ($nome === '') {
            $errors[] = 'Nome é obrigatório.';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE logistica_unidades_medida SET nome = :nome, ordem = :ordem, ativo = :ativo, updated_at = NOW() WHERE id = :id");
                $stmt->execute([
                    ':nome' => $nome,
                    ':ordem' => $ordem,
                    ':ativo' => $ativo,
                    ':id' => $id
                ]);
                $messages[] = 'Unidade atualizada.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO logistica_unidades_medida (nome, ordem, ativo) VALUES (:nome, :ordem, :ativo)");
                $stmt->execute([
                    ':nome' => $nome,
                    ':ordem' => $ordem,
                    ':ativo' => $ativo
                ]);
                $messages[] = 'Unidade criada.';
            }
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE logistica_unidades_medida SET ativo = NOT ativo, updated_at = NOW() WHERE id = :id")
                ->execute([':id' => $id]);
        }
    }
}

$unidades = $pdo->query("SELECT * FROM logistica_unidades_medida ORDER BY ordem, nome")->fetchAll(PDO::FETCH_ASSOC);
$edit_id = (int)($_GET['edit_id'] ?? 0);
$edit_item = null;
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM logistica_unidades_medida WHERE id = :id");
    $stmt->execute([':id' => $edit_id]);
    $edit_item = $stmt->fetch(PDO::FETCH_ASSOC);
}

includeSidebar('Unidades de Medida - Logística');
?>

<style>
.page-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 1.5rem;
}

.section-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.section-card h2 {
    margin: 0 0 1rem 0;
    font-size: 1.25rem;
    color: #0f172a;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
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

.table th,
.table td {
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
</style>

<div class="page-container">
    <h1>Unidades de Medida</h1>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-success"><?= h($message) ?></div>
    <?php endforeach; ?>

    <div class="section-card">
        <h2>Nova / Editar Unidade</h2>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $edit_item ? (int)$edit_item['id'] : '' ?>">
            <div class="form-row">
                <div>
                    <label>Nome</label>
                    <input class="form-input" name="nome" required value="<?= h($edit_item['nome'] ?? '') ?>">
                </div>
                <div>
                    <label>Ordem</label>
                    <input class="form-input" name="ordem" type="number" value="<?= h($edit_item['ordem'] ?? 0) ?>">
                </div>
                <div>
                    <label>Ativo</label>
                    <input type="checkbox" name="ativo" <?= !isset($edit_item) || !empty($edit_item['ativo']) ? 'checked' : '' ?>>
                </div>
            </div>
            <button class="btn-primary" type="submit">Salvar</button>
        </form>
    </div>

    <div class="section-card">
        <h2>Lista de Unidades</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Ordem</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($unidades as $un): ?>
                    <tr>
                        <td><?= h($un['nome']) ?></td>
                        <td><?= (int)$un['ordem'] ?></td>
                        <td>
                            <span class="status-pill <?= $un['ativo'] ? 'status-ativo' : 'status-inativo' ?>">
                                <?= $un['ativo'] ? 'Ativo' : 'Inativo' ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$un['id'] ?>">
                                <button class="btn-secondary" type="submit">Ativar/Desativar</button>
                            </form>
                            <a class="btn-secondary" href="index.php?page=logistica_unidades_medida&edit_id=<?= (int)$un['id'] ?>">Editar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($unidades)): ?>
                    <tr><td colspan="4">Nenhuma unidade cadastrada.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endSidebar(); ?>
