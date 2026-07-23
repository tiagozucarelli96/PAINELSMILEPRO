<?php
/**
 * Dashboard de andamento do checklist de planejamento por evento.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/eventos_checklist_planejamento_helper.php';

if (empty($_SESSION['perm_eventos']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

eventos_checklist_planejamento_ensure_schema($pdo);

function ecd_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$scope = (string)($_GET['scope'] ?? 'proximos');
$whereDate = $scope === 'anteriores' ? 'e.data_evento < CURRENT_DATE' : 'e.data_evento >= CURRENT_DATE';
$stmt = $pdo->query("
    SELECT e.id AS evento_id,
           COALESCE(NULLIF(TRIM(e.nome_evento), ''), 'Evento') AS nome_evento,
           e.data_evento::text AS data_evento,
           COALESCE(NULLIF(TRIM(e.space_visivel), ''), NULLIF(TRIM(e.localevento), ''), 'Unidade') AS unidade,
           COUNT(t.id)::int AS total,
           COUNT(t.id) FILTER (WHERE t.status = 'concluida')::int AS concluidas,
           COUNT(t.id) FILTER (WHERE t.status = 'desativada')::int AS desativadas,
           COUNT(t.id) FILTER (WHERE t.status = 'em_andamento')::int AS em_andamento,
           COUNT(t.id) FILTER (WHERE t.status = 'pendente')::int AS pendentes,
           COUNT(t.id) FILTER (WHERE t.status = 'aguardando_validacao')::int AS aguardando_validacao,
           COUNT(t.id) FILTER (
               WHERE t.status IN ('pendente', 'em_andamento', 'aguardando_validacao')
                 AND t.vencimento < CURRENT_DATE
                 AND t.responsabilidade <> 'cliente'
           )::int AS atrasadas_empresa,
           COUNT(t.id) FILTER (
               WHERE t.status IN ('pendente', 'em_andamento', 'aguardando_validacao')
                 AND t.vencimento < CURRENT_DATE
                 AND t.responsabilidade = 'cliente'
           )::int AS atrasadas_cliente
    FROM logistica_eventos_espelho e
    JOIN eventos_checklist_tarefas t ON t.evento_id = e.id
    WHERE {$whereDate}
      AND COALESCE(e.arquivado, FALSE) = FALSE
    GROUP BY e.id, e.nome_evento, e.data_evento, e.space_visivel, e.localevento
    ORDER BY e.data_evento " . ($scope === 'anteriores' ? 'DESC' : 'ASC') . ", e.id
    LIMIT 300
");
$eventos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totais = [
    'em_andamento' => 0, 'concluidas' => 0, 'desativadas' => 0, 'pendentes' => 0,
    'atrasadas_empresa' => 0, 'atrasadas_cliente' => 0,
];
foreach ($eventos as $evento) {
    foreach ($totais as $key => $_) {
        $totais[$key] += (int)($evento[$key] ?? 0);
    }
}

includeSidebar('Dashboard de Checklists');
?>

<style>
.ecd-page{max-width:1460px;margin:0 auto;padding:1.5rem;background:#f8fafc}
.ecd-head{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start}.ecd-head h1{margin:0;color:#1e3a8a;font-size:1.9rem}.ecd-head p{margin:.35rem 0;color:#64748b}
.ecd-btn{display:inline-flex;padding:.62rem .9rem;border-radius:9px;background:#e2e8f0;color:#334155;text-decoration:none;font-weight:750}.ecd-btn.active{background:#2563eb;color:#fff}
.ecd-filters{display:flex;gap:.55rem;margin:1rem 0}
.ecd-stats{display:grid;grid-template-columns:repeat(6,minmax(130px,1fr));gap:.8rem;margin:1rem 0}
.ecd-stat{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1rem}.ecd-stat strong{display:block;color:#1e3a8a;font-size:1.55rem}.ecd-stat span{font-size:.8rem;color:#64748b}
.ecd-list{display:grid;gap:.8rem}.ecd-event{display:block;background:#fff;border:1px solid #dbe3ef;border-radius:13px;padding:1rem;text-decoration:none;color:#334155;box-shadow:0 6px 18px rgba(15,23,42,.04)}
.ecd-event:hover{border-color:#2563eb;transform:translateY(-1px)}.ecd-event-head{display:flex;justify-content:space-between;gap:1rem}.ecd-event h3{margin:0;color:#0f172a}.ecd-meta{font-size:.84rem;color:#64748b;margin-top:.25rem}
.ecd-bars{height:18px;background:#e2e8f0;border-radius:999px;display:flex;overflow:hidden;margin:.8rem 0}.ecd-bars span{height:100%}.ecd-done{background:#22c55e}.ecd-progressing{background:#0ea5e9}.ecd-pending{background:#fb7185}.ecd-disabled{background:#f59e0b}.ecd-waiting{background:#8b5cf6}
.ecd-counts{display:flex;gap:.45rem;flex-wrap:wrap}.ecd-badge{padding:.24rem .52rem;border-radius:999px;background:#f1f5f9;font-size:.76rem;font-weight:700}
.ecd-empty{text-align:center;padding:2rem;background:#fff;border:1px dashed #cbd5e1;border-radius:13px;color:#64748b}
@media(max-width:1000px){.ecd-stats{grid-template-columns:repeat(3,1fr)}}@media(max-width:650px){.ecd-stats{grid-template-columns:repeat(2,1fr)}.ecd-event-head{flex-direction:column}}
</style>

<div class="ecd-page">
    <div class="ecd-head">
        <div><h1>✅ Dashboard de Checklists</h1><p>Acompanhamento do planejamento por evento. Clique em um evento para abrir suas tarefas.</p></div>
        <a class="ecd-btn" href="index.php?page=eventos">← Eventos</a>
    </div>
    <div class="ecd-filters">
        <a class="ecd-btn <?= $scope !== 'anteriores' ? 'active' : '' ?>" href="index.php?page=eventos_checklist_dashboard&scope=proximos">Próximos eventos</a>
        <a class="ecd-btn <?= $scope === 'anteriores' ? 'active' : '' ?>" href="index.php?page=eventos_checklist_dashboard&scope=anteriores">Eventos anteriores</a>
    </div>
    <div class="ecd-stats">
        <div class="ecd-stat"><strong><?= $totais['em_andamento'] ?></strong><span>Em andamento</span></div>
        <div class="ecd-stat"><strong><?= $totais['concluidas'] ?></strong><span>Concluídas</span></div>
        <div class="ecd-stat"><strong><?= $totais['desativadas'] ?></strong><span>Não terá/canceladas</span></div>
        <div class="ecd-stat"><strong><?= $totais['pendentes'] ?></strong><span>Pendentes</span></div>
        <div class="ecd-stat"><strong><?= $totais['atrasadas_empresa'] ?></strong><span>Atrasadas — empresa</span></div>
        <div class="ecd-stat"><strong><?= $totais['atrasadas_cliente'] ?></strong><span>Atrasadas — cliente</span></div>
    </div>
    <div class="ecd-list">
        <?php if (!$eventos): ?><div class="ecd-empty">Nenhum evento com checklist neste período.</div><?php endif; ?>
        <?php foreach ($eventos as $evento): ?>
            <?php
            $base = max(1, (int)$evento['total']);
            $width = static fn(string $key): float => ((int)$evento[$key] / $base) * 100;
            ?>
            <a class="ecd-event" href="index.php?page=eventos_checklist_planejamento&evento_id=<?= (int)$evento['evento_id'] ?>">
                <div class="ecd-event-head">
                    <div><h3><?= ecd_h($evento['nome_evento']) ?></h3><div class="ecd-meta"><?= ecd_h(date('d/m/Y', strtotime((string)$evento['data_evento']))) ?> • <?= ecd_h($evento['unidade']) ?></div></div>
                    <strong><?= (int)$evento['concluidas'] ?>/<?= max(0, (int)$evento['total'] - (int)$evento['desativadas']) ?> concluídas</strong>
                </div>
                <div class="ecd-bars">
                    <span class="ecd-done" style="width:<?= $width('concluidas') ?>%"></span>
                    <span class="ecd-progressing" style="width:<?= $width('em_andamento') ?>%"></span>
                    <span class="ecd-waiting" style="width:<?= $width('aguardando_validacao') ?>%"></span>
                    <span class="ecd-pending" style="width:<?= $width('pendentes') ?>%"></span>
                    <span class="ecd-disabled" style="width:<?= $width('desativadas') ?>%"></span>
                </div>
                <div class="ecd-counts">
                    <span class="ecd-badge">Concluídas <?= (int)$evento['concluidas'] ?></span>
                    <span class="ecd-badge">Em andamento <?= (int)$evento['em_andamento'] ?></span>
                    <span class="ecd-badge">Pendentes <?= (int)$evento['pendentes'] ?></span>
                    <span class="ecd-badge">Atrasadas empresa <?= (int)$evento['atrasadas_empresa'] ?></span>
                    <span class="ecd-badge">Atrasadas cliente <?= (int)$evento['atrasadas_cliente'] ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?php endSidebar(); ?>
