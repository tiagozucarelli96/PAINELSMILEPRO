<?php
/**
 * eventos_reunioes.php
 * Lista de todas as reuniões com filtros
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

// Verificar permissão
if (empty($_SESSION['perm_eventos']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

// Filtros
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$page_num = max(1, (int)($_GET['p'] ?? 1));
$per_page = 20;

// Buscar reuniões
$where = [];
$params = [];

if ($search) {
    $where[] = "(me_event_snapshot->>'nome' ILIKE :search OR me_event_snapshot->'cliente'->>'nome' ILIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($status && in_array($status, ['rascunho', 'concluida'])) {
    $where[] = "status = :status";
    $params[':status'] = $status;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Total
$stmt = $pdo->prepare("SELECT COUNT(*) FROM eventos_reunioes {$where_sql}");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$total_pages = ceil($total / $per_page);

// Reuniões
$offset = ($page_num - 1) * $per_page;
$stmt = $pdo->prepare("
    SELECT r.*, 
           (r.me_event_snapshot->>'data') as data_evento,
           (r.me_event_snapshot->>'nome') as nome_evento,
           (r.me_event_snapshot->>'local') as local_evento,
           (r.me_event_snapshot->'cliente'->>'nome') as cliente_nome,
           (r.me_event_snapshot->>'convidados') as convidados
    FROM eventos_reunioes r
    {$where_sql}
    ORDER BY (r.me_event_snapshot->>'data')::date DESC
    LIMIT {$per_page} OFFSET {$offset}
");
$stmt->execute($params);
$reunioes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    .reunioes-container {
        padding: 2rem;
        max-width: 1400px;
        margin: 0 auto;
    }
    
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .page-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
    }
    
    .header-actions {
        display: flex;
        gap: 0.75rem;
    }
    
    .btn {
        padding: 0.625rem 1.25rem;
        border-radius: 8px;
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
    }
    
    .btn-primary {
        background: #1e3a8a;
        color: white;
    }
    
    .btn-secondary {
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #e2e8f0;
    }
    
    /* Filtros */
    .filters {
        background: white;
        border-radius: 12px;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        align-items: center;
    }
    
    .filter-input {
        flex: 1;
        min-width: 200px;
        padding: 0.625rem 1rem;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 0.875rem;
    }
    
    .filter-select {
        padding: 0.625rem 1rem;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 0.875rem;
        min-width: 150px;
    }
    
    /* Tabela */
    .table-wrapper {
        background: white;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        overflow: hidden;
    }
    
    .table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .table th {
        background: #f8fafc;
        padding: 1rem;
        text-align: left;
        font-size: 0.75rem;
        font-weight: 600;
        color: #64748b;
        text-transform: uppercase;
        border-bottom: 1px solid #e5e7eb;
    }
    
    .table td {
        padding: 1rem;
        border-bottom: 1px solid #e5e7eb;
        font-size: 0.875rem;
    }
    
    .table tr:hover {
        background: #f8fafc;
    }
    
    .table tr:last-child td {
        border-bottom: none;
    }
    
    .event-name {
        font-weight: 600;
        color: #1e293b;
    }
    
    .event-meta {
        font-size: 0.8rem;
        color: #64748b;
        margin-top: 0.25rem;
    }
    
    .status-badge {
        display: inline-block;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    
    .status-rascunho {
        background: #fef3c7;
        color: #92400e;
    }
    
    .status-concluida {
        background: #d1fae5;
        color: #065f46;
    }
    
    .actions {
        display: flex;
        gap: 0.5rem;
    }
    
    .btn-sm {
        padding: 0.375rem 0.75rem;
        font-size: 0.75rem;
    }
    
    /* Paginação */
    .pagination {
        display: flex;
        justify-content: center;
        gap: 0.5rem;
        margin-top: 1.5rem;
    }
    
    .pagination a,
    .pagination span {
        padding: 0.5rem 1rem;
        border-radius: 6px;
        font-size: 0.875rem;
        text-decoration: none;
    }
    
    .pagination a {
        background: #f1f5f9;
        color: #475569;
    }
    
    .pagination a:hover {
        background: #e2e8f0;
    }
    
    .pagination .current {
        background: #1e3a8a;
        color: white;
    }
    
    .empty-state {
        text-align: center;
        padding: 3rem;
        color: #64748b;
    }
    
    .empty-state p {
        margin: 0.5rem 0;
    }
    
    @media (max-width: 768px) {
        .reunioes-container {
            padding: 1rem;
        }
        
        .table {
            display: block;
            overflow-x: auto;
        }
    }
</style>

<div class="reunioes-container">
    <div class="page-header">
        <h1 class="page-title">📋 Reuniões</h1>
        <div class="header-actions">
            <a href="index.php?page=eventos_organizacao" class="btn btn-primary">+ Nova Organização</a>
            <a href="index.php?page=eventos_calendario" class="btn btn-secondary">📅 Calendário</a>
            <a href="index.php?page=eventos" class="btn btn-secondary">← Voltar</a>
        </div>
    </div>
    
    <!-- Filtros -->
    <form class="filters" method="GET">
        <input type="hidden" name="page" value="eventos_reunioes">
        <input type="text" name="search" class="filter-input" placeholder="Buscar por nome do evento ou cliente..." value="<?= htmlspecialchars($search) ?>">
        <select name="status" class="filter-select">
            <option value="">Todos os status</option>
            <option value="rascunho" <?= $status === 'rascunho' ? 'selected' : '' ?>>Rascunho</option>
            <option value="concluida" <?= $status === 'concluida' ? 'selected' : '' ?>>Concluída</option>
        </select>
        <button type="submit" class="btn btn-primary">Filtrar</button>
    </form>
    
    <!-- Tabela -->
    <div class="table-wrapper">
        <?php if (empty($reunioes)): ?>
        <div class="empty-state">
            <p style="font-size: 2rem;">📝</p>
            <p><strong>Nenhuma reunião encontrada</strong></p>
            <p>Crie uma nova reunião selecionando um evento da ME.</p>
        </div>
        <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Evento</th>
                    <th>Data</th>
                    <th>Local</th>
                    <th>Convidados</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reunioes as $r): ?>
                <tr>
                    <td>
                        <div class="event-name"><?= htmlspecialchars($r['nome_evento'] ?: 'Sem nome') ?></div>
                        <div class="event-meta">👤 <?= htmlspecialchars($r['cliente_nome'] ?: 'Cliente') ?></div>
                    </td>
                    <td><?= $r['data_evento'] ? date('d/m/Y', strtotime($r['data_evento'])) : '-' ?></td>
                    <td><?= htmlspecialchars($r['local_evento'] ?: '-') ?></td>
                    <td><?= (int)$r['convidados'] ?></td>
                    <td>
                        <span class="status-badge status-<?= $r['status'] ?>">
                            <?= $r['status'] === 'concluida' ? '✓ Concluída' : '⏳ Rascunho' ?>
                        </span>
                    </td>
                    <td class="actions">
                        <a href="index.php?page=eventos_organizacao&id=<?= $r['id'] ?>" class="btn btn-primary btn-sm">Editar</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    
    <!-- Paginação -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page_num > 1): ?>
        <a href="?page=eventos_reunioes&p=<?= $page_num - 1 ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>">← Anterior</a>
        <?php endif; ?>
        
        <?php for ($p = max(1, $page_num - 2); $p <= min($total_pages, $page_num + 2); $p++): ?>
        <?php if ($p === $page_num): ?>
        <span class="current"><?= $p ?></span>
        <?php else: ?>
        <a href="?page=eventos_reunioes&p=<?= $p ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>"><?= $p ?></a>
        <?php endif; ?>
        <?php endfor; ?>
        
        <?php if ($page_num < $total_pages): ?>
        <a href="?page=eventos_reunioes&p=<?= $page_num + 1 ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>">Próxima →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
