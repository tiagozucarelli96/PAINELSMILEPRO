<?php
/**
 * logistica_cardapio_secoes.php
 * Cadastro de seções do cardápio.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/logistica_cardapio_helper.php';

$can_manage = !empty($_SESSION['perm_superadmin'])
    || !empty($_SESSION['perm_configuracoes'])
    || !empty($_SESSION['perm_logistico'])
    || !empty($_SESSION['perm_cadastros']);

if (!$can_manage) {
    http_response_code(403);
    echo '<div style="padding:1rem;color:#b91c1c;">Acesso negado.</div>';
    exit;
}

logistica_cardapio_ensure_schema($pdo);

$user_id = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? 0);
$errors = [];
$messages = [];
$flash_key = 'logistica_cardapio_secoes_flash';

if (!empty($_SESSION[$flash_key]) && is_array($_SESSION[$flash_key])) {
    $flash = $_SESSION[$flash_key];
    unset($_SESSION[$flash_key]);
    foreach (($flash['errors'] ?? []) as $flash_error) {
        $errors[] = (string)$flash_error;
    }
    foreach (($flash['messages'] ?? []) as $flash_message) {
        $messages[] = (string)$flash_message;
    }
}

$edit_id = (int)($_GET['edit_id'] ?? 0);
$edit_item = [
    'id' => 0,
    'nome' => '',
    'descricao' => '',
    'ordem' => 0,
    'ativo' => true,
];

if ($edit_id > 0) {
    $found = logistica_cardapio_secao_get($pdo, $edit_id);
    if ($found) {
        $edit_item = $found;
    } else {
        $errors[] = 'Seção não encontrada para edição.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save') {
        $secao_id = (int)($_POST['id'] ?? 0);
        $nome = trim((string)($_POST['nome'] ?? ''));
        $descricao = trim((string)($_POST['descricao'] ?? ''));
        $ordem = (int)($_POST['ordem'] ?? 0);
        $ativo = ((string)($_POST['ativo'] ?? '0') === '1');

        $edit_item = [
            'id' => $secao_id,
            'nome' => $nome,
            'descricao' => $descricao,
            'ordem' => $ordem,
            'ativo' => $ativo,
        ];

        $result = logistica_cardapio_secao_salvar($pdo, $secao_id, $nome, $descricao, $ordem, $ativo, $user_id);
        if (!empty($result['ok'])) {
            $_SESSION[$flash_key] = [
                'messages' => [
                    !empty($result['mode']) && $result['mode'] === 'updated'
                        ? 'Seção atualizada com sucesso.'
                        : 'Seção criada com sucesso.',
                ],
            ];
            header('Location: index.php?page=logistica_cardapio_secoes');
            exit;
        } else {
            $errors[] = (string)($result['error'] ?? 'Não foi possível salvar a seção.');
        }
    }

    if ($action === 'toggle_status') {
        $secao_id = (int)($_POST['id'] ?? 0);
        $found = logistica_cardapio_secao_get($pdo, $secao_id);
        if (!$found) {
            $errors[] = 'Seção não encontrada.';
        } else {
            $novo_status = empty($found['ativo']);
            $result = logistica_cardapio_secao_alterar_status($pdo, $secao_id, $novo_status);
            if (!empty($result['ok'])) {
                $messages[] = $novo_status
                    ? 'Seção reativada com sucesso.'
                    : 'Seção inativada com sucesso.';
            } else {
                $errors[] = (string)($result['error'] ?? 'Não foi possível alterar o status da seção.');
            }
        }
    }

    if ($action === 'delete') {
        $secao_id = (int)($_POST['id'] ?? 0);
        $result = logistica_cardapio_secao_excluir($pdo, $secao_id, $user_id);
        if (!empty($result['ok'])) {
            $messages[] = 'Seção excluída com sucesso.';
            if ((int)$edit_item['id'] === $secao_id) {
                $edit_item = [
                    'id' => 0,
                    'nome' => '',
                    'descricao' => '',
                    'ordem' => 0,
                    'ativo' => true,
                ];
            }
        } else {
            $errors[] = (string)($result['error'] ?? 'Não foi possível excluir a seção.');
        }
    }
}

$secoes = logistica_cardapio_listar_secoes($pdo, true);

function logistica_cardapio_secoes_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

includeSidebar('Seções de Cardápio');
?>

<style>
    .page-container {
        max-width: 1240px;
        margin: 0 auto;
        padding: 1.5rem;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .page-title {
        margin: 0;
        color: #0f172a;
        font-size: 1.65rem;
    }

    .page-subtitle {
        margin: 0.35rem 0 0;
        color: #64748b;
        font-size: 0.92rem;
    }

    .btn-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.58rem 0.9rem;
        border-radius: 8px;
        background: #e2e8f0;
        color: #1f2937;
        text-decoration: none;
        font-size: 0.88rem;
        font-weight: 600;
    }

    .card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 1.2rem;
        margin-bottom: 1rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    }

    .card h2 {
        margin: 0 0 0.9rem 0;
        font-size: 1.15rem;
        color: #0f172a;
    }

    .form-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 0.85rem;
        margin-bottom: 0.75rem;
    }

    .form-field label {
        display: block;
        margin: 0 0 0.35rem;
        font-size: 0.86rem;
        color: #334155;
        font-weight: 600;
    }

    .form-input,
    .form-textarea {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0.58rem 0.68rem;
        font-size: 0.9rem;
        color: #0f172a;
        background: #fff;
    }

    .form-textarea {
        min-height: 100px;
        resize: vertical;
    }

    .form-check {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.9rem;
        color: #334155;
        margin-top: 0.2rem;
    }

    .form-actions {
        display: flex;
        gap: 0.55rem;
        flex-wrap: wrap;
        margin-top: 0.8rem;
    }

    .btn-primary,
    .btn-secondary,
    .btn-danger {
        border: none;
        border-radius: 8px;
        padding: 0.55rem 0.9rem;
        cursor: pointer;
        font-size: 0.86rem;
        font-weight: 600;
    }

    .btn-primary {
        background: #2563eb;
        color: #fff;
    }

    .btn-secondary {
        background: #e2e8f0;
        color: #1f2937;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
    }

    .btn-danger {
        background: #b91c1c;
        color: #fff;
    }

    .table-wrap {
        overflow-x: auto;
    }

    .table {
        width: 100%;
        border-collapse: collapse;
    }

    .table th,
    .table td {
        border-bottom: 1px solid #e5e7eb;
        padding: 0.62rem 0.55rem;
        text-align: left;
        font-size: 0.9rem;
        vertical-align: top;
    }

    .status-pill {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 0.2rem 0.6rem;
        font-size: 0.76rem;
        font-weight: 700;
        color: #fff;
    }

    .status-ativo {
        background: #15803d;
    }

    .status-inativo {
        background: #475569;
    }

    .row-actions {
        display: flex;
        gap: 0.4rem;
        flex-wrap: wrap;
    }

    .inline-form {
        display: inline;
    }

    .alert {
        border-radius: 8px;
        padding: 0.68rem 0.9rem;
        margin-bottom: 0.7rem;
        font-size: 0.9rem;
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
    <div class="page-header">
        <div>
            <h1 class="page-title">Seções de Cardápio</h1>
            <p class="page-subtitle">Cadastre as seções que agrupam os itens do cardápio do cliente.</p>
        </div>
        <a href="index.php?page=cadastros" class="btn-link">← Voltar para Cadastros</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= logistica_cardapio_secoes_e((string)$error) ?></div>
    <?php endforeach; ?>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-success"><?= logistica_cardapio_secoes_e((string)$message) ?></div>
    <?php endforeach; ?>

    <div class="card">
        <h2><?= (int)$edit_item['id'] > 0 ? 'Editar Seção' : 'Nova Seção' ?></h2>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)$edit_item['id'] ?>">

            <div class="form-row">
                <div class="form-field">
                    <label for="secaoNome">Nome da seção</label>
                    <input id="secaoNome" class="form-input" name="nome" required maxlength="180"
                           value="<?= logistica_cardapio_secoes_e((string)($edit_item['nome'] ?? '')) ?>">
                </div>
                <div class="form-field">
                    <label for="secaoOrdem">Ordem</label>
                    <input id="secaoOrdem" class="form-input" type="number" min="0" step="1" name="ordem"
                           value="<?= (int)($edit_item['ordem'] ?? 0) ?>">
                </div>
            </div>

            <div class="form-field">
                <label for="secaoDescricao">Descrição (opcional)</label>
                <textarea id="secaoDescricao" class="form-textarea" name="descricao" maxlength="2000"><?= logistica_cardapio_secoes_e((string)($edit_item['descricao'] ?? '')) ?></textarea>
            </div>

            <label class="form-check">
                <input type="checkbox" name="ativo" value="1" <?= !empty($edit_item['ativo']) ? 'checked' : '' ?>>
                <span>Seção ativa</span>
            </label>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Salvar seção</button>
                <?php if ((int)$edit_item['id'] > 0): ?>
                    <a class="btn-secondary" href="index.php?page=logistica_cardapio_secoes">Cancelar edição</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Seções Cadastradas</h2>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Descrição</th>
                        <th>Ordem</th>
                        <th>Status</th>
                        <th>Atualizado</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($secoes as $secao): ?>
                        <?php
                        $secao_id = (int)($secao['id'] ?? 0);
                        $updated_at = trim((string)($secao['updated_at'] ?? ''));
                        $updated_at_fmt = $updated_at !== '' ? date('d/m/Y H:i', strtotime($updated_at)) : '-';
                        $is_ativo = !empty($secao['ativo']);
                        ?>
                        <tr>
                            <td><?= logistica_cardapio_secoes_e((string)($secao['nome'] ?? '')) ?></td>
                            <td><?= logistica_cardapio_secoes_e((string)($secao['descricao'] ?? '')) ?></td>
                            <td><?= (int)($secao['ordem'] ?? 0) ?></td>
                            <td>
                                <span class="status-pill <?= $is_ativo ? 'status-ativo' : 'status-inativo' ?>">
                                    <?= $is_ativo ? 'Ativa' : 'Inativa' ?>
                                </span>
                            </td>
                            <td><?= logistica_cardapio_secoes_e($updated_at_fmt) ?></td>
                            <td>
                                <div class="row-actions">
                                    <a class="btn-secondary" href="index.php?page=logistica_cardapio_secoes&edit_id=<?= $secao_id ?>">Editar</a>

                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?= $secao_id ?>">
                                        <button type="submit" class="btn-secondary">
                                            <?= $is_ativo ? 'Inativar' : 'Reativar' ?>
                                        </button>
                                    </form>

                                    <form method="POST" class="inline-form" onsubmit="return confirm('Deseja realmente excluir esta seção?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $secao_id ?>">
                                        <button type="submit" class="btn-danger">Excluir</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($secoes)): ?>
                        <tr>
                            <td colspan="6">Nenhuma seção cadastrada.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endSidebar(); ?>
