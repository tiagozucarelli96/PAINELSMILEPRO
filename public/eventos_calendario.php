<?php
/**
 * eventos_calendario.php
 * Lista de eventos já organizados
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';

if (empty($_SESSION['perm_eventos']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

$search = trim((string)($_GET['search'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 20;

$where = [
    'r.me_event_id IS NOT NULL',
    'r.me_event_id > 0',
];
$params = [];

if ($search !== '') {
    $where[] = "(
        r.me_event_snapshot->>'nome' ILIKE :search
        OR r.me_event_snapshot->'cliente'->>'nome' ILIKE :search
        OR r.me_event_snapshot->>'local' ILIKE :search
        OR CAST(r.me_event_id AS TEXT) ILIKE :search
    )";
    $params[':search'] = '%' . $search . '%';
}

$allowed_status = ['rascunho', 'concluida'];
if (in_array($status, $allowed_status, true)) {
    $where[] = 'r.status = :status';
    $params[':status'] = $status;
} else {
    $status = '';
}

$where_sql = 'WHERE ' . implode(' AND ', $where);
$stats = [
    'total' => 0,
    'rascunho' => 0,
    'concluida' => 0,
];
$eventos = [];
$total = 0;
$total_pages = 1;

try {
    $stmt_stats = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'rascunho' THEN 1 ELSE 0 END) AS rascunho,
            SUM(CASE WHEN status = 'concluida' THEN 1 ELSE 0 END) AS concluida
        FROM eventos_reunioes
        WHERE me_event_id IS NOT NULL
          AND me_event_id > 0
    ");
    $stats_row = $stmt_stats->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats['total'] = (int)($stats_row['total'] ?? 0);
    $stats['rascunho'] = (int)($stats_row['rascunho'] ?? 0);
    $stats['concluida'] = (int)($stats_row['concluida'] ?? 0);

    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM eventos_reunioes r {$where_sql}");
    $stmt_count->execute($params);
    $total = (int)$stmt_count->fetchColumn();
    $total_pages = max(1, (int)ceil($total / $per_page));
    if ($page_num > $total_pages) {
        $page_num = $total_pages;
    }
    $offset = ($page_num - 1) * $per_page;

    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.me_event_id,
            r.status,
            r.updated_at,
            (r.me_event_snapshot->>'data') AS data_evento,
            COALESCE(NULLIF(TRIM(r.me_event_snapshot->>'nome'), ''), 'Evento sem nome') AS nome_evento,
            COALESCE(NULLIF(TRIM(r.me_event_snapshot->>'local'), ''), 'Local não informado') AS local_evento,
            COALESCE(NULLIF(TRIM(r.me_event_snapshot->'cliente'->>'nome'), ''), 'Cliente não informado') AS cliente_nome
        FROM eventos_reunioes r
        {$where_sql}
        ORDER BY
            (r.me_event_snapshot->>'data')::date DESC NULLS LAST,
            r.updated_at DESC NULLS LAST,
            r.id DESC
        LIMIT :limit OFFSET :offset
    ");

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Erro ao carregar eventos organizados: ' . $e->getMessage());
}

$base_params = [
    'page' => 'eventos_calendario',
    'search' => $search,
    'status' => $status,
];
$base_query = http_build_query($base_params);

includeSidebar('Eventos Organizados');
?>

<style>
    .organizados-container {
        padding: 2rem;
        max-width: 1400px;
        margin: 0 auto;
        background: #f8fafc;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .page-title {
        font-size: 1.6rem;
        font-weight: 700;
        color: #1e3a8a;
        margin: 0;
    }

    .page-subtitle {
        margin-top: 0.35rem;
        color: #64748b;
        font-size: 0.9rem;
    }

    .header-actions {
        display: flex;
        gap: 0.7rem;
        flex-wrap: wrap;
    }

    .btn {
        padding: 0.62rem 1.1rem;
        border-radius: 8px;
        font-size: 0.86rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        text-decoration: none;
    }

    .btn-primary {
        background: #1e3a8a;
        color: #fff;
    }

    .btn-primary:hover {
        background: #254ac9;
    }

    .btn-secondary {
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #dbe3ef;
    }

    .btn-secondary:hover {
        background: #e2e8f0;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 0.8rem;
        margin-bottom: 1rem;
    }

    .stat-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 0.95rem;
    }

    .stat-card .label {
        color: #64748b;
        font-size: 0.82rem;
    }

    .stat-card .value {
        margin-top: 0.25rem;
        font-size: 1.55rem;
        font-weight: 700;
        color: #1e293b;
    }

    .filters {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 1rem;
        display: flex;
        gap: 0.7rem;
        flex-wrap: wrap;
        align-items: center;
        margin-bottom: 1rem;
    }

    .filter-input,
    .filter-select {
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0.58rem 0.72rem;
        font-size: 0.86rem;
    }

    .filter-input {
        min-width: 260px;
        flex: 1;
    }

    .filter-select {
        min-width: 180px;
    }

    .table-wrapper {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        overflow: hidden;
    }

    .table {
        width: 100%;
        border-collapse: collapse;
    }

    .table th {
        background: #f8fafc;
        color: #64748b;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        text-align: left;
        padding: 0.9rem;
        border-bottom: 1px solid #e5e7eb;
    }

    .table td {
        padding: 0.9rem;
        font-size: 0.86rem;
        color: #334155;
        border-bottom: 1px solid #eef2f7;
        vertical-align: middle;
    }

    .table tr:last-child td {
        border-bottom: none;
    }

    .event-name {
        font-weight: 700;
        color: #1f2937;
    }

    .event-meta {
        font-size: 0.78rem;
        color: #64748b;
        margin-top: 0.2rem;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 0.22rem 0.58rem;
        font-size: 0.75rem;
        font-weight: 700;
        border: 1px solid transparent;
        white-space: nowrap;
    }

    .status-badge.rascunho {
        background: #fef3c7;
        color: #92400e;
        border-color: #fde68a;
    }

    .status-badge.concluida {
        background: #d1fae5;
        color: #065f46;
        border-color: #a7f3d0;
    }

    .empty-state {
        padding: 2rem;
        text-align: center;
        color: #64748b;
    }

    .pagination {
        display: flex;
        justify-content: center;
        gap: 0.45rem;
        margin-top: 1rem;
        flex-wrap: wrap;
    }

    .pagination a,
    .pagination span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 36px;
        padding: 0.45rem 0.7rem;
        border-radius: 8px;
        font-size: 0.82rem;
        text-decoration: none;
        border: 1px solid #dbe3ef;
    }

    .pagination a {
        background: #fff;
        color: #334155;
    }

    .pagination a:hover {
        background: #f8fafc;
    }

    .pagination .current {
        background: #1e3a8a;
        border-color: #1e3a8a;
        color: #fff;
    }

    @media (max-width: 768px) {
        .organizados-container {
            padding: 1rem;
        }

        .table {
            display: block;
            overflow-x: auto;
            white-space: nowrap;
        }

        .filter-input,
        .filter-select {
            width: 100%;
        }
    }
</style>

<div class="organizados-container">
    <div class="page-header">
        <div>
            <h1 class="page-title">📚 Eventos Organizados</h1>
            <div class="page-subtitle">
                Eventos que já foram organizados em <strong>Organização de Eventos</strong>.
                Mostrando <?= (int)$total ?> resultado(s) no filtro atual.
            </div>
        </div>
        <div class="header-actions">
            <a href="index.php?page=eventos_organizacao" class="btn btn-primary">+ Organizar novo evento</a>
            <a href="index.php?page=eventos" class="btn btn-secondary">← Voltar</a>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Total organizados</div>
            <div class="value"><?= (int)$stats['total'] ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Em rascunho</div>
            <div class="value"><?= (int)$stats['rascunho'] ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Concluídos</div>
            <div class="value"><?= (int)$stats['concluida'] ?></div>
        </div>
    </div>

    <form class="filters" method="GET">
        <input type="hidden" name="page" value="eventos_calendario">
        <input
            type="text"
            name="search"
            class="filter-input"
            value="<?= htmlspecialchars($search) ?>"
            placeholder="Buscar por evento, cliente, local ou ID do evento..."
        >
        <select name="status" class="filter-select">
            <option value="">Todos os status</option>
            <option value="rascunho" <?= $status === 'rascunho' ? 'selected' : '' ?>>Rascunho</option>
            <option value="concluida" <?= $status === 'concluida' ? 'selected' : '' ?>>Concluída</option>
        </select>
        <button type="submit" class="btn btn-primary">Filtrar</button>
        <?php if ($search !== '' || $status !== ''): ?>
        <a href="index.php?page=eventos_calendario" class="btn btn-secondary">Limpar</a>
        <?php endif; ?>
    </form>

    <div class="table-wrapper">
        <?php if (empty($eventos)): ?>
        <div class="empty-state">
            <div style="font-size: 1.9rem;">🧩</div>
            <p><strong>Nenhum evento organizado encontrado.</strong></p>
            <p>Organize um evento em Organização de Eventos para ele aparecer aqui.</p>
        </div>
        <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Evento</th>
                    <th>Data</th>
                    <th>Cliente</th>
                    <th>Local</th>
                    <th>Status</th>
                    <th>Atualizado</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eventos as $ev): ?>
                <?php
                    $data_evento = trim((string)($ev['data_evento'] ?? ''));
                    $data_ts = $data_evento !== '' ? strtotime($data_evento) : false;
                    $data_fmt = $data_ts ? date('d/m/Y', $data_ts) : '-';
                    $updated_at = trim((string)($ev['updated_at'] ?? ''));
                    $updated_ts = $updated_at !== '' ? strtotime($updated_at) : false;
                    $updated_fmt = $updated_ts ? date('d/m/Y H:i', $updated_ts) : '-';
                    $status_key = $ev['status'] === 'concluida' ? 'concluida' : 'rascunho';
                    $status_label = $status_key === 'concluida' ? 'Concluída' : 'Rascunho';
                ?>
                <tr>
                    <td>
                        <div class="event-name"><?= htmlspecialchars((string)$ev['nome_evento']) ?></div>
                        <div class="event-meta">ME ID: <?= (int)$ev['me_event_id'] ?></div>
                    </td>
                    <td><?= htmlspecialchars($data_fmt) ?></td>
                    <td><?= htmlspecialchars((string)$ev['cliente_nome']) ?></td>
                    <td><?= htmlspecialchars((string)$ev['local_evento']) ?></td>
                    <td>
                        <span class="status-badge <?= $status_key ?>"><?= $status_label ?></span>
                    </td>
                    <td><?= htmlspecialchars($updated_fmt) ?></td>
                    <td>
                        <a href="index.php?page=eventos_organizacao&id=<?= (int)$ev['id'] ?>" class="btn btn-secondary">Abrir</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page_num > 1): ?>
        <a href="?<?= $base_query ?>&p=<?= $page_num - 1 ?>">← Anterior</a>
        <?php endif; ?>

        <?php for ($p = max(1, $page_num - 2); $p <= min($total_pages, $page_num + 2); $p++): ?>
        <?php if ($p === $page_num): ?>
        <span class="current"><?= $p ?></span>
        <?php else: ?>
        <a href="?<?= $base_query ?>&p=<?= $p ?>"><?= $p ?></a>
        <?php endif; ?>
        <?php endfor; ?>

        <?php if ($page_num < $total_pages): ?>
        <a href="?<?= $base_query ?>&p=<?= $page_num + 1 ?>">Próxima →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php endSidebar(); ?>
