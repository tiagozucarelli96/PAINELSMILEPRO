<?php
// rh_dashboard.php
// Hub do RH - Dashboard com cards e estatísticas

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
if (!in_array($perfil, ['ADM', 'FIN'])) {
    header('Location: dashboard.php?erro=permissao_negada');
    exit;
}

// Buscar estatísticas
$stats = [
    'total_colaboradores' => 0,
    'colaboradores_ativos' => 0,
    'holerites_mes_atual' => 0,
    'documentos_vencendo' => 0
];

try {
    $stmt = $pdo->query("SELECT * FROM rh_estatisticas_dashboard()");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Usar valores padrão se houver erro
}

// Buscar holerites recentes
$holerites_recentes = [];
try {
    $stmt = $pdo->query("
        SELECT h.id, h.mes_competencia, h.valor_liquido, h.criado_em,
               u.nome as colaborador_nome
        FROM rh_holerites h
        JOIN usuarios u ON u.id = h.usuario_id
        ORDER BY h.criado_em DESC
        LIMIT 5
    ");
    $holerites_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignorar erro
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RH Dashboard - Sistema Smile</title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .rh-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .rh-header {
            text-align: center;
            margin-bottom: 40px;
            padding: 30px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border-radius: 16px;
        }
        
        .rh-title {
            font-size: 2.5rem;
            font-weight: 800;
            margin: 0 0 10px 0;
        }
        
        .rh-subtitle {
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
    </style>
</head>
<body>
    <div class="rh-container">
        <!-- Header -->
        <div class="rh-header">
            <h1 class="rh-title">👥 RH Dashboard</h1>
            <p class="rh-subtitle">Central de gestão de recursos humanos e holerites</p>
        </div>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['total_colaboradores'] ?></div>
                <div class="stat-label">Total de Colaboradores</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['colaboradores_ativos'] ?></div>
                <div class="stat-label">Colaboradores Ativos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['holerites_mes_atual'] ?></div>
                <div class="stat-label">Holerites do Mês</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['documentos_vencendo'] ?></div>
                <div class="stat-label">Documentos Recentes</div>
            </div>
        </div>
        
        <!-- Seções Principais -->
        <div class="sections-grid">
            <!-- Seção de Colaboradores -->
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon">👥</div>
                    <div>
                        <h2 class="section-title">Colaboradores</h2>
                    </div>
                </div>
                <div class="section-actions">
                    <a href="rh_colaboradores.php" class="action-btn">
                        <span class="action-btn-icon">👤</span>
                        <span class="action-btn-text">Lista de Colaboradores</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="usuarios.php" class="action-btn">
                        <span class="action-btn-icon">➕</span>
                        <span class="action-btn-text">Novo Colaborador</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                </div>
            </div>
            
            <!-- Seção de Holerites -->
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon">💰</div>
                    <div>
                        <h2 class="section-title">Holerites</h2>
                    </div>
                </div>
                <div class="section-actions">
                    <a href="rh_holerite_upload.php" class="action-btn">
                        <span class="action-btn-icon">📤</span>
                        <span class="action-btn-text">Lançar Holerites</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="rh_colaboradores.php" class="action-btn">
                        <span class="action-btn-icon">📋</span>
                        <span class="action-btn-text">Gerenciar Holerites</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Holerites Recentes -->
        <?php if (!empty($holerites_recentes)): ?>
        <div class="section-card">
            <div class="section-header">
                <div class="section-icon">📄</div>
                <div>
                    <h2 class="section-title">Holerites Recentes</h2>
                </div>
            </div>
            <div class="recent-items">
                <h4>📋 Últimos Holerites Lançados</h4>
                <?php foreach ($holerites_recentes as $holerite): ?>
                <div class="recent-item">
                    <div class="recent-item-info">
                        <div class="recent-item-name"><?= htmlspecialchars($holerite['colaborador_nome']) ?></div>
                        <div class="recent-item-meta">
                            <?= $holerite['mes_competencia'] ?> • 
                            <?= $holerite['valor_liquido'] ? 'R$ ' . number_format($holerite['valor_liquido'], 2, ',', '.') : 'Valor não informado' ?> • 
                            <?= date('d/m/Y H:i', strtotime($holerite['criado_em'])) ?>
                        </div>
                    </div>
                    <a href="rh_colaborador_ver.php?id=<?= $holerite['id'] ?>" class="recent-item-action">
                        Ver →
                    </a>
                </div>
                <?php endforeach; ?>
                <div style="text-align: center; margin-top: 15px;">
                    <a href="rh_colaboradores.php" class="smile-btn smile-btn-primary">
                        Ver Todos os Holerites
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
