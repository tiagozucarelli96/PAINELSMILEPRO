<?php
// sidebar_unified.php — Sistema unificado de sidebar para todas as páginas
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$nomeUser = $_SESSION['nome'] ?? 'Usuário';
$perfil = $_SESSION['perfil'] ?? 'CONSULTA';
$current_page = $_GET['page'] ?? 'dashboard';

// Função para determinar se item está ativo
if (!function_exists('isActiveUnified')) {
    function isActiveUnified($page) {
        global $current_page;
        return $current_page === $page ? 'active' : '';
    }
}

// Para todas as páginas, incluir o conteúdo da página atual
$page_file = $_GET['page'] ?? 'dashboard';
$page_path = __DIR__ . '/' . $page_file . '.php';

// Se for dashboard, criar conteúdo padrão
if ($current_page === 'dashboard') {
    $dashboard_content = '
    <div class="page-container">
        <div class="page-header">
            <h1 class="page-title">🏠 Dashboard</h1>
            <p class="page-subtitle">Bem-vindo, ' . htmlspecialchars($nomeUser) . '!</p>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📋 Comercial</h3>
                    <span class="card-icon">📋</span>
                </div>
                <div class="card-content">
                    <p>Gestão de degustações e conversões</p>
                    <a href="index.php?page=comercial_degustacoes" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📦 Logístico</h3>
                    <span class="card-icon">📦</span>
                </div>
                <div class="card-content">
                    <p>Controle de estoque e compras</p>
                    <a href="index.php?page=lc_index" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>⚙️ Configurações</h3>
                    <span class="card-icon">⚙️</span>
                </div>
                <div class="card-content">
                    <p>Configurações do sistema</p>
                    <a href="index.php?page=configuracoes" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📝 Cadastros</h3>
                    <span class="card-icon">📝</span>
                </div>
                <div class="card-content">
                    <p>Gestão de usuários e fornecedores</p>
                    <a href="index.php?page=usuarios" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>💰 Financeiro</h3>
                    <span class="card-icon">💰</span>
                </div>
                <div class="card-content">
                    <p>Pagamentos e solicitações</p>
                    <a href="index.php?page=pagamentos" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>👥 Administrativo</h3>
                    <span class="card-icon">👥</span>
                </div>
                <div class="card-content">
                    <p>Relatórios e administração</p>
                    <a href="index.php?page=administrativo" class="btn-primary">Acessar</a>
                </div>
            </div>
        </div>
    </div>';
    
    // Inserir o conteúdo do dashboard no JavaScript
    $dashboard_js = "
    document.addEventListener('DOMContentLoaded', function() {
        const pageContent = document.getElementById('pageContent');
        if (pageContent) {
            pageContent.innerHTML = `$dashboard_content`;
        }
    });";
} else {
    // Para outras páginas, incluir o conteúdo da página atual
    if (file_exists($page_path)) {
        // Capturar o conteúdo da página
        ob_start();
        include $page_path;
        $page_content = ob_get_clean();
        
        // Extrair apenas o conteúdo principal (sem sidebar duplicada)
        if (strpos($page_content, '<div class="page-container">') !== false) {
            $start = strpos($page_content, '<div class="page-container">');
            $end = strrpos($page_content, '</div>');
            if ($start !== false && $end !== false) {
                $page_content = substr($page_content, $start, $end - $start + 6);
            }
        }
        
        $dashboard_js = "
        document.addEventListener('DOMContentLoaded', function() {
            const pageContent = document.getElementById('pageContent');
            if (pageContent) {
                pageContent.innerHTML = `" . addslashes($page_content) . "`;
            }
        });";
    } else {
        $dashboard_js = "
        document.addEventListener('DOMContentLoaded', function() {
            const pageContent = document.getElementById('pageContent');
            if (pageContent) {
                pageContent.innerHTML = '<div style=\"text-align: center; padding: 50px; color: #dc2626;\"><div style=\"font-size: 24px; margin-bottom: 20px;\">❌</div><div>Página não encontrada</div></div>';
            }
        });";
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
        
        /* Dashboard Styles */
        .page-container {
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .page-header {
            margin-bottom: 30px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }
        
        .page-subtitle {
            color: #64748b;
            font-size: 16px;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .dashboard-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }
        
        .dashboard-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .card-header h3 {
            margin: 0;
            color: #1e293b;
            font-size: 18px;
            font-weight: 600;
        }
        
        .card-icon {
            font-size: 24px;
        }
        
        .card-content p {
            color: #64748b;
            margin-bottom: 15px;
            line-height: 1.5;
        }
        
        .btn-primary {
            display: inline-block;
            background: #1e40af;
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            transition: background 0.3s ease;
        }
        
        .btn-primary:hover {
            background: #1e3a8a;
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
            </div>
            
            <nav class="sidebar-nav">
                <a href="index.php?page=dashboard" class="nav-item <?= isActiveUnified('dashboard') ?>">
                    <span class="nav-item-icon">🏠</span>
                    Dashboard
                </a>
                
                <a href="index.php?page=comercial" class="nav-item <?= isActiveUnified('comercial') ?>">
                    <span class="nav-item-icon">📋</span>
                    Comercial
                </a>
                
                <a href="index.php?page=logistico" class="nav-item <?= isActiveUnified('logistico') ?>">
                    <span class="nav-item-icon">📦</span>
                    Logístico
                </a>
                
                <a href="index.php?page=configuracoes" class="nav-item <?= isActiveUnified('configuracoes') ?>">
                    <span class="nav-item-icon">⚙️</span>
                    Configurações
                </a>
                
                <a href="index.php?page=cadastros" class="nav-item <?= isActiveUnified('cadastros') ?>">
                    <span class="nav-item-icon">📝</span>
                    Cadastros
                </a>
                
                <a href="index.php?page=financeiro" class="nav-item <?= isActiveUnified('financeiro') ?>">
                    <span class="nav-item-icon">💰</span>
                    Financeiro
                </a>
                
                <a href="index.php?page=administrativo" class="nav-item <?= isActiveUnified('administrativo') ?>">
                    <span class="nav-item-icon">👥</span>
                    Administrativo
                </a>
            </nav>
        </div>
        
        <div class="main-content" id="mainContent">
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
            const floatingToggle = document.getElementById('floatingToggle');
            
            const isCollapsed = sidebar.classList.contains('collapsed');
            
            if (isCollapsed) {
                // Mostrar sidebar
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
                floatingToggle.style.display = 'none';
            } else {
                // Esconder sidebar
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
                floatingToggle.style.display = 'block';
            }
        }
        
        // Função para voltar
        function goBack() {
            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.location.href = 'index.php?page=dashboard';
            }
        }
        
        // Carregar conteúdo da página atual
        document.addEventListener('DOMContentLoaded', function() {
            const currentPage = '<?= $current_page ?>';
            loadPageContent(currentPage);
        });
        
        <?= $dashboard_js ?>
        
        // Função para carregar conteúdo das páginas
        function loadPageContent(page) {
            const pageContent = document.getElementById('pageContent');
            if (!pageContent) return;
            
            // Mostrar loading
            pageContent.innerHTML = '<div style="text-align: center; padding: 50px; color: #64748b;"><div style="font-size: 24px; margin-bottom: 20px;">⏳</div><div>Carregando...</div></div>';
            
            // Carregar página via AJAX
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
    </script>
</body>
</html>
