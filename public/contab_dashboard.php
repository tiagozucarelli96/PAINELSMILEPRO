<?php
// contab_dashboard.php
// Hub da Contabilidade - Dashboard com cards e estatísticas

session_start();
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
if (!in_array($perfil, ['ADM', 'FIN'])) {
    header('Location: dashboard.php?erro=permissao_negada');
    exit;
}

// Buscar estatísticas
$stats = [
    'boletos_pendentes' => 0,
    'valor_pendente' => 0,
    'vencem_hoje' => 0,
    'vencem_48h' => 0,
    'vencem_7d' => 0,
    'total_mes' => 0
];

try {
    $stmt = $pdo->query("SELECT * FROM contab_estatisticas_dashboard()");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Usar valores padrão se houver erro
}

// Buscar parcelas que vencem hoje
$vencem_hoje = [];
try {
    $stmt = $pdo->query("
        SELECT p.*, d.descricao, d.tipo
        FROM contab_parcelas p
        JOIN contab_documentos d ON d.id = p.documento_id
        WHERE p.status = 'pendente' AND p.vencimento = CURRENT_DATE
        ORDER BY p.valor DESC
        LIMIT 5
    ");
    $vencem_hoje = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignorar erro
}

// Buscar documentos recentes
$documentos_recentes = [];
try {
    $stmt = $pdo->query("
        SELECT d.*, COUNT(p.id) as total_parcelas,
               SUM(CASE WHEN p.status = 'pendente' THEN 1 ELSE 0 END) as parcelas_pendentes
        FROM contab_documentos d
        LEFT JOIN contab_parcelas p ON p.documento_id = d.id
        GROUP BY d.id
        ORDER BY d.criado_em DESC
        LIMIT 5
    ");
    $documentos_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignorar erro
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contabilidade Dashboard - Sistema Smile</title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .contab-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .contab-header {
            text-align: center;
            margin-bottom: 40px;
            padding: 30px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border-radius: 16px;
        }
        
        .contab-title {
            font-size: 2.5rem;
            font-weight: 800;
            margin: 0 0 10px 0;
        }
        
        .contab-subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            margin: 0;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border-left: 4px solid #1e40af;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 16px;
            color: #64748b;
        }
        
        .stat-value {
            font-size: 20px;
            font-weight: 600;
            color: #059669;
            margin-top: 5px;
        }
        
        .sections-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }
        
        .section-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb;
        }
        
        .section-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .section-icon {
            font-size: 32px;
            margin-right: 15px;
        }
        
        .section-title {
            font-size: 24px;
            font-weight: 700;
            color: #1e40af;
            margin: 0;
        }
        
        .section-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .action-btn {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            background: #f8fafc;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            text-decoration: none;
            color: #374151;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .action-btn:hover {
            background: #1e40af;
            color: white;
            border-color: #1e40af;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
        }
        
        .action-btn-icon {
            font-size: 20px;
            margin-right: 10px;
        }
        
        .action-btn-text {
            flex: 1;
        }
        
        .action-btn-arrow {
            font-size: 16px;
            opacity: 0.7;
        }
        
        .recent-items {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .recent-items h4 {
            color: #374151;
            margin: 0 0 15px 0;
            font-size: 16px;
        }
        
        .recent-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .recent-item:last-child {
            border-bottom: none;
        }
        
        .recent-item-info {
            flex: 1;
        }
        
        .recent-item-name {
            font-weight: 500;
            color: #374151;
        }
        
        .recent-item-meta {
            font-size: 12px;
            color: #64748b;
        }
        
        .recent-item-action {
            font-size: 12px;
            color: #1e40af;
            text-decoration: none;
        }
        
        .recent-item-action:hover {
            text-decoration: underline;
        }
        
        .urgent-badge {
            background: #fee2e2;
            color: #991b1b;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="contab-container">
        <!-- Header -->
        <div class="contab-header">
            <h1 class="contab-title">📑 Contabilidade</h1>
            <p class="contab-subtitle">Central de gestão de documentos e boletos</p>
        </div>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['boletos_pendentes'] ?></div>
                <div class="stat-label">Boletos Pendentes</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['vencem_hoje'] ?></div>
                <div class="stat-label">Vencem Hoje</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['vencem_48h'] ?></div>
                <div class="stat-label">Vencem em 48h</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['vencem_7d'] ?></div>
                <div class="stat-label">Vencem em 7 dias</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">R$ <?= number_format($stats['valor_pendente'], 2, ',', '.') ?></div>
                <div class="stat-label">Valor Total Pendente</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">R$ <?= number_format($stats['total_mes'], 2, ',', '.') ?></div>
                <div class="stat-label">Pago no Mês</div>
            </div>
        </div>
        
        <!-- Seções Principais -->
        <div class="sections-grid">
            <!-- Seção de Documentos -->
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon">📄</div>
                    <div>
                        <h2 class="section-title">Documentos</h2>
                    </div>
                </div>
                <div class="section-actions">
                    <a href="contab_documentos.php" class="action-btn">
                        <span class="action-btn-icon">📋</span>
                        <span class="action-btn-text">Lista de Documentos</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="contab_documentos.php?filtro=pendente" class="action-btn">
                        <span class="action-btn-icon">⏰</span>
                        <span class="action-btn-text">Pendentes</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                </div>
            </div>
            
            <!-- Seção de Portal -->
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon">🔗</div>
                    <div>
                        <h2 class="section-title">Portal Contábil</h2>
                    </div>
                </div>
                <div class="section-actions">
                    <a href="contab_link.php" class="action-btn">
                        <span class="action-btn-icon">🌐</span>
                        <span class="action-btn-text">Acessar Portal</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="configuracoes.php?secao=contabilidade" class="action-btn">
                        <span class="action-btn-icon">⚙️</span>
                        <span class="action-btn-text">Configurar</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Vencem Hoje -->
        <?php if (!empty($vencem_hoje)): ?>
        <div class="section-card">
            <div class="section-header">
                <div class="section-icon">⚠️</div>
                <div>
                    <h2 class="section-title">Vencem Hoje</h2>
                </div>
            </div>
            <div class="recent-items">
                <h4>🚨 Parcelas que vencem hoje</h4>
                <?php foreach ($vencem_hoje as $parcela): ?>
                <div class="recent-item">
                    <div class="recent-item-info">
                        <div class="recent-item-name">
                            <?= htmlspecialchars($parcela['descricao']) ?>
                            <span class="urgent-badge">URGENTE</span>
                        </div>
                        <div class="recent-item-meta">
                            <?= $parcela['tipo'] ?> • 
                            R$ <?= number_format($parcela['valor'], 2, ',', '.') ?> • 
                            Parcela <?= $parcela['numero_parcela'] ?>/<?= $parcela['total_parcelas'] ?>
                        </div>
                    </div>
                    <a href="contab_doc_ver.php?id=<?= $parcela['documento_id'] ?>" class="recent-item-action">
                        Ver →
                    </a>
                </div>
                <?php endforeach; ?>
                <div style="text-align: center; margin-top: 15px;">
                    <a href="contab_documentos.php?filtro=vencimento&data=hoje" class="smile-btn smile-btn-primary">
                        Ver Todas as Parcelas de Hoje
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Documentos Recentes -->
        <?php if (!empty($documentos_recentes)): ?>
        <div class="section-card">
            <div class="section-header">
                <div class="section-icon">📄</div>
                <div>
                    <h2 class="section-title">Documentos Recentes</h2>
                </div>
            </div>
            <div class="recent-items">
                <h4>📋 Últimos documentos cadastrados</h4>
                <?php foreach ($documentos_recentes as $documento): ?>
                <div class="recent-item">
                    <div class="recent-item-info">
                        <div class="recent-item-name"><?= htmlspecialchars($documento['descricao']) ?></div>
                        <div class="recent-item-meta">
                            <?= $documento['tipo'] ?> • 
                            <?= $documento['competencia'] ?> • 
                            <?= $documento['parcelas_pendentes'] ?>/<?= $documento['total_parcelas'] ?> pendentes • 
                            <?= date('d/m/Y H:i', strtotime($documento['criado_em'])) ?>
                        </div>
                    </div>
                    <a href="contab_doc_ver.php?id=<?= $documento['id'] ?>" class="recent-item-action">
                        Ver →
                    </a>
                </div>
                <?php endforeach; ?>
                <div style="text-align: center; margin-top: 15px;">
                    <a href="contab_documentos.php" class="smile-btn smile-btn-primary">
                        Ver Todos os Documentos
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
