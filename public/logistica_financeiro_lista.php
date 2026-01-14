<?php
// logistica_financeiro_lista.php — Custo da lista
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

$lista_id = (int)($_GET['id'] ?? 0);
if ($lista_id <= 0) {
    echo '<div class="alert-error">Lista inválida.</div>';
    exit;
}

$stmt = $pdo->prepare("
    SELECT l.*, u.nome AS unidade_nome
    FROM logistica_listas l
    LEFT JOIN logistica_unidades u ON u.id = l.unidade_interna_id
    WHERE l.id = :id
");
$stmt->execute([':id' => $lista_id]);
$lista = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$lista) {
    echo '<div class="alert-error">Lista não encontrada.</div>';
    exit;
}

$stmt = $pdo->prepare("
    SELECT li.*, i.nome_oficial, i.custo_padrao, u.nome AS unidade_nome
    FROM logistica_lista_itens li
    LEFT JOIN logistica_insumos i ON i.id = li.insumo_id
    LEFT JOIN logistica_unidades_medida u ON u.id = li.unidade_medida_id
    WHERE li.lista_id = :id
    ORDER BY i.nome_oficial
");
$stmt->execute([':id' => $lista_id]);
$itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = 0.0;
$missing = 0;
foreach ($itens as &$it) {
    if ($it['custo_padrao'] === null || $it['custo_padrao'] === '') {
        $it['custo_total'] = null;
        $missing++;
    } else {
        $it['custo_total'] = (float)$it['quantidade_total_bruto'] * (float)$it['custo_padrao'];
        $total += (float)$it['custo_total'];
    }
}

$stmt = $pdo->prepare("
    SELECT e.id, e.nome_evento, e.data_evento, e.hora_inicio, e.space_visivel,
           SUM(li.quantidade_total_bruto * COALESCE(i.custo_padrao, 0)) AS custo_total
    FROM logistica_lista_evento_itens li
    JOIN logistica_eventos_espelho e ON e.id = li.evento_id
    LEFT JOIN logistica_insumos i ON i.id = li.insumo_id
    WHERE li.lista_id = :id
    GROUP BY e.id, e.nome_evento, e.data_evento, e.hora_inicio, e.space_visivel
    ORDER BY e.data_evento ASC, e.hora_inicio ASC NULLS LAST
");
$stmt->execute([':id' => $lista_id]);
$eventos_totais = $stmt->fetchAll(PDO::FETCH_ASSOC);

includeSidebar('Financeiro - Custo da Lista');
?>

<style>
.page-container { max-width: 1400px; margin: 0 auto; padding: 1.5rem; }
.section-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:1.5rem; margin-bottom:1.5rem; box-shadow:0 2px 8px rgba(0,0,0,0.08); }
.table { width:100%; border-collapse:collapse; }
.table th,.table td { padding:.6rem; border-bottom:1px solid #e5e7eb; }
.right { text-align:right; }
.badge-missing { background:#fef3c7; color:#92400e; padding:.2rem .5rem; border-radius:6px; font-size:.85rem; }
.btn-secondary { background:#e2e8f0; color:#0f172a; border:none; padding:.5rem .9rem; border-radius:8px; cursor:pointer; }
.btn-primary { background:#2563eb; color:#fff; border:none; padding:.5rem .9rem; border-radius:8px; cursor:pointer; }
</style>

<div class="page-container">
    <h1>Custo da Lista #<?= (int)$lista_id ?></h1>

    <div class="section-card">
        <p>
            Unidade: <?= h($lista['unidade_nome'] ?? '') ?>
            <?= $lista['space_visivel'] ? '· ' . h($lista['space_visivel']) : '' ?>
            · Gerada em <?= h(date('d/m/Y H:i', strtotime($lista['criado_em']))) ?>
        </p>
        <div style="display:flex;gap:1rem;flex-wrap:wrap;">
            <a class="btn-secondary" href="index.php?page=logistica_lista_pdf&lista_id=<?= (int)$lista_id ?>&show_values=1" target="_blank">PDF com valores</a>
            <button class="btn-secondary" type="button" id="toggle-eventos">Mostrar por evento</button>
        </div>
    </div>

    <div class="section-card">
        <table class="table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Unidade</th>
                    <th class="right">Quantidade (bruta)</th>
                    <th class="right">Custo padrão</th>
                    <th class="right">Custo total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$itens): ?>
                    <tr><td colspan="5">Nenhum item encontrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($itens as $it): ?>
                        <tr>
                            <td><?= h($it['nome_oficial'] ?? '') ?></td>
                            <td><?= h($it['unidade_nome'] ?? '') ?></td>
                            <td class="right"><?= number_format((float)$it['quantidade_total_bruto'], 4, ',', '.') ?></td>
                            <td class="right">
                                <?php if ($it['custo_padrao'] === null || $it['custo_padrao'] === ''): ?>
                                    <span class="badge-missing">Sem custo</span>
                                <?php else: ?>
                                    <?= format_currency($it['custo_padrao']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="right"><?= $it['custo_total'] === null ? '-' : format_currency($it['custo_total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <div style="margin-top:1rem;display:flex;justify-content:space-between;align-items:center;">
            <div><?= $missing > 0 ? "Itens sem custo: {$missing}" : '' ?></div>
            <div><strong>Total da lista:</strong> <?= format_currency($total) ?></div>
        </div>
    </div>

    <div class="section-card" id="eventos-total" style="display:none;">
        <h3>Totais por evento</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Evento</th>
                    <th>Data/Hora</th>
                    <th>Unidade</th>
                    <th class="right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$eventos_totais): ?>
                    <tr><td colspan="4">Sem detalhamento por evento.</td></tr>
                <?php else: ?>
                    <?php foreach ($eventos_totais as $ev): ?>
                        <tr>
                            <td><?= h($ev['nome_evento'] ?? 'Evento') ?></td>
                            <td><?= format_date($ev['data_evento'] ?? null, 'd/m/Y') ?> <?= h($ev['hora_inicio'] ?? '') ?></td>
                            <td><?= h($ev['space_visivel'] ?? '') ?></td>
                            <td class="right"><?= format_currency($ev['custo_total'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.getElementById('toggle-eventos').addEventListener('click', () => {
    const box = document.getElementById('eventos-total');
    const visible = box.style.display !== 'none';
    box.style.display = visible ? 'none' : 'block';
});
</script>

<?php endSidebar(); ?>
