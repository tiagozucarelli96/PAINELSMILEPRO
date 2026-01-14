<?php
// logistica_divergencias.php — Divergências e auditoria
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/logistica_alertas_helper.php';
require_once __DIR__ . '/logistica_baixa_helper.php';
define('LOGISTICA_CRON_INTERNAL', true);
require_once __DIR__ . '/logistica_cron_runner.php';

$is_superadmin = !empty($_SESSION['perm_superadmin']);
$can_view = $is_superadmin || !empty($_SESSION['perm_logistico_divergencias']);
if (!$can_view) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

$messages = [];
$cron_summary = null;
$scope = $_SESSION['unidade_scope'] ?? 'todas';
$unit_id = (int)($_SESSION['unidade_id'] ?? 0);
$filter_unit = !$is_superadmin && ($scope === 'unidade' && $unit_id > 0);
$block_scope = !$is_superadmin && ($scope === 'nenhuma');

$eventos_alertas = $block_scope ? ['eventos_total' => 0, 'conflitos' => [], 'sem_lista' => [], 'sem_detalhe' => [], 'faltas' => []]
    : logistica_compute_alertas_eventos($pdo, 3, $filter_unit, $unit_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rodar_rotina' && $is_superadmin) {
    $result = logistica_run_daily($pdo, ['filter_unit' => false]);
    if (!empty($result['ok'])) {
        $cron_summary = $result['summary'];
        $messages[] = 'Rotina executada com sucesso.';
    } else {
        $messages[] = 'Erro ao executar rotina: ' . ($result['error'] ?? 'desconhecido');
    }
}

function parse_delta_from_obs(?string $obs, float $fallback): float {
    if (!$obs) return $fallback;
    if (preg_match('/\\(([+-])\\s*([0-9.,]+)\\)/', $obs, $m)) {
        $value = (float)str_replace(',', '.', $m[2]);
        return $m[1] === '-' ? -abs($value) : abs($value);
    }
    return $fallback;
}

// Ajustes de contagem (últimos 30 dias)
if ($block_scope) {
    $ajustes = [];
} else {
    $params = [];
    $where = "WHERE m.tipo = 'ajuste_contagem' AND m.criado_em >= (NOW() - INTERVAL '30 days')";
    if ($filter_unit) {
        $where .= " AND (m.unidade_id_destino = :uid OR m.unidade_id_origem = :uid OR c.unidade_id = :uid)";
        $params[':uid'] = $unit_id;
    }
    $stmt = $pdo->prepare("
        SELECT m.*, i.nome_oficial, u.nome AS unidade_nome,
               c.finalizada_em, c.criado_por,
               ci.quantidade_contada
        FROM logistica_estoque_movimentos m
        LEFT JOIN logistica_insumos i ON i.id = m.insumo_id
        LEFT JOIN logistica_estoque_contagens c ON c.id = m.referencia_id
        LEFT JOIN logistica_estoque_contagens_itens ci
            ON ci.contagem_id = c.id AND ci.insumo_id = m.insumo_id
        LEFT JOIN logistica_unidades u
            ON u.id = COALESCE(m.unidade_id_destino, m.unidade_id_origem, c.unidade_id)
        $where
        ORDER BY m.criado_em DESC
    ");
    $stmt->execute($params);
    $ajustes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Mapear usuários
$usuarios = [];
if ($ajustes) {
    $ids = array_unique(array_filter(array_map(fn($a) => (int)($a['criado_por'] ?? 0), $ajustes)));
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, nome FROM usuarios WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $usuarios[(int)$u['id']] = $u['nome'];
        }
    }
}

// Contagem atrasada por unidade
if ($block_scope) {
    $unidades = [];
} else {
    $unidades = $pdo->query("
        SELECT u.id, u.nome, MAX(c.finalizada_em) AS ultima
        FROM logistica_unidades u
        LEFT JOIN logistica_estoque_contagens c ON c.unidade_id = u.id AND c.status = 'finalizada'
        WHERE u.ativo IS TRUE
        GROUP BY u.id, u.nome
        ORDER BY u.nome
    ")->fetchAll(PDO::FETCH_ASSOC);
    if ($filter_unit) {
        $unidades = array_values(array_filter($unidades, fn($u) => (int)$u['id'] === $unit_id));
    }
}

// Transferências pendentes
if ($block_scope) {
    $transferencias = [];
} else {
    $params = [];
    $where = "WHERE t.status IN ('rascunho','em_transito')";
    if ($filter_unit) {
        $where .= " AND (t.unidade_destino_id = :uid OR (t.space_destino = 'Cristal' AND t.unidade_destino_id = :uid))";
        $params[':uid'] = $unit_id;
    }
    $stmt = $pdo->prepare("
        SELECT t.*, uo.nome AS origem_nome, ud.nome AS destino_nome
        FROM logistica_transferencias t
        LEFT JOIN logistica_unidades uo ON uo.id = t.unidade_origem_id
        LEFT JOIN logistica_unidades ud ON ud.id = t.unidade_destino_id
        $where
        ORDER BY t.criado_em DESC
    ");
    $stmt->execute($params);
    $transferencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Logs operacionais
if ($block_scope) {
    $logs = [];
} else {
    $params = [];
    $where = "WHERE tipo = 'saldo_negativo_bloqueado'";
    if ($filter_unit) {
        $where .= " AND unidade_id = :uid";
        $params[':uid'] = $unit_id;
    }
    $stmt = $pdo->prepare("
        SELECT l.*, i.nome_oficial, u.nome AS unidade_nome
        FROM logistica_alertas_log l
        LEFT JOIN logistica_insumos i ON i.id = l.insumo_id
        LEFT JOIN logistica_unidades u ON u.id = l.unidade_id
        $where
        ORDER BY l.criado_em DESC
        LIMIT 100
    ");
    try {
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $logs = [];
    }
}

includeSidebar('Divergências - Logística');
?>

<style>
.divergencias-container {
    max-width: 1400px;
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
.badge { padding:.2rem .6rem; border-radius:999px; font-size:.8rem; font-weight:600; }
.badge-danger { background:#fee2e2; color:#991b1b; }
.badge-warning { background:#ffedd5; color:#9a3412; }
.badge-success { background:#dcfce7; color:#166534; }
.btn { padding:.4rem .8rem; border-radius:8px; border:none; cursor:pointer; }
.btn-secondary { background:#e2e8f0; color:#0f172a; }
.text-danger { color:#dc2626; font-weight:600; }
.alert-success { background:#dcfce7; color:#166534; padding:.6rem .9rem; border-radius:8px; margin-bottom:1rem; }
.alert-error { background:#fee2e2; color:#991b1b; padding:.6rem .9rem; border-radius:8px; margin-bottom:1rem; }
</style>

<div class="divergencias-container">
    <h1>🧭 Divergências</h1>

    <?php foreach ($messages as $m): ?>
        <div class="alert-success"><?= htmlspecialchars($m) ?></div>
    <?php endforeach; ?>

    <?php if ($is_superadmin): ?>
    <div class="card">
        <h3>Rotina diária (manual)</h3>
        <form method="post">
            <input type="hidden" name="action" value="rodar_rotina">
            <button class="btn btn-secondary" type="submit">Rodar rotina agora</button>
        </form>
        <?php if ($cron_summary): ?>
            <div style="margin-top:1rem;">
                <strong>Resumo:</strong>
                <pre style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:.75rem;margin-top:.5rem;white-space:pre-wrap;"><?= htmlspecialchars(json_encode($cron_summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <h3>Ajustes de contagem (últimos 30 dias)</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Data/Hora</th>
                    <th>Unidade</th>
                    <th>Insumo</th>
                    <th>Antes</th>
                    <th>Contado</th>
                    <th>Diferença</th>
                    <th>Usuário</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$ajustes): ?>
                <tr><td colspan="7">Nenhum ajuste encontrado.</td></tr>
            <?php else: ?>
                <?php foreach ($ajustes as $a): ?>
                    <?php
                        $contado = (float)($a['quantidade_contada'] ?? 0);
                        $fallback = (float)($a['quantidade'] ?? 0);
                        $delta = parse_delta_from_obs($a['observacao'] ?? '', $fallback);
                        $antes = $contado - $delta;
                        $limiar = abs($delta) >= 2;
                    ?>
                    <tr class="<?= $limiar ? 'text-danger' : '' ?>">
                        <td><?= format_date($a['criado_em'] ?? null) ?></td>
                        <td><?= htmlspecialchars($a['unidade_nome'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($a['nome_oficial'] ?? '-') ?></td>
                        <td><?= number_format($antes, 3, ',', '.') ?></td>
                        <td><?= number_format($contado, 3, ',', '.') ?></td>
                        <td><?= number_format($delta, 3, ',', '.') ?></td>
                        <td><?= htmlspecialchars($usuarios[(int)($a['criado_por'] ?? 0)] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3>Contagem atrasada</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Unidade</th>
                    <th>Última contagem</th>
                    <th>Dias sem contar</th>
                    <th>Ação</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$unidades): ?>
                <tr><td colspan="4">Nenhuma unidade encontrada.</td></tr>
            <?php else: ?>
                <?php foreach ($unidades as $u): ?>
                    <?php
                        $ultima = $u['ultima'] ?? null;
                        $days = $ultima ? (int)floor((time() - strtotime($ultima)) / 86400) : 999;
                        $badge = $days > 7 ? 'badge-danger' : ($days >= 5 ? 'badge-warning' : 'badge-success');
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($u['nome']) ?></td>
                        <td><?= $ultima ? format_date($ultima) : '-' ?></td>
                        <td><span class="badge <?= $badge ?>"><?= $days ?></span></td>
                        <td><a class="btn btn-secondary" href="index.php?page=logistica_contagem">Iniciar contagem</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3>Transferências pendentes</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Origem/Destino</th>
                    <th>Criada em</th>
                    <th>Enviada em</th>
                    <th>Status</th>
                    <th>Ação</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$transferencias): ?>
                <tr><td colspan="6">Nenhuma transferência pendente.</td></tr>
            <?php else: ?>
                <?php foreach ($transferencias as $t): ?>
                    <?php $statusBadge = $t['status'] === 'em_transito' ? 'badge-danger' : 'badge-warning'; ?>
                    <tr>
                        <td><?= (int)$t['id'] ?></td>
                        <td><?= htmlspecialchars(($t['origem_nome'] ?? '-') . ' → ' . ($t['space_destino'] ?: ($t['destino_nome'] ?? '-'))) ?></td>
                        <td><?= format_date($t['criado_em'] ?? null) ?></td>
                        <td><?= format_date($t['enviado_em'] ?? null) ?></td>
                        <td><span class="badge <?= $statusBadge ?>"><?= htmlspecialchars($t['status']) ?></span></td>
                        <td>
                            <a class="btn btn-secondary" href="index.php?page=logistica_transferencia_ver&id=<?= (int)$t['id'] ?>">Ver</a>
                            <?php if ($t['status'] === 'em_transito'): ?>
                                <a class="btn btn-secondary" href="index.php?page=logistica_transferencia_receber&id=<?= (int)$t['id'] ?>">Receber</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3>Eventos sem lista pronta</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Evento</th>
                    <th>Unidade</th>
                    <th>Motivo</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($eventos_alertas['sem_lista']) && empty($eventos_alertas['sem_detalhe'])): ?>
                <tr><td colspan="4">Nenhum evento sem lista pronta.</td></tr>
            <?php else: ?>
                <?php foreach ($eventos_alertas['sem_lista'] as $ev): ?>
                    <tr>
                        <td><?= format_date($ev['data_evento'] ?? null, 'd/m/Y') ?></td>
                        <td><?= htmlspecialchars($ev['nome_evento'] ?? 'Evento') ?></td>
                        <td><?= htmlspecialchars($ev['space_visivel'] ?? '') ?></td>
                        <td>Evento sem lista pronta</td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($eventos_alertas['sem_detalhe'] as $ev): ?>
                    <tr>
                        <td><?= format_date($ev['data_evento'] ?? null, 'd/m/Y') ?></td>
                        <td><?= htmlspecialchars($ev['nome_evento'] ?? 'Evento') ?></td>
                        <td><?= htmlspecialchars($ev['space_visivel'] ?? '') ?></td>
                        <td>Lista antiga sem detalhamento — gere uma nova lista para esse evento ou mantenha sem baixa automática.</td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3>Conflito de listas prontas</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Evento</th>
                    <th>Unidade</th>
                    <th>Listas</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($eventos_alertas['conflitos'])): ?>
                <tr><td colspan="4">Nenhum conflito encontrado.</td></tr>
            <?php else: ?>
                <?php foreach ($eventos_alertas['conflitos'] as $ev): ?>
                    <tr>
                        <td><?= format_date($ev['data_evento'] ?? null, 'd/m/Y') ?></td>
                        <td><?= htmlspecialchars($ev['nome_evento'] ?? 'Evento') ?></td>
                        <td><?= htmlspecialchars($ev['space_visivel'] ?? '') ?></td>
                        <td><?= htmlspecialchars(implode(', ', $ev['listas'] ?? [])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3>Faltando insumos (próximos 3 dias)</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Evento</th>
                    <th>Unidade</th>
                    <th>Faltas</th>
                    <th>Ação</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($eventos_alertas['faltas'])): ?>
                <tr><td colspan="5">Nenhuma falta encontrada.</td></tr>
            <?php else: ?>
                <?php foreach ($eventos_alertas['faltas'] as $ev): ?>
                    <tr>
                        <td><?= format_date($ev['data_evento'] ?? null, 'd/m/Y') ?></td>
                        <td><?= htmlspecialchars($ev['nome_evento'] ?? 'Evento') ?></td>
                        <td><?= htmlspecialchars($ev['space_visivel'] ?? '') ?></td>
                        <td><?= (int)$ev['faltas_total'] ?> de <?= (int)$ev['itens_total'] ?></td>
                        <td>
                            <a class="btn btn-secondary" href="index.php?page=logistica_faltas_evento&event_id=<?= (int)$ev['id'] ?>">Ver detalhes</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3>Faltas operacionais</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Data/Hora</th>
                    <th>Unidade</th>
                    <th>Insumo</th>
                    <th>Mensagem</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$logs): ?>
                <tr><td colspan="4">Nenhum alerta registrado.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td><?= format_date($l['criado_em'] ?? null) ?></td>
                        <td><?= htmlspecialchars($l['unidade_nome'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($l['nome_oficial'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($l['mensagem'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endSidebar(); ?>
