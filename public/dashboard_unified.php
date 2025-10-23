<?php
// dashboard_unified.php — Dashboard centralizado unificado
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_unified.php';

// Migrar permissões se necessário
lc_migrate_permissions($pdo);

$nomeUser = $_SESSION['nome'] ?? $_SESSION['usuario'] ?? 'Usuário';
$perfil = lc_get_user_perfil();

// Buscar métricas do banco de dados
$stats = [
    'categorias' => 0,
    'insumos' => 0,
    'receitas' => 0,
    'unidades' => 0,
    'usuarios' => 0,
    'fornecedores' => 0,
    'solicitacoes_pendentes' => 0,
    'contagens_abertas' => 0,
    'holerites_mes' => 0,
    'documentos_pendentes' => 0
];

try {
    // Métricas básicas
    $stats['categorias'] = $pdo->query("SELECT COUNT(*) FROM lc_categorias WHERE ativo = true")->fetchColumn();
    $stats['insumos'] = $pdo->query("SELECT COUNT(*) FROM lc_insumos WHERE ativo = true")->fetchColumn();
    $stats['receitas'] = $pdo->query("SELECT COUNT(*) FROM lc_receitas WHERE ativo = true")->fetchColumn();
    $stats['unidades'] = $pdo->query("SELECT COUNT(*) FROM lc_unidades WHERE ativo = true")->fetchColumn();
    $stats['usuarios'] = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = true")->fetchColumn();
    $stats['fornecedores'] = $pdo->query("SELECT COUNT(*) FROM fornecedores WHERE ativo = true")->fetchColumn();
    
    // Métricas de pagamentos
    if (lc_can_access_module('pagamentos')) {
        $stats['solicitacoes_pendentes'] = $pdo->query("
            SELECT COUNT(*) FROM pagamentos_solicitacoes 
            WHERE status = 'aguardando_analise'
        ")->fetchColumn();
    }
    
    // Métricas de estoque
    if (lc_can_access_module('estoque')) {
        $stats['contagens_abertas'] = $pdo->query("
            SELECT COUNT(*) FROM estoque_contagens 
            WHERE status = 'aberta'
        ")->fetchColumn();
    }
    
    // Métricas de RH
    if (lc_can_access_module('rh')) {
        $stats['holerites_mes'] = $pdo->query("
            SELECT COUNT(*) FROM rh_holerites 
            WHERE mes_competencia = DATE_FORMAT(NOW(), '%Y-%m')
        ")->fetchColumn();
    }
    
    // Métricas de Contabilidade
    if (lc_can_access_module('contabilidade')) {
        $stats['documentos_pendentes'] = $pdo->query("
            SELECT COUNT(*) FROM contab_documentos 
            WHERE status = 'pendente'
        ")->fetchColumn();
    }
    
} catch (Exception $e) {
    // Em caso de erro, usar valores padrão
    error_log("Erro ao buscar métricas: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - GRUPO Smile EVENTOS</title>
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .dashboard-card {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .dashboard-card h3 {
            margin: 0 0 10px 0;
            font-size: 18px;
            font-weight: 600;
        }
        
        .dashboard-card .value {
            font-size: 32px;
            font-weight: 700;
            margin: 10px 0;
        }
        
        .dashboard-card .description {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .quick-action {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            text-decoration: none;
            color: #374151;
            transition: all 0.2s;
        }
        
        .quick-action:hover {
            background: #f9fafb;
            border-color: #3b82f6;
            transform: translateY(-2px);
        }
        
        .quick-action-icon {
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        .quick-action-title {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .quick-action-desc {
            font-size: 14px;
            color: #6b7280;
        }
        
        .welcome-section {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
        }
        
        .welcome-section h1 {
            margin: 0 0 10px 0;
            font-size: 28px;
            font-weight: 700;
        }
        
        .welcome-section p {
            margin: 0;
            font-size: 16px;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <?php include 'sidebar_unified.php'; ?>
    
    <div class="main-content">
        <!-- Seção de Boas-vindas -->
        <div class="welcome-section">
            <h1>Bem-vindo, <?= htmlspecialchars($nomeUser) ?>!</h1>
            <p>Dashboard centralizado - Perfil: <?= $perfil ?></p>
        </div>
        
        <!-- Métricas Principais -->
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3>📦 Insumos</h3>
                <div class="value"><?= number_format($stats['insumos']) ?></div>
                <div class="description">Total de insumos ativos</div>
            </div>
            
            <div class="dashboard-card">
                <h3>🏢 Fornecedores</h3>
                <div class="value"><?= number_format($stats['fornecedores']) ?></div>
                <div class="description">Fornecedores cadastrados</div>
            </div>
            
            <div class="dashboard-card">
                <h3>👥 Usuários</h3>
                <div class="value"><?= number_format($stats['usuarios']) ?></div>
                <div class="description">Usuários ativos</div>
            </div>
            
            <?php if (lc_can_access_module('pagamentos') && $stats['solicitacoes_pendentes'] > 0): ?>
            <div class="dashboard-card" style="background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);">
                <h3>💳 Solicitações Pendentes</h3>
                <div class="value"><?= number_format($stats['solicitacoes_pendentes']) ?></div>
                <div class="description">Aguardando análise</div>
            </div>
            <?php endif; ?>
            
            <?php if (lc_can_access_module('estoque') && $stats['contagens_abertas'] > 0): ?>
            <div class="dashboard-card" style="background: linear-gradient(135deg, #ea580c 0%, #f97316 100%);">
                <h3>📦 Contagens Abertas</h3>
                <div class="value"><?= number_format($stats['contagens_abertas']) ?></div>
                <div class="description">Contagens em andamento</div>
            </div>
            <?php endif; ?>
            
            <?php if (lc_can_access_module('rh')): ?>
            <div class="dashboard-card">
                <h3>💰 Holerites</h3>
                <div class="value"><?= number_format($stats['holerites_mes']) ?></div>
                <div class="description">Este mês</div>
            </div>
            <?php endif; ?>
            
            <?php if (lc_can_access_module('contabilidade')): ?>
            <div class="dashboard-card">
                <h3>📄 Documentos</h3>
                <div class="value"><?= number_format($stats['documentos_pendentes']) ?></div>
                <div class="description">Pendentes</div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Ações Rápidas -->
        <h2 style="margin-bottom: 20px; color: #1e3a8a;">Ações Rápidas</h2>
        <div class="quick-actions">
            <?php if (lc_can_access_module('demandas')): ?>
            <a href="lc_index.php" class="quick-action">
                <div class="quick-action-icon">🛒</div>
                <div class="quick-action-title">Lista de Compras</div>
                <div class="quick-action-desc">Gerar nova lista de compras</div>
            </a>
            <?php endif; ?>
            
            <?php if (lc_can_access_module('estoque')): ?>
            <a href="estoque_contagens.php" class="quick-action">
                <div class="quick-action-icon">📦</div>
                <div class="quick-action-title">Controle de Estoque</div>
                <div class="quick-action-desc">Gerenciar contagens e alertas</div>
            </a>
            <?php endif; ?>
            
            <?php if (lc_can_access_module('pagamentos')): ?>
            <a href="pagamentos_solicitar.php" class="quick-action">
                <div class="quick-action-icon">💳</div>
                <div class="quick-action-title">Solicitar Pagamento</div>
                <div class="quick-action-desc">Criar nova solicitação</div>
            </a>
            <?php endif; ?>
            
            <a href="fornecedores.php" class="quick-action">
                <div class="quick-action-icon">🏢</div>
                <div class="quick-action-title">Fornecedores</div>
                <div class="quick-action-desc">Cadastrar e gerenciar fornecedores</div>
            </a>
            
            <?php if (lc_can_access_module('rh')): ?>
            <a href="rh_dashboard.php" class="quick-action">
                <div class="quick-action-icon">👥</div>
                <div class="quick-action-title">Recursos Humanos</div>
                <div class="quick-action-desc">Holerites e colaboradores</div>
            </a>
            <?php endif; ?>
            
            <?php if (lc_can_access_module('contabilidade')): ?>
            <a href="contab_dashboard.php" class="quick-action">
                <div class="quick-action-icon">💰</div>
                <div class="quick-action-title">Contabilidade</div>
                <div class="quick-action-desc">Documentos e parcelas</div>
            </a>
            <?php endif; ?>
            
            <?php if (lc_can_access_module('usuarios')): ?>
            <a href="usuarios.php" class="quick-action">
                <div class="quick-action-icon">👥</div>
                <div class="quick-action-title">Usuários</div>
                <div class="quick-action-desc">Gerenciar usuários e permissões</div>
            </a>
            <?php endif; ?>
            
            <?php if (lc_can_access_module('configuracoes')): ?>
            <a href="configuracoes.php" class="quick-action">
                <div class="quick-action-icon">⚙️</div>
                <div class="quick-action-title">Configurações</div>
                <div class="quick-action-desc">Configurar sistema</div>
            </a>
            <?php endif; ?>
        </div>
        
        <!-- Informações do Sistema -->
        <div style="background: #f8fafc; padding: 20px; border-radius: 8px; margin-top: 30px;">
            <h3 style="margin: 0 0 15px 0; color: #1e3a8a;">Informações do Sistema</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                <div>
                    <strong>Perfil do Usuário:</strong><br>
                    <span style="color: #059669;"><?= $perfil ?></span>
                </div>
                <div>
                    <strong>Último Acesso:</strong><br>
                    <span><?= date('d/m/Y H:i') ?></span>
                </div>
                <div>
                    <strong>Versão:</strong><br>
                    <span>2.0.0</span>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
