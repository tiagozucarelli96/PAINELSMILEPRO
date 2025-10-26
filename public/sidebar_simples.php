<?php
// sidebar_simples.php — Sidebar simplificada com 6 categorias principais
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$nomeUser = $_SESSION['nome'] ?? 'Usuário';
$perfil = $_SESSION['perfil'] ?? 'CONSULTA';

// Função para determinar se item está ativo
if (!function_exists('isActiveSimples')) {
    function isActiveSimples($page) {
        $current_page = $_GET['page'] ?? 'dashboard';
        return $current_page === $page ? 'active' : '';
    }
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
            transition: transform 0.3s ease;
        }
        
        .sidebar.collapsed {
            transform: translateX(-100%);
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }
        
        .user-info {
            margin-top: 15px;
            text-align: center;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-weight: 600;
            font-size: 18px;
        }
        
        .user-name {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 6px;
        }
        
        .user-plan {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.7);
            background: rgba(255, 255, 255, 0.1);
            padding: 4px 12px;
            border-radius: 12px;
            display: inline-block;
        }
        
        /* Controles da Sidebar */
        .sidebar-controls {
            padding: 15px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            gap: 10px;
        }
        
        .sidebar-btn {
            flex: 1;
            background: rgba(255, 255, 255, 0.1);
            border: none;
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        
        .sidebar-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .sidebar-btn.hidden {
            background: rgba(255, 255, 255, 0.2);
        }
        
        /* Navigation */
        .sidebar-nav {
            padding: 20px 0;
        }
        
        .nav-item {
            display: block;
            padding: 15px 20px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
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
            margin-right: 12px;
            font-size: 18px;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
            background: #f8fafc;
            transition: margin-left 0.3s ease;
        }
        
        .main-content.expanded {
            margin-left: 0;
        }
        
        /* Toggle Button */
        .sidebar-toggle {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: #1e3a8a;
            color: white;
            border: none;
            padding: 12px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 4px 15px rgba(30, 58, 138, 0.3);
            transition: all 0.3s ease;
        }
        
        .sidebar-toggle:hover {
            background: #1e40af;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(30, 58, 138, 0.4);
        }
        
        /* Responsivo */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .sidebar-toggle {
                display: block;
            }
        }
    </style>
</head>
<body>
    <button class="sidebar-toggle" onclick="toggleSidebar()">☰</button>
    
    <div class="app-container">
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="user-info">
                    <div class="user-avatar"><?= strtoupper(substr($nomeUser, 0, 2)) ?></div>
                    <div class="user-name"><?= htmlspecialchars($nomeUser) ?></div>
                    <div class="user-plan"><?= strtoupper($perfil) ?></div>
                </div>
            </div>
            
            <div class="sidebar-controls">
                <button class="sidebar-btn" onclick="goBack()">← Voltar</button>
                <button class="sidebar-btn" onclick="toggleSidebar()" id="toggleBtn">☰ Esconder</button>
            </div>
            
            <nav class="sidebar-nav">
                <a href="#" class="nav-item active" data-page="dashboard">
                    <span class="nav-item-icon">🏠</span>
                    Dashboard
                </a>
                
                <a href="#" class="nav-item" data-page="comercial">
                    <span class="nav-item-icon">📋</span>
                    Comercial
                </a>
                
                <a href="#" class="nav-item" data-page="logistico">
                    <span class="nav-item-icon">📦</span>
                    Logístico
                </a>
                
                <a href="#" class="nav-item" data-page="configuracoes">
                    <span class="nav-item-icon">⚙️</span>
                    Configurações
                </a>
                
                <a href="#" class="nav-item" data-page="cadastros">
                    <span class="nav-item-icon">📝</span>
                    Cadastros
                </a>
                
                <a href="#" class="nav-item" data-page="financeiro">
                    <span class="nav-item-icon">💰</span>
                    Financeiro
                </a>
                
                <a href="#" class="nav-item" data-page="administrativo">
                    <span class="nav-item-icon">👥</span>
                    Administrativo
                </a>
            </nav>
        </div>
        
        <div class="main-content" id="mainContent">
            <!-- Botão toggle flutuante quando sidebar está escondida -->
            <button class="sidebar-toggle" id="floatingToggle" onclick="toggleSidebar()" style="display: none;">
                ☰ Mostrar Sidebar
            </button>
            
            <div id="pageContent">
                <!-- Conteúdo da página será inserido aqui -->
            </div>
        </div>
    </div>

    <script>
        // Função para alternar sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const toggleBtn = document.getElementById('toggleBtn');
            const floatingToggle = document.getElementById('floatingToggle');
            
            const isCollapsed = sidebar.classList.contains('collapsed');
            
            if (isCollapsed) {
                // Mostrar sidebar
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
                toggleBtn.innerHTML = '☰ Esconder';
                toggleBtn.classList.remove('hidden');
                floatingToggle.style.display = 'none';
            } else {
                // Esconder sidebar
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
                toggleBtn.innerHTML = '☰ Mostrar';
                toggleBtn.classList.add('hidden');
                floatingToggle.style.display = 'block';
            }
        }
        
        // Função para voltar
        function goBack() {
            if (window.history.length > 1) {
                window.history.back();
            } else {
                loadPageContent('dashboard');
            }
        }
        
        // Função para carregar conteúdo das páginas
        function loadPageContent(page) {
            const pageContent = document.getElementById('pageContent');
            if (!pageContent) return;
            
            // Mostrar loading
            pageContent.innerHTML = '<div style="text-align: center; padding: 50px; color: #64748b;"><div style="font-size: 24px; margin-bottom: 20px;">⏳</div><div>Carregando...</div></div>';
            
            // Se for dashboard, carregar dashboard_simples.php diretamente
            if (page === 'dashboard') {
                fetch('dashboard_simples.php')
                    .then(response => response.text())
                    .then(html => {
                        // Extrair apenas o conteúdo da página (sem sidebar duplicada)
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const content = doc.querySelector('.dashboard-container') || doc.body;
                        
                        if (content) {
                            pageContent.innerHTML = content.innerHTML;
                        } else {
                            pageContent.innerHTML = html;
                        }
                    })
                    .catch(error => {
                        console.error('Erro ao carregar dashboard:', error);
                        pageContent.innerHTML = '<div style="text-align: center; padding: 50px; color: #dc2626;"><div style="font-size: 24px; margin-bottom: 20px;">❌</div><div>Erro ao carregar dashboard</div></div>';
                    });
            } else {
                // Para outras páginas, usar o sistema de roteamento normal
                fetch(`index.php?page=${page}`)
                    .then(response => response.text())
                    .then(html => {
                        // Extrair apenas o conteúdo da página (sem sidebar duplicada)
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const content = doc.querySelector('#pageContent') || doc.body;
                        
                        if (content) {
                            pageContent.innerHTML = content.innerHTML;
                        } else {
                            pageContent.innerHTML = html;
                        }
                    })
                    .catch(error => {
                        console.error('Erro ao carregar página:', error);
                        pageContent.innerHTML = '<div style="text-align: center; padding: 50px; color: #dc2626;"><div style="font-size: 24px; margin-bottom: 20px;">❌</div><div>Erro ao carregar página</div></div>';
                    });
            }
        }
        
        // Adicionar event listeners para os links da sidebar
        document.addEventListener('DOMContentLoaded', function() {
            const navItems = document.querySelectorAll('.nav-item');
            navItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    const page = this.getAttribute('data-page');
                    
                    if (page) {
                        // Remover classe active de todos os itens
                        navItems.forEach(nav => nav.classList.remove('active'));
                        // Adicionar classe active ao item clicado
                        this.classList.add('active');
                        
                        // Carregar conteúdo da página
                        loadPageContent(page);
                    }
                });
            });
            
            // Carregar dashboard por padrão
            loadPageContent('dashboard');
        });
    </script>
</body>
</html>
