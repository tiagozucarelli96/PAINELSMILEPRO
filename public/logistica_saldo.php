<?php
require_once __DIR__ . '/logistica_tz.php';
// logistica_saldo.php — Saldo atual
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/logistica_insumo_base_helper.php';

if (empty($_SESSION['perm_superadmin']) && empty($_SESSION['perm_logistico'])) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

$page_errors = [];
try {
    logistica_ensure_insumo_base_schema($pdo);
} catch (Throwable $e) {
    $page_errors[] = 'Falha ao preparar estrutura de insumo base: ' . $e->getMessage();
}

$unidades = $pdo->query("SELECT id, nome, codigo FROM logistica_unidades WHERE ativo IS TRUE ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$unidade_id = (int)($_GET['unidade_id'] ?? 0);
if ($unidade_id === 0 && $unidades) {
    $unidade_id = (int)$unidades[0]['id'];
}
$q = trim((string)($_GET['q'] ?? ''));

$params = [':uid' => $unidade_id];
$where = "WHERE 1=1";
if ($q !== '') {
    $where .= " AND (
        LOWER(i.nome_oficial) LIKE :q
        OR LOWER(COALESCE(b.nome_base, '')) LIKE :q
    )";
    $params[':q'] = '%' . strtolower($q) . '%';
}

$stmt = $pdo->prepare("
    SELECT i.nome_oficial,
           COALESCE(b.nome_base, i.nome_oficial) AS insumo_base_nome,
           i.foto_url,
           u.nome AS unidade_nome,
           s.quantidade_atual, s.updated_at
    FROM logistica_insumos i
    LEFT JOIN logistica_insumos_base b ON b.id = i.insumo_base_id
    LEFT JOIN logistica_unidades_medida u ON u.id = i.unidade_medida_padrao_id
    LEFT JOIN logistica_estoque_saldos s ON s.insumo_id = i.id AND s.unidade_id = :uid
    $where
    ORDER BY COALESCE(b.nome_base, i.nome_oficial), i.nome_oficial
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

includeSidebar('Saldo de Estoque - Logística');
?>

<style>
.saldo-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 1.5rem;
}
.card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.table { width:100%; border-collapse:collapse; }
.table th,.table td { padding:.6rem; border-bottom:1px solid #e5e7eb; vertical-align:middle; }
.thumb { width:48px; height:48px; border-radius:8px; background:#f1f5f9; overflow:hidden; border:1px solid #e5e7eb; display:flex; align-items:center; justify-content:center; }
.thumb img { width:100%; height:100%; object-fit:cover; }
</style>

<div class="saldo-container">
    <h1>Saldo atual</h1>

    <?php foreach ($page_errors as $error): ?>
        <div class="alert-error" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
    <?php endforeach; ?>

    <form method="get" class="card" style="margin-bottom:1rem;" id="saldo-filter-form">
        <input type="hidden" name="page" value="logistica_saldo">
        <div style="display:flex;gap:1rem;flex-wrap:wrap;">
            <div style="min-width:220px;">
                <label>Unidade</label>
                <select name="unidade_id" id="saldo-unidade-select">
                    <?php foreach ($unidades as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= $unidade_id === (int)$u['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1;min-width:260px;">
                <label>Buscar insumo</label>
                <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Nome base ou nome cadastrado">
            </div>
            <div style="align-self:flex-end;">
                <button class="btn btn-primary" type="submit">Filtrar</button>
            </div>
        </div>
    </form>

    <div class="card">
        <table class="table">
            <thead>
                <tr>
                    <th>Foto</th>
                    <th>Insumo</th>
                    <th>Unidade</th>
                    <th>Saldo atual</th>
                    <th>Atualizado em</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="5">Nenhum insumo encontrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>
                                <div class="thumb">
                                    <?php if (!empty($r['foto_url'])): ?>
                                        <img src="<?= htmlspecialchars($r['foto_url']) ?>" alt="">
                                    <?php else: ?>
                                        📦
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($r['insumo_base_nome'] ?? $r['nome_oficial']) ?></strong>
                                <?php
                                    $base_nome = trim((string)($r['insumo_base_nome'] ?? ''));
                                    $nome_oficial = trim((string)($r['nome_oficial'] ?? ''));
                                ?>
                                <?php if ($base_nome !== '' && strcasecmp($base_nome, $nome_oficial) !== 0): ?>
                                    <div style="font-size:.82rem;color:#64748b;"><?= htmlspecialchars($nome_oficial) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($r['unidade_nome'] ?? '-') ?></td>
                            <td><?= number_format((float)($r['quantidade_atual'] ?? 0), 3, ',', '.') ?></td>
                            <td><?= format_date($r['updated_at'] ?? null) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.getElementById('saldo-unidade-select')?.addEventListener('change', () => {
    document.getElementById('saldo-filter-form')?.submit();
});
</script>

<?php endSidebar(); ?>
