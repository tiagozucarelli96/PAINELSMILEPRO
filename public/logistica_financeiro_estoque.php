<?php
// logistica_financeiro_estoque.php — Valor do estoque
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

$can_finance = !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_logistico_financeiro']);
if (!$can_finance) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

$unidades = $pdo->query("SELECT id, nome, codigo FROM logistica_unidades WHERE ativo IS TRUE ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$unidade_id = $_GET['unidade_id'] ?? 'todas';
$q = trim((string)($_GET['q'] ?? ''));

$params = [];
$where = [];

if ($unidade_id !== 'todas') {
    $where[] = "s.unidade_id = :uid";
    $params[':uid'] = (int)$unidade_id;
}
if ($q !== '') {
    $where[] = "(LOWER(i.nome_oficial) LIKE :q OR LOWER(COALESCE(i.sinonimos,'')) LIKE :q)";
    $params[':q'] = '%' . strtolower($q) . '%';
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

if ($unidade_id === 'todas') {
    $sql = "
        SELECT i.id, i.nome_oficial, i.custo_padrao, i.unidade_medida_padrao_id,
               u.nome AS unidade_nome,
               SUM(COALESCE(s.quantidade_atual, 0)) AS saldo_atual
        FROM logistica_insumos i
        LEFT JOIN logistica_unidades_medida u ON u.id = i.unidade_medida_padrao_id
        LEFT JOIN logistica_estoque_saldos s ON s.insumo_id = i.id
        $where_sql
        GROUP BY i.id, i.nome_oficial, i.custo_padrao, i.unidade_medida_padrao_id, u.nome
        HAVING SUM(COALESCE(s.quantidade_atual, 0)) > 0
        ORDER BY i.nome_oficial
    ";
} else {
    $sql = "
        SELECT i.id, i.nome_oficial, i.custo_padrao, i.unidade_medida_padrao_id,
               u.nome AS unidade_nome,
               COALESCE(s.quantidade_atual, 0) AS saldo_atual
        FROM logistica_insumos i
        LEFT JOIN logistica_unidades_medida u ON u.id = i.unidade_medida_padrao_id
        LEFT JOIN logistica_estoque_saldos s ON s.insumo_id = i.id
        $where_sql
        AND COALESCE(s.quantidade_atual, 0) > 0
        ORDER BY i.nome_oficial
    ";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = 0.0;
$missing = 0;
foreach ($rows as $r) {
    if ($r['custo_padrao'] === null || $r['custo_padrao'] === '') {
        $missing++;
        continue;
    }
    $total += (float)$r['saldo_atual'] * (float)$r['custo_padrao'];
}

includeSidebar('Financeiro - Valor do Estoque');
?>

<style>
.page-container { max-width: 1400px; margin: 0 auto; padding: 1.5rem; }
.section-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:1.5rem; margin-bottom:1.5rem; box-shadow:0 2px 8px rgba(0,0,0,0.08); }
.table { width:100%; border-collapse:collapse; }
.table th,.table td { padding:.6rem; border-bottom:1px solid #e5e7eb; }
.badge-missing { background:#fef3c7; color:#92400e; padding:.2rem .5rem; border-radius:6px; font-size:.85rem; }
.btn-secondary { background:#e2e8f0; color:#0f172a; border:none; padding:.5rem .9rem; border-radius:8px; cursor:pointer; }
.btn-primary { background:#2563eb; color:#fff; border:none; padding:.5rem .9rem; border-radius:8px; cursor:pointer; }
.right { text-align:right; }
</style>

<div class="page-container">
    <h1>Valor do Estoque</h1>

    <div class="section-card">
        <form method="get" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;">
            <input type="hidden" name="page" value="logistica_financeiro_estoque">
            <div>
                <label>Unidade</label>
                <select name="unidade_id">
                    <option value="todas" <?= $unidade_id === 'todas' ? 'selected' : '' ?>>Todas</option>
                    <?php foreach ($unidades as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (string)$unidade_id === (string)$u['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:260px;">
                <label>Buscar</label>
                <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Nome ou sinônimo">
            </div>
            <button class="btn-secondary" type="submit">Filtrar</button>
            <a class="btn-primary" href="index.php?page=logistica_financeiro_estoque_pdf&unidade_id=<?= urlencode($unidade_id) ?>&q=<?= urlencode($q) ?>" target="_blank">PDF</a>
        </form>
    </div>

    <div class="section-card">
        <table class="table">
            <thead>
                <tr>
                    <th>Insumo</th>
                    <th>Unidade</th>
                    <th class="right">Saldo</th>
                    <th class="right">Custo padrão</th>
                    <th class="right">Valor total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="5">Nenhum item encontrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                            $custo = $r['custo_padrao'];
                            $saldo = (float)$r['saldo_atual'];
                            $valor_total = ($custo !== null && $custo !== '') ? $saldo * (float)$custo : null;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($r['nome_oficial']) ?></td>
                            <td><?= htmlspecialchars($r['unidade_nome'] ?? '') ?></td>
                            <td class="right"><?= number_format($saldo, 4, ',', '.') ?></td>
                            <td class="right">
                                <?php if ($custo === null || $custo === ''): ?>
                                    <span class="badge-missing">Sem custo</span>
                                <?php else: ?>
                                    <?= format_currency($custo) ?>
                                <?php endif; ?>
                            </td>
                            <td class="right"><?= $valor_total === null ? '-' : format_currency($valor_total) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div style="margin-top:1rem;display:flex;justify-content:space-between;align-items:center;">
            <div><?= $missing > 0 ? "Itens sem custo: {$missing}" : '' ?></div>
            <div><strong>Total geral:</strong> <?= format_currency($total) ?></div>
        </div>
    </div>
</div>

<?php endSidebar(); ?>
