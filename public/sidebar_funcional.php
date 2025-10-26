<?php
// sidebar_funcional.php — Sidebar com funções organizadas por macro áreas
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$nomeUser = $_SESSION['nome'] ?? 'Usuário';
$perfil = $_SESSION['perfil'] ?? 'CONSULTA';

// Função para determinar se item está ativo
if (!function_exists('isActiveFuncional')) {
    function isActiveFuncional($page) {
        $current_page = $_GET['page'] ?? 'dashboard';
        return $current_page === $page ? 'active' : '';
    }
}

// Função para determinar se submenu está aberto
if (!function_exists('isSubmenuOpenFuncional')) {
    function isSubmenuOpenFuncional($pages) {
        $current_page = $_GET['page'] ?? 'dashboard';
        return in_array($current_page, $pages) ? 'open' : '';
    }
}

// Função para verificar permissões
function canAccess($required_perfil) {
    global $perfil;
    $hierarchy = ['CONSULTA' => 1, 'GERENTE' => 2, 'ADM' => 3, 'FINANCEIRO' => 3];
    return ($hierarchy[$perfil] ?? 0) >= ($hierarchy[$required_perfil] ?? 0);
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GRUPO Smile EVENTOS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #1e293b;
            overflow-x: hidden;
        }
        
        /* Layout Principal */
        .app-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #1e3a8a 0%, #1e40af 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }
        
        .sidebar-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 0;
        }
        
        .sidebar-logo img {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }
        
        .user-info {
            margin-top: 15px;
            text-align: center;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 8px;
            font-weight: 600;
            font-size: 16px;
        }
        
        .user-name {
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 4px;
        }
        
        .user-plan {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.7);
            background: rgba(255, 255, 255, 0.1);
            padding: 4px 8px;
            border-radius: 12px;
            display: inline-block;
        }
        
        /* Navigation */
        .sidebar-nav {
            padding: 20px 0;
        }
        
        .nav-section {
            margin-bottom: 30px;
        }
        
        .nav-section-title {
            font-size: 12px;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.6);
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 0 20px 10px;
            margin-bottom: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .nav-item {
            display: block;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
            font-size: 14px;
            font-weight: 500;
        }
        
        .nav-item:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: rgba(255, 255, 255, 0.3);
        }
        
        .nav-item.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-left-color: white;
            font-weight: 600;
        }
        
        .nav-item-icon {
            margin-right: 10px;
            font-size: 16px;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
            background: #f8fafc;
        }
        
        /* Responsivo */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <img src="logo-smile.png" alt="Smile EVENTOS">
                </div>
                <div class="user-info">
                    <div class="user-avatar"><?= strtoupper(substr($nomeUser, 0, 2)) ?></div>
                    <div class="user-name"><?= htmlspecialchars($nomeUser) ?></div>
                    <div class="user-plan"><?= strtoupper($perfil) ?></div>
                </div>
            </div>
            
            <nav class="sidebar-nav">
                <!-- Dashboard -->
                <div class="nav-section">
                    <a href="index.php?page=dashboard" class="nav-item <?= isActiveFuncional('dashboard') ?>">
                        <span class="nav-item-icon">🏠</span>
                        Dashboard
                    </a>
                </div>
                
                <!-- Comercial -->
                <div class="nav-section">
                    <div class="nav-section-title">Comercial</div>
                    <a href="index.php?page=comercial_degustacoes" class="nav-item <?= isActiveFuncional('comercial_degustacoes') ?>">
                        <span class="nav-item-icon">📋</span>
                        Contratos & Clientes
                    </a>
                    <a href="index.php?page=comercial_degust_inscricoes" class="nav-item <?= isActiveFuncional('comercial_degust_inscricoes') ?>">
                        <span class="nav-item-icon">💬</span>
                        Comunicação & Propostas
                    </a>
                    <a href="index.php?page=comercial_clientes" class="nav-item <?= isActiveFuncional('comercial_clientes') ?>">
                        <span class="nav-item-icon">📊</span>
                        Indicadores de Vendas
                    </a>
                </div>
                
                <!-- Agenda -->
                <div class="nav-section">
                    <div class="nav-section-title">Agenda</div>
                    <a href="index.php?page=agenda" class="nav-item <?= isActiveFuncional('agenda') ?>">
                        <span class="nav-item-icon">📅</span>
                        Agenda Geral
                    </a>
                    <a href="index.php?page=agenda_config" class="nav-item <?= isActiveFuncional('agenda_config') ?>">
                        <span class="nav-item-icon">✅</span>
                        Checklist dos Eventos
                    </a>
                    <a href="index.php?page=agenda_relatorios" class="nav-item <?= isActiveFuncional('agenda_relatorios') ?>">
                        <span class="nav-item-icon">🏢</span>
                        Espaços & Reservas
                    </a>
                    <a href="index.php?page=demandas" class="nav-item <?= isActiveFuncional('demandas') ?>">
                        <span class="nav-item-icon">⚡</span>
                        Demandas Operacionais
                    </a>
                </div>
                
                <!-- Administrativo -->
                <div class="nav-section">
                    <div class="nav-section-title">Administrativo</div>
                    <a href="index.php?page=usuarios" class="nav-item <?= isActiveFuncional('usuarios') ?>">
                        <span class="nav-item-icon">👥</span>
                        Equipe & Permissões
                    </a>
                    <a href="index.php?page=usuarios" class="nav-item <?= isActiveFuncional('usuarios') ?>">
                        <span class="nav-item-icon">👤</span>
                        Colaboradores
                    </a>
                    <a href="index.php?page=pagamentos" class="nav-item <?= isActiveFuncional('pagamentos') ?>">
                        <span class="nav-item-icon">💰</span>
                        Pagamentos
                    </a>
                    <a href="index.php?page=contab_link" class="nav-item <?= isActiveFuncional('contab_link') ?>">
                        <span class="nav-item-icon">📊</span>
                        Contabilidade
                    </a>
                    <a href="index.php?page=config_fornecedores" class="nav-item <?= isActiveFuncional('config_fornecedores') ?>">
                        <span class="nav-item-icon">🏢</span>
                        Fornecedores
                    </a>
                    <a href="index.php?page=configuracoes" class="nav-item <?= isActiveFuncional('configuracoes') ?>">
                        <span class="nav-item-icon">🎯</span>
                        Metas & Configurações
                    </a>
                </div>
                
                <!-- Logístico -->
                <div class="nav-section">
                    <div class="nav-section-title">Logístico</div>
                    <a href="index.php?page=lc_index" class="nav-item <?= isActiveFuncional('lc_index') ?>">
                        <span class="nav-item-icon">🛒</span>
                        Lista de Compras & Encomendas
                    </a>
                    <a href="index.php?page=estoque_logistico" class="nav-item <?= isActiveFuncional('estoque_logistico') ?>">
                        <span class="nav-item-icon">📦</span>
                        Estoque & Alertas
                    </a>
                    <a href="index.php?page=ver" class="nav-item <?= isActiveFuncional('ver') ?>">
                        <span class="nav-item-icon">📋</span>
                        Separação por Evento
                    </a>
                    <a href="index.php?page=notas_fiscais" class="nav-item <?= isActiveFuncional('notas_fiscais') ?>">
                        <span class="nav-item-icon">📄</span>
                        Entrada por Nota Fiscal
                    </a>
                </div>
                
                <!-- Configurações -->
                <div class="nav-section">
                    <div class="nav-section-title">Configurações</div>
                    <a href="index.php?page=configuracoes" class="nav-item <?= isActiveFuncional('configuracoes') ?>">
                        <span class="nav-item-icon">🔗</span>
                        Integrações
                    </a>
                    <a href="index.php?page=verificacao_completa_erros" class="nav-item <?= isActiveFuncional('verificacao_completa_erros') ?>">
                        <span class="nav-item-icon">🔧</span>
                        Diagnóstico & Manutenção
                    </a>
                </div>
            </nav>
        </div>
        
        <div class="main-content">
            <div id="mainContent">
                <!-- Conteúdo da página será inserido aqui -->
            </div>
        </div>
    </div>
</body>
</html>
