<?php
// configuracoes_novo.php
// Página de configurações reorganizada

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
    'categorias' => 0,
    'insumos' => 0,
    'unidades' => 0,
    'fornecedores' => 0,
    'usuarios' => 0,
    'freelancers' => 0
];

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_categorias WHERE ativo = true");
    $stats['categorias'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_insumos WHERE ativo = true");
    $stats['insumos'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_unidades WHERE ativo = true");
    $stats['unidades'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM fornecedores WHERE ativo = true");
    $stats['fornecedores'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = true");
    $stats['usuarios'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_freelancers WHERE ativo = true");
    $stats['freelancers'] = $stmt->fetchColumn();
    
} catch (Exception $e) {
    // Usar valores padrão
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações - Sistema Smile</title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .config-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .config-header {
            text-align: center;
            margin-bottom: 40px;
            padding: 30px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border-radius: 16px;
        }
        
        .config-title {
            font-size: 2.5rem;
            font-weight: 800;
            margin: 0 0 10px 0;
        }
        
        .config-subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            margin: 0;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border-left: 4px solid #1e40af;
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
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
    </style>
</head>
<body>
    <div class="config-container">
        <!-- Header -->
        <div class="config-header">
            <h1 class="config-title">⚙️ Configurações</h1>
            <p class="config-subtitle">Central de administração e configuração do sistema</p>
        </div>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['categorias'] ?></div>
                <div class="stat-label">Categorias</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['insumos'] ?></div>
                <div class="stat-label">Insumos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['unidades'] ?></div>
                <div class="stat-label">Unidades</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['fornecedores'] ?></div>
                <div class="stat-label">Fornecedores</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['usuarios'] ?></div>
                <div class="stat-label">Usuários</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['freelancers'] ?></div>
                <div class="stat-label">Freelancers</div>
            </div>
        </div>
        
        <!-- Ações Rápidas -->
        <div class="quick-actions">
            <h3>⚡ Ações Rápidas</h3>
            <div class="quick-actions-grid">
                <a href="config_categorias.php" class="quick-btn">
                    <span class="quick-btn-icon">📂</span>
                    Gerenciar Categorias
                </a>
                <a href="config_insumos.php" class="quick-btn">
                    <span class="quick-btn-icon">📦</span>
                    Gerenciar Insumos
                </a>
                <a href="fornecedores.php" class="quick-btn">
                    <span class="quick-btn-icon">🏢</span>
                    Gerenciar Fornecedores
                </a>
                <a href="usuarios.php" class="quick-btn">
                    <span class="quick-btn-icon">👥</span>
                    Gerenciar Usuários
                </a>
            </div>
        </div>
        
        <!-- Seções Principais -->
        <div class="sections-grid">
            <!-- Seção de Cadastros Básicos -->
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon">📋</div>
                    <div>
                        <h2 class="section-title">Cadastros Básicos</h2>
                        <p class="section-description">Categorias, insumos e unidades</p>
                    </div>
                </div>
                <div class="section-actions">
                    <a href="config_categorias.php" class="action-btn">
                        <span class="action-btn-icon">📂</span>
                        <span class="action-btn-text">Categorias</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="config_insumos.php" class="action-btn">
                        <span class="action-btn-icon">📦</span>
                        <span class="action-btn-text">Insumos</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="config_unidades.php" class="action-btn">
                        <span class="action-btn-icon">📏</span>
                        <span class="action-btn-text">Unidades</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="config_fornecedores.php" class="action-btn">
                        <span class="action-btn-icon">🏢</span>
                        <span class="action-btn-text">Fornecedores</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                </div>
            </div>
            
            <!-- Seção de Usuários e Permissões -->
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon">👥</div>
                    <div>
                        <h2 class="section-title">Usuários e Permissões</h2>
                        <p class="section-description">Gerenciar usuários e permissões</p>
                    </div>
                </div>
                <div class="section-actions">
                    <a href="usuarios.php" class="action-btn">
                        <span class="action-btn-icon">👤</span>
                        <span class="action-btn-text">Usuários</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="permissoes.php" class="action-btn">
                        <span class="action-btn-icon">🔐</span>
                        <span class="action-btn-text">Permissões</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="perfis.php" class="action-btn">
                        <span class="action-btn-icon">👔</span>
                        <span class="action-btn-text">Perfis</span>
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
                        <p class="section-description">Configurar sistema de pagamentos</p>
                    </div>
                </div>
                <div class="section-actions">
                    <a href="pagamentos_painel.php" class="action-btn">
                        <span class="action-btn-icon">⚡</span>
                        <span class="action-btn-text">Painel Financeiro</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="freelancers.php" class="action-btn">
                        <span class="action-btn-icon">👨‍💼</span>
                        <span class="action-btn-text">Freelancers</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="fornecedores.php" class="action-btn">
                        <span class="action-btn-icon">🏢</span>
                        <span class="action-btn-text">Fornecedores</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                </div>
            </div>
            
            <!-- Seção de RH -->
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon">👥</div>
                    <div>
                        <h2 class="section-title">RH</h2>
                        <p class="section-description">Gestão de recursos humanos</p>
                    </div>
                </div>
                <div class="section-actions">
                    <a href="rh_dashboard.php" class="action-btn">
                        <span class="action-btn-icon">📊</span>
                        <span class="action-btn-text">Dashboard RH</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="rh_colaboradores.php" class="action-btn">
                        <span class="action-btn-icon">👤</span>
                        <span class="action-btn-text">Colaboradores</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="rh_holerite_upload.php" class="action-btn">
                        <span class="action-btn-icon">💰</span>
                        <span class="action-btn-text">Lançar Holerites</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                </div>
            </div>
            
            <!-- Seção de Contabilidade -->
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon">📑</div>
                    <div>
                        <h2 class="section-title">Contabilidade</h2>
                        <p class="section-description">Gestão de documentos e boletos</p>
                    </div>
                </div>
                <div class="section-actions">
                    <a href="contab_dashboard.php" class="action-btn">
                        <span class="action-btn-icon">📊</span>
                        <span class="action-btn-text">Dashboard Contábil</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="contab_documentos.php" class="action-btn">
                        <span class="action-btn-icon">📄</span>
                        <span class="action-btn-text">Documentos</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="contab_link.php" class="action-btn">
                        <span class="action-btn-icon">🔗</span>
                        <span class="action-btn-text">Portal Contábil</span>
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
                        <p class="section-description">Configurar sistema de estoque</p>
                    </div>
                </div>
                <div class="section-actions">
                    <a href="estoque_contagens.php" class="action-btn">
                        <span class="action-btn-icon">📊</span>
                        <span class="action-btn-text">Contagens</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="estoque_alertas.php" class="action-btn">
                        <span class="action-btn-icon">🚨</span>
                        <span class="action-btn-text">Alertas</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="estoque_kardex.php" class="action-btn">
                        <span class="action-btn-icon">📒</span>
                        <span class="action-btn-text">Kardex</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="estoque_desvios.php" class="action-btn">
                        <span class="action-btn-icon">📈</span>
                        <span class="action-btn-text">Desvios</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                </div>
            </div>
            
            <!-- Seção de Sistema -->
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon">🔧</div>
                    <div>
                        <h2 class="section-title">Sistema</h2>
                        <p class="section-description">Configurações avançadas do sistema</p>
                    </div>
                </div>
                <div class="section-actions">
                    <a href="config_avancadas.php" class="action-btn">
                        <span class="action-btn-icon">⚙️</span>
                        <span class="action-btn-text">Configurações Avançadas</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="logs.php" class="action-btn">
                        <span class="action-btn-icon">📋</span>
                        <span class="action-btn-text">Logs do Sistema</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="backup.php" class="action-btn">
                        <span class="action-btn-icon">💾</span>
                        <span class="action-btn-text">Backup</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                </div>
            </div>
            
            <!-- Seção de Relatórios -->
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon">📊</div>
                    <div>
                        <h2 class="section-title">Relatórios</h2>
                        <p class="section-description">Relatórios e análises do sistema</p>
                    </div>
                </div>
                <div class="section-actions">
                    <a href="relatorios_compras.php" class="action-btn">
                        <span class="action-btn-icon">🛒</span>
                        <span class="action-btn-text">Relatórios de Compras</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="relatorios_estoque.php" class="action-btn">
                        <span class="action-btn-icon">📦</span>
                        <span class="action-btn-text">Relatórios de Estoque</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                    <a href="relatorios_pagamentos.php" class="action-btn">
                        <span class="action-btn-icon">💰</span>
                        <span class="action-btn-text">Relatórios de Pagamentos</span>
                        <span class="action-btn-arrow">→</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
