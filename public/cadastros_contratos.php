<?php
/**
 * cadastros_contratos.php
 * Modelos de contratos com editor TinyMCE.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/contratos_modelos_helper.php';

if (empty($_SESSION['perm_cadastros']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

function cadastros_contratos_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function cadastros_contratos_query_params(): array
{
    $params = $_GET;
    $queryString = (string)($_SERVER['QUERY_STRING'] ?? '');
    if ($queryString !== '') {
        parse_str(str_replace('&amp;', '&', $queryString), $parsed);
        if (is_array($parsed)) {
            $params = array_merge($parsed, $params);
        }
    }

    $requestQuery = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY) ?? '');
    if ($requestQuery !== '') {
        parse_str(str_replace('&amp;', '&', $requestQuery), $parsed);
        if (is_array($parsed)) {
            $params = array_merge($parsed, $params);
        }
    }

    return $params;
}

contratos_modelos_ensure_schema($pdo);

$userId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
$success = '';
$errors = [];
$queryParams = cadastros_contratos_query_params();
$modalOpen = array_key_exists('novo', $queryParams);
$editId = (int)($queryParams['edit_id'] ?? 0);
$modalModelo = [
    'id' => 0,
    'nome' => '',
    'conteudo_html' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("UPDATE contrato_modelos SET ativo = NOT ativo, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $_SESSION['cadastros_contratos_success'] = 'Status do modelo atualizado.';
            header('Location: index.php?page=cadastros_contratos');
            exit;
        } catch (Throwable $e) {
            error_log('cadastros_contratos toggle: ' . $e->getMessage());
            $errors[] = 'Não foi possível atualizar o modelo.';
        }
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim((string)($_POST['nome'] ?? ''));
        $conteudoHtml = trim((string)($_POST['conteudo_html'] ?? ''));

        if ($nome === '') {
            $errors[] = 'Informe o nome do modelo.';
        }
        if ($conteudoHtml === '') {
            $errors[] = 'Informe o texto do modelo.';
        }

        if (empty($errors)) {
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE contrato_modelos
                        SET nome = :nome,
                            conteudo_html = :conteudo_html,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':id' => $id,
                        ':nome' => $nome,
                        ':conteudo_html' => $conteudoHtml,
                    ]);
                    $_SESSION['cadastros_contratos_success'] = 'Modelo atualizado com sucesso.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO contrato_modelos (nome, conteudo_html, ativo, created_by, created_at, updated_at)
                        VALUES (:nome, :conteudo_html, TRUE, :created_by, NOW(), NOW())
                    ");
                    $stmt->execute([
                        ':nome' => $nome,
                        ':conteudo_html' => $conteudoHtml,
                        ':created_by' => $userId > 0 ? $userId : null,
                    ]);
                    $_SESSION['cadastros_contratos_success'] = 'Modelo cadastrado com sucesso.';
                }
                header('Location: index.php?page=cadastros_contratos');
                exit;
            } catch (Throwable $e) {
                error_log('cadastros_contratos save: ' . $e->getMessage());
                $errors[] = 'Não foi possível salvar o modelo.';
            }
        }
    }
}

if (!empty($_SESSION['cadastros_contratos_success'])) {
    $success = (string)$_SESSION['cadastros_contratos_success'];
    unset($_SESSION['cadastros_contratos_success']);
}

if (!empty($errors) && ($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? 'save') !== 'toggle')) {
    $modalOpen = true;
    $modalModelo = [
        'id' => (int)($_POST['id'] ?? 0),
        'nome' => trim((string)($_POST['nome'] ?? '')),
        'conteudo_html' => trim((string)($_POST['conteudo_html'] ?? '')),
    ];
} elseif ($editId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM contrato_modelos WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $editId]);
        $modeloEdicao = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($modeloEdicao) {
            $modalOpen = true;
            $modalModelo = [
                'id' => (int)$modeloEdicao['id'],
                'nome' => (string)$modeloEdicao['nome'],
                'conteudo_html' => (string)$modeloEdicao['conteudo_html'],
            ];
        } else {
            $errors[] = 'Modelo não encontrado para edição.';
        }
    } catch (Throwable $e) {
        error_log('cadastros_contratos edit: ' . $e->getMessage());
        $errors[] = 'Não foi possível carregar o modelo para edição.';
    }
}

try {
    $stmt = $pdo->query("SELECT * FROM contrato_modelos ORDER BY ativo DESC, updated_at DESC, id DESC");
    $modelos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('cadastros_contratos list: ' . $e->getMessage());
    $modelos = [];
    $errors[] = 'Não foi possível carregar os modelos.';
}

try {
    $stmt = $pdo->query("SELECT tag_codigo, nome FROM contrato_tags WHERE ativo IS TRUE ORDER BY tag_codigo ASC");
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $tags = [];
}

includeSidebar('Contratos');
?>

<style>
.contratos-page { padding: 1.5rem; max-width: 1480px; margin: 0 auto; }
.contratos-header { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; flex-wrap: wrap; margin-bottom: 1rem; }
.contratos-title { margin: 0; color: #1e3a8a; font-size: 1.8rem; font-weight: 800; }
.contratos-subtitle { margin: 0.35rem 0 0; color: #64748b; }
.contratos-header-actions { display: flex; gap: 0.65rem; flex-wrap: wrap; }
.contratos-back, .contratos-primary { border-radius: 999px; text-decoration: none; font-weight: 800; padding: 0.7rem 1rem; display: inline-flex; align-items: center; justify-content: center; border: 1px solid #dbe3ef; cursor: pointer; }
.contratos-back { background: #fff; color: #1e293b; }
.contratos-primary { background: #1e3a8a; color: #fff; border-color: #1e3a8a; }
.contratos-alert { margin-bottom: 1rem; border-radius: 10px; padding: 0.85rem 1rem; font-weight: 800; }
.contratos-alert.success { background: #ecfdf5; color: #166534; border: 1px solid #a7f3d0; }
.contratos-alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.contratos-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 14px 34px rgba(15, 23, 42, 0.07); overflow: hidden; }
.contratos-card-header { padding: 1rem 1.1rem; border-bottom: 1px solid #e2e8f0; background: #f8fbff; display: flex; justify-content: space-between; gap: 1rem; align-items: center; }
.contratos-card-title { margin: 0; color: #1e293b; font-weight: 800; font-size: 1.05rem; }
.contratos-table-wrap { overflow: auto; }
.contratos-table { width: 100%; border-collapse: collapse; }
.contratos-table th, .contratos-table td { padding: 0.85rem; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
.contratos-table th { background: #f8fafc; color: #475569; font-size: 0.78rem; text-transform: uppercase; }
.modelo-name { color: #1e293b; font-weight: 900; }
.modelo-meta { color: #64748b; font-size: 0.84rem; margin-top: 0.2rem; }
.modelo-actions { display: flex; gap: 0.45rem; flex-wrap: wrap; }
.modelo-action { border: 1px solid #dbe3ef; background: #fff; color: #334155; border-radius: 8px; padding: 0.45rem 0.62rem; font-weight: 800; text-decoration: none; cursor: pointer; font-size: 0.84rem; }
.modelo-status { display: inline-flex; width: fit-content; border-radius: 999px; padding: 0.22rem 0.55rem; font-size: 0.78rem; font-weight: 900; }
.modelo-status.on { background: #dcfce7; color: #166534; }
.modelo-status.off { background: #fee2e2; color: #991b1b; }
.contratos-modal-backdrop { position: fixed; inset: 0; z-index: 1000; display: none; align-items: center; justify-content: center; padding: 1rem; background: rgba(15, 23, 42, 0.55); }
.contratos-modal-backdrop.open,
#contrato-modal:target { display: flex; }
.contratos-modal { width: min(1120px, 100%); max-height: calc(100vh - 2rem); overflow: auto; background: #fff; border-radius: 16px; box-shadow: 0 24px 70px rgba(15, 23, 42, 0.28); }
.contratos-modal-header { padding: 1rem 1.1rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; gap: 1rem; align-items: center; }
.contratos-modal-title { margin: 0; color: #1e293b; font-weight: 900; font-size: 1.15rem; }
.contratos-modal-close { width: 36px; height: 36px; border: none; border-radius: 999px; background: #f1f5f9; color: #334155; cursor: pointer; font-size: 1.25rem; }
.contratos-form { padding: 1rem; display: grid; gap: 0.9rem; }
.contratos-field { display: grid; gap: 0.35rem; }
.contratos-field label { color: #334155; font-size: 0.82rem; font-weight: 800; }
.contratos-field input, .contratos-field textarea { width: 100%; border: 1px solid #d1d9e6; border-radius: 10px; padding: 0.68rem 0.78rem; color: #1e293b; background: #fff; }
.tags-help { display: flex; flex-wrap: wrap; gap: 0.42rem; padding: 0 1rem 1rem; }
.tag-chip { border: 1px solid #bae6fd; background: #e0f2fe; color: #075985; border-radius: 999px; padding: 0.25rem 0.55rem; font-weight: 900; font-size: 0.78rem; cursor: pointer; }
.contratos-modal-actions { display: flex; justify-content: flex-end; gap: 0.65rem; border-top: 1px solid #e2e8f0; padding: 1rem; }
.contratos-btn { border: none; border-radius: 10px; background: #1e3a8a; color: #fff; font-weight: 800; padding: 0.75rem 1rem; cursor: pointer; text-decoration: none; }
.contratos-btn.secondary { background: #f1f5f9; color: #334155; border: 1px solid #dbe3ef; }
</style>

<main class="contratos-page">
    <div class="contratos-header">
        <div>
            <h1 class="contratos-title">Contratos</h1>
            <p class="contratos-subtitle">Modelos de contratos com tags automáticas.</p>
        </div>
        <div class="contratos-header-actions">
            <a class="contratos-back" href="index.php?page=cadastros">← Cadastros</a>
            <a class="contratos-primary" href="index.php?page=cadastros_contratos&novo=1#contrato-modal" data-open-contrato-modal>+ Adicionar contrato</a>
        </div>
    </div>

    <?php if ($success !== ''): ?><div class="contratos-alert success"><?= cadastros_contratos_e($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $error): ?><div class="contratos-alert error"><?= cadastros_contratos_e((string)$error) ?></div><?php endforeach; ?>

    <section class="contratos-card">
        <div class="contratos-card-header"><h2 class="contratos-card-title">Modelos cadastrados</h2></div>
        <div class="contratos-table-wrap">
            <table class="contratos-table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Status</th>
                        <th>Atualização</th>
                        <th>Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($modelos as $modelo): ?>
                        <tr>
                            <td>
                                <div class="modelo-name"><?= cadastros_contratos_e((string)$modelo['nome']) ?></div>
                                <div class="modelo-meta">ID <?= (int)$modelo['id'] ?></div>
                            </td>
                            <td><span class="modelo-status <?= !empty($modelo['ativo']) ? 'on' : 'off' ?>"><?= !empty($modelo['ativo']) ? 'Ativo' : 'Inativo' ?></span></td>
                            <td><?= cadastros_contratos_e(date('d/m/Y H:i', strtotime((string)$modelo['updated_at']))) ?></td>
                            <td>
                                <div class="modelo-actions">
                                    <a
                                        class="modelo-action"
                                        href="index.php?page=cadastros_contratos&edit_id=<?= (int)$modelo['id'] ?>#contrato-modal"
                                        data-edit-contrato
                                        data-id="<?= (int)$modelo['id'] ?>"
                                        data-nome="<?= cadastros_contratos_e((string)$modelo['nome']) ?>"
                                        data-conteudo="<?= cadastros_contratos_e((string)$modelo['conteudo_html']) ?>"
                                    >Editar</a>
                                    <form method="post">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$modelo['id'] ?>">
                                        <button class="modelo-action" type="submit"><?= !empty($modelo['ativo']) ? 'Inativar' : 'Ativar' ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($modelos)): ?>
                        <tr><td colspan="4">Nenhum modelo cadastrado.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<div class="contratos-modal-backdrop <?= $modalOpen ? 'open' : '' ?>" id="contrato-modal" role="dialog" aria-modal="true" aria-labelledby="contrato-modal-title">
    <div class="contratos-modal">
        <div class="contratos-modal-header">
            <h2 class="contratos-modal-title" id="contrato-modal-title"><?= (int)$modalModelo['id'] > 0 ? 'Editar contrato' : 'Adicionar contrato' ?></h2>
            <a class="contratos-modal-close" href="index.php?page=cadastros_contratos" data-close-contrato-modal aria-label="Fechar">×</a>
        </div>
        <form method="post" class="contratos-form" id="contrato-form">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="contrato_id" value="<?= (int)$modalModelo['id'] ?>">
            <div class="contratos-field">
                <label for="nome">Nome do modelo</label>
                <input type="text" name="nome" id="nome" maxlength="180" required placeholder="CONTRATO BUFFET INFANTIL LISBON" value="<?= cadastros_contratos_e((string)$modalModelo['nome']) ?>">
            </div>
            <div class="contratos-field">
                <label for="conteudo_html">Texto do contrato</label>
                <textarea name="conteudo_html" id="conteudo_html" rows="18"><?= cadastros_contratos_e((string)$modalModelo['conteudo_html']) ?></textarea>
            </div>
        </form>
        <div class="tags-help" aria-label="Tags disponíveis">
            <?php foreach ($tags as $tag): ?>
                <button class="tag-chip" type="button" data-tag="<?= cadastros_contratos_e((string)$tag['tag_codigo']) ?>" title="<?= cadastros_contratos_e((string)$tag['nome']) ?>"><?= cadastros_contratos_e((string)$tag['tag_codigo']) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="contratos-modal-actions">
            <a class="contratos-btn secondary" href="index.php?page=cadastros_tags">Gerenciar tags</a>
            <a class="contratos-btn secondary" href="index.php?page=cadastros_contratos" data-close-contrato-modal>Cancelar</a>
            <button class="contratos-btn" type="submit" form="contrato-form">Salvar modelo</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js"></script>
<script>
const contratoModal = document.getElementById('contrato-modal');
const contratoId = document.getElementById('contrato_id');
const contratoNome = document.getElementById('nome');
const contratoTextarea = document.getElementById('conteudo_html');

function initContratoTiny() {
    if (typeof tinymce === 'undefined' || tinymce.get('conteudo_html')) return;
    tinymce.init({
        selector: '#conteudo_html',
        menubar: true,
        branding: false,
        promotion: false,
        plugins: 'advlist autolink lists link table code fullscreen wordcount',
        toolbar: 'undo redo | blocks | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link table | removeformat | code fullscreen',
        height: 520,
        content_style: 'body { font-family: Arial, Helvetica, sans-serif; font-size: 12pt; line-height: 1.45; color: #111827; }'
    });
}

function setEditorContent(html) {
    if (typeof tinymce !== 'undefined' && tinymce.get('conteudo_html')) {
        tinymce.get('conteudo_html').setContent(html || '');
    } else if (contratoTextarea) {
        contratoTextarea.value = html || '';
    }
}

function openContratoModal(data = null) {
    if (!contratoModal) return;
    document.getElementById('contrato-modal-title').textContent = data ? 'Editar contrato' : 'Adicionar contrato';
    contratoId.value = data?.id || '0';
    contratoNome.value = data?.nome || '';
    contratoModal.classList.add('open');
    initContratoTiny();
    window.setTimeout(() => {
        setEditorContent(data?.conteudo || '');
        contratoNome.focus();
    }, 120);
}

function closeContratoModal() {
    contratoModal?.classList.remove('open');
}

function openContratoModalFromButton(button) {
    if (!button) return;
    openContratoModal({
        id: button.dataset.id || '0',
        nome: button.dataset.nome || '',
        conteudo: button.dataset.conteudo || '',
    });
}

window.openContratoModal = openContratoModal;
window.closeContratoModal = closeContratoModal;
window.openContratoModalFromButton = openContratoModalFromButton;

function openContratoModalFromUrl() {
    const params = new URLSearchParams(window.location.search || '');
    const editId = params.get('edit_id');
    if (editId) {
        const editButton = Array.from(document.querySelectorAll('[data-edit-contrato]')).find((button) => button.dataset.id === editId);
        if (editButton) {
            openContratoModalFromButton(editButton);
            return;
        }
    }
    if (params.has('novo')) {
        openContratoModal();
    }
}

document.querySelector('[data-open-contrato-modal]')?.addEventListener('click', (event) => {
    event.preventDefault();
    history.replaceState(null, '', 'index.php?page=cadastros_contratos&novo=1#contrato-modal');
    openContratoModal();
});
document.querySelectorAll('[data-close-contrato-modal]').forEach((button) => button.addEventListener('click', (event) => {
    event.preventDefault();
    history.replaceState(null, '', 'index.php?page=cadastros_contratos');
    closeContratoModal();
}));
contratoModal?.addEventListener('click', (event) => {
    if (event.target === contratoModal) closeContratoModal();
});

document.querySelectorAll('[data-edit-contrato]').forEach((button) => {
    button.addEventListener('click', (event) => {
        event.preventDefault();
        history.replaceState(null, '', `index.php?page=cadastros_contratos&edit_id=${button.dataset.id || ''}#contrato-modal`);
        openContratoModalFromButton(button);
    });
});

openContratoModalFromUrl();

if (contratoModal?.classList.contains('open')) {
    initContratoTiny();
    window.setTimeout(() => setEditorContent(contratoTextarea?.value || ''), 120);
}

document.getElementById('contrato-form')?.addEventListener('submit', () => {
    if (typeof tinymce !== 'undefined') {
        tinymce.triggerSave();
    }
});

document.querySelectorAll('.tag-chip[data-tag]').forEach((button) => {
    button.addEventListener('click', () => {
        const tag = button.getAttribute('data-tag') || '';
        if (!tag) return;
        if (typeof tinymce !== 'undefined' && tinymce.get('conteudo_html')) {
            tinymce.get('conteudo_html').insertContent(tag);
            return;
        }
        const start = contratoTextarea.selectionStart || 0;
        const end = contratoTextarea.selectionEnd || 0;
        contratoTextarea.value = contratoTextarea.value.slice(0, start) + tag + contratoTextarea.value.slice(end);
        contratoTextarea.focus();
        contratoTextarea.selectionStart = contratoTextarea.selectionEnd = start + tag.length;
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeContratoModal();
});
</script>
