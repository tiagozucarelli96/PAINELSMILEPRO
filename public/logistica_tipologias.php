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

$is_editing_insumo = is_array($edit_insumo);
$is_editing_receita = is_array($edit_receita);
?>

<style>
.page-container {
    max-width: 1320px;
    margin: 0 auto;
    padding: 1.5rem;
}

.page-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}

.page-title {
    margin: 0;
    color: #0f172a;
}

.page-subtitle {
    margin: 0.35rem 0 0;
    color: #64748b;
    font-size: 0.95rem;
}

.page-header-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.tipologia-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(460px, 1fr));
    gap: 1rem;
}

.section-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    min-width: 0;
}

.section-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.section-head h2 {
    margin: 0;
    font-size: 1.2rem;
    color: #0f172a;
}

.section-head p {
    margin: 0.35rem 0 0;
    color: #64748b;
    font-size: 0.88rem;
}

.section-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
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
    font-size: 0.95rem;
}

.field-label {
    display: block;
    margin: 0 0 0.4rem;
    font-size: 0.88rem;
    color: #334155;
    font-weight: 600;
}

.check-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(140px, 1fr));
    gap: 0.65rem;
}

.check-item {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.9rem;
    color: #334155;
}

.check-item input {
    width: 16px;
    height: 16px;
}

.form-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-top: 0.2rem;
}

.btn-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    font-size: 0.9rem;
}

.btn-primary {
    background: #2563eb;
    color: #fff;
    border: none;
    padding: 0.6rem 1rem;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    text-decoration: none;
}

.btn-secondary {
    background: #e2e8f0;
    color: #1f2937;
    border: none;
    padding: 0.5rem 0.9rem;
    border-radius: 8px;
    cursor: pointer;
    text-decoration: none;
}

.edit-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.2rem 0.6rem;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 600;
    background: #eff6ff;
    color: #1d4ed8;
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
    margin-top: 1rem;
}

.table th,
.table td {
    text-align: left;
    padding: 0.65rem;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.9rem;
    white-space: nowrap;
}

.table th {
    color: #475569;
    font-weight: 600;
    background: #f8fafc;
}

.table td:last-child {
    white-space: normal;
}

.actions-row {
    display: flex;
    gap: 0.45rem;
    flex-wrap: wrap;
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

@media (max-width: 760px) {
    .page-container {
        padding: 1rem;
    }
    .section-card {
        padding: 1rem;
    }
    .tipologia-grid {
        grid-template-columns: 1fr;
    }
    .check-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="page-container">
    <div class="page-header">
        <div>
            <h1 class="page-title">Tipologias</h1>
            <p class="page-subtitle">Gerencie os tipos usados no cadastro de insumos e receitas.</p>
        </div>
        <div class="page-header-actions">
            <?php if ($is_editing_insumo || $is_editing_receita): ?>
                <a class="btn-secondary btn-link" href="index.php?page=logistica_tipologias">Novo cadastro</a>
            <?php endif; ?>
            <a class="btn-secondary btn-link" href="index.php?page=logistica_catalogo">Voltar ao catálogo</a>
        </div>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-success"><?= h($message) ?></div>
    <?php endforeach; ?>

    <div class="tipologia-grid">
        <div class="section-card">
            <div class="section-head">
                <div>
                    <h2>Tipologias de Insumo</h2>
                    <p><?= count($tipologias_insumo) ?> cadastrada(s)</p>
                </div>
                <?php if ($is_editing_insumo): ?>
                    <div class="section-actions">
                        <span class="edit-badge">Editando ID #<?= (int)$edit_insumo['id'] ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="tipo" value="insumo">
                <input type="hidden" name="id" value="<?= $is_editing_insumo ? (int)$edit_insumo['id'] : '' ?>">
                <div class="form-row">
                    <div>
                        <label class="field-label">Nome</label>
                        <input class="form-input" name="nome" required value="<?= h($edit_insumo['nome'] ?? '') ?>" placeholder="Ex.: Hortifruti">
                    </div>
                    <div>
                        <label class="field-label">Ordem</label>
                        <input class="form-input" name="ordem" type="number" value="<?= h($edit_insumo['ordem'] ?? 10) ?>">
                    </div>
                    <div>
                        <label class="field-label">Configuração</label>
                        <div class="check-grid">
                            <label class="check-item">
                                <input type="checkbox" name="ativo" <?= $is_editing_insumo ? (!empty($edit_insumo['ativo']) ? 'checked' : '') : 'checked' ?>>
                                Ativo
                            </label>
                            <label class="check-item">
                                <input type="checkbox" name="visivel_na_lista" <?= $is_editing_insumo ? (!empty($edit_insumo['visivel_na_lista']) ? 'checked' : '') : 'checked' ?>>
                                Visível na lista
                            </label>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn-primary" type="submit"><?= $is_editing_insumo ? 'Atualizar tipologia de insumo' : 'Criar tipologia de insumo' ?></button>
                    <?php if ($is_editing_insumo): ?>
                        <a class="btn-secondary btn-link" href="index.php?page=logistica_tipologias">Cancelar edição</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="table-wrap">
                <table class="table">
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
                                    <div class="actions-row">
                                        <form method="POST">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="tipo" value="insumo">
                                            <input type="hidden" name="id" value="<?= (int)$tip['id'] ?>">
                                            <button class="btn-secondary" type="submit">Ativar/Desativar</button>
                                        </form>
                                        <a class="btn-secondary btn-link" href="index.php?page=logistica_tipologias&edit_insumo_id=<?= (int)$tip['id'] ?>">Editar</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($tipologias_insumo)): ?>
                            <tr><td colspan="5">Nenhuma tipologia de insumo cadastrada.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="section-card">
            <div class="section-head">
                <div>
                    <h2>Tipologias de Receita</h2>
                    <p><?= count($tipologias_receita) ?> cadastrada(s)</p>
                </div>
                <?php if ($is_editing_receita): ?>
                    <div class="section-actions">
                        <span class="edit-badge">Editando ID #<?= (int)$edit_receita['id'] ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="tipo" value="receita">
                <input type="hidden" name="id" value="<?= $is_editing_receita ? (int)$edit_receita['id'] : '' ?>">
                <div class="form-row">
                    <div>
                        <label class="field-label">Nome</label>
                        <input class="form-input" name="nome" required value="<?= h($edit_receita['nome'] ?? '') ?>" placeholder="Ex.: Finalização">
                    </div>
                    <div>
                        <label class="field-label">Ordem</label>
                        <input class="form-input" name="ordem" type="number" value="<?= h($edit_receita['ordem'] ?? 10) ?>">
                    </div>
                    <div>
                        <label class="field-label">Configuração</label>
                        <div class="check-grid">
                            <label class="check-item">
                                <input type="checkbox" name="ativo" <?= $is_editing_receita ? (!empty($edit_receita['ativo']) ? 'checked' : '') : 'checked' ?>>
                                Ativo
                            </label>
                            <label class="check-item">
                                <input type="checkbox" name="visivel_na_lista" <?= $is_editing_receita ? (!empty($edit_receita['visivel_na_lista']) ? 'checked' : '') : 'checked' ?>>
                                Visível na lista
                            </label>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn-primary" type="submit"><?= $is_editing_receita ? 'Atualizar tipologia de receita' : 'Criar tipologia de receita' ?></button>
                    <?php if ($is_editing_receita): ?>
                        <a class="btn-secondary btn-link" href="index.php?page=logistica_tipologias<?= $is_editing_insumo ? '&edit_insumo_id=' . (int)$edit_insumo['id'] : '' ?>">Cancelar edição</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="table-wrap">
                <table class="table">
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
                                    <div class="actions-row">
                                        <form method="POST">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="tipo" value="receita">
                                            <input type="hidden" name="id" value="<?= (int)$tip['id'] ?>">
                                            <button class="btn-secondary" type="submit">Ativar/Desativar</button>
                                        </form>
                                        <a class="btn-secondary btn-link" href="index.php?page=logistica_tipologias&edit_receita_id=<?= (int)$tip['id'] ?><?= $is_editing_insumo ? '&edit_insumo_id=' . (int)$edit_insumo['id'] : '' ?>">Editar</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($tipologias_receita)): ?>
                            <tr><td colspan="5">Nenhuma tipologia de receita cadastrada.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php endSidebar(); ?>
