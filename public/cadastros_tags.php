<?php
/**
 * cadastros_tags.php
 * Cadastro de tags usadas nos modelos de contratos.
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

function cadastros_tags_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

contratos_modelos_ensure_schema($pdo);

$success = '';
$errors = [];
$options = contratos_modelos_default_tag_options();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("UPDATE contrato_tags SET ativo = NOT ativo, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $_SESSION['cadastros_tags_success'] = 'Status da tag atualizado.';
            header('Location: index.php?page=cadastros_tags');
            exit;
        } catch (Throwable $e) {
            error_log('cadastros_tags toggle: ' . $e->getMessage());
            $errors[] = 'Não foi possível atualizar a tag.';
        }
    } else {
        $optionKey = trim((string)($_POST['tag_option'] ?? ''));
        $selected = $options[$optionKey] ?? null;
        $tagCodigo = contratos_modelos_normalize_tag((string)($_POST['tag_codigo'] ?? ($selected['tag'] ?? '')));
        $nome = trim((string)($_POST['nome'] ?? ($selected['nome'] ?? '')));
        $origemTipo = trim((string)($_POST['origem_tipo'] ?? ($selected['origem_tipo'] ?? 'manual')));
        $origemCampo = trim((string)($_POST['origem_campo'] ?? ($selected['origem_campo'] ?? 'manual')));
        $descricao = trim((string)($_POST['descricao'] ?? ''));

        if ($tagCodigo === '') {
            $errors[] = 'Informe uma tag válida.';
        }
        if ($nome === '') {
            $errors[] = 'Informe o nome da tag.';
        }
        if ($origemTipo === '' || $origemCampo === '') {
            $errors[] = 'Selecione a origem da tag.';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO contrato_tags (tag_codigo, nome, origem_tipo, origem_campo, descricao, ativo, created_at, updated_at)
                    VALUES (:tag_codigo, :nome, :origem_tipo, :origem_campo, :descricao, TRUE, NOW(), NOW())
                    ON CONFLICT (tag_codigo) DO UPDATE SET
                        nome = EXCLUDED.nome,
                        origem_tipo = EXCLUDED.origem_tipo,
                        origem_campo = EXCLUDED.origem_campo,
                        descricao = EXCLUDED.descricao,
                        ativo = TRUE,
                        updated_at = NOW()
                ");
                $stmt->execute([
                    ':tag_codigo' => $tagCodigo,
                    ':nome' => $nome,
                    ':origem_tipo' => $origemTipo,
                    ':origem_campo' => $origemCampo,
                    ':descricao' => $descricao !== '' ? $descricao : null,
                ]);
                $_SESSION['cadastros_tags_success'] = 'Tag salva com sucesso.';
                header('Location: index.php?page=cadastros_tags');
                exit;
            } catch (Throwable $e) {
                error_log('cadastros_tags save: ' . $e->getMessage());
                $errors[] = 'Não foi possível salvar a tag.';
            }
        }
    }
}

if (!empty($_SESSION['cadastros_tags_success'])) {
    $success = (string)$_SESSION['cadastros_tags_success'];
    unset($_SESSION['cadastros_tags_success']);
}

try {
    $stmt = $pdo->query("SELECT * FROM contrato_tags ORDER BY ativo DESC, tag_codigo ASC");
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('cadastros_tags list: ' . $e->getMessage());
    $tags = [];
    $errors[] = 'Não foi possível carregar as tags.';
}

includeSidebar('Tags de Contratos');
?>

<style>
.tags-page { padding: 1.5rem; max-width: 1380px; margin: 0 auto; }
.tags-header { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; flex-wrap: wrap; margin-bottom: 1rem; }
.tags-title { margin: 0; color: #1e3a8a; font-size: 1.8rem; font-weight: 800; }
.tags-subtitle { margin: 0.35rem 0 0; color: #64748b; }
.tags-back { border: 1px solid #dbe3ef; border-radius: 999px; background: #fff; color: #1e293b; text-decoration: none; font-weight: 800; padding: 0.7rem 1rem; }
.tags-alert { margin-bottom: 1rem; border-radius: 10px; padding: 0.85rem 1rem; font-weight: 800; }
.tags-alert.success { background: #ecfdf5; color: #166534; border: 1px solid #a7f3d0; }
.tags-alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.tags-layout { display: grid; grid-template-columns: 390px minmax(0, 1fr); gap: 1rem; align-items: start; }
.tags-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 14px 34px rgba(15, 23, 42, 0.07); overflow: hidden; }
.tags-card-header { padding: 1rem 1.1rem; border-bottom: 1px solid #e2e8f0; background: #f8fbff; }
.tags-card-title { margin: 0; color: #1e293b; font-weight: 800; font-size: 1.05rem; }
.tags-form { padding: 1rem; display: grid; gap: 0.85rem; }
.tags-field { display: grid; gap: 0.35rem; }
.tags-field label { color: #334155; font-size: 0.82rem; font-weight: 800; }
.tags-field input, .tags-field select, .tags-field textarea { width: 100%; border: 1px solid #d1d9e6; border-radius: 10px; padding: 0.68rem 0.78rem; color: #1e293b; background: #fff; }
.tags-field textarea { min-height: 78px; resize: vertical; }
.tags-btn { border: none; border-radius: 10px; background: #1e3a8a; color: #fff; font-weight: 800; padding: 0.75rem 1rem; cursor: pointer; }
.tags-table-wrap { overflow: auto; }
.tags-table { width: 100%; border-collapse: collapse; }
.tags-table th, .tags-table td { padding: 0.8rem; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
.tags-table th { background: #f8fafc; color: #475569; font-size: 0.78rem; text-transform: uppercase; }
.tag-code { display: inline-flex; border-radius: 999px; background: #e0f2fe; color: #075985; font-weight: 900; padding: 0.25rem 0.55rem; }
.tag-status { display: inline-flex; border-radius: 999px; padding: 0.22rem 0.55rem; font-size: 0.78rem; font-weight: 900; }
.tag-status.on { background: #dcfce7; color: #166534; }
.tag-status.off { background: #fee2e2; color: #991b1b; }
.tag-toggle { border: 1px solid #dbe3ef; background: #fff; border-radius: 8px; padding: 0.42rem 0.62rem; cursor: pointer; font-weight: 800; color: #334155; }
@media (max-width: 980px) { .tags-layout { grid-template-columns: 1fr; } }
</style>

<main class="tags-page">
    <div class="tags-header">
        <div>
            <h1 class="tags-title">Tags</h1>
            <p class="tags-subtitle">Variáveis usadas nos modelos de contrato para preenchimento automático.</p>
        </div>
        <a class="tags-back" href="index.php?page=cadastros">← Cadastros</a>
    </div>

    <?php if ($success !== ''): ?><div class="tags-alert success"><?= cadastros_tags_e($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $error): ?><div class="tags-alert error"><?= cadastros_tags_e((string)$error) ?></div><?php endforeach; ?>

    <div class="tags-layout">
        <section class="tags-card">
            <div class="tags-card-header"><h2 class="tags-card-title">Cadastrar tag</h2></div>
            <form method="post" class="tags-form">
                <input type="hidden" name="action" value="save">
                <div class="tags-field">
                    <label for="tag_option">Campo automático</label>
                    <select name="tag_option" id="tag_option">
                        <option value="">Selecione uma opção</option>
                        <?php foreach ($options as $key => $option): ?>
                            <option value="<?= cadastros_tags_e((string)$key) ?>"
                                data-tag="<?= cadastros_tags_e((string)$option['tag']) ?>"
                                data-nome="<?= cadastros_tags_e((string)$option['nome']) ?>"
                                data-origem-tipo="<?= cadastros_tags_e((string)$option['origem_tipo']) ?>"
                                data-origem-campo="<?= cadastros_tags_e((string)$option['origem_campo']) ?>">
                                <?= cadastros_tags_e((string)$option['tag']) ?> - <?= cadastros_tags_e((string)$option['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tags-field">
                    <label for="tag_codigo">Tag</label>
                    <input type="text" name="tag_codigo" id="tag_codigo" placeholder="#BAIRRO#">
                </div>
                <div class="tags-field">
                    <label for="nome">Nome</label>
                    <input type="text" name="nome" id="nome" maxlength="160" placeholder="Bairro do cliente">
                </div>
                <div class="tags-field">
                    <label for="origem_tipo">Origem</label>
                    <input type="text" name="origem_tipo" id="origem_tipo" readonly>
                </div>
                <div class="tags-field">
                    <label for="origem_campo">Campo</label>
                    <input type="text" name="origem_campo" id="origem_campo" readonly>
                </div>
                <div class="tags-field">
                    <label for="descricao">Descrição</label>
                    <textarea name="descricao" id="descricao" placeholder="Uso interno para explicar esta tag."></textarea>
                </div>
                <button class="tags-btn" type="submit">Salvar tag</button>
            </form>
        </section>

        <section class="tags-card">
            <div class="tags-card-header"><h2 class="tags-card-title">Tags cadastradas</h2></div>
            <div class="tags-table-wrap">
                <table class="tags-table">
                    <thead>
                        <tr>
                            <th>Tag</th>
                            <th>Nome</th>
                            <th>Origem</th>
                            <th>Status</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tags as $tag): ?>
                            <tr>
                                <td><span class="tag-code"><?= cadastros_tags_e((string)$tag['tag_codigo']) ?></span></td>
                                <td><?= cadastros_tags_e((string)$tag['nome']) ?></td>
                                <td><?= cadastros_tags_e((string)$tag['origem_tipo']) ?>.<?= cadastros_tags_e((string)$tag['origem_campo']) ?></td>
                                <td><span class="tag-status <?= !empty($tag['ativo']) ? 'on' : 'off' ?>"><?= !empty($tag['ativo']) ? 'Ativa' : 'Inativa' ?></span></td>
                                <td>
                                    <form method="post">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$tag['id'] ?>">
                                        <button class="tag-toggle" type="submit"><?= !empty($tag['ativo']) ? 'Inativar' : 'Ativar' ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($tags)): ?>
                            <tr><td colspan="5">Nenhuma tag cadastrada.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>

<script>
const tagOption = document.getElementById('tag_option');
if (tagOption) {
    tagOption.addEventListener('change', () => {
        const selected = tagOption.options[tagOption.selectedIndex];
        document.getElementById('tag_codigo').value = selected.dataset.tag || '';
        document.getElementById('nome').value = selected.dataset.nome || '';
        document.getElementById('origem_tipo').value = selected.dataset.origemTipo || '';
        document.getElementById('origem_campo').value = selected.dataset.origemCampo || '';
    });
}
</script>
