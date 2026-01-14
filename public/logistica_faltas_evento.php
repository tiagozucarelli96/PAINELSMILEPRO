<?php
require_once __DIR__ . '/logistica_tz.php';
// logistica_faltas_evento.php — Detalhe de faltas por evento
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/logistica_alertas_helper.php';

$can_manage = !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_logistico']);
if (!$can_manage) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

$event_id = (int)($_GET['event_id'] ?? 0);
if ($event_id <= 0) {
    echo '<div class="alert-error">Evento inválido.</div>';
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM logistica_eventos_espelho WHERE id = :id");
$stmt->execute([':id' => $event_id]);
$evento = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$evento) {
    echo '<div class="alert-error">Evento não encontrado.</div>';
    exit;
}

$resolve = logistica_resolve_lista_pronta($pdo, $event_id);
$lista_id = ($resolve['status'] === 'ok') ? (int)$resolve['lista_id'] : 0;
$faltas = [];
$total_itens = 0;

if ($lista_id > 0) {
    $calc = logistica_fetch_faltas_evento($pdo, $event_id, $lista_id, (int)$evento['unidade_interna_id']);
    $faltas = $calc['faltas'] ?? [];
    $total_itens = (int)($calc['total_itens'] ?? 0);
}

includeSidebar('Faltas do Evento - Logística');
?>

<style>
.page-container {
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
    margin-bottom: 1.5rem;
}
.table { width:100%; border-collapse:collapse; }
.table th,.table td { padding:.6rem; border-bottom:1px solid #e5e7eb; }
.btn { padding:.4rem .8rem; border-radius:8px; border:none; cursor:pointer; }
.btn-secondary { background:#e2e8f0; color:#0f172a; }
</style>

<div class="page-container">
    <h1>Faltas do Evento</h1>

    <div class="card">
        <h2><?= htmlspecialchars($evento['nome_evento'] ?? 'Evento') ?></h2>
        <p>
            Data: <?= format_date($evento['data_evento'] ?? null, 'd/m/Y') ?>
            · Local: <?= htmlspecialchars($evento['localevento'] ?? '') ?>
            · Unidade: <?= htmlspecialchars($evento['space_visivel'] ?? '') ?>
        </p>
        <p>Status da lista: <?= htmlspecialchars($resolve['status'] ?? '-') ?></p>
    </div>

    <div class="card">
        <h3>Itens faltantes (<?= count($faltas) ?> de <?= $total_itens ?>)</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Insumo</th>
                    <th>Unidade</th>
                    <th>Planejado</th>
                    <th>Disponível</th>
                    <th>Faltando</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$faltas): ?>
                <tr><td colspan="5">Nenhuma falta encontrada.</td></tr>
            <?php else: ?>
                <?php foreach ($faltas as $f): ?>
                    <tr>
                        <td><?= htmlspecialchars($f['nome_oficial'] ?? '') ?></td>
                        <td><?= htmlspecialchars($f['unidade_nome'] ?? '') ?></td>
                        <td><?= number_format((float)$f['quantidade_total_bruto'], 4, ',', '.') ?></td>
                        <td><?= number_format((float)$f['saldo_atual'], 4, ',', '.') ?></td>
                        <td><?= number_format((float)$f['faltando'], 4, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <div style="margin-top:1rem;">
            <a class="btn btn-secondary" href="index.php?page=logistica_divergencias">Voltar</a>
        </div>
    </div>
</div>

<?php endSidebar(); ?>
