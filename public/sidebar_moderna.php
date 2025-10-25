<?php
// sidebar_moderna.php — Sidebar moderna e limpa para todas as páginas
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Verificar se usuário está logado
$logado = isset($_SESSION['logado']) && $_SESSION['logado'] == 1;
$perfil = $_SESSION['perfil'] ?? 'Consulta';
$user_nome = $_SESSION['nome'] ?? 'Usuário';

// Função para verificar permissões
function hasPermission($permission) {
    global $perfil;
    if ($perfil === 'ADM') return true;
    return isset($_SESSION[$permission]) && $_SESSION[$permission] == 1;
}

// Função para determinar se item está ativo
function isActive($page) {
    $current_page = $_GET['page'] ?? 'dashboard';
    return $current_page === $page ? 'active' : '';
}

// Função para determinar se submenu está aberto
function isSubmenuOpen($pages) {
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
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 1000;
            transition: transform 0.3s ease;
            overflow-y: auto;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
        }
        
        .sidebar.collapsed {
            transform: translateX(-100%);
        }
        
        /* Header da Sidebar */
        .sidebar-header {
            padding: 25px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }
        
        .sidebar-logo {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .sidebar-subtitle {
            font-size: 14px;
            opacity: 0.8;
            font-weight: 400;
        }
        
        /* Navegação */
        .sidebar-nav {
            padding: 20px 0;
        }
        
        .nav-section {
            margin-bottom: 30px;
        }
        
        .nav-section-title {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.6);
            padding: 0 20px 10px;
            margin-bottom: 10px;
        }
        
        .nav-item {
            margin: 2px 0;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            transition: all 0.3s ease;
            position: relative;
            font-weight: 500;
        }
        
        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            transform: translateX(4px);
        }
        
        .nav-link.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            font-weight: 600;
        }
        
        .nav-link.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: #fbbf24;
        }
        
        .nav-icon {
            font-size: 18px;
            margin-right: 12px;
            width: 20px;
            text-align: center;
        }
        
        .nav-text {
            flex: 1;
        }
        
        .nav-arrow {
            font-size: 12px;
            transition: transform 0.3s ease;
        }
        
        .nav-item.open .nav-arrow {
            transform: rotate(90deg);
        }
        
        /* Submenu */
        .nav-submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            background: rgba(0, 0, 0, 0.1);
        }
        
        .nav-item.open .nav-submenu {
            max-height: 300px;
        }
        
        .nav-submenu .nav-link {
            padding-left: 52px;
            font-size: 14px;
            font-weight: 400;
        }
        
        .nav-submenu .nav-link:hover {
            background: rgba(255, 255, 255, 0.08);
        }
        
        /* Botão Voltar */
        .back-button {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1001;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 15px rgba(30, 58, 138, 0.3);
            transition: all 0.3s ease;
        }
        
        .back-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(30, 58, 138, 0.4);
        }
        
        /* Toggle Sidebar */
        .sidebar-toggle {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: rgba(30, 58, 138, 0.9);
            color: white;
            border: none;
            padding: 12px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 18px;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }
        
        .sidebar-toggle:hover {
            background: rgba(30, 58, 138, 1);
            transform: scale(1.05);
        }
        
        /* Conteúdo Principal */
        .main-content {
            flex: 1;
            margin-left: 280px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
        }
        
        .main-content.sidebar-collapsed {
            margin-left: 0;
        }
        
        /* Overlay para mobile */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
        }
        
        .sidebar-overlay.active {
            display: block;
        }
        
        /* Responsivo */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .back-button {
                top: 80px;
            }
        }
        
        /* Animações */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .nav-item {
            animation: slideIn 0.3s ease forwards;
        }
        
        .nav-item:nth-child(1) { animation-delay: 0.1s; }
        .nav-item:nth-child(2) { animation-delay: 0.2s; }
        .nav-item:nth-child(3) { animation-delay: 0.3s; }
        .nav-item:nth-child(4) { animation-delay: 0.4s; }
        .nav-item:nth-child(5) { animation-delay: 0.5s; }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Overlay para mobile -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        
        <!-- Sidebar -->
        <nav class="sidebar" id="sidebar">
            <!-- Header -->
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <span>🏢</span>
                    <span>Smile EVENTOS</span>
                </div>
                <div class="sidebar-subtitle">Sistema de Gestão</div>
            </div>
            
            <!-- Navegação -->
            <div class="sidebar-nav">
                <?php if ($logado): ?>
                    <!-- Seção Principal -->
                    <div class="nav-section">
                        <div class="nav-section-title">Principal</div>
                        
                        <div class="nav-item">
                            <a href="index.php?page=dashboard" class="nav-link <?= isActive('dashboard') ?>">
                                <span class="nav-icon">📊</span>
                                <span class="nav-text">Dashboard</span>
                            </a>
                        </div>
                        
                        <div class="nav-item">
                            <a href="index.php?page=agenda" class="nav-link <?= isActive('agenda') ?>">
                                <span class="nav-icon">📅</span>
                                <span class="nav-text">Agenda</span>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Seção Comercial -->
                    <?php if (hasPermission('perm_comercial_ver') || $perfil === 'ADM'): ?>
                    <div class="nav-section">
                        <div class="nav-section-title">Comercial</div>
                        
                        <div class="nav-item">
                            <a href="index.php?page=comercial_degustacoes" class="nav-link <?= isActive('comercial_degustacoes') ?>">
                                <span class="nav-icon">🍷</span>
                                <span class="nav-text">Degustações</span>
                            </a>
                        </div>
                        
                        <div class="nav-item">
                            <a href="index.php?page=comercial_degust_inscricoes" class="nav-link <?= isActive('comercial_degust_inscricoes') ?>">
                                <span class="nav-icon">👥</span>
                                <span class="nav-text">Inscrições</span>
                            </a>
                        </div>
                        
                        <div class="nav-item">
                            <a href="index.php?page=comercial_clientes" class="nav-link <?= isActive('comercial_clientes') ?>">
                                <span class="nav-icon">📈</span>
                                <span class="nav-text">Conversão</span>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Seção Compras -->
                    <div class="nav-section">
                        <div class="nav-section-title">Compras</div>
                        
                        <div class="nav-item">
                            <a href="index.php?page=lc_index" class="nav-link <?= isActive('lc_index') ?>">
                                <span class="nav-icon">🛒</span>
                                <span class="nav-text">Lista de Compras</span>
                            </a>
                        </div>
                        
                        <div class="nav-item">
                            <a href="index.php?page=config_fornecedores" class="nav-link <?= isActive('config_fornecedores') ?>">
                                <span class="nav-icon">🏪</span>
                                <span class="nav-text">Fornecedores</span>
                            </a>
                        </div>
                        
                        <div class="nav-item">
                            <a href="index.php?page=config_insumos" class="nav-link <?= isActive('config_insumos') ?>">
                                <span class="nav-icon">📦</span>
                                <span class="nav-text">Insumos</span>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Seção Estoque -->
                    <div class="nav-section">
                        <div class="nav-section-title">Estoque</div>
                        
                        <div class="nav-item">
                            <a href="index.php?page=estoque_logistico" class="nav-link <?= isActive('estoque_logistico') ?>">
                                <span class="nav-icon">📋</span>
                                <span class="nav-text">Logístico</span>
                            </a>
                        </div>
                        
                        <div class="nav-item">
                            <a href="index.php?page=ver" class="nav-link <?= isActive('ver') ?>">
                                <span class="nav-icon">👁️</span>
                                <span class="nav-text">Visualizar</span>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Seção Financeiro -->
                    <div class="nav-section">
                        <div class="nav-section-title">Financeiro</div>
                        
                        <div class="nav-item">
                            <a href="index.php?page=pagamentos" class="nav-link <?= isActive('pagamentos') ?>">
                                <span class="nav-icon">💰</span>
                                <span class="nav-text">Pagamentos</span>
                            </a>
                        </div>
                        
                        <div class="nav-item">
                            <a href="index.php?page=notas_fiscais" class="nav-link <?= isActive('notas_fiscais') ?>">
                                <span class="nav-icon">🧾</span>
                                <span class="nav-text">Notas Fiscais</span>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Seção RH -->
                    <div class="nav-section">
                        <div class="nav-section-title">Recursos Humanos</div>
                        
                        <div class="nav-item">
                            <a href="index.php?page=usuarios" class="nav-link <?= isActive('usuarios') ?>">
                                <span class="nav-icon">👤</span>
                                <span class="nav-text">Usuários</span>
                            </a>
                        </div>
                        
                        <div class="nav-item">
                            <a href="index.php?page=configuracoes" class="nav-link <?= isActive('configuracoes') ?>">
                                <span class="nav-icon">⚙️</span>
                                <span class="nav-text">Configurações</span>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Seção Administrativo -->
                    <?php if ($perfil === 'ADM'): ?>
                    <div class="nav-section">
                        <div class="nav-section-title">Administrativo</div>
                        
                        <div class="nav-item">
                            <a href="contab_gerar_link.php" class="nav-link">
                                <span class="nav-icon">🔗</span>
                                <span class="nav-text">Portal Contábil</span>
                            </a>
                        </div>
                        
                        <div class="nav-item">
                            <a href="index.php?page=banco_smile_admin" class="nav-link <?= isActive('banco_smile_admin') ?>">
                                <span class="nav-icon">🏦</span>
                                <span class="nav-text">Banco Smile</span>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <!-- Usuário não logado -->
                    <div class="nav-section">
                        <div class="nav-item">
                            <a href="login.php" class="nav-link">
                                <span class="nav-icon">🔐</span>
                                <span class="nav-text">Fazer Login</span>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </nav>
        
        <!-- Conteúdo Principal -->
        <main class="main-content" id="mainContent">
            <!-- Conteúdo da página será inserido aqui -->
        </main>
    </div>
    
    <!-- Botão Voltar -->
    <button class="back-button" onclick="goBack()" id="backButton" style="display: none;">
        <span>←</span>
        <span>Voltar</span>
    </button>
    
    <!-- Toggle Sidebar -->
    <button class="sidebar-toggle" onclick="toggleSidebar()" id="sidebarToggle">
        ☰
    </button>
    
    <script>
        // Toggle sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const mainContent = document.getElementById('mainContent');
            
            if (window.innerWidth <= 768) {
                // Mobile
                sidebar.classList.toggle('mobile-open');
                overlay.classList.toggle('active');
            } else {
                // Desktop
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('sidebar-collapsed');
            }
        }
        
        // Fechar sidebar no mobile ao clicar no overlay
        document.getElementById('sidebarOverlay').addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                document.getElementById('sidebar').classList.remove('mobile-open');
                document.getElementById('sidebarOverlay').classList.remove('active');
            }
        });
        
        // Função voltar
        function goBack() {
            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.location.href = 'index.php?page=dashboard';
            }
        }
        
        // Mostrar/esconder botão voltar baseado na página
        function updateBackButton() {
            const backButton = document.getElementById('backButton');
            const currentPage = new URLSearchParams(window.location.search).get('page') || 'dashboard';
            
            // Páginas que não precisam do botão voltar
            const noBackPages = ['dashboard', 'login'];
            
            if (noBackPages.includes(currentPage)) {
                backButton.style.display = 'none';
            } else {
                backButton.style.display = 'flex';
            }
        }
        
        // Atualizar botão voltar ao carregar a página
        document.addEventListener('DOMContentLoaded', updateBackButton);
        
        // Responsividade
        function handleResize() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const mainContent = document.getElementById('mainContent');
            
            if (window.innerWidth > 768) {
                // Desktop
                sidebar.classList.remove('mobile-open');
                overlay.classList.remove('active');
                mainContent.classList.remove('sidebar-collapsed');
            }
        }
        
        window.addEventListener('resize', handleResize);
        
        // Adicionar classe para animações
        document.addEventListener('DOMContentLoaded', () => {
            const navItems = document.querySelectorAll('.nav-item');
            navItems.forEach((item, index) => {
                item.style.animationDelay = `${index * 0.1}s`;
            });
        });
    </script>
</body>
</html>