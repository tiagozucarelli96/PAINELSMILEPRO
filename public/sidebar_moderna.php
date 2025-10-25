<?php
// sidebar_moderna.php — Sidebar moderna e fixa
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lc_permissions_enhanced.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$nomeUser = isset($_SESSION['nome']) ? $_SESSION['nome'] : 'Usuário';
$perfil = $_SESSION['perfil'] ?? 'CONSULTA';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sidebar Moderna</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            margin: 0;
            padding: 0;
        }
        
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(180deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            z-index: 1000;
            overflow-y: auto;
            transition: all 0.3s ease;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
        }
        
        .sidebar-header {
            padding: 25px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }
        
        .sidebar-logo {
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 5px;
            color: white;
        }
        
        .sidebar-subtitle {
            font-size: 0.9rem;
            opacity: 0.8;
            color: rgba(255, 255, 255, 0.9);
        }
        
        .user-info {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-size: 1.5rem;
        }
        
        .user-name {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .user-role {
            font-size: 0.8rem;
            opacity: 0.8;
            background: rgba(255, 255, 255, 0.1);
            padding: 4px 8px;
            border-radius: 12px;
            display: inline-block;
        }
        
        .sidebar-nav {
            padding: 20px 0;
        }
        
        .nav-section {
            margin-bottom: 30px;
        }
        
        .nav-section-title {
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.6);
            padding: 0 20px 10px;
            margin-bottom: 10px;
        }
        
        .nav-item {
            display: block;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
            position: relative;
        }
        
        .nav-item:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: rgba(255, 255, 255, 0.5);
        }
        
        .nav-item.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-left-color: white;
        }
        
        .nav-item-icon {
            margin-right: 12px;
            font-size: 1.1rem;
            width: 20px;
            display: inline-block;
        }
        
        .nav-item-text {
            font-weight: 500;
        }
        
        .back-button {
            position: fixed;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 25px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
            transition: all 0.3s ease;
            z-index: 1001;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .back-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
        }
        
        .main-content {
            margin-left: 280px;
            min-height: 100vh;
            background: #f8fafc;
        }
        
        .content-header {
            background: white;
            padding: 20px 30px;
            border-bottom: 1px solid #e5e7eb;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .content-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0;
        }
        
        .content-body {
            padding: 30px;
        }
        
        .mobile-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            background: #1e3a8a;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            z-index: 1002;
        }
        
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
            
            .mobile-toggle {
                display: block;
            }
            
            .back-button {
                top: 70px;
            }
        }
        
        .badge {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 8px;
        }
        
        .badge.new {
            background: #10b981;
        }
        
        .badge.warning {
            background: #f59e0b;
        }
    </style>
</head>
<body>
    <!-- Mobile Toggle -->
    <button class="mobile-toggle" onclick="toggleSidebar()">☰</button>
    
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
  <div class="sidebar-header">
            <div class="sidebar-logo">🏠 Smile Pro</div>
            <div class="sidebar-subtitle">Sistema de Gestão</div>
        </div>
        
        <div class="user-info">
            <div class="user-avatar">👤</div>
            <div class="user-name"><?= h($nomeUser) ?></div>
            <div class="user-role"><?= h($perfil) ?></div>
  </div>

  <nav class="sidebar-nav">
            <!-- Dashboard -->
            <div class="nav-section">
                <div class="nav-section-title">Principal</div>
                <a href="index.php?page=dashboard" class="nav-item">
                    <span class="nav-item-icon">🏠</span>
      <span class="nav-item-text">Dashboard</span>
    </a>
            </div>
            
            <!-- Compras -->
            <?php if (lc_can_access_module('compras')): ?>
            <div class="nav-section">
                <div class="nav-section-title">Compras</div>
                <a href="lc_index.php" class="nav-item">
                    <span class="nav-item-icon">🛒</span>
                    <span class="nav-item-text">Listas de Compras</span>
                </a>
                <a href="config_insumos.php" class="nav-item">
                    <span class="nav-item-icon">📦</span>
                    <span class="nav-item-text">Insumos</span>
                </a>
                <a href="config_fornecedores.php" class="nav-item">
                    <span class="nav-item-icon">🏢</span>
                    <span class="nav-item-text">Fornecedores</span>
                </a>
            </div>
            <?php endif; ?>
            
            <!-- Estoque -->
            <?php if (lc_can_access_module('estoque')): ?>
            <div class="nav-section">
                <div class="nav-section-title">Estoque</div>
                <a href="estoque_logistico.php" class="nav-item">
                    <span class="nav-item-icon">📊</span>
                    <span class="nav-item-text">Controle de Estoque</span>
                </a>
                <a href="#" class="nav-item">
                    <span class="nav-item-icon">📈</span>
                    <span class="nav-item-text">Relatórios</span>
                </a>
            </div>
            <?php endif; ?>
            
            <!-- Pagamentos -->
            <?php if (lc_can_access_module('pagamentos')): ?>
            <div class="nav-section">
                <div class="nav-section-title">Financeiro</div>
                <a href="pagamentos.php" class="nav-item">
      <span class="nav-item-icon">💳</span>
                    <span class="nav-item-text">Pagamentos</span>
                    <span class="badge">3</span>
                </a>
                <a href="fornecedores.php" class="nav-item">
                    <span class="nav-item-icon">🏢</span>
                    <span class="nav-item-text">Fornecedores</span>
                </a>
            </div>
            <?php endif; ?>
            
            <!-- Comercial -->
            <?php if (lc_can_access_module('comercial')): ?>
            <div class="nav-section">
                <div class="nav-section-title">Comercial</div>
                <a href="comercial_degustacoes.php" class="nav-item">
                    <span class="nav-item-icon">🍷</span>
                    <span class="nav-item-text">Degustações</span>
                </a>
                <a href="comercial_clientes.php" class="nav-item">
                    <span class="nav-item-icon">👥</span>
                    <span class="nav-item-text">Clientes</span>
                </a>
            </div>
            <?php endif; ?>
            
            <!-- Usuários -->
            <?php if (lc_can_access_module('usuarios')): ?>
            <div class="nav-section">
                <div class="nav-section-title">Sistema</div>
                <a href="usuarios.php" class="nav-item">
                    <span class="nav-item-icon">👤</span>
                    <span class="nav-item-text">Usuários</span>
                </a>
                <a href="configuracoes.php" class="nav-item">
      <span class="nav-item-icon">⚙️</span>
      <span class="nav-item-text">Configurações</span>
    </a>
            </div>
            <?php endif; ?>
        </nav>
    </div>
    
    <!-- Botão Voltar -->
    <a href="javascript:history.back()" class="back-button">
        ← Voltar
    </a>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="content-header">
            <h1 class="content-title">Conteúdo da Página</h1>
      </div>
        <div class="content-body">
            <p>Conteúdo principal da página será exibido aqui.</p>
      </div>
    </div>

<script>
function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  sidebar.classList.toggle('open');
        }
        
        // Fechar sidebar ao clicar fora (mobile)
        document.addEventListener('click', function(e) {
            const sidebar = document.getElementById('sidebar');
            const mobileToggle = document.querySelector('.mobile-toggle');
            
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });
        
        // Marcar item ativo baseado na URL
        document.addEventListener('DOMContentLoaded', function() {
            const currentPath = window.location.pathname;
            const navItems = document.querySelectorAll('.nav-item');
            
            navItems.forEach(item => {
                const href = item.getAttribute('href');
                if (href && currentPath.includes(href.split('?')[0])) {
                    item.classList.add('active');
                }
            });
});
</script>
</body>
</html>