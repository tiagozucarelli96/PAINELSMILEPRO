<?php
require_once __DIR__ . '/logistica_tz.php';
// logistica_tipologias.php — Cadastro de tipologias (Insumo/Receita)
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

function handle_tipologia_save(PDO $pdo, string $table, array $data, array &$errors, array &$messages): void {
    $nome = trim((string)($data['nome'] ?? ''));
    if ($nome === '') {
        $errors[] = 'Nome é obrigatório.';
        return;
    }

    $ordem = (int)($data['ordem'] ?? 0);
    $ativo = !empty($data['ativo']) ? 'TRUE' : 'FALSE';
    $visivel = !empty($data['visivel_na_lista']) ? 'TRUE' : 'FALSE';
    $id = isset($data['id']) ? (int)$data['id'] : 0;

    if ($id > 0) {
        $stmt = $pdo->prepare("
            UPDATE {$table}
            SET nome = :nome,
                ordem = :ordem,
                ativo = {$ativo},
                visivel_na_lista = {$visivel},
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':nome' => $nome, ':ordem' => $ordem, ':id' => $id]);
        $messages[] = 'Tipologia atualizada.';
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO {$table} (nome, ordem, ativo, visivel_na_lista)
        VALUES (:nome, :ordem, {$ativo}, {$visivel})
    ");
    $stmt->execute([':nome' => $nome, ':ordem' => $ordem]);
    $messages[] = 'Tipologia criada.';
}

function handle_tipologia_toggle(PDO $pdo, string $table, int $id): void {
    $stmt = $pdo->prepare("UPDATE {$table} SET ativo = NOT ativo, updated_at = NOW() WHERE id = :id");
    $stmt->execute([':id' => $id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $tipo = $_POST['tipo'] ?? '';

    if ($action === 'save' && in_array($tipo, ['insumo', 'receita'], true)) {
        $table = $tipo === 'insumo' ? 'logistica_tipologias_insumo' : 'logistica_tipologias_receita';
        handle_tipologia_save($pdo, $table, $_POST, $errors, $messages);
    }

    if ($action === 'toggle' && in_array($tipo, ['insumo', 'receita'], true)) {
        $table = $tipo === 'insumo' ? 'logistica_tipologias_insumo' : 'logistica_tipologias_receita';
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            handle_tipologia_toggle($pdo, $table, $id);
        }
    }
}

$tipologias_insumo = $pdo->query("SELECT * FROM logistica_tipologias_insumo ORDER BY ordem, nome")->fetchAll(PDO::FETCH_ASSOC);
$tipologias_receita = $pdo->query("SELECT * FROM logistica_tipologias_receita ORDER BY ordem, nome")->fetchAll(PDO::FETCH_ASSOC);

$edit_insumo_id = (int)($_GET['edit_insumo_id'] ?? 0);
$edit_receita_id = (int)($_GET['edit_receita_id'] ?? 0);
$edit_insumo = null;
$edit_receita = null;
if ($edit_insumo_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM logistica_tipologias_insumo WHERE id = :id");
    $stmt->execute([':id' => $edit_insumo_id]);
    $edit_insumo = $stmt->fetch(PDO::FETCH_ASSOC);
}
if ($edit_receita_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM logistica_tipologias_receita WHERE id = :id");
    $stmt->execute([':id' => $edit_receita_id]);
    $edit_receita = $stmt->fetch(PDO::FETCH_ASSOC);
}

includeSidebar('Tipologias - Logística');
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

.status-ativo {
    background: #16a34a;
}

.status-inativo {
    background: #f97316;
}

.alert {
    padding: 0.75rem 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
}

.alert-success {
    background: #dcfce7;
    color: #166534;
}
</style>

<div class="page-container">
    <h1>Tipologias</h1>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-success"><?= h($message) ?></div>
    <?php endforeach; ?>

    <div class="section-card">
        <h2>Tipologia de Insumo</h2>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="tipo" value="insumo">
            <input type="hidden" name="id" value="<?= $edit_insumo ? (int)$edit_insumo['id'] : '' ?>">
            <div class="form-row">
                <div>
                    <label>Nome</label>
                    <input class="form-input" name="nome" required value="<?= h($edit_insumo['nome'] ?? '') ?>">
                </div>
                <div>
                    <label>Ordem</label>
                    <input class="form-input" name="ordem" type="number" value="<?= h($edit_insumo['ordem'] ?? 0) ?>">
                </div>
                <div>
                    <label>Ativo</label>
                    <input type="checkbox" name="ativo" <?= !isset($edit_insumo) || !empty($edit_insumo['ativo']) ? 'checked' : '' ?>>
                </div>
                <div>
                    <label>Visível na lista</label>
                    <input type="checkbox" name="visivel_na_lista" <?= !isset($edit_insumo) || !empty($edit_insumo['visivel_na_lista']) ? 'checked' : '' ?>>
                </div>
            </div>
            <button class="btn-primary" type="submit">Salvar</button>
        </form>

        <table class="table" style="margin-top: 1rem;">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Ordem</th>
                    <th>Status</th>
                    <th>Visível</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tipologias_insumo as $tip): ?>
                    <tr>
                        <td><?= h($tip['nome']) ?></td>
                        <td><?= (int)$tip['ordem'] ?></td>
                        <td>
                            <span class="status-pill <?= $tip['ativo'] ? 'status-ativo' : 'status-inativo' ?>">
                                <?= $tip['ativo'] ? 'Ativo' : 'Inativo' ?>
                            </span>
                        </td>
                        <td><?= $tip['visivel_na_lista'] ? 'Sim' : 'Não' ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="tipo" value="insumo">
                                <input type="hidden" name="id" value="<?= (int)$tip['id'] ?>">
                                <button class="btn-secondary" type="submit">Ativar/Desativar</button>
                            </form>
                            <a class="btn-secondary" href="index.php?page=logistica_tipologias&edit_insumo_id=<?= (int)$tip['id'] ?>">Editar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($tipologias_insumo)): ?>
                    <tr><td colspan="5">Nenhuma tipologia cadastrada.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="section-card">
        <h2>Tipologia de Receita</h2>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="tipo" value="receita">
            <input type="hidden" name="id" value="<?= $edit_receita ? (int)$edit_receita['id'] : '' ?>">
            <div class="form-row">
                <div>
                    <label>Nome</label>
                    <input class="form-input" name="nome" required value="<?= h($edit_receita['nome'] ?? '') ?>">
                </div>
                <div>
                    <label>Ordem</label>
                    <input class="form-input" name="ordem" type="number" value="<?= h($edit_receita['ordem'] ?? 0) ?>">
                </div>
                <div>
                    <label>Ativo</label>
                    <input type="checkbox" name="ativo" <?= !isset($edit_receita) || !empty($edit_receita['ativo']) ? 'checked' : '' ?>>
                </div>
                <div>
                    <label>Visível na lista</label>
                    <input type="checkbox" name="visivel_na_lista" <?= !isset($edit_receita) || !empty($edit_receita['visivel_na_lista']) ? 'checked' : '' ?>>
                </div>
            </div>
            <button class="btn-primary" type="submit">Salvar</button>
        </form>

        <table class="table" style="margin-top: 1rem;">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Ordem</th>
                    <th>Status</th>
                    <th>Visível</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tipologias_receita as $tip): ?>
                    <tr>
                        <td><?= h($tip['nome']) ?></td>
                        <td><?= (int)$tip['ordem'] ?></td>
                        <td>
                            <span class="status-pill <?= $tip['ativo'] ? 'status-ativo' : 'status-inativo' ?>">
                                <?= $tip['ativo'] ? 'Ativo' : 'Inativo' ?>
                            </span>
                        </td>
                        <td><?= $tip['visivel_na_lista'] ? 'Sim' : 'Não' ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="tipo" value="receita">
                                <input type="hidden" name="id" value="<?= (int)$tip['id'] ?>">
                                <button class="btn-secondary" type="submit">Ativar/Desativar</button>
                            </form>
                            <a class="btn-secondary" href="index.php?page=logistica_tipologias&edit_receita_id=<?= (int)$tip['id'] ?>">Editar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($tipologias_receita)): ?>
                    <tr><td colspan="5">Nenhuma tipologia cadastrada.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endSidebar(); ?>
