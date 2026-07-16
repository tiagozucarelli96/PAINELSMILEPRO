<?php
/**
 * comercial_clientes_cadastrados.php
 * Lista completa de clientes cadastrados no Comercial.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

if (empty($_SESSION['perm_comercial']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

function comercial_clientes_cadastrados_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function comercial_clientes_cadastrados_has_table(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SELECT to_regclass(:table)');
        $stmt->execute([':table' => $table]);
        return trim((string)$stmt->fetchColumn()) !== '';
    } catch (Throwable $e) {
        return false;
    }
}

function comercial_clientes_cadastrados_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM pg_attribute
            WHERE attrelid = to_regclass(:table)
              AND attname = :column
              AND NOT attisdropped
            LIMIT 1
        ");
        $stmt->execute([':table' => $table, ':column' => $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function comercial_clientes_cadastrados_lista(PDO $pdo, string $search): array
{
    if (!comercial_clientes_cadastrados_has_table($pdo, 'comercial_cadastro_clientes')) {
        return [];
    }

    $where = ['c.ativo IS TRUE'];
    $params = [];
    if ($search !== '') {
        $where[] = "(
            c.nome_completo ILIKE :search
            OR c.email ILIKE :search
            OR c.telefone_whatsapp ILIKE :search
            OR c.documento_numero ILIKE :search
        )";
        $params[':search'] = '%' . $search . '%';
    }

    $responsavelExpr = comercial_clientes_cadastrados_has_column($pdo, 'usuarios', 'nome')
        ? "u.nome"
        : (comercial_clientes_cadastrados_has_column($pdo, 'usuarios', 'email') ? "u.email" : "NULL");

    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.tipo_pessoa,
            c.nome_completo,
            c.email,
            c.telefone_whatsapp,
            c.documento_tipo,
            c.documento_numero,
            c.origem_cliente,
            c.tipo_interesse,
            c.created_at,
            c.updated_at,
            {$responsavelExpr} AS responsavel_nome
        FROM comercial_cadastro_clientes c
        LEFT JOIN usuarios u ON u.id = c.responsavel_usuario_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY LOWER(c.nome_completo) ASC, c.id ASC
        LIMIT 1000
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$search = trim((string)($_GET['search'] ?? ''));
$clientes = comercial_clientes_cadastrados_lista($pdo, $search);

includeSidebar('Clientes cadastrados');
?>

<style>
.clientes-page {
    padding: 1.5rem;
    max-width: 1360px;
    margin: 0 auto;
    background: #f8fafc;
}
.clientes-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}
.clientes-title {
    margin: 0;
    color: #1e3a8a;
    font-size: 1.8rem;
    font-weight: 800;
}
.clientes-subtitle {
    margin: 0.35rem 0 0;
    color: #64748b;
}
.clientes-actions {
    display: flex;
    gap: 0.6rem;
    flex-wrap: wrap;
}
.clientes-btn {
    border: 1px solid #dbe3ef;
    border-radius: 999px;
    background: #fff;
    color: #1e293b;
    text-decoration: none;
    font-weight: 800;
    padding: 0.72rem 1rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.clientes-btn.primary {
    background: #1e3a8a;
    color: #fff;
    border-color: #1e3a8a;
}
.clientes-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 14px 34px rgba(15, 23, 42, 0.07);
    overflow: hidden;
}
.clientes-toolbar {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 0.7rem;
    padding: 1rem;
    border-bottom: 1px solid #e2e8f0;
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
}
.clientes-search {
    width: 100%;
    border: 1px solid #d1d9e6;
    border-radius: 10px;
    padding: 0.76rem 0.9rem;
    color: #1e293b;
    font-size: 0.94rem;
}
.clientes-table-wrap {
    overflow-x: auto;
}
.clientes-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 880px;
}
.clientes-table th {
    text-align: left;
    padding: 0.82rem 1rem;
    font-size: 0.78rem;
    color: #334155;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}
.clientes-table td {
    padding: 0.9rem 1rem;
    border-bottom: 1px solid #e2e8f0;
    vertical-align: top;
}
.clientes-name {
    color: #0f6ea8;
    font-weight: 800;
    text-decoration: none;
}
.clientes-type {
    display: inline-flex;
    margin-top: 0.35rem;
    padding: 0.2rem 0.42rem;
    border-radius: 6px;
    background: #f6a437;
    color: #fff;
    font-size: 0.74rem;
    font-weight: 800;
}
.clientes-muted {
    color: #64748b;
    font-size: 0.84rem;
    line-height: 1.45;
}
.clientes-contact {
    display: grid;
    gap: 0.22rem;
    color: #334155;
    font-size: 0.88rem;
}
.clientes-edit {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    background: #f8fafc;
    border: 1px solid #dbe3ef;
    color: #1e3a8a;
    text-decoration: none;
    font-weight: 800;
    padding: 0.48rem 0.7rem;
}
.clientes-empty {
    padding: 2rem;
    color: #64748b;
}
@media (max-width: 760px) {
    .clientes-toolbar {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="clientes-page">
    <div class="clientes-header">
        <div>
            <h1 class="clientes-title">Clientes cadastrados</h1>
            <p class="clientes-subtitle">Lista completa de clientes do Comercial.</p>
        </div>
        <div class="clientes-actions">
            <a class="clientes-btn" href="index.php?page=comercial">← Comercial</a>
            <a class="clientes-btn primary" href="index.php?page=comercial_cadastro_cliente">+ Novo cliente</a>
        </div>
    </div>

    <section class="clientes-card">
        <form method="get" class="clientes-toolbar">
            <input type="hidden" name="page" value="comercial_clientes_cadastrados">
            <input class="clientes-search" type="text" name="search" value="<?= comercial_clientes_cadastrados_e($search) ?>" placeholder="Buscar por nome, e-mail, telefone ou documento">
            <button class="clientes-btn primary" type="submit">Buscar</button>
        </form>

        <div class="clientes-table-wrap">
            <table class="clientes-table">
                <thead>
                    <tr>
                        <th>Nome/Razão Social</th>
                        <th>Contato</th>
                        <th>Documento</th>
                        <th>Origem/Interesse</th>
                        <th>Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($clientes)): ?>
                        <tr>
                            <td colspan="5" class="clientes-empty">Nenhum cliente encontrado.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($clientes as $cliente): ?>
                            <?php
                            $tipoPessoa = (string)($cliente['tipo_pessoa'] ?? 'PF');
                            $tipoLabel = $tipoPessoa === 'PJ' ? 'Pessoa Jurídica' : 'Pessoa Física';
                            $editId = (int)$cliente['id'];
                            $editHref = 'index.php?page=comercial_cadastro_cliente';
                            $editOnClick = "var f=document.createElement('form');f.method='post';f.action='index.php?page=comercial_cadastro_cliente';var a=document.createElement('input');a.type='hidden';a.name='action';a.value='open_cliente_edit';f.appendChild(a);var i=document.createElement('input');i.type='hidden';i.name='id';i.value='{$editId}';f.appendChild(i);document.body.appendChild(f);f.submit();return false;";
                            ?>
                            <tr>
                                <td>
                                    <a class="clientes-name" href="<?= comercial_clientes_cadastrados_e($editHref) ?>" onclick="<?= comercial_clientes_cadastrados_e($editOnClick) ?>">
                                        <?= comercial_clientes_cadastrados_e((string)$cliente['nome_completo']) ?>
                                    </a>
                                    <br>
                                    <span class="clientes-type"><?= comercial_clientes_cadastrados_e($tipoLabel) ?></span>
                                </td>
                                <td>
                                    <div class="clientes-contact">
                                        <span>✉ <?= comercial_clientes_cadastrados_e((string)($cliente['email'] ?? '')) ?: '-' ?></span>
                                        <span>☏ <?= comercial_clientes_cadastrados_e((string)($cliente['telefone_whatsapp'] ?? '')) ?: '-' ?></span>
                                    </div>
                                </td>
                                <td class="clientes-muted">
                                    <?= comercial_clientes_cadastrados_e((string)($cliente['documento_tipo'] ?? '')) ?>
                                    <?= comercial_clientes_cadastrados_e((string)($cliente['documento_numero'] ?? '')) ?>
                                </td>
                                <td class="clientes-muted">
                                    <?= comercial_clientes_cadastrados_e((string)($cliente['origem_cliente'] ?? '')) ?: '-' ?><br>
                                    <?= comercial_clientes_cadastrados_e((string)($cliente['tipo_interesse'] ?? '')) ?>
                                </td>
                                <td>
                                    <a class="clientes-edit" href="<?= comercial_clientes_cadastrados_e($editHref) ?>" onclick="<?= comercial_clientes_cadastrados_e($editOnClick) ?>">Editar</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
