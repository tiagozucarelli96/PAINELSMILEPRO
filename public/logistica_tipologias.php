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

function handle_tipologia_delete(PDO $pdo, string $tipo, int $id, array &$errors, array &$messages): void {
    if ($id <= 0) {
        $errors[] = 'Tipologia inválida para exclusão.';
        return;
    }

    if ($tipo === 'insumo') {
        $check = $pdo->prepare("SELECT COUNT(*) FROM logistica_insumos WHERE tipologia_insumo_id = :id");
        $check->execute([':id' => $id]);
        $count = (int)$check->fetchColumn();
        if ($count > 0) {
            $errors[] = 'Não foi possível apagar a tipologia de insumo: existem ' . $count . ' insumo(s) vinculado(s).';
            return;
        }

        $stmt = $pdo->prepare("DELETE FROM logistica_tipologias_insumo WHERE id = :id");
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() > 0) {
            $messages[] = 'Tipologia de insumo apagada.';
        } else {
            $errors[] = 'Tipologia de insumo não encontrada.';
        }
        return;
    }

    if ($tipo === 'receita') {
        $check = $pdo->prepare("SELECT COUNT(*) FROM logistica_receitas WHERE tipologia_receita_id = :id");
        $check->execute([':id' => $id]);
        $count = (int)$check->fetchColumn();
        if ($count > 0) {
            $errors[] = 'Não foi possível apagar a tipologia de receita: existem ' . $count . ' receita(s) vinculada(s).';
            return;
        }

        $stmt = $pdo->prepare("DELETE FROM logistica_tipologias_receita WHERE id = :id");
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() > 0) {
            $messages[] = 'Tipologia de receita apagada.';
        } else {
            $errors[] = 'Tipologia de receita não encontrada.';
        }
    }
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

    if ($action === 'delete' && in_array($tipo, ['insumo', 'receita'], true)) {
        $id = (int)($_POST['id'] ?? 0);
        handle_tipologia_delete($pdo, $tipo, $id, $errors, $messages);
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
    max-width: 1180px;
    margin: 0 auto;
    padding: 1.35rem 1.5rem 2rem;
}

.page-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.1rem;
    padding: 1.15rem 1.25rem;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
}

.page-kicker {
    margin: 0 0 0.25rem;
    color: #2563eb;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0;
    text-transform: uppercase;
}

.page-title {
    margin: 0;
    color: #0f172a;
    font-size: 1.85rem;
    line-height: 1.1;
}

.page-subtitle {
    margin: 0.4rem 0 0;
    color: #64748b;
    font-size: 0.95rem;
}

.page-header-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    align-items: center;
}

.tipologia-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.1rem;
}

.section-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 0;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
    min-width: 0;
    overflow: hidden;
}

.section-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 1.1rem 1.25rem 0.9rem;
    border-bottom: 1px solid #e2e8f0;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
}

.section-head h2 {
    margin: 0;
    font-size: 1.15rem;
    line-height: 1.2;
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

.section-meta {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.section-count {
    display: inline-flex;
    align-items: center;
    min-height: 28px;
    padding: 0.25rem 0.65rem;
    border-radius: 999px;
    background: #eef2ff;
    color: #1e40af;
    font-size: 0.82rem;
    font-weight: 700;
    white-space: nowrap;
}

.tipologia-form {
    padding: 1rem 1.25rem 1.1rem;
    border-bottom: 1px solid #e2e8f0;
    background: #ffffff;
}

.form-row {
    display: grid;
    grid-template-columns: minmax(260px, 1fr) 120px minmax(250px, 0.85fr) auto;
    align-items: end;
    gap: 0.85rem;
    margin-bottom: 0;
}

.form-input {
    width: 100%;
    height: 42px;
    padding: 0.55rem 0.75rem;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.95rem;
    color: #0f172a;
    background: #ffffff;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.form-input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    outline: none;
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
    gap: 0.5rem;
}

.check-item {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    min-height: 42px;
    padding: 0.45rem 0.65rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #f8fafc;
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
    min-height: 42px;
    padding: 0.55rem 0.95rem;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.9rem;
    text-decoration: none;
    white-space: nowrap;
}

.btn-secondary {
    background: #e2e8f0;
    color: #1f2937;
    border: none;
    min-height: 36px;
    padding: 0.45rem 0.75rem;
    border-radius: 8px;
    cursor: pointer;
    text-decoration: none;
    font-weight: 600;
    white-space: nowrap;
}

.btn-danger {
    background: #fee2e2;
    color: #991b1b;
    border: none;
    min-height: 36px;
    padding: 0.45rem 0.75rem;
    border-radius: 8px;
    cursor: pointer;
    text-decoration: none;
    font-weight: 600;
    white-space: nowrap;
}

.btn-primary:hover,
.btn-secondary:hover,
.btn-danger:hover {
    filter: brightness(0.98);
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
    margin-top: 0;
}

.table th,
.table td {
    text-align: left;
    padding: 0.78rem 1rem;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.9rem;
    white-space: nowrap;
    vertical-align: middle;
}

.table th {
    color: #475569;
    font-weight: 600;
    background: #f1f5f9;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0;
}

.table tbody tr:hover {
    background: #f8fafc;
}

.table tbody tr:last-child td {
    border-bottom: none;
}

.table td:first-child {
    color: #0f172a;
    font-weight: 650;
    min-width: 220px;
}

.table td:last-child {
    white-space: normal;
    min-width: 270px;
}

.actions-row {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    flex-wrap: wrap;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    min-height: 24px;
    padding: 0.15rem 0.55rem;
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
    .page-header {
        padding: 1rem;
    }
    .section-card {
        border-radius: 8px;
    }
    .section-head {
        padding: 1rem;
    }
    .tipologia-grid {
        grid-template-columns: 1fr;
    }
    .tipologia-form {
        padding: 1rem;
    }
    .form-row {
        grid-template-columns: 1fr;
    }
    .check-grid {
        grid-template-columns: 1fr;
    }
    .form-actions,
    .form-actions .btn-primary,
    .form-actions .btn-secondary {
        width: 100%;
    }
    .page-header-actions,
    .page-header-actions .btn-secondary {
        width: 100%;
    }
    .table td:first-child,
    .table td:last-child {
        min-width: 0;
    }
}
</style>

<div class="page-container">
    <div class="page-header">
        <div>
            <p class="page-kicker">Logística > Catálogo</p>
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
                    <p>Categorias usadas nos insumos do catálogo.</p>
                </div>
                <div class="section-meta">
                    <span class="section-count"><?= count($tipologias_insumo) ?> cadastrada(s)</span>
                    <?php if ($is_editing_insumo): ?>
                        <div class="section-actions">
                            <span class="edit-badge">Editando ID #<?= (int)$edit_insumo['id'] ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <form class="tipologia-form" method="POST">
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
                    <div class="form-actions">
                        <button class="btn-primary" type="submit"><?= $is_editing_insumo ? 'Atualizar insumo' : 'Criar insumo' ?></button>
                        <?php if ($is_editing_insumo): ?>
                            <a class="btn-secondary btn-link" href="index.php?page=logistica_tipologias">Cancelar</a>
                        <?php endif; ?>
                    </div>
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
                                            <button class="btn-secondary" type="submit">Alternar</button>
                                        </form>
                                        <a class="btn-secondary btn-link" href="index.php?page=logistica_tipologias&edit_insumo_id=<?= (int)$tip['id'] ?>">Editar</a>
                                        <form method="POST" onsubmit="return confirm('Apagar esta tipologia de insumo? Esta ação não pode ser desfeita.');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="tipo" value="insumo">
                                            <input type="hidden" name="id" value="<?= (int)$tip['id'] ?>">
                                            <button class="btn-danger" type="submit">Apagar</button>
                                        </form>
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
                    <p>Categorias usadas nas receitas do catálogo.</p>
                </div>
                <div class="section-meta">
                    <span class="section-count"><?= count($tipologias_receita) ?> cadastrada(s)</span>
                    <?php if ($is_editing_receita): ?>
                        <div class="section-actions">
                            <span class="edit-badge">Editando ID #<?= (int)$edit_receita['id'] ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <form class="tipologia-form" method="POST">
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
                    <div class="form-actions">
                        <button class="btn-primary" type="submit"><?= $is_editing_receita ? 'Atualizar receita' : 'Criar receita' ?></button>
                        <?php if ($is_editing_receita): ?>
                            <a class="btn-secondary btn-link" href="index.php?page=logistica_tipologias<?= $is_editing_insumo ? '&edit_insumo_id=' . (int)$edit_insumo['id'] : '' ?>">Cancelar</a>
                        <?php endif; ?>
                    </div>
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
                                            <button class="btn-secondary" type="submit">Alternar</button>
                                        </form>
                                        <a class="btn-secondary btn-link" href="index.php?page=logistica_tipologias&edit_receita_id=<?= (int)$tip['id'] ?><?= $is_editing_insumo ? '&edit_insumo_id=' . (int)$edit_insumo['id'] : '' ?>">Editar</a>
                                        <form method="POST" onsubmit="return confirm('Apagar esta tipologia de receita? Esta ação não pode ser desfeita.');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="tipo" value="receita">
                                            <input type="hidden" name="id" value="<?= (int)$tip['id'] ?>">
                                            <button class="btn-danger" type="submit">Apagar</button>
                                        </form>
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
