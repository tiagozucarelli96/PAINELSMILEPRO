<?php
/**
 * cadastros_pacotes_produtos.php
 * Cadastro unificado de pacotes, serviços e produtos na base de pacotes existente.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/pacotes_evento_helper.php';

if (empty($_SESSION['perm_cadastros']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

function cadastros_pp_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function cadastros_pp_money_to_float(string $value): float
{
    $value = trim($value);
    if ($value === '') {
        return 0.0;
    }
    $value = str_replace(['R$', ' ', '.'], '', $value);
    $value = str_replace(',', '.', $value);
    return is_numeric($value) ? (float)$value : 0.0;
}

function cadastros_pp_money($value): string
{
    return 'R$ ' . number_format((float)($value ?? 0), 2, ',', '.');
}

function cadastros_pp_query_params(): array
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

function cadastros_pp_ensure_schema(PDO $pdo): void
{
    pacotes_evento_ensure_schema($pdo);
    try {
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS categoria VARCHAR(20) NOT NULL DEFAULT 'Pacote'");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS valor_venda NUMERIC(12,2) NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS valor_pacote NUMERIC(12,2) NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS pessoas_base INTEGER NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS valor_convidado_adicional NUMERIC(12,2) NULL");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logistica_pacotes_evento_categoria ON logistica_pacotes_evento(categoria, deleted_at)");
    } catch (Throwable $e) {
        error_log('cadastros_pp_ensure_schema: ' . $e->getMessage());
    }
}

cadastros_pp_ensure_schema($pdo);

$userId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
$success = '';
$errors = [];
$queryParams = cadastros_pp_query_params();
$modalOpen = array_key_exists('novo', $queryParams);
$editId = (int)($queryParams['edit_id'] ?? 0);
$modalItem = [
    'id' => 0,
    'categoria' => 'Pacote',
    'nome' => '',
    'valor_venda' => '',
    'valor_pacote' => '',
    'pessoas_base' => '',
    'valor_convidado_adicional' => '',
    'descricao' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $categoria = trim((string)($_POST['categoria'] ?? 'Pacote'));
        if (!in_array($categoria, ['Pacote', 'Serviço', 'Produto'], true)) {
            $categoria = 'Pacote';
        }

        $nome = trim((string)($_POST['nome'] ?? ''));
        $descricao = trim((string)($_POST['descricao'] ?? ''));
        $valorVenda = cadastros_pp_money_to_float((string)($_POST['valor_venda'] ?? '0'));
        $valorPacote = cadastros_pp_money_to_float((string)($_POST['valor_pacote'] ?? '0'));
        $pessoasBase = max(0, (int)($_POST['pessoas_base'] ?? 0));
        $valorConvidadoAdicional = cadastros_pp_money_to_float((string)($_POST['valor_convidado_adicional'] ?? '0'));

        if ($nome === '') {
            $errors[] = 'Informe o nome.';
        }

        if (empty($errors)) {
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE logistica_pacotes_evento
                        SET categoria = :categoria,
                            nome = :nome,
                            descricao = :descricao,
                            valor_venda = :valor_venda,
                            valor_pacote = :valor_pacote,
                            pessoas_base = :pessoas_base,
                            valor_convidado_adicional = :valor_convidado_adicional,
                            updated_at = NOW()
                        WHERE id = :id
                          AND deleted_at IS NULL
                    ");
                    $stmt->execute([
                        ':id' => $id,
                        ':categoria' => $categoria,
                        ':nome' => $nome,
                        ':descricao' => $descricao !== '' ? $descricao : null,
                        ':valor_venda' => $categoria === 'Pacote' ? null : $valorVenda,
                        ':valor_pacote' => $categoria === 'Pacote' ? $valorPacote : null,
                        ':pessoas_base' => $categoria === 'Pacote' && $pessoasBase > 0 ? $pessoasBase : null,
                        ':valor_convidado_adicional' => $categoria === 'Pacote' ? $valorConvidadoAdicional : null,
                    ]);
                    $_SESSION['cadastros_pp_success'] = 'Cadastro atualizado com sucesso.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO logistica_pacotes_evento (
                            categoria, nome, descricao, valor_venda, valor_pacote, pessoas_base,
                            valor_convidado_adicional, oculto, created_by_user_id, created_at, updated_at
                        ) VALUES (
                            :categoria, :nome, :descricao, :valor_venda, :valor_pacote, :pessoas_base,
                            :valor_convidado_adicional, FALSE, :created_by_user_id, NOW(), NOW()
                        )
                    ");
                    $stmt->execute([
                        ':categoria' => $categoria,
                        ':nome' => $nome,
                        ':descricao' => $descricao !== '' ? $descricao : null,
                        ':valor_venda' => $categoria === 'Pacote' ? null : $valorVenda,
                        ':valor_pacote' => $categoria === 'Pacote' ? $valorPacote : null,
                        ':pessoas_base' => $categoria === 'Pacote' && $pessoasBase > 0 ? $pessoasBase : null,
                        ':valor_convidado_adicional' => $categoria === 'Pacote' ? $valorConvidadoAdicional : null,
                        ':created_by_user_id' => $userId > 0 ? $userId : null,
                    ]);
                    $_SESSION['cadastros_pp_success'] = 'Cadastro criado com sucesso.';
                }
                header('Location: index.php?page=cadastros_pacotes_produtos');
                exit;
            } catch (Throwable $e) {
                error_log('cadastros_pp save: ' . $e->getMessage());
                $errors[] = 'Não foi possível salvar.';
            }
        }
    }

    if ($action === 'duplicate') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("
                INSERT INTO logistica_pacotes_evento (
                    categoria, nome, descricao, valor_venda, valor_pacote, pessoas_base,
                    valor_convidado_adicional, oculto, created_by_user_id, created_at, updated_at
                )
                SELECT categoria, nome || ' (Cópia)', descricao, valor_venda, valor_pacote, pessoas_base,
                       valor_convidado_adicional, FALSE, :created_by_user_id, NOW(), NOW()
                FROM logistica_pacotes_evento
                WHERE id = :id
                  AND deleted_at IS NULL
            ");
            $stmt->execute([
                ':id' => $id,
                ':created_by_user_id' => $userId > 0 ? $userId : null,
            ]);
            $_SESSION['cadastros_pp_success'] = 'Cadastro duplicado com sucesso.';
            header('Location: index.php?page=cadastros_pacotes_produtos');
            exit;
        } catch (Throwable $e) {
            error_log('cadastros_pp duplicate: ' . $e->getMessage());
            $errors[] = 'Não foi possível duplicar.';
        }
    }

    if ($action === 'archive') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("
                UPDATE logistica_pacotes_evento
                SET deleted_at = NOW(),
                    deleted_by_user_id = :user_id,
                    updated_at = NOW()
                WHERE id = :id
                  AND deleted_at IS NULL
            ");
            $stmt->execute([
                ':id' => $id,
                ':user_id' => $userId > 0 ? $userId : null,
            ]);
            $_SESSION['cadastros_pp_success'] = 'Cadastro arquivado com sucesso.';
            header('Location: index.php?page=cadastros_pacotes_produtos');
            exit;
        } catch (Throwable $e) {
            error_log('cadastros_pp archive: ' . $e->getMessage());
            $errors[] = 'Não foi possível arquivar.';
        }
    }
}

if (!empty($_SESSION['cadastros_pp_success'])) {
    $success = (string)$_SESSION['cadastros_pp_success'];
    unset($_SESSION['cadastros_pp_success']);
}

if (!empty($errors) && ($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'save')) {
    $modalOpen = true;
    $modalItem = [
        'id' => (int)($_POST['id'] ?? 0),
        'categoria' => trim((string)($_POST['categoria'] ?? 'Pacote')),
        'nome' => trim((string)($_POST['nome'] ?? '')),
        'valor_venda' => trim((string)($_POST['valor_venda'] ?? '')),
        'valor_pacote' => trim((string)($_POST['valor_pacote'] ?? '')),
        'pessoas_base' => trim((string)($_POST['pessoas_base'] ?? '')),
        'valor_convidado_adicional' => trim((string)($_POST['valor_convidado_adicional'] ?? '')),
        'descricao' => trim((string)($_POST['descricao'] ?? '')),
    ];
} elseif ($editId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM logistica_pacotes_evento
            WHERE id = :id
              AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => $editId]);
        $itemEdicao = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($itemEdicao) {
            $modalOpen = true;
            $modalItem = [
                'id' => (int)$itemEdicao['id'],
                'categoria' => (string)($itemEdicao['categoria'] ?? 'Pacote'),
                'nome' => (string)($itemEdicao['nome'] ?? ''),
                'valor_venda' => (string)($itemEdicao['valor_venda'] ?? ''),
                'valor_pacote' => (string)($itemEdicao['valor_pacote'] ?? ''),
                'pessoas_base' => (string)($itemEdicao['pessoas_base'] ?? ''),
                'valor_convidado_adicional' => (string)($itemEdicao['valor_convidado_adicional'] ?? ''),
                'descricao' => (string)($itemEdicao['descricao'] ?? ''),
            ];
        } else {
            $errors[] = 'Cadastro não encontrado para edição.';
        }
    } catch (Throwable $e) {
        error_log('cadastros_pp edit: ' . $e->getMessage());
        $errors[] = 'Não foi possível carregar o cadastro para edição.';
    }
}

try {
    $stmt = $pdo->query("
        SELECT *
        FROM logistica_pacotes_evento
        WHERE deleted_at IS NULL
        ORDER BY LOWER(nome) ASC, id ASC
    ");
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('cadastros_pp list: ' . $e->getMessage());
    $itens = [];
    $errors[] = 'Não foi possível carregar a listagem.';
}

$galeriaItens = [];
try {
    $table = $pdo->query("SELECT to_regclass('eventos_galeria')")->fetchColumn();
    if (trim((string)$table) !== '') {
        $stmt = $pdo->query("
            SELECT id, categoria, nome, descricao, tags, public_url
            FROM eventos_galeria
            WHERE deleted_at IS NULL
            ORDER BY uploaded_at DESC NULLS LAST, id DESC
            LIMIT 96
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $publicUrl = trim((string)($row['public_url'] ?? ''));
            $fallbackUrl = 'eventos_galeria_imagem.php?id=' . $id;
            $galeriaItens[] = [
                'id' => $id,
                'categoria' => (string)($row['categoria'] ?? ''),
                'nome' => (string)($row['nome'] ?? 'Imagem'),
                'texto' => trim((string)($row['descricao'] ?? '')) !== '' ? (string)$row['descricao'] : (string)($row['tags'] ?? ''),
                'preview_url' => $publicUrl !== '' ? $publicUrl : $fallbackUrl,
                'source_url' => $publicUrl !== '' ? $publicUrl : $fallbackUrl,
            ];
        }
    }
} catch (Throwable $e) {
    error_log('cadastros_pp galeria: ' . $e->getMessage());
}

includeSidebar('Pacotes, Serviços e Produtos');

$modalCategoria = in_array((string)$modalItem['categoria'], ['Pacote', 'Serviço', 'Produto'], true) ? (string)$modalItem['categoria'] : 'Pacote';
$modalIsPacote = $modalCategoria === 'Pacote';
?>

<style>
.pp-page { padding: 1.5rem; max-width: 1500px; margin: 0 auto; }
.pp-header { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; flex-wrap: wrap; margin-bottom: 1rem; }
.pp-title { margin: 0; color: #1e3a8a; font-size: 1.8rem; font-weight: 800; }
.pp-subtitle { margin: 0.35rem 0 0; color: #64748b; }
.pp-actions { display: flex; gap: 0.65rem; flex-wrap: wrap; }
.pp-btn { border: none; border-radius: 10px; background: #1e3a8a; color: #fff; font-weight: 800; padding: 0.7rem 1rem; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
.pp-btn.secondary { background: #fff; color: #1e293b; border: 1px solid #dbe3ef; }
.pp-alert { margin-bottom: 1rem; border-radius: 10px; padding: 0.85rem 1rem; font-weight: 800; }
.pp-alert.success { background: #ecfdf5; color: #166534; border: 1px solid #a7f3d0; }
.pp-alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.pp-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 14px 34px rgba(15, 23, 42, 0.07); overflow: hidden; }
.pp-card-header { padding: 1rem 1.1rem; border-bottom: 1px solid #e2e8f0; background: #f8fbff; }
.pp-card-title { margin: 0; color: #1e293b; font-weight: 800; font-size: 1.05rem; }
.pp-table-wrap { overflow: auto; }
.pp-table { width: 100%; border-collapse: collapse; min-width: 980px; }
.pp-table th, .pp-table td { padding: 0.85rem; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: middle; }
.pp-table th { background: #f8fafc; color: #475569; font-size: 0.78rem; text-transform: uppercase; }
.pp-name { color: #2878b8; font-weight: 900; }
.pp-pill { display: inline-flex; border-radius: 999px; padding: 0.22rem 0.58rem; font-size: 0.78rem; font-weight: 900; background: #e0f2fe; color: #075985; }
.pp-row-actions { display: flex; gap: 0.45rem; flex-wrap: wrap; }
.pp-icon-btn { width: 38px; height: 38px; border: none; border-radius: 8px; color: #fff; font-weight: 900; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
.pp-icon-btn.copy { background: #263747; }
.pp-icon-btn.edit { background: #f2c94c; }
.pp-icon-btn.delete { background: #d9534f; }
.pp-modal-backdrop { position: fixed; inset: 0; z-index: 1000; display: none; align-items: center; justify-content: center; padding: 1rem; background: rgba(15, 23, 42, 0.55); }
.pp-modal-backdrop.open,
#pp-modal:target { display: flex; }
.pp-modal { width: min(1120px, 100%); max-height: calc(100vh - 2rem); overflow: auto; background: #fff; border-radius: 16px; box-shadow: 0 24px 70px rgba(15, 23, 42, 0.28); }
.pp-modal-header { padding: 1rem 1.1rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; gap: 1rem; align-items: center; }
.pp-modal-title { margin: 0; color: #1e293b; font-weight: 900; font-size: 1.15rem; }
.pp-modal-close { width: 36px; height: 36px; border: none; border-radius: 999px; background: #f1f5f9; color: #334155; cursor: pointer; font-size: 1.25rem; }
.pp-form { padding: 1rem; display: grid; gap: 0.9rem; }
.pp-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.85rem; }
.pp-field { display: grid; gap: 0.35rem; }
.pp-field.full { grid-column: 1 / -1; }
.pp-field label { color: #334155; font-size: 0.82rem; font-weight: 800; }
.pp-field input, .pp-field select, .pp-field textarea { width: 100%; border: 1px solid #d1d9e6; border-radius: 10px; padding: 0.68rem 0.78rem; color: #1e293b; background: #fff; }
.pp-modal-actions { display: flex; justify-content: flex-end; gap: 0.65rem; border-top: 1px solid #e2e8f0; padding: 1rem; }
.pp-service-fields.hidden, .pp-package-fields.hidden { display: none; }
.pp-gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 0.8rem; padding: 1rem; }
.pp-gallery-item { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; overflow: hidden; display: grid; text-align: left; cursor: pointer; }
.pp-gallery-thumb { aspect-ratio: 4 / 3; background: #f1f5f9; overflow: hidden; }
.pp-gallery-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pp-gallery-body { padding: 0.7rem; display: grid; gap: 0.25rem; }
.pp-gallery-name { font-weight: 900; color: #1e293b; font-size: 0.86rem; }
.pp-gallery-meta { color: #64748b; font-size: 0.76rem; }
.pp-gallery-empty { padding: 1rem; color: #64748b; }
@media (max-width: 900px) { .pp-grid { grid-template-columns: 1fr; } }
</style>

<main class="pp-page">
    <div class="pp-header">
        <div>
            <h1 class="pp-title">Pacotes, serviços e produtos</h1>
            <p class="pp-subtitle">Cadastros comerciais reutilizando a base atual de pacotes.</p>
        </div>
        <div class="pp-actions">
            <a class="pp-btn secondary" href="index.php?page=cadastros">← Cadastros</a>
            <a class="pp-btn" href="index.php?page=cadastros_pacotes_produtos&novo=1#pp-modal" data-open-pp-modal>+ Adicionar</a>
        </div>
    </div>

    <?php if ($success !== ''): ?><div class="pp-alert success"><?= cadastros_pp_e($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $error): ?><div class="pp-alert error"><?= cadastros_pp_e((string)$error) ?></div><?php endforeach; ?>

    <section class="pp-card">
        <div class="pp-card-header"><h2 class="pp-card-title">Listagem</h2></div>
        <div class="pp-table-wrap">
            <table class="pp-table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Categoria</th>
                        <th>Base</th>
                        <th>Valor venda</th>
                        <th>Opções</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($itens as $item): ?>
                        <?php
                        $categoria = trim((string)($item['categoria'] ?? 'Pacote'));
                        $valorVenda = $categoria === 'Pacote' ? ($item['valor_pacote'] ?? 0) : ($item['valor_venda'] ?? 0);
                        ?>
                        <tr>
                            <td><span class="pp-name"><?= cadastros_pp_e((string)$item['nome']) ?></span></td>
                            <td><span class="pp-pill"><?= cadastros_pp_e($categoria) ?></span></td>
                            <td><?= $categoria === 'Pacote' ? (int)($item['pessoas_base'] ?? 0) . ' pessoas' : '-' ?></td>
                            <td><?= cadastros_pp_money($valorVenda) ?></td>
                            <td>
                                <div class="pp-row-actions">
                                    <form method="post">
                                        <input type="hidden" name="action" value="duplicate">
                                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                        <button class="pp-icon-btn copy" type="submit" title="Duplicar">⧉</button>
                                    </form>
                                    <a
                                        class="pp-icon-btn edit"
                                        href="index.php?page=cadastros_pacotes_produtos&edit_id=<?= (int)$item['id'] ?>#pp-modal"
                                        title="Editar"
                                        data-edit-pp
                                        data-id="<?= (int)$item['id'] ?>"
                                    >✎</a>
                                    <form method="post" onsubmit="return confirm('Arquivar este cadastro?');">
                                        <input type="hidden" name="action" value="archive">
                                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                        <button class="pp-icon-btn delete" type="submit" title="Arquivar">🗑</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($itens)): ?>
                        <tr><td colspan="5">Nenhum cadastro encontrado.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<div class="pp-modal-backdrop <?= $modalOpen ? 'open' : '' ?>" id="pp-modal" role="dialog" aria-modal="true" aria-labelledby="pp-modal-title">
    <div class="pp-modal">
        <div class="pp-modal-header">
            <h2 class="pp-modal-title" id="pp-modal-title"><?= (int)$modalItem['id'] > 0 ? 'Editar cadastro' : 'Adicionar cadastro' ?></h2>
            <a class="pp-modal-close" href="index.php?page=cadastros_pacotes_produtos" data-close-pp-modal aria-label="Fechar">×</a>
        </div>
        <form method="post" class="pp-form" id="pp-form">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="pp-id" value="<?= (int)$modalItem['id'] ?>">
            <div class="pp-grid">
                <div class="pp-field">
                    <label for="pp-categoria">Categoria</label>
                    <select name="categoria" id="pp-categoria" required>
                        <option value="Pacote" <?= $modalCategoria === 'Pacote' ? 'selected' : '' ?>>Pacote</option>
                        <option value="Serviço" <?= $modalCategoria === 'Serviço' ? 'selected' : '' ?>>Serviço</option>
                        <option value="Produto" <?= $modalCategoria === 'Produto' ? 'selected' : '' ?>>Produto</option>
                    </select>
                </div>
                <div class="pp-field">
                    <label for="pp-nome">Nome</label>
                    <input type="text" name="nome" id="pp-nome" maxlength="180" required value="<?= cadastros_pp_e((string)$modalItem['nome']) ?>">
                </div>
                <div class="pp-field pp-service-fields <?= $modalIsPacote ? 'hidden' : '' ?>">
                    <label for="pp-valor-venda">Valor de venda</label>
                    <input type="text" name="valor_venda" id="pp-valor-venda" inputmode="decimal" placeholder="0,00" value="<?= cadastros_pp_e((string)$modalItem['valor_venda']) ?>">
                </div>
                <div class="pp-field pp-package-fields <?= $modalIsPacote ? '' : 'hidden' ?>">
                    <label for="pp-valor-pacote">Valor do pacote</label>
                    <input type="text" name="valor_pacote" id="pp-valor-pacote" inputmode="decimal" placeholder="0,00" value="<?= cadastros_pp_e((string)$modalItem['valor_pacote']) ?>">
                </div>
                <div class="pp-field pp-package-fields <?= $modalIsPacote ? '' : 'hidden' ?>">
                    <label for="pp-pessoas-base">Quantia de pessoas base</label>
                    <input type="number" name="pessoas_base" id="pp-pessoas-base" min="0" step="1" value="<?= cadastros_pp_e((string)$modalItem['pessoas_base']) ?>">
                </div>
                <div class="pp-field pp-package-fields <?= $modalIsPacote ? '' : 'hidden' ?>">
                    <label for="pp-valor-convidado-adicional">Valor convidado adicional</label>
                    <input type="text" name="valor_convidado_adicional" id="pp-valor-convidado-adicional" inputmode="decimal" placeholder="0,00" value="<?= cadastros_pp_e((string)$modalItem['valor_convidado_adicional']) ?>">
                </div>
                <div class="pp-field full">
                    <label for="pp-descricao">Descrição</label>
                    <textarea name="descricao" id="pp-descricao" rows="14"><?= cadastros_pp_e((string)$modalItem['descricao']) ?></textarea>
                </div>
            </div>
        </form>
        <div class="pp-modal-actions">
            <a class="pp-btn secondary" href="index.php?page=cadastros_pacotes_produtos" data-close-pp-modal>Cancelar</a>
            <button class="pp-btn" type="submit" form="pp-form">Salvar</button>
        </div>
    </div>
</div>

<div class="pp-modal-backdrop" id="pp-gallery-modal" role="dialog" aria-modal="true" aria-labelledby="pp-gallery-title">
    <div class="pp-modal">
        <div class="pp-modal-header">
            <h2 class="pp-modal-title" id="pp-gallery-title">Selecionar imagem da galeria</h2>
            <button class="pp-modal-close" type="button" data-close-pp-gallery aria-label="Fechar">×</button>
        </div>
        <?php if (!empty($galeriaItens)): ?>
            <div class="pp-gallery-grid">
                <?php foreach ($galeriaItens as $imagem): ?>
                    <button
                        type="button"
                        class="pp-gallery-item"
                        data-gallery-image
                        data-src="<?= cadastros_pp_e((string)$imagem['source_url']) ?>"
                        data-name="<?= cadastros_pp_e((string)$imagem['nome']) ?>"
                    >
                        <span class="pp-gallery-thumb"><img src="<?= cadastros_pp_e((string)$imagem['preview_url']) ?>" alt="<?= cadastros_pp_e((string)$imagem['nome']) ?>"></span>
                        <span class="pp-gallery-body">
                            <span class="pp-gallery-name"><?= cadastros_pp_e((string)$imagem['nome']) ?></span>
                            <span class="pp-gallery-meta"><?= cadastros_pp_e((string)$imagem['categoria']) ?></span>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="pp-gallery-empty">Nenhuma imagem encontrada na galeria.</div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js"></script>
<script type="application/json" id="pp-items-json"><?= json_encode(array_column($itens, null, 'id'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
<script>
const ppModal = document.getElementById('pp-modal');
const ppGalleryModal = document.getElementById('pp-gallery-modal');
const ppForm = document.getElementById('pp-form');
const ppCategoria = document.getElementById('pp-categoria');
const ppDescricao = document.getElementById('pp-descricao');
let ppItems = {};
try {
    ppItems = JSON.parse(document.getElementById('pp-items-json')?.textContent || '{}');
} catch (error) {
    ppItems = {};
}

function initPpTiny() {
    if (typeof tinymce === 'undefined' || tinymce.get('pp-descricao')) return;
    tinymce.init({
        selector: '#pp-descricao',
        base_url: 'https://cdn.jsdelivr.net/npm/tinymce@6',
        suffix: '.min',
        menubar: false,
        branding: false,
        promotion: false,
        plugins: 'lists link image table code fullscreen',
        toolbar: 'undo redo | bold italic underline strikethrough | alignleft aligncenter alignright | bullist numlist outdent indent | link image galeriaSmile table | removeformat | code fullscreen',
        height: 420,
        paste_data_images: true,
        automatic_uploads: true,
        setup: function(editor) {
            editor.ui.registry.addButton('galeriaSmile', {
                text: 'Galeria',
                tooltip: 'Inserir imagem da galeria',
                onAction: function() {
                    ppGalleryModal?.classList.add('open');
                }
            });
        },
        images_upload_handler: function(blobInfo) {
            return new Promise(function(resolve, reject) {
                const xhr = new XMLHttpRequest();
                const formData = new FormData();
                formData.append('file', blobInfo.blob(), blobInfo.filename());
                xhr.open('POST', `${window.location.pathname}?page=cadastros_upload_imagem`);
                xhr.onload = function() {
                    if (xhr.status < 200 || xhr.status >= 300) {
                        reject('Upload falhou: ' + xhr.status);
                        return;
                    }
                    try {
                        const data = JSON.parse(xhr.responseText);
                        if (data.location) resolve(data.location);
                        else reject(data.error || 'Resposta inválida');
                    } catch (error) {
                        reject('Resposta inválida');
                    }
                };
                xhr.onerror = function() { reject('Falha de rede'); };
                xhr.send(formData);
            });
        },
        content_style: 'body { font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.45; color: #111827; }'
    });
}

function setTinyContent(html) {
    if (typeof tinymce !== 'undefined' && tinymce.get('pp-descricao')) {
        tinymce.get('pp-descricao').setContent(html || '');
    } else {
        ppDescricao.value = html || '';
    }
}

function updateCategoriaFields() {
    const isPacote = ppCategoria.value === 'Pacote';
    document.querySelectorAll('.pp-package-fields').forEach((el) => el.classList.toggle('hidden', !isPacote));
    document.querySelectorAll('.pp-service-fields').forEach((el) => el.classList.toggle('hidden', isPacote));
}

function openPpModal(data = null) {
    document.getElementById('pp-modal-title').textContent = data ? 'Editar cadastro' : 'Adicionar cadastro';
    document.getElementById('pp-id').value = data?.id || '0';
    ppCategoria.value = data?.categoria || 'Pacote';
    document.getElementById('pp-nome').value = data?.nome || '';
    document.getElementById('pp-valor-venda').value = data?.valorVenda || '';
    document.getElementById('pp-valor-pacote').value = data?.valorPacote || '';
    document.getElementById('pp-pessoas-base').value = data?.pessoasBase || '';
    document.getElementById('pp-valor-convidado-adicional').value = data?.valorConvidadoAdicional || '';
    updateCategoriaFields();
    ppModal.classList.add('open');
    initPpTiny();
    window.setTimeout(() => setTinyContent(data?.descricao || ''), 120);
}

function closePpModal() {
    ppModal?.classList.remove('open');
}

function getPpItemModalData(id) {
    const item = ppItems[id || ''] || {};
    return {
        id: item.id || id || '0',
        categoria: item.categoria || 'Pacote',
        nome: item.nome || '',
        valorVenda: item.valor_venda || '',
        valorPacote: item.valor_pacote || '',
        pessoasBase: item.pessoas_base || '',
        valorConvidadoAdicional: item.valor_convidado_adicional || '',
        descricao: item.descricao || '',
    };
}

window.ppOpenCadastroModal = function () {
    openPpModal();
};

window.ppOpenCadastroModalFromButton = function (button) {
    openPpModal(getPpItemModalData(button?.dataset?.id || ''));
};

window.ppCloseCadastroModal = closePpModal;

function closePpGalleryModal() {
    ppGalleryModal?.classList.remove('open');
}

function openPpModalFromUrl() {
    const params = new URLSearchParams(window.location.search || '');
    const editId = params.get('edit_id');
    if (editId) {
        openPpModal(getPpItemModalData(editId));
        return;
    }
    if (params.has('novo')) {
        openPpModal();
    }
}

document.querySelector('[data-open-pp-modal]')?.addEventListener('click', (event) => {
    event.preventDefault();
    history.replaceState(null, '', 'index.php?page=cadastros_pacotes_produtos&novo=1#pp-modal');
    openPpModal();
});
document.querySelectorAll('[data-close-pp-modal]').forEach((button) => button.addEventListener('click', (event) => {
    event.preventDefault();
    history.replaceState(null, '', 'index.php?page=cadastros_pacotes_produtos');
    closePpModal();
}));
ppModal?.addEventListener('click', (event) => {
    if (event.target === ppModal) closePpModal();
});
ppGalleryModal?.addEventListener('click', (event) => {
    if (event.target === ppGalleryModal) closePpGalleryModal();
});
document.querySelectorAll('[data-close-pp-gallery]').forEach((button) => button.addEventListener('click', closePpGalleryModal));
ppCategoria?.addEventListener('change', updateCategoriaFields);

document.querySelectorAll('[data-edit-pp]').forEach((button) => {
    button.addEventListener('click', (event) => {
        event.preventDefault();
        history.replaceState(null, '', `index.php?page=cadastros_pacotes_produtos&edit_id=${button.dataset.id || ''}#pp-modal`);
        openPpModal(getPpItemModalData(button.dataset.id || ''));
    });
});

openPpModalFromUrl();

if (ppModal?.classList.contains('open')) {
    updateCategoriaFields();
    initPpTiny();
    window.setTimeout(() => setTinyContent(ppDescricao?.value || ''), 120);
}

ppForm?.addEventListener('submit', () => {
    if (typeof tinymce !== 'undefined') {
        tinymce.triggerSave();
    }
});

document.querySelectorAll('[data-gallery-image]').forEach((button) => {
    button.addEventListener('click', () => {
        const src = button.dataset.src || '';
        const name = button.dataset.name || 'Imagem';
        const editor = typeof tinymce !== 'undefined' ? tinymce.get('pp-descricao') : null;
        if (src && editor) {
            editor.insertContent(`<p><img src="${src}" alt="${name}" style="max-width:100%;height:auto;"></p>`);
        }
        closePpGalleryModal();
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closePpGalleryModal();
        closePpModal();
    }
});
</script>
<script>
(function () {
    const modal = document.getElementById('pp-modal');
    if (!modal) return;

    function readItems() {
        try {
            return JSON.parse(document.getElementById('pp-items-json')?.textContent || '{}');
        } catch (error) {
            return {};
        }
    }

    function itemData(id) {
        const item = readItems()[id || ''] || {};
        return {
            id: item.id || id || '0',
            categoria: item.categoria || 'Pacote',
            nome: item.nome || '',
            valorVenda: item.valor_venda || '',
            valorPacote: item.valor_pacote || '',
            pessoasBase: item.pessoas_base || '',
            valorConvidadoAdicional: item.valor_convidado_adicional || '',
            descricao: item.descricao || '',
        };
    }

    function setField(id, value) {
        const field = document.getElementById(id);
        if (field) field.value = value || '';
    }

    function syncCategory() {
        const categoria = document.getElementById('pp-categoria')?.value || 'Pacote';
        const isPacote = categoria === 'Pacote';
        document.querySelectorAll('.pp-package-fields').forEach((el) => el.classList.toggle('hidden', !isPacote));
        document.querySelectorAll('.pp-service-fields').forEach((el) => el.classList.toggle('hidden', isPacote));
    }

    function open(data) {
        document.getElementById('pp-modal-title').textContent = data ? 'Editar cadastro' : 'Adicionar cadastro';
        setField('pp-id', data?.id || '0');
        setField('pp-categoria', data?.categoria || 'Pacote');
        setField('pp-nome', data?.nome || '');
        setField('pp-valor-venda', data?.valorVenda || '');
        setField('pp-valor-pacote', data?.valorPacote || '');
        setField('pp-pessoas-base', data?.pessoasBase || '');
        setField('pp-valor-convidado-adicional', data?.valorConvidadoAdicional || '');
        setField('pp-descricao', data?.descricao || '');
        syncCategory();
        modal.classList.add('open');
    }

    function close() {
        modal.classList.remove('open');
        history.replaceState(null, '', 'index.php?page=cadastros_pacotes_produtos');
    }

    document.addEventListener('click', function (event) {
        const add = event.target.closest('[data-open-pp-modal]');
        if (add) {
            event.preventDefault();
            history.replaceState(null, '', 'index.php?page=cadastros_pacotes_produtos&novo=1#pp-modal');
            open(null);
            return;
        }

        const edit = event.target.closest('[data-edit-pp]');
        if (edit) {
            event.preventDefault();
            history.replaceState(null, '', `index.php?page=cadastros_pacotes_produtos&edit_id=${edit.dataset.id || ''}#pp-modal`);
            open(itemData(edit.dataset.id || ''));
            return;
        }

        if (event.target.closest('[data-close-pp-modal]')) {
            event.preventDefault();
            close();
        }
    });

    document.getElementById('pp-categoria')?.addEventListener('change', syncCategory);

    const params = new URLSearchParams(window.location.search || '');
    if (params.get('edit_id')) {
        open(itemData(params.get('edit_id')));
    } else if (params.has('novo')) {
        open(null);
    }
})();
</script>
