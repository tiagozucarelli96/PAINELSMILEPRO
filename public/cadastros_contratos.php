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

contratos_modelos_ensure_schema($pdo);

$userId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
$success = '';
$errors = [];
$editId = (int)($_GET['id'] ?? 0);

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

$modeloAtual = null;
if ($editId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM contrato_modelos WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $editId]);
        $modeloAtual = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$modeloAtual) {
            $errors[] = 'Modelo não encontrado.';
            $editId = 0;
        }
    } catch (Throwable $e) {
        error_log('cadastros_contratos edit: ' . $e->getMessage());
        $errors[] = 'Não foi possível carregar o modelo.';
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

$formNome = (string)($modeloAtual['nome'] ?? '');
$formConteudo = (string)($modeloAtual['conteudo_html'] ?? '');

includeSidebar('Contratos');
?>

<style>
.contratos-page { padding: 1.5rem; max-width: 1480px; margin: 0 auto; }
.contratos-header { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; flex-wrap: wrap; margin-bottom: 1rem; }
.contratos-title { margin: 0; color: #1e3a8a; font-size: 1.8rem; font-weight: 800; }
.contratos-subtitle { margin: 0.35rem 0 0; color: #64748b; }
.contratos-back { border: 1px solid #dbe3ef; border-radius: 999px; background: #fff; color: #1e293b; text-decoration: none; font-weight: 800; padding: 0.7rem 1rem; }
.contratos-alert { margin-bottom: 1rem; border-radius: 10px; padding: 0.85rem 1rem; font-weight: 800; }
.contratos-alert.success { background: #ecfdf5; color: #166534; border: 1px solid #a7f3d0; }
.contratos-alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.contratos-layout { display: grid; grid-template-columns: minmax(0, 1fr) 390px; gap: 1rem; align-items: start; }
.contratos-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 14px 34px rgba(15, 23, 42, 0.07); overflow: hidden; }
.contratos-card-header { padding: 1rem 1.1rem; border-bottom: 1px solid #e2e8f0; background: #f8fbff; display: flex; justify-content: space-between; gap: 1rem; align-items: center; }
.contratos-card-title { margin: 0; color: #1e293b; font-weight: 800; font-size: 1.05rem; }
.contratos-form { padding: 1rem; display: grid; gap: 0.9rem; }
.contratos-field { display: grid; gap: 0.35rem; }
.contratos-field label { color: #334155; font-size: 0.82rem; font-weight: 800; }
.contratos-field input, .contratos-field textarea { width: 100%; border: 1px solid #d1d9e6; border-radius: 10px; padding: 0.68rem 0.78rem; color: #1e293b; background: #fff; }
.contratos-actions { display: flex; justify-content: flex-end; gap: 0.65rem; border-top: 1px solid #e2e8f0; padding-top: 1rem; }
.contratos-btn { border: none; border-radius: 10px; background: #1e3a8a; color: #fff; font-weight: 800; padding: 0.75rem 1rem; cursor: pointer; text-decoration: none; }
.contratos-btn.secondary { background: #f1f5f9; color: #334155; border: 1px solid #dbe3ef; }
.contratos-list { display: grid; gap: 0.75rem; padding: 1rem; }
.modelo-item { border: 1px solid #e2e8f0; border-radius: 12px; background: #fbfdff; padding: 0.85rem; display: grid; gap: 0.55rem; }
.modelo-name { color: #1e293b; font-weight: 900; }
.modelo-meta { color: #64748b; font-size: 0.84rem; }
.modelo-actions { display: flex; gap: 0.45rem; flex-wrap: wrap; }
.modelo-action { border: 1px solid #dbe3ef; background: #fff; color: #334155; border-radius: 8px; padding: 0.45rem 0.62rem; font-weight: 800; text-decoration: none; cursor: pointer; font-size: 0.84rem; }
.modelo-status { display: inline-flex; width: fit-content; border-radius: 999px; padding: 0.22rem 0.55rem; font-size: 0.78rem; font-weight: 900; }
.modelo-status.on { background: #dcfce7; color: #166534; }
.modelo-status.off { background: #fee2e2; color: #991b1b; }
.tags-help { display: flex; flex-wrap: wrap; gap: 0.42rem; padding: 0.75rem 1rem 1rem; border-top: 1px solid #e2e8f0; }
.tag-chip { border: 1px solid #bae6fd; background: #e0f2fe; color: #075985; border-radius: 999px; padding: 0.25rem 0.55rem; font-weight: 900; font-size: 0.78rem; cursor: pointer; }
@media (max-width: 1100px) { .contratos-layout { grid-template-columns: 1fr; } }
</style>

<main class="contratos-page">
    <div class="contratos-header">
        <div>
            <h1 class="contratos-title">Contratos</h1>
            <p class="contratos-subtitle">Modelos de contratos com tags automáticas para uso nos eventos.</p>
        </div>
        <a class="contratos-back" href="index.php?page=cadastros">← Cadastros</a>
    </div>

    <?php if ($success !== ''): ?><div class="contratos-alert success"><?= cadastros_contratos_e($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $error): ?><div class="contratos-alert error"><?= cadastros_contratos_e((string)$error) ?></div><?php endforeach; ?>

    <div class="contratos-layout">
        <section class="contratos-card">
            <div class="contratos-card-header">
                <h2 class="contratos-card-title"><?= $editId > 0 ? 'Editar modelo' : 'Novo modelo' ?></h2>
                <?php if ($editId > 0): ?><a class="contratos-btn secondary" href="index.php?page=cadastros_contratos">Novo</a><?php endif; ?>
            </div>
            <form method="post" class="contratos-form" id="contrato-form">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)$editId ?>">
                <div class="contratos-field">
                    <label for="nome">Nome do modelo</label>
                    <input type="text" name="nome" id="nome" value="<?= cadastros_contratos_e($formNome) ?>" maxlength="180" required placeholder="CONTRATO BUFFET INFANTIL LISBON">
                </div>
                <div class="contratos-field">
                    <label for="conteudo_html">Texto do contrato</label>
                    <textarea name="conteudo_html" id="conteudo_html" rows="18"><?= cadastros_contratos_e($formConteudo) ?></textarea>
                </div>
                <div class="contratos-actions">
                    <a class="contratos-btn secondary" href="index.php?page=cadastros_tags">Gerenciar tags</a>
                    <button class="contratos-btn" type="submit">Salvar modelo</button>
                </div>
            </form>
            <div class="tags-help" aria-label="Tags disponíveis">
                <?php foreach ($tags as $tag): ?>
                    <button class="tag-chip" type="button" data-tag="<?= cadastros_contratos_e((string)$tag['tag_codigo']) ?>" title="<?= cadastros_contratos_e((string)$tag['nome']) ?>"><?= cadastros_contratos_e((string)$tag['tag_codigo']) ?></button>
                <?php endforeach; ?>
            </div>
        </section>

        <aside class="contratos-card">
            <div class="contratos-card-header"><h2 class="contratos-card-title">Modelos cadastrados</h2></div>
            <div class="contratos-list">
                <?php foreach ($modelos as $modelo): ?>
                    <div class="modelo-item">
                        <div class="modelo-name"><?= cadastros_contratos_e((string)$modelo['nome']) ?></div>
                        <span class="modelo-status <?= !empty($modelo['ativo']) ? 'on' : 'off' ?>"><?= !empty($modelo['ativo']) ? 'Ativo' : 'Inativo' ?></span>
                        <div class="modelo-meta">Atualizado em <?= cadastros_contratos_e(date('d/m/Y H:i', strtotime((string)$modelo['updated_at']))) ?></div>
                        <div class="modelo-actions">
                            <a class="modelo-action" href="index.php?page=cadastros_contratos&id=<?= (int)$modelo['id'] ?>">Editar</a>
                            <form method="post">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$modelo['id'] ?>">
                                <button class="modelo-action" type="submit"><?= !empty($modelo['ativo']) ? 'Inativar' : 'Ativar' ?></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($modelos)): ?>
                    <div class="modelo-item"><div class="modelo-meta">Nenhum modelo cadastrado.</div></div>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js"></script>
<script>
function initContratoTiny() {
    if (typeof tinymce === 'undefined') return;
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
initContratoTiny();

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
        const textarea = document.getElementById('conteudo_html');
        if (!textarea) return;
        const start = textarea.selectionStart || 0;
        const end = textarea.selectionEnd || 0;
        textarea.value = textarea.value.slice(0, start) + tag + textarea.value.slice(end);
        textarea.focus();
        textarea.selectionStart = textarea.selectionEnd = start + tag.length;
    });
});
</script>
