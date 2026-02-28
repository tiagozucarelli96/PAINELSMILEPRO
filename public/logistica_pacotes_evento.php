<?php
/**
 * logistica_pacotes_evento.php
 * Cadastro simples de pacotes de evento.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/pacotes_evento_helper.php';

if (empty($_SESSION['perm_configuracoes']) && empty($_SESSION['perm_superadmin'])) {
    http_response_code(403);
    echo '<div style="padding:1rem;color:#b91c1c;">Acesso negado.</div>';
    exit;
}

pacotes_evento_ensure_schema($pdo);

$user_id = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? 0);
$errors = [];
$messages = [];

$edit_id = (int)($_GET['edit_id'] ?? 0);
$edit_item = [
    'id' => 0,
    'nome' => '',
    'descricao' => '',
    'oculto' => false,
];

if ($edit_id > 0) {
    $found = pacotes_evento_get($pdo, $edit_id);
    if ($found) {
        $edit_item = $found;
    } else {
        $errors[] = 'Pacote não encontrado para edição.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save') {
        $pacote_id = (int)($_POST['id'] ?? 0);
        $nome = trim((string)($_POST['nome'] ?? ''));
        $descricao = trim((string)($_POST['descricao'] ?? ''));
        $oculto = ((string)($_POST['oculto'] ?? '0') === '1');

        $edit_item = [
            'id' => $pacote_id,
            'nome' => $nome,
            'descricao' => $descricao,
            'oculto' => $oculto,
        ];

        $result = pacotes_evento_salvar($pdo, $pacote_id, $nome, $descricao, $oculto, $user_id);
        if (!empty($result['ok'])) {
            if (!empty($result['mode']) && $result['mode'] === 'updated') {
                $messages[] = 'Pacote atualizado com sucesso.';
                $refreshed = pacotes_evento_get($pdo, $pacote_id);
                if ($refreshed) {
                    $edit_item = $refreshed;
                }
            } else {
                $messages[] = 'Pacote criado com sucesso.';
                $edit_item = [
                    'id' => 0,
                    'nome' => '',
                    'descricao' => '',
                    'oculto' => false,
                ];
            }
        } else {
            $errors[] = (string)($result['error'] ?? 'Não foi possível salvar o pacote.');
        }
    }

    if ($action === 'toggle_oculto') {
        $pacote_id = (int)($_POST['id'] ?? 0);
        $found = pacotes_evento_get($pdo, $pacote_id);
        if (!$found) {
            $errors[] = 'Pacote não encontrado.';
        } else {
            $novo_oculto = empty($found['oculto']);
            $result = pacotes_evento_alterar_oculto($pdo, $pacote_id, $novo_oculto);
            if (!empty($result['ok'])) {
                $messages[] = $novo_oculto
                    ? 'Pacote ocultado com sucesso.'
                    : 'Pacote reexibido com sucesso.';
            } else {
                $errors[] = (string)($result['error'] ?? 'Não foi possível alterar a visibilidade do pacote.');
            }
        }
    }

    if ($action === 'delete') {
        $pacote_id = (int)($_POST['id'] ?? 0);
        $result = pacotes_evento_excluir($pdo, $pacote_id, $user_id);
        if (!empty($result['ok'])) {
            $messages[] = 'Pacote excluído com sucesso.';
            if ((int)$edit_item['id'] === $pacote_id) {
                $edit_item = [
                    'id' => 0,
                    'nome' => '',
                    'descricao' => '',
                    'oculto' => false,
                ];
            }
        } else {
            $errors[] = (string)($result['error'] ?? 'Não foi possível excluir o pacote.');
        }
    }
}

$pacotes = pacotes_evento_listar($pdo, true);

function logistica_pacotes_evento_e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

includeSidebar('Pacotes de Evento');
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

    .status-visivel {
        background: #15803d;
    }

    .status-oculto {
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
            <h1 class="page-title">Pacotes de Evento</h1>
            <p class="page-subtitle">Cadastro simples de pacotes para seleção na organização do evento.</p>
        </div>
        <a href="index.php?page=config_logistica" class="btn-link">← Voltar para Config. Logística</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= logistica_pacotes_evento_e((string)$error) ?></div>
    <?php endforeach; ?>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-success"><?= logistica_pacotes_evento_e((string)$message) ?></div>
    <?php endforeach; ?>

    <div class="card">
        <h2><?= (int)$edit_item['id'] > 0 ? 'Editar Pacote' : 'Novo Pacote' ?></h2>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)$edit_item['id'] ?>">

            <div class="form-row">
                <div class="form-field">
                    <label for="pacoteNome">Nome do pacote</label>
                    <input id="pacoteNome" class="form-input" name="nome" required maxlength="180"
                           value="<?= logistica_pacotes_evento_e((string)($edit_item['nome'] ?? '')) ?>">
                </div>
            </div>

            <div class="form-field">
                <label for="pacoteDescricao">Descrição (opcional)</label>
                <textarea id="pacoteDescricao" class="form-textarea" name="descricao" maxlength="2000"><?= logistica_pacotes_evento_e((string)($edit_item['descricao'] ?? '')) ?></textarea>
            </div>

            <label class="form-check">
                <input type="checkbox" name="oculto" value="1" <?= !empty($edit_item['oculto']) ? 'checked' : '' ?>>
                <span>Ocultar pacote da seleção da organização</span>
            </label>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Salvar pacote</button>
                <?php if ((int)$edit_item['id'] > 0): ?>
                    <a class="btn-secondary" href="index.php?page=logistica_pacotes_evento">Cancelar edição</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Pacotes Cadastrados</h2>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Descrição</th>
                        <th>Status</th>
                        <th>Atualizado</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pacotes as $pacote): ?>
                        <?php
                        $pacote_id = (int)($pacote['id'] ?? 0);
                        $updated_at = trim((string)($pacote['updated_at'] ?? ''));
                        $updated_at_fmt = $updated_at !== '' ? date('d/m/Y H:i', strtotime($updated_at)) : '-';
                        $is_oculto = !empty($pacote['oculto']);
                        ?>
                        <tr>
                            <td><?= logistica_pacotes_evento_e((string)($pacote['nome'] ?? '')) ?></td>
                            <td><?= logistica_pacotes_evento_e((string)($pacote['descricao'] ?? '')) ?></td>
                            <td>
                                <span class="status-pill <?= $is_oculto ? 'status-oculto' : 'status-visivel' ?>">
                                    <?= $is_oculto ? 'Oculto' : 'Visível' ?>
                                </span>
                            </td>
                            <td><?= logistica_pacotes_evento_e($updated_at_fmt) ?></td>
                            <td>
                                <div class="row-actions">
                                    <a class="btn-secondary" href="index.php?page=logistica_pacotes_evento&edit_id=<?= $pacote_id ?>">Editar</a>

                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="action" value="toggle_oculto">
                                        <input type="hidden" name="id" value="<?= $pacote_id ?>">
                                        <button type="submit" class="btn-secondary">
                                            <?= $is_oculto ? 'Reexibir' : 'Ocultar' ?>
                                        </button>
                                    </form>

                                    <form method="POST" class="inline-form" onsubmit="return confirm('Deseja realmente excluir este pacote?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $pacote_id ?>">
                                        <button type="submit" class="btn-danger">Excluir</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($pacotes)): ?>
                        <tr>
                            <td colspan="5">Nenhum pacote cadastrado.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endSidebar(); ?>
