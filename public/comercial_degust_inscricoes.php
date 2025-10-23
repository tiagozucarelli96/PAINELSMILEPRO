<?php
// comercial_degust_inscricoes.php — Todas as inscrições de todas as degustações
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';

// Verificar permissões
if (!lc_can_manage_inscritos()) {
    header('Location: dashboard.php?error=permission_denied');
    exit;
}

// Filtros
$event_filter = (int)($_GET['event_id'] ?? 0);
$status_filter = $_GET['status'] ?? '';
$fechou_filter = $_GET['fechou_contrato'] ?? '';
$search = trim($_GET['search'] ?? '');
$data_inicio = $_GET['data_inicio'] ?? '';
$data_fim = $_GET['data_fim'] ?? '';

$where = [];
$params = [];

if ($event_filter) {
    $where[] = 'i.event_id = :event_id';
    $params[':event_id'] = $event_filter;
}

if ($status_filter) {
    $where[] = 'i.status = :status';
    $params[':status'] = $status_filter;
}

if ($fechou_filter) {
    $where[] = 'i.fechou_contrato = :fechou_contrato';
    $params[':fechou_contrato'] = $fechou_filter;
}

if ($search) {
    $where[] = '(i.nome ILIKE :search OR i.email ILIKE :search OR d.nome ILIKE :search)';
    $params[':search'] = "%$search%";
}

if ($data_inicio) {
    $where[] = 'd.data >= :data_inicio';
    $params[':data_inicio'] = $data_inicio;
}

if ($data_fim) {
    $where[] = 'd.data <= :data_fim';
    $params[':data_fim'] = $data_fim;
}

// Buscar inscrições
$sql = "SELECT i.*, d.nome as degustacao_nome, d.data as degustacao_data, d.local as degustacao_local,
               CASE WHEN i.fechou_contrato = 'sim' THEN 'Sim' 
                    WHEN i.fechou_contrato = 'nao' THEN 'Não' 
                    ELSE 'Indefinido' END as fechou_contrato_text,
               CASE WHEN i.pagamento_status = 'pago' THEN 'Pago' 
                    WHEN i.pagamento_status = 'aguardando' THEN 'Aguardando' 
                    WHEN i.pagamento_status = 'expirado' THEN 'Expirado' 
                    ELSE 'N/A' END as pagamento_text
        FROM comercial_inscricoes i
        LEFT JOIN comercial_degustacoes d ON d.id = i.event_id";

if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY i.criado_em DESC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$inscricoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar degustações para filtro
$stmt = $pdo->query("SELECT id, nome, data FROM comercial_degustacoes ORDER BY data DESC");
$degustacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas gerais
$stats = [
    'total' => count($inscricoes),
    'confirmados' => count(array_filter($inscricoes, fn($i) => $i['status'] === 'confirmado')),
    'lista_espera' => count(array_filter($inscricoes, fn($i) => $i['status'] === 'lista_espera')),
    'fechou_contrato' => count(array_filter($inscricoes, fn($i) => $i['fechou_contrato'] === 'sim')),
    'nao_fechou_contrato' => count(array_filter($inscricoes, fn($i) => $i['fechou_contrato'] === 'nao')),
    'pagamentos_pagos' => count(array_filter($inscricoes, fn($i) => $i['pagamento_status'] === 'pago'))
];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function getStatusBadge($status) {
    $badges = [
        'confirmado' => '<span class="badge badge-success">Confirmado</span>',
        'lista_espera' => '<span class="badge badge-warning">Lista de Espera</span>',
        'cancelado' => '<span class="badge badge-danger">Cancelado</span>'
    ];
    return $badges[$status] ?? '<span class="badge badge-secondary">' . $status . '</span>';
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Todas as Inscrições - GRUPO Smile EVENTOS</title>
    <link rel="stylesheet" href="estilo.css">
    <style>
        .inscricoes-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0 0 5px 0;
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 14px;
        }
        
        .filters {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 5px;
            font-size: 14px;
        }
        
        .form-input {
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .form-select {
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            background: white;
        }
        
        .filters-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .inscricoes-table {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .table-header {
            background: #f8fafc;
            padding: 15px 20px;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 600;
            color: #374151;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr 1fr;
            gap: 15px;
        }
        
        .table-row {
            padding: 15px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr 1fr;
            gap: 15px;
            align-items: center;
        }
        
        .table-row:hover {
            background: #f8fafc;
        }
        
        .table-row:last-child {
            border-bottom: none;
        }
        
        .participant-info {
            display: flex;
            flex-direction: column;
        }
        
        .participant-name {
            font-weight: 600;
            color: #1f2937;
            margin: 0 0 5px 0;
        }
        
        .participant-email {
            color: #6b7280;
            font-size: 14px;
            margin: 0;
        }
        
        .degustacao-info {
            display: flex;
            flex-direction: column;
        }
        
        .degustacao-name {
            font-weight: 600;
            color: #1e3a8a;
            margin: 0 0 5px 0;
        }
        
        .degustacao-date {
            color: #6b7280;
            font-size: 14px;
            margin: 0;
        }
        
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .badge-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        
        .btn-sm {
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .btn-edit {
            background: #3b82f6;
            color: white;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .pagination a {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            text-decoration: none;
            color: #374151;
        }
        
        .pagination a:hover {
            background: #f3f4f6;
        }
        
        .pagination .current {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }
    </style>
</head>
<body>
    <?php if (is_file(__DIR__.'/sidebar.php')) { include __DIR__.'/sidebar.php'; } ?>
    
    <div class="main-content">
        <div class="inscricoes-container">
            <!-- Header -->
            <div class="page-header">
                <h1 class="page-title">📋 Todas as Inscrições</h1>
                <div>
                    <a href="comercial_degustacoes.php" class="btn-secondary">← Degustações</a>
                    <button class="btn-primary" onclick="exportCSV()">📊 Exportar CSV</button>
                </div>
            </div>
            
            <!-- Estatísticas -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['total'] ?></div>
                    <div class="stat-label">Total de Inscrições</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['confirmados'] ?></div>
                    <div class="stat-label">Confirmados</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['lista_espera'] ?></div>
                    <div class="stat-label">Lista de Espera</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['fechou_contrato'] ?></div>
                    <div class="stat-label">Fecharam Contrato</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['nao_fechou_contrato'] ?></div>
                    <div class="stat-label">Não Fecharam</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['pagamentos_pagos'] ?></div>
                    <div class="stat-label">Pagamentos Pagos</div>
                </div>
            </div>
            
            <!-- Filtros -->
            <div class="filters">
                <form method="GET" id="filtersForm">
                    <div class="filters-grid">
                        <div class="form-group">
                            <label class="form-label">Degustação</label>
                            <select name="event_id" class="form-select">
                                <option value="">Todas as degustações</option>
                                <?php foreach ($degustacoes as $degustacao): ?>
                                    <option value="<?= $degustacao['id'] ?>" <?= $event_filter == $degustacao['id'] ? 'selected' : '' ?>>
                                        <?= h($degustacao['nome']) ?> - <?= date('d/m/Y', strtotime($degustacao['data'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="">Todos os status</option>
                                <option value="confirmado" <?= $status_filter === 'confirmado' ? 'selected' : '' ?>>Confirmados</option>
                                <option value="lista_espera" <?= $status_filter === 'lista_espera' ? 'selected' : '' ?>>Lista de Espera</option>
                                <option value="cancelado" <?= $status_filter === 'cancelado' ? 'selected' : '' ?>>Cancelados</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Fechou Contrato</label>
                            <select name="fechou_contrato" class="form-select">
                                <option value="">Todos</option>
                                <option value="sim" <?= $fechou_filter === 'sim' ? 'selected' : '' ?>>Sim</option>
                                <option value="nao" <?= $fechou_filter === 'nao' ? 'selected' : '' ?>>Não</option>
                                <option value="indefinido" <?= $fechou_filter === 'indefinido' ? 'selected' : '' ?>>Indefinido</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Data Início</label>
                            <input type="date" name="data_inicio" class="form-input" value="<?= h($data_inicio) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Data Fim</label>
                            <input type="date" name="data_fim" class="form-input" value="<?= h($data_fim) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Pesquisar</label>
                            <input type="text" name="search" class="form-input" placeholder="Nome, e-mail ou degustação..." value="<?= h($search) ?>">
                        </div>
                    </div>
                    
                    <div class="filters-actions">
                        <button type="submit" class="btn-primary">🔍 Filtrar</button>
                        <a href="comercial_degust_inscricoes.php" class="btn-secondary">Limpar</a>
                    </div>
                </form>
            </div>
            
            <!-- Tabela de Inscrições -->
            <div class="inscricoes-table">
                <div class="table-header">
                    <div>Participante</div>
                    <div>Degustação</div>
                    <div>Status</div>
                    <div>Tipo de Festa</div>
                    <div>Pessoas</div>
                    <div>Fechou Contrato</div>
                    <div>Pagamento</div>
                </div>
                
                <?php foreach ($inscricoes as $inscricao): ?>
                    <div class="table-row">
                        <div class="participant-info">
                            <div class="participant-name"><?= h($inscricao['nome']) ?></div>
                            <div class="participant-email"><?= h($inscricao['email']) ?></div>
                        </div>
                        
                        <div class="degustacao-info">
                            <div class="degustacao-name"><?= h($inscricao['degustacao_nome']) ?></div>
                            <div class="degustacao-date"><?= date('d/m/Y', strtotime($inscricao['degustacao_data'])) ?></div>
                        </div>
                        
                        <div><?= getStatusBadge($inscricao['status']) ?></div>
                        
                        <div><?= ucfirst($inscricao['tipo_festa']) ?></div>
                        
                        <div><?= $inscricao['qtd_pessoas'] ?> pessoas</div>
                        
                        <div><?= $inscricao['fechou_contrato_text'] ?></div>
                        
                        <div><?= $inscricao['pagamento_text'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if (empty($inscricoes)): ?>
                <div style="text-align: center; padding: 40px; color: #6b7280;">
                    <h3>Nenhuma inscrição encontrada</h3>
                    <p>Experimente ajustar os filtros de pesquisa</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function exportCSV() {
            // TODO: Implementar exportação CSV
            alert('Funcionalidade de exportação será implementada em breve');
        }
    </script>
</body>
</html>
