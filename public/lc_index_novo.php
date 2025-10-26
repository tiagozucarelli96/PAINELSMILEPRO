<?php
// lc_index_novo.php
// Página principal reorganizada do sistema

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
$isAdmin = in_array($perfil, ['ADM', 'FIN']);

// Buscar estatísticas
$stats = [
    'fornecedores' => 0,
    'solicitacoes_pendentes' => 0,
    'contagens_abertas' => 0,
    'listas_recentes' => 0
];

try {
    // Total de fornecedores ativos
    $stmt = $pdo->query("SELECT COUNT(*) FROM fornecedores WHERE ativo = true");
    $stats['fornecedores'] = $stmt->fetchColumn();
    
    // Solicitações pendentes
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_solicitacoes_pagamento WHERE status = 'aguardando'");
    $stats['solicitacoes_pendentes'] = $stmt->fetchColumn();
    
    // Contagens abertas
    $stmt = $pdo->query("SELECT COUNT(*) FROM estoque_contagens WHERE status = 'rascunho'");
    $stats['contagens_abertas'] = $stmt->fetchColumn();
    
    // Listas recentes (últimos 7 dias)
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_listas WHERE data_gerada >= NOW() - INTERVAL '7 days'");
    $stats['listas_recentes'] = $stmt->fetchColumn();
    
} catch (Exception $e) {
    // Usar valores padrão se houver erro
}

// Buscar fornecedores recentes
$fornecedores_recentes = [];
try {
    $stmt = $pdo->query("
        SELECT id, nome, telefone, email, criado_em 
        FROM fornecedores 
        WHERE ativo = true 
        ORDER BY criado_em DESC 
        LIMIT 5
    ");
    $fornecedores_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignorar erro
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Compras - Sistema Smile</title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .dashboard-header {
            text-align: center;
            margin-bottom: 40px;
            padding: 30px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border-radius: 16px;
        }
        
        .dashboard-title {
            font-size: 2.5rem;
            font-weight: 800;
            margin: 0 0 10px 0;
        }
        
        .dashboard-subtitle {
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
        
        .section-description {
            color: #64748b;
            margin: 5px 0 0 0;
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
        
        .quick-actions {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 1px solid #0ea5e9;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
        }
        
        .quick-actions h3 {
            color: #0c4a6e;
            margin: 0 0 20px 0;
            font-size: 20px;
        }
        
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .quick-btn {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            background: white;
            border: 1px solid #0ea5e9;
            border-radius: 8px;
            text-decoration: none;
            color: #0c4a6e;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .quick-btn:hover {
            background: #0ea5e9;
            color: white;
        }
        
        .quick-btn-icon {
            font-size: 18px;
            margin-right: 10px;
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
    <div class="dashboard-container">
        <!-- Header -->
        <div class="dashboard-header">
            <h1 class="dashboard-title">🏢 Gestão de Compras</h1>
            <p class="dashboard-subtitle">Central de controle para compras, estoque e pagamentos</p>
        </div>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['fornecedores'] ?></div>
                <div class="stat-label">Fornecedores Ativos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['solicitacoes_pendentes'] ?></div>
                <div class="stat-label">Solicitações Pendentes</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['contagens_abertas'] ?></div>
                <div class="stat-label">Contagens Abertas</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['listas_recentes'] ?></div>
                <div class="stat-label">Listas Recentes</div>
            </div>
        </div>
        
        <!-- Ações Rápidas -->
        <div class="quick-actions">
            <h3>⚡ Ações Rápidas</h3>
            <div class="quick-actions-grid">
                <a href="lista_compras.php" class="quick-btn">
                    <span class="quick-btn-icon">📝</span>
                    Gerar Lista de Compras
                </a>
                <a href="pagamentos_solicitar.php" class="quick-btn">
                    <span class="quick-btn-icon">💰</span>
                    Solicitar Pagamento
                </a>
                <a href="estoque_contagens.php" class="quick-btn">
                    <span class="quick-btn-icon">📊</span>
                    Nova Contagem
                </a>
                <a href="fornecedores.php" class="quick-btn">
                    <span class="quick-btn-icon">🏢</span>
                    Cadastrar Fornecedor
                </a>
            </div>
        </div>
        
        <!-- Seções Principais -->
        <div class="sections-grid">
            <!-- Seção de Compras -->
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon">🛒</div>
                    <div>
                        <h2 class="section-title">Compras</h2>
                        <p class="section-description">Gerar e gerenciar listas de compras</p>
                    </div>
                </div>
                <div class="section-actions">
                    <a href="lista_compras.php" class="action-btn">
                        <span class="action-btn-icon">📝</span>
                        <span class="action-btn-text">Gerar Lista</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="historico.php" class="action-btn">
                        <span class="action-btn-icon">📋</span>
                        <span class="action-btn-text">Histórico</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="fornecedores.php" class="action-btn">
                        <span class="action-btn-icon">🏢</span>
                        <span class="action-btn-text">Fornecedores</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                </div>
            </div>
            
            <!-- Seção de Estoque -->
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon">📦</div>
                    <div>
                        <h2 class="section-title">Estoque</h2>
                        <p class="section-description">Controle de estoque e movimentações</p>
                    </div>
                </div>
                <div class="section-actions">
                    <a href="estoque_contagens.php" class="action-btn">
                        <span class="action-btn-icon">📊</span>
                        <span class="action-btn-text">Contagens</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="estoque_kardex.php" class="action-btn">
                        <span class="action-btn-icon">📒</span>
                        <span class="action-btn-text">Kardex</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="estoque_alertas.php" class="action-btn">
                        <span class="action-btn-icon">🚨</span>
                        <span class="action-btn-text">Alertas</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="estoque_desvios.php" class="action-btn">
                        <span class="action-btn-icon">📈</span>
                        <span class="action-btn-text">Desvios</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                </div>
            </div>
            
            <!-- Seção de Pagamentos -->
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon">💰</div>
                    <div>
                        <h2 class="section-title">Pagamentos</h2>
                        <p class="section-description">Solicitar e gerenciar pagamentos</p>
                    </div>
                </div>
                <div class="section-actions">
                    <a href="pagamentos_solicitar.php" class="action-btn">
                        <span class="action-btn-icon">📝</span>
                        <span class="action-btn-text">Solicitar</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="pagamentos_minhas.php" class="action-btn">
                        <span class="action-btn-icon">📋</span>
                        <span class="action-btn-text">Minhas Solicitações</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <?php if ($isAdmin): ?>
                    <a href="pagamentos_painel.php" class="action-btn">
                        <span class="action-btn-icon">⚡</span>
                        <span class="action-btn-text">Painel Financeiro</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Seção de Configurações -->
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon">⚙️</div>
                    <div>
                        <h2 class="section-title">Configurações</h2>
                        <p class="section-description">Configurar sistema e cadastros</p>
                    </div>
                </div>
                <div class="section-actions">
                    <a href="configuracoes.php" class="action-btn">
                        <span class="action-btn-icon">🔧</span>
                        <span class="action-btn-text">Configurações Gerais</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="fornecedores.php" class="action-btn">
                        <span class="action-btn-icon">🏢</span>
                        <span class="action-btn-text">Fornecedores</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="usuarios.php" class="action-btn">
                        <span class="action-btn-icon">👥</span>
                        <span class="action-btn-text">Usuários</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Fornecedores Recentes -->
        <?php if (!empty($fornecedores_recentes)): ?>
        <div class="section-card">
            <div class="section-header">
                <div class="section-icon">🏢</div>
                <div>
                    <h2 class="section-title">Fornecedores Recentes</h2>
                    <p class="section-description">Últimos fornecedores cadastrados</p>
                </div>
            </div>
            <div class="recent-items">
                <h4>📋 Lista de Fornecedores</h4>
                <?php foreach ($fornecedores_recentes as $fornecedor): ?>
                <div class="recent-item">
                    <div class="recent-item-info">
                        <div class="recent-item-name"><?= htmlspecialchars($fornecedor['nome']) ?></div>
                        <div class="recent-item-meta">
                            <?= htmlspecialchars($fornecedor['telefone'] ?: $fornecedor['email']) ?> • 
                            <?= date('d/m/Y', strtotime($fornecedor['criado_em'])) ?>
                        </div>
                    </div>
                    <a href="fornecedor_ver.php?id=<?= $fornecedor['id'] ?>" class="recent-item-action">
                        Ver →
                    </a>
                </div>
                <?php endforeach; ?>
                <div style="text-align: center; margin-top: 15px;">
                    <a href="fornecedores.php" class="smile-btn smile-btn-primary">
                        Ver Todos os Fornecedores
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
