<?php
// dashboard_moderno.php — Dashboard reorganizado com apenas itens principais
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$nomeUser = isset($_SESSION['nome']) ? $_SESSION['nome'] : 'Usuário';
$usuario_id = $_SESSION['user_id'] ?? 1;

// Buscar métricas principais
$stats = [];
try {
    // Métricas básicas
    $stats['usuarios'] = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = true")->fetchColumn();
    $stats['fornecedores'] = $pdo->query("SELECT COUNT(*) FROM fornecedores WHERE ativo = true")->fetchColumn();
    
    // Métricas de compras
    if (lc_can_access_module('compras')) {
        $stats['listas_ativas'] = $pdo->query("SELECT COUNT(*) FROM lc_listas WHERE status = 'ativa'")->fetchColumn();
        $stats['insumos_ativos'] = $pdo->query("SELECT COUNT(*) FROM lc_insumos WHERE ativo = true")->fetchColumn();
    }
    
    // Métricas de estoque
    if (lc_can_access_module('estoque')) {
        $stats['contagens_abertas'] = $pdo->query("SELECT COUNT(*) FROM estoque_contagens WHERE status = 'aberta'")->fetchColumn();
    }
    
    // Métricas de pagamentos
    if (lc_can_access_module('pagamentos')) {
        $stats['solicitacoes_pendentes'] = $pdo->query("SELECT COUNT(*) FROM lc_solicitacoes_pagamento WHERE status = 'aguardando'")->fetchColumn();
    }
    
    // Métricas de comercial
    if (lc_can_access_module('comercial')) {
        $stats['degustacoes_ativas'] = $pdo->query("SELECT COUNT(*) FROM comercial_degustacoes WHERE status = 'publicado'")->fetchColumn();
    }
    
} catch (Exception $e) {
    $stats = [
        'usuarios' => 0,
        'fornecedores' => 0,
        'listas_ativas' => 0,
        'insumos_ativos' => 0,
        'contagens_abertas' => 0,
        'solicitacoes_pendentes' => 0,
        'degustacoes_ativas' => 0
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - GRUPO Smile EVENTOS</title>
    <link rel="stylesheet" href="estilo.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }
        
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .dashboard-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        }
        
        .dashboard-title {
            font-size: 2.5rem;
            font-weight: 800;
            color: #1e3a8a;
            margin-bottom: 10px;
            text-align: center;
        }
        
        .dashboard-subtitle {
            font-size: 1.2rem;
            color: #6b7280;
            text-align: center;
            margin-bottom: 30px;
        }
        
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .module-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .module-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
        }
        
        .module-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .module-icon {
            font-size: 2.5rem;
            margin-right: 15px;
        }
        
        .module-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0;
        }
        
        .module-description {
            color: #6b7280;
            margin-bottom: 20px;
            line-height: 1.6;
        }
        
        .module-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-item {
            background: rgba(59, 130, 246, 0.1);
            padding: 15px;
            border-radius: 12px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e3a8a;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #6b7280;
        }
        
        .module-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #6b7280 0%, #9ca3af 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(107, 114, 128, 0.3);
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(107, 114, 128, 0.4);
        }
        
        .agenda-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        }
        
        .agenda-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1e3a8a;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .agenda-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .agenda-item {
            background: rgba(59, 130, 246, 0.1);
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 10px;
            border-left: 4px solid #3b82f6;
        }
        
        .agenda-time {
            font-weight: 600;
            color: #1e3a8a;
            margin-bottom: 5px;
        }
        
        .agenda-title-text {
            color: #374151;
            font-weight: 500;
        }
        
        .no-events {
            text-align: center;
            color: #6b7280;
            font-style: italic;
            padding: 20px;
        }
        
        @media (max-width: 768px) {
            .modules-grid {
                grid-template-columns: 1fr;
            }
            
            .module-stats {
                grid-template-columns: 1fr;
            }
            
            .module-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <h1 class="dashboard-title">🏠 Dashboard Principal</h1>
            <p class="dashboard-subtitle">Bem-vindo, <?= h($nomeUser) ?>! Gerencie seu sistema de forma eficiente.</p>
        </div>
        
        <div class="modules-grid">
            <!-- Módulo de Compras -->
            <?php if (lc_can_access_module('compras')): ?>
            <div class="module-card">
                <div class="module-header">
                    <div class="module-icon">🛒</div>
                    <h2 class="module-title">Compras</h2>
                </div>
                <p class="module-description">Gerencie listas de compras, insumos e fornecedores.</p>
                <div class="module-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?= $stats['listas_ativas'] ?></div>
                        <div class="stat-label">Listas Ativas</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $stats['insumos_ativos'] ?></div>
                        <div class="stat-label">Insumos</div>
                    </div>
                </div>
                <div class="module-actions">
                    <a href="lc_index.php" class="btn btn-primary">📋 Listas de Compras</a>
                    <a href="config_insumos.php" class="btn btn-secondary">⚙️ Configurar</a>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Módulo de Estoque -->
            <?php if (lc_can_access_module('estoque')): ?>
            <div class="module-card">
                <div class="module-header">
                    <div class="module-icon">📦</div>
                    <h2 class="module-title">Estoque</h2>
                </div>
                <p class="module-description">Controle de estoque, contagens e movimentações.</p>
                <div class="module-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?= $stats['contagens_abertas'] ?></div>
                        <div class="stat-label">Contagens Abertas</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">-</div>
                        <div class="stat-label">Em Breve</div>
                    </div>
                </div>
                <div class="module-actions">
                    <a href="estoque_logistico.php" class="btn btn-primary">📊 Controle de Estoque</a>
                    <a href="#" class="btn btn-secondary">📈 Relatórios</a>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Módulo de Pagamentos -->
            <?php if (lc_can_access_module('pagamentos')): ?>
            <div class="module-card">
                <div class="module-header">
                    <div class="module-icon">💳</div>
                    <h2 class="module-title">Pagamentos</h2>
                </div>
                <p class="module-description">Solicitações de pagamento e gestão financeira.</p>
                <div class="module-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?= $stats['solicitacoes_pendentes'] ?></div>
                        <div class="stat-label">Pendentes</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">-</div>
                        <div class="stat-label">Em Breve</div>
                    </div>
                </div>
                <div class="module-actions">
                    <a href="pagamentos.php" class="btn btn-primary">💰 Solicitações</a>
                    <a href="fornecedores.php" class="btn btn-secondary">🏢 Fornecedores</a>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Módulo Comercial -->
            <?php if (lc_can_access_module('comercial')): ?>
            <div class="module-card">
                <div class="module-header">
                    <div class="module-icon">🎯</div>
                    <h2 class="module-title">Comercial</h2>
                </div>
                <p class="module-description">Degustações, eventos e gestão comercial.</p>
                <div class="module-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?= $stats['degustacoes_ativas'] ?></div>
                        <div class="stat-label">Degustações</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">-</div>
                        <div class="stat-label">Em Breve</div>
                    </div>
                </div>
                <div class="module-actions">
                    <a href="comercial_degustacoes.php" class="btn btn-primary">🍷 Degustações</a>
                    <a href="comercial_clientes.php" class="btn btn-secondary">👥 Clientes</a>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Módulo de Usuários -->
            <?php if (lc_can_access_module('usuarios')): ?>
            <div class="module-card">
                <div class="module-header">
                    <div class="module-icon">👥</div>
                    <h2 class="module-title">Usuários</h2>
                </div>
                <p class="module-description">Gestão de usuários e permissões do sistema.</p>
                <div class="module-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?= $stats['usuarios'] ?></div>
                        <div class="stat-label">Usuários</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $stats['fornecedores'] ?></div>
                        <div class="stat-label">Fornecedores</div>
                    </div>
                </div>
                <div class="module-actions">
                    <a href="usuarios.php" class="btn btn-primary">👤 Usuários</a>
                    <a href="configuracoes.php" class="btn btn-secondary">⚙️ Configurações</a>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Seção de Agenda (se disponível) -->
        <?php if (lc_can_access_module('agenda')): ?>
        <div class="agenda-section">
            <h2 class="agenda-title">📅 Agenda do Dia</h2>
            <ul class="agenda-list">
                <li class="agenda-item">
                    <div class="agenda-time">09:00</div>
                    <div class="agenda-title-text">Reunião de planejamento</div>
                </li>
                <li class="agenda-item">
                    <div class="agenda-time">14:00</div>
                    <div class="agenda-title-text">Apresentação para cliente</div>
                </li>
                <li class="agenda-item">
                    <div class="agenda-time">16:30</div>
                    <div class="agenda-title-text">Revisão de estoque</div>
                </li>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
