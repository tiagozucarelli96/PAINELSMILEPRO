<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/comercial_pagamento_solicitacao_helper.php';

if (!lc_can_access_comercial()) {
    header('Location: index.php?page=dashboard&error=permission_denied');
    exit;
}
if (!$pdo instanceof PDO) {
    http_response_code(503);
    exit('Banco de dados indisponível.');
}

comercial_pagamento_solicitacao_ensure_schema($pdo);

$statusFiltro = trim((string)($_GET['status'] ?? 'todos'));
$busca = trim((string)($_GET['q'] ?? ''));
$allowedStatus = ['todos', 'solicitado', 'pendente', 'pago', 'vencido', 'cancelado'];
if (!in_array($statusFiltro, $allowedStatus, true)) {
    $statusFiltro = 'todos';
}

$where = [];
$params = [];
if ($statusFiltro !== 'todos') {
    $where[] = "COALESCE(status_calc, s.status) = :status";
    $params[':status'] = $statusFiltro;
}
if ($busca !== '') {
    $where[] = "(s.descricao ILIKE :q OR s.pagador_nome ILIKE :q OR COALESCE(e.nome_evento, s.evento_nome, '') ILIKE :q OR s.pixgo_payment_id ILIKE :q)";
    $params[':q'] = '%' . $busca . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
    WITH base AS (
        SELECT s.*,
               CASE
                   WHEN s.status = 'pendente'
                    AND s.pixgo_expires_at IS NOT NULL
                    AND s.pixgo_expires_at < NOW()
                   THEN 'vencido'
                   ELSE s.status
               END AS status_calc
        FROM comercial_pagamento_solicitacoes s
    )
    SELECT s.*,
           e.data_evento::text AS evento_data,
           COALESCE(e.nome_evento, s.evento_nome, '') AS evento_nome_atual,
           COALESCE(t.valor_cobrado, s.valor_original) AS ultimo_valor_cobrado,
           t.created_at::text AS ultimo_pix_criado_em,
           t.expires_at::text AS ultimo_pix_expira_em,
           COALESCE(t.payment_id, s.pixgo_payment_id, '') AS ultimo_payment_id,
           COALESCE(t.status, s.status_calc) AS ultimo_status_pix
    FROM base s
    LEFT JOIN logistica_eventos_espelho e ON e.id = s.evento_id
    LEFT JOIN LATERAL (
        SELECT *
        FROM comercial_pagamento_pixgo_tentativas tt
        WHERE tt.solicitacao_id = s.id
        ORDER BY tt.created_at DESC
        LIMIT 1
    ) t ON TRUE
    {$whereSql}
    ORDER BY s.created_at DESC
    LIMIT 300
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$solicitacoes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stats = [
    'todos' => 0,
    'solicitado' => 0,
    'pendente' => 0,
    'pago' => 0,
    'vencido' => 0,
];
try {
    $rows = $pdo->query("
        SELECT
            CASE
                WHEN status = 'pendente' AND pixgo_expires_at IS NOT NULL AND pixgo_expires_at < NOW() THEN 'vencido'
                ELSE status
            END AS status_calc,
            COUNT(*) AS total
        FROM comercial_pagamento_solicitacoes
        GROUP BY 1
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $status = (string)$row['status_calc'];
        $count = (int)$row['total'];
        $stats['todos'] += $count;
        if (array_key_exists($status, $stats)) {
            $stats[$status] += $count;
        }
    }
} catch (Throwable $e) {
}

includeSidebar('Comercial');
?>

<style>
.pix-page { max-width: 1320px; margin: 0 auto; padding: 2rem; }
.pix-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1.5rem; }
.pix-header h1 { margin: 0 0 .4rem; color: #1e3a8a; font-size: 2rem; }
.pix-header p { margin: 0; color: #64748b; }
.pix-actions { display: flex; gap: .75rem; flex-wrap: wrap; }
.pix-btn { border: 0; border-radius: 8px; padding: .75rem 1rem; font-weight: 800; cursor: pointer; background: #2563eb; color: #fff; text-decoration: none; display: inline-flex; align-items: center; }
.pix-btn.secondary { background: #e2e8f0; color: #334155; }
.pix-filters { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1rem; margin-bottom: 1rem; display: flex; gap: .75rem; flex-wrap: wrap; align-items: center; }
.pix-filters input, .pix-filters select { border: 1px solid #cbd5e1; border-radius: 8px; padding: .72rem .8rem; font: inherit; }
.pix-filters input { min-width: min(100%, 320px); }
.pix-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: .9rem; margin-bottom: 1rem; }
.pix-stat { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1rem; }
.pix-stat strong { display: block; font-size: 1.5rem; color: #0f172a; }
.pix-stat span { color: #64748b; font-size: .85rem; }
.pix-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(15, 23, 42, .08); }
.pix-table-wrap { overflow-x: auto; }
.pix-table { width: 100%; border-collapse: collapse; min-width: 980px; }
.pix-table th, .pix-table td { padding: .9rem .8rem; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
.pix-table th { color: #475569; font-size: .78rem; text-transform: uppercase; background: #f8fafc; }
.pix-main { font-weight: 800; color: #0f172a; }
.pix-sub { color: #64748b; font-size: .82rem; margin-top: .2rem; }
.pix-status { display: inline-block; border-radius: 999px; padding: .3rem .65rem; font-size: .78rem; font-weight: 800; background: #eff6ff; color: #1d4ed8; white-space: nowrap; }
.pix-status.pago { background: #ecfdf5; color: #047857; }
.pix-status.vencido, .pix-status.cancelado { background: #fef2f2; color: #b91c1c; }
.pix-status.solicitado { background: #f8fafc; color: #475569; }
.pix-row-actions { display: flex; gap: .5rem; flex-wrap: wrap; }
.pix-small { border: 1px solid #cbd5e1; border-radius: 7px; padding: .45rem .6rem; color: #334155; text-decoration: none; background: #fff; font-weight: 700; cursor: pointer; }
.pix-empty { padding: 2rem; color: #64748b; text-align: center; }
@media (max-width: 760px) { .pix-page { padding: 1rem; } .pix-header { flex-direction: column; } }
</style>

<div class="pix-page">
    <div class="pix-header">
        <div>
            <h1>Gerenciar Pix</h1>
            <p>Acompanhe links solicitados, Pix gerados e pagamentos confirmados.</p>
        </div>
        <div class="pix-actions">
            <a class="pix-btn secondary" href="index.php?page=comercial">Voltar</a>
            <a class="pix-btn" href="index.php?page=comercial_solicitar_pagamento">Nova solicitação</a>
        </div>
    </div>

    <div class="pix-stats">
        <div class="pix-stat"><strong><?= (int)$stats['todos'] ?></strong><span>Total</span></div>
        <div class="pix-stat"><strong><?= (int)$stats['solicitado'] ?></strong><span>Sem Pix gerado</span></div>
        <div class="pix-stat"><strong><?= (int)$stats['pendente'] ?></strong><span>Aguardando pagamento</span></div>
        <div class="pix-stat"><strong><?= (int)$stats['pago'] ?></strong><span>Pagos</span></div>
        <div class="pix-stat"><strong><?= (int)$stats['vencido'] ?></strong><span>Expirados</span></div>
    </div>

    <form class="pix-filters" method="get">
        <input type="hidden" name="page" value="comercial_gerenciar_pix">
        <input name="q" value="<?= h($busca) ?>" placeholder="Buscar por cliente, evento, descrição ou payment_id">
        <select name="status">
            <option value="todos" <?= $statusFiltro === 'todos' ? 'selected' : '' ?>>Todos</option>
            <option value="solicitado" <?= $statusFiltro === 'solicitado' ? 'selected' : '' ?>>Sem Pix gerado</option>
            <option value="pendente" <?= $statusFiltro === 'pendente' ? 'selected' : '' ?>>Aguardando pagamento</option>
            <option value="pago" <?= $statusFiltro === 'pago' ? 'selected' : '' ?>>Pagos</option>
            <option value="vencido" <?= $statusFiltro === 'vencido' ? 'selected' : '' ?>>Expirados</option>
            <option value="cancelado" <?= $statusFiltro === 'cancelado' ? 'selected' : '' ?>>Cancelados</option>
        </select>
        <button class="pix-btn" type="submit">Filtrar</button>
        <a class="pix-btn secondary" href="index.php?page=comercial_gerenciar_pix">Limpar</a>
    </form>

    <section class="pix-card">
        <div class="pix-table-wrap">
            <table class="pix-table">
                <thead>
                    <tr>
                        <th>Solicitação</th>
                        <th>Cliente</th>
                        <th>Valor</th>
                        <th>Vencimento</th>
                        <th>Último Pix</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($solicitacoes as $row): ?>
                        <?php
                            $status = (string)($row['status_calc'] ?? $row['status'] ?? 'solicitado');
                            $publicUrl = comercial_pagamento_public_url((string)$row['token']);
                        ?>
                        <tr>
                            <td>
                                <div class="pix-main"><?= h((string)$row['descricao']) ?></div>
                                <div class="pix-sub">Criado em <?= h(brDate((string)$row['created_at'])) ?></div>
                                <?php if (!empty($row['evento_nome_atual'])): ?>
                                    <div class="pix-sub">Evento: <?= h((string)$row['evento_nome_atual']) ?><?= !empty($row['evento_data']) ? ' · ' . h(brDateOnly((string)$row['evento_data'])) : '' ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="pix-main"><?= h((string)$row['pagador_nome']) ?></div>
                                <div class="pix-sub"><?= h((string)$row['pagador_documento']) ?></div>
                                <?php if (!empty($row['pagador_email'])): ?><div class="pix-sub"><?= h((string)$row['pagador_email']) ?></div><?php endif; ?>
                            </td>
                            <td>
                                <div class="pix-main"><?= h(format_currency($row['ultimo_valor_cobrado'])) ?></div>
                                <div class="pix-sub">Original: <?= h(format_currency($row['valor_original'])) ?></div>
                            </td>
                            <td>
                                <div class="pix-main"><?= h(brDateOnly((string)$row['vencimento'])) ?></div>
                                <?php $calculo = comercial_pagamento_calcular_total((float)$row['valor_original'], (string)$row['vencimento']); ?>
                                <?php if ((int)$calculo['dias_atraso'] > 0): ?><div class="pix-sub"><?= (int)$calculo['dias_atraso'] ?> dias em atraso</div><?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['ultimo_payment_id'])): ?>
                                    <div class="pix-main"><?= h((string)$row['ultimo_payment_id']) ?></div>
                                    <div class="pix-sub">Criado: <?= h(brDate((string)$row['ultimo_pix_criado_em'])) ?></div>
                                    <?php if (!empty($row['ultimo_pix_expira_em'])): ?><div class="pix-sub">Expira: <?= h(brDate((string)$row['ultimo_pix_expira_em'])) ?></div><?php endif; ?>
                                <?php else: ?>
                                    <span class="pix-sub">Ainda não gerado</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="pix-status <?= h($status) ?>"><?= h(comercial_pagamento_status_label($status)) ?></span></td>
                            <td>
                                <div class="pix-row-actions">
                                    <a class="pix-small" href="<?= h($publicUrl) ?>" target="_blank" rel="noopener">Abrir link</a>
                                    <button class="pix-small" type="button" data-copy="<?= h($publicUrl) ?>">Copiar</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$solicitacoes): ?>
                        <tr><td colspan="7"><div class="pix-empty">Nenhuma solicitação encontrada.</div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script>
document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(button.getAttribute('data-copy') || '');
        } catch (_) {
            const field = document.createElement('textarea');
            field.value = button.getAttribute('data-copy') || '';
            document.body.appendChild(field);
            field.select();
            document.execCommand('copy');
            field.remove();
        }
        button.textContent = 'Copiado';
    });
});
</script>

<?php endSidebar(); ?>
