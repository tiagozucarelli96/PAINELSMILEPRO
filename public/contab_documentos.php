<?php
// contab_documentos.php
// Lista de documentos contábeis

session_start();
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
if (!in_array($perfil, ['ADM', 'FIN', 'GERENTE', 'CONSULTA'])) {
    header('Location: dashboard.php?erro=permissao_negada');
    exit;
}

// Filtros
$filtro_periodo = $_GET['periodo'] ?? '';
$filtro_tipo = $_GET['tipo'] ?? '';
$filtro_status = $_GET['status'] ?? '';
$filtro_texto = $_GET['texto'] ?? '';

// Construir query
$where_conditions = [];
$params = [];

if ($filtro_periodo) {
    switch ($filtro_periodo) {
        case 'hoje':
            $where_conditions[] = "p.vencimento = CURRENT_DATE";
            break;
        case '48h':
            $where_conditions[] = "p.vencimento BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '2 days'";
            break;
        case '7d':
            $where_conditions[] = "p.vencimento BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '7 days'";
            break;
        case 'mes':
            $where_conditions[] = "d.competencia = TO_CHAR(NOW(), 'YYYY-MM')";
            break;
    }
}

if ($filtro_tipo) {
    $where_conditions[] = "d.tipo = :tipo";
    $params['tipo'] = $filtro_tipo;
}

if ($filtro_status) {
    $where_conditions[] = "p.status = :status";
    $params['status'] = $filtro_status;
}

if ($filtro_texto) {
    $where_conditions[] = "(d.descricao ILIKE :texto OR d.fornecedor_sugerido ILIKE :texto)";
    $params['texto'] = "%$filtro_texto%";
}

$where_sql = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Buscar documentos
$documentos = [];
try {
    $sql = "
        SELECT d.*, 
               COUNT(p.id) as total_parcelas,
               SUM(CASE WHEN p.status = 'pendente' THEN 1 ELSE 0 END) as parcelas_pendentes,
               SUM(CASE WHEN p.status = 'pago' THEN 1 ELSE 0 END) as parcelas_pagas,
               MIN(CASE WHEN p.status = 'pendente' THEN p.vencimento END) as proximo_vencimento,
               SUM(CASE WHEN p.status = 'pendente' THEN p.valor ELSE 0 END) as valor_pendente
        FROM contab_documentos d
        LEFT JOIN contab_parcelas p ON p.documento_id = d.id
        $where_sql
        GROUP BY d.id
        ORDER BY d.criado_em DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $erro = "Erro ao buscar documentos: " . $e->getMessage();
}

// Buscar tipos únicos para filtro
$tipos = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT tipo FROM contab_documentos ORDER BY tipo");
    $tipos = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // Ignorar erro
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documentos Contábeis</title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .contab-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .contab-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border-radius: 12px;
        }
        
        .contab-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
        }
        
        .contab-actions {
            display: flex;
            gap: 10px;
        }
        
        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-label {
            font-weight: 500;
            color: #374151;
            margin-bottom: 5px;
        }
        
        .filter-input {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .documentos-grid {
            display: grid;
            gap: 20px;
        }
        
        .documento-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
        }
        
        .documento-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .documento-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .documento-info {
            flex: 1;
        }
        
        .documento-titulo {
            font-size: 18px;
            font-weight: 600;
            color: #1e40af;
            margin: 0 0 5px 0;
        }
        
        .documento-meta {
            color: #64748b;
            font-size: 14px;
            margin: 0 0 10px 0;
        }
        
        .documento-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-pendente {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-pago {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-vencido {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .documento-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        
        .detail-label {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 2px;
        }
        
        .detail-value {
            font-size: 14px;
            color: #374151;
            font-weight: 500;
        }
        
        .documento-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: #1e40af;
            color: white;
        }
        
        .btn-primary:hover {
            background: #1e3a8a;
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        .btn-outline {
            background: transparent;
            color: #1e40af;
            border: 1px solid #1e40af;
        }
        
        .btn-outline:hover {
            background: #1e40af;
            color: white;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 20px;
        }
        
        .empty-state-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .empty-state-text {
            font-size: 14px;
        }
        
        .urgent-badge {
            background: #fee2e2;
            color: #991b1b;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 500;
            margin-left: 8px;
        }
    </style>
</head>
<body>
    <div class="contab-container">
        <!-- Header -->
        <div class="contab-header">
            <h1 class="contab-title">📄 Documentos Contábeis</h1>
            <div class="contab-actions">
                <a href="contab_dashboard.php" class="smile-btn smile-btn-outline">← Dashboard</a>
                <a href="contab_link.php" class="smile-btn smile-btn-primary">+ Novo Documento</a>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filters-section">
            <form method="GET" class="filters-form">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label class="filter-label">Período</label>
                        <select name="periodo" class="filter-input">
                            <option value="">Todos</option>
                            <option value="hoje" <?= $filtro_periodo === 'hoje' ? 'selected' : '' ?>>Vencem Hoje</option>
                            <option value="48h" <?= $filtro_periodo === '48h' ? 'selected' : '' ?>>Próximos 2 dias</option>
                            <option value="7d" <?= $filtro_periodo === '7d' ? 'selected' : '' ?>>Próximos 7 dias</option>
                            <option value="mes" <?= $filtro_periodo === 'mes' ? 'selected' : '' ?>>Mês Atual</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Tipo</label>
                        <select name="tipo" class="filter-input">
                            <option value="">Todos os tipos</option>
                            <?php foreach ($tipos as $tipo): ?>
                            <option value="<?= htmlspecialchars($tipo) ?>" 
                                    <?= $filtro_tipo === $tipo ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucfirst($tipo)) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Status</label>
                        <select name="status" class="filter-input">
                            <option value="">Todos</option>
                            <option value="pendente" <?= $filtro_status === 'pendente' ? 'selected' : '' ?>>Pendente</option>
                            <option value="pago" <?= $filtro_status === 'pago' ? 'selected' : '' ?>>Pago</option>
                            <option value="vencido" <?= $filtro_status === 'vencido' ? 'selected' : '' ?>>Vencido</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Buscar</label>
                        <input type="text" name="texto" value="<?= htmlspecialchars($filtro_texto) ?>" 
                               class="filter-input" placeholder="Descrição ou fornecedor...">
                    </div>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="smile-btn smile-btn-primary">🔍 Filtrar</button>
                    <a href="contab_documentos.php" class="smile-btn smile-btn-outline">Limpar</a>
                </div>
            </form>
        </div>
        
        <!-- Lista de Documentos -->
        <?php if (!empty($documentos)): ?>
        <div class="documentos-grid">
            <?php foreach ($documentos as $documento): ?>
            <div class="documento-card">
                <div class="documento-header">
                    <div class="documento-info">
                        <h3 class="documento-titulo"><?= htmlspecialchars($documento['descricao']) ?></h3>
                        <p class="documento-meta">
                            <?= ucfirst($documento['tipo']) ?> • 
                            <?= $documento['competencia'] ?> • 
                            <?= ucfirst($documento['origem']) ?>
                        </p>
                    </div>
                    <span class="documento-status <?= $documento['parcelas_pendentes'] > 0 ? 'status-pendente' : 'status-pago' ?>">
                        <?= $documento['parcelas_pendentes'] > 0 ? 'Pendente' : 'Pago' ?>
                    </span>
                </div>
                
                <div class="documento-details">
                    <div class="detail-item">
                        <span class="detail-label">Parcelas</span>
                        <span class="detail-value">
                            <?= $documento['parcelas_pagas'] ?>/<?= $documento['total_parcelas'] ?> pagas
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Próximo Vencimento</span>
                        <span class="detail-value">
                            <?php if ($documento['proximo_vencimento']): ?>
                                <?= date('d/m/Y', strtotime($documento['proximo_vencimento'])) ?>
                                <?php if (strtotime($documento['proximo_vencimento']) <= strtotime('+2 days')): ?>
                                    <span class="urgent-badge">URGENTE</span>
                                <?php endif; ?>
                            <?php else: ?>
                                Nenhum
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Valor Pendente</span>
                        <span class="detail-value">
                            R$ <?= number_format($documento['valor_pendente'], 2, ',', '.') ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Fornecedor</span>
                        <span class="detail-value">
                            <?= htmlspecialchars($documento['fornecedor_sugerido'] ?? 'Não informado') ?>
                        </span>
                    </div>
                </div>
                
                <div class="documento-actions">
                    <a href="contab_doc_ver.php?id=<?= $documento['id'] ?>" class="action-btn btn-primary">
                        👁️ Ver Detalhes
                    </a>
                    <?php if (in_array($perfil, ['ADM', 'FIN'])): ?>
                    <a href="contab_doc_ver.php?id=<?= $documento['id'] ?>&acao=editar" class="action-btn btn-secondary">
                        ✏️ Editar
                    </a>
                    <?php endif; ?>
                    <?php if ($documento['parcelas_pendentes'] > 0): ?>
                    <a href="contab_doc_ver.php?id=<?= $documento['id'] ?>&tab=parcelas" class="action-btn btn-outline">
                        💰 Gerenciar Parcelas
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon">📄</div>
            <div class="empty-state-title">Nenhum documento encontrado</div>
            <div class="empty-state-text">
                <?php if ($filtro_periodo || $filtro_tipo || $filtro_status || $filtro_texto): ?>
                Tente ajustar os filtros ou <a href="contab_documentos.php">limpar a busca</a>.
                <?php else: ?>
                <a href="contab_link.php">Cadastre o primeiro documento</a> para começar.
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
