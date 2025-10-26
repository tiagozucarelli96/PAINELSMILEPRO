<?php
// sidebar_macro.php — Sidebar organizada por grupos macro
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Verificar se usuário está logado
$logado = isset($_SESSION['logado']) && $_SESSION['logado'] == 1;
$perfil = $_SESSION['perfil'] ?? 'Consulta';
$user_nome = $_SESSION['nome'] ?? 'Usuário';

// Função para verificar permissões por área
function canAccessArea($area) {
    global $perfil;
    
    // Admin tem acesso a tudo
    if ($perfil === 'ADM') return true;
    
    // Permissões por área
    switch ($area) {
        case 'comercial':
            return in_array($perfil, ['ADM', 'COMERCIAL', 'GERENTE']);
        case 'agenda':
            return in_array($perfil, ['ADM', 'COMERCIAL', 'AGENDA', 'OPERACIONAL']);
        case 'administrativo':
            return in_array($perfil, ['ADM', 'FINANCEIRO', 'ADMINISTRATIVO']);
        case 'logistico':
            return in_array($perfil, ['ADM', 'LOGISTICO', 'COZINHA', 'OPERACIONAL']);
        case 'configuracoes':
            return in_array($perfil, ['ADM']);
        default:
            return false;
    }
}

// Função para determinar se item está ativo
function isActiveMacroMacro($page) {
    $current_page = $_GET['page'] ?? 'dashboard';
    return $current_page === $page ? 'active' : '';
}

// Função para determinar se submenu está aberto
function isSubmenuOpenMacroMacro($pages) {
    $current_page = $_GET['page'] ?? 'dashboard';
    return in_array($current_page, $pages) ? 'open' : '';
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
            transition: transform 0.3s ease;
        }
        
        .sidebar-logo img:hover {
            transform: scale(1.05);
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
        
        /* Menu Groups */
        .menu-groups {
            padding: 20px 0;
        }
        
        .menu-group {
            margin-bottom: 8px;
        }
        
        .menu-group-title {
            padding: 12px 20px 8px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: rgba(255, 255, 255, 0.6);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 4px;
        }
        
        .menu-item {
            position: relative;
        }
        
        .menu-link {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 500;
        }
        
        .menu-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        
        .menu-link.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-right: 3px solid #fbbf24;
        }
        
        .menu-icon {
            width: 20px;
            height: 20px;
            margin-right: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        
        .menu-text {
            flex: 1;
        }
        
        .menu-arrow {
            font-size: 12px;
            transition: transform 0.3s ease;
        }
        
        .menu-item.open .menu-arrow {
            transform: rotate(90deg);
        }
        
        .submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            background: rgba(0, 0, 0, 0.1);
        }
        
        .menu-item.open .submenu {
            max-height: 500px;
        }
        
        .submenu-item {
            padding-left: 52px;
        }
        
        .submenu-link {
            display: flex;
            align-items: center;
            padding: 10px 20px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 13px;
        }
        
        .submenu-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        
        .submenu-link.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-right: 3px solid #fbbf24;
        }
        
        .submenu-icon {
            width: 16px;
            height: 16px;
            margin-right: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }
        
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
            background: #f8fafc;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .sidebar.open {
                transform: translateX(0);
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
                    <div class="user-avatar"><?= strtoupper(substr($user_nome, 0, 2)) ?></div>
                    <div class="user-name"><?= htmlspecialchars($user_nome) ?></div>
                    <div class="user-plan">ADMINISTRADOR</div>
                </div>
            </div>
            
            <div class="menu-groups">
                <!-- Comercial -->
                <?php if (canAccessArea('comercial')): ?>
                <div class="menu-group">
                    <div class="menu-group-title">Comercial</div>
                    <div class="menu-item <?= isSubmenuOpenMacroMacro(['comercial_clientes', 'comercial_degustacoes', 'comercial_degust_inscricoes']) ? 'open' : '' ?>">
                        <a href="#" class="menu-link" onclick="toggleSubmenu(this)">
                            <span class="menu-icon">📊</span>
                            <span class="menu-text">Contratos & Clientes</span>
                            <span class="menu-arrow">▶</span>
                        </a>
                        <div class="submenu">
                            <div class="submenu-item">
                                <a href="index.php?page=comercial_clientes" class="submenu-link <?= isActiveMacroMacro('comercial_clientes') ?>">
                                    <span class="submenu-icon">👥</span>
                                    <span>Clientes</span>
                                </a>
                            </div>
                            <div class="submenu-item">
                                <a href="index.php?page=comercial_degustacoes" class="submenu-link <?= isActiveMacroMacro('comercial_degustacoes') ?>">
                                    <span class="submenu-icon">🍰</span>
                                    <span>Degustações</span>
                                </a>
                            </div>
                            <div class="submenu-item">
                                <a href="index.php?page=comercial_degust_inscricoes" class="submenu-link <?= isActiveMacroMacro('comercial_degust_inscricoes') ?>">
                                    <span class="submenu-icon">📝</span>
                                    <span>Inscrições</span>
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="menu-item">
                        <a href="#" class="menu-link">
                            <span class="menu-icon">💬</span>
                            <span class="menu-text">Comunicação & Propostas</span>
                        </a>
                    </div>
                    <div class="menu-item">
                        <a href="#" class="menu-link">
                            <span class="menu-icon">📈</span>
                            <span class="menu-text">Indicadores de Vendas</span>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Agenda -->
                <?php if (canAccessArea('agenda')): ?>
                <div class="menu-group">
                    <div class="menu-group-title">Agenda</div>
                    <div class="menu-item">
                        <a href="index.php?page=agenda" class="menu-link <?= isActiveMacro('agenda') ?>">
                            <span class="menu-icon">📅</span>
                            <span class="menu-text">Agenda Geral</span>
                        </a>
                    </div>
                    <div class="menu-item">
                        <a href="#" class="menu-link">
                            <span class="menu-icon">✅</span>
                            <span class="menu-text">Checklist dos Eventos</span>
                        </a>
                    </div>
                    <div class="menu-item">
                        <a href="#" class="menu-link">
                            <span class="menu-icon">🏢</span>
                            <span class="menu-text">Espaços & Reservas</span>
                        </a>
                    </div>
                    <div class="menu-item">
                        <a href="index.php?page=demandas" class="menu-link <?= isActiveMacro('demandas') ?>">
                            <span class="menu-icon">📋</span>
                            <span class="menu-text">Demandas Operacionais</span>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Administrativo -->
                <?php if (canAccessArea('administrativo')): ?>
                <div class="menu-group">
                    <div class="menu-group-title">Administrativo</div>
                    <div class="menu-item">
                        <a href="index.php?page=usuarios" class="menu-link <?= isActiveMacro('usuarios') ?>">
                            <span class="menu-icon">👥</span>
                            <span class="menu-text">Equipe & Permissões</span>
                        </a>
                    </div>
                    <div class="menu-item">
                        <a href="#" class="menu-link">
                            <span class="menu-icon">👷</span>
                            <span class="menu-text">Colaboradores</span>
                        </a>
                    </div>
                    <div class="menu-item">
                        <a href="index.php?page=pagamentos" class="menu-link <?= isActiveMacro('pagamentos') ?>">
                            <span class="menu-icon">💰</span>
                            <span class="menu-text">Pagamentos</span>
                        </a>
                    </div>
                    <div class="menu-item">
                        <a href="#" class="menu-link">
                            <span class="menu-icon">📊</span>
                            <span class="menu-text">Contabilidade</span>
                        </a>
                    </div>
                    <div class="menu-item">
                        <a href="index.php?page=config_fornecedores" class="menu-link <?= isActiveMacro('config_fornecedores') ?>">
                            <span class="menu-icon">🏢</span>
                            <span class="menu-text">Fornecedores</span>
                        </a>
                    </div>
                    <div class="menu-item">
                        <a href="#" class="menu-link">
                            <span class="menu-icon">🎯</span>
                            <span class="menu-text">Metas & Configurações</span>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Logístico -->
                <?php if (canAccessArea('logistico')): ?>
                <div class="menu-group">
                    <div class="menu-group-title">Logístico</div>
                    <div class="menu-item">
                        <a href="index.php?page=lista" class="menu-link <?= isActiveMacro('lista') ?>">
                            <span class="menu-icon">🛒</span>
                            <span class="menu-text">Lista de Compras & Encomendas</span>
                        </a>
                    </div>
                    <div class="menu-item">
                        <a href="index.php?page=estoque_logistico" class="menu-link <?= isActiveMacro('estoque_logistico') ?>">
                            <span class="menu-icon">📦</span>
                            <span class="menu-text">Estoque & Alertas</span>
                        </a>
                    </div>
                    <div class="menu-item">
                        <a href="#" class="menu-link">
                            <span class="menu-icon">🎉</span>
                            <span class="menu-text">Separação por Evento</span>
                        </a>
                    </div>
                    <div class="menu-item">
                        <a href="#" class="menu-link">
                            <span class="menu-icon">📄</span>
                            <span class="menu-text">Entrada por Nota Fiscal</span>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Configurações -->
                <?php if (canAccessArea('configuracoes')): ?>
                <div class="menu-group">
                    <div class="menu-group-title">Configurações</div>
                    <div class="menu-item">
                        <a href="#" class="menu-link">
                            <span class="menu-icon">🔗</span>
                            <span class="menu-text">Integrações</span>
                        </a>
                    </div>
                    <div class="menu-item">
                        <a href="index.php?page=configuracoes" class="menu-link <?= isActiveMacro('configuracoes') ?>">
                            <span class="menu-icon">🔧</span>
                            <span class="menu-text">Diagnóstico & Manutenção</span>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
        </div>
        
        <div class="main-content">
            <!-- Conteúdo principal será inserido aqui -->
        </div>
    </div>
    
    <script>
        function toggleSubmenu(element) {
            const menuItem = element.closest('.menu-item');
            const isOpen = menuItem.classList.contains('open');
            
            // Fechar todos os outros submenus
            document.querySelectorAll('.menu-item.open').forEach(item => {
                if (item !== menuItem) {
                    item.classList.remove('open');
                }
            });
            
            // Toggle do submenu atual
            menuItem.classList.toggle('open', !isOpen);
        }
        
        // Auto-abrir submenu se página atual estiver dentro dele
        document.addEventListener('DOMContentLoaded', function() {
            const activeSubmenuLink = document.querySelector('.submenu-link.active');
            if (activeSubmenuLink) {
                const menuItem = activeSubmenuLink.closest('.menu-item');
                if (menuItem) {
                    menuItem.classList.add('open');
                }
            }
        });
    </script>
</body>
</html>
