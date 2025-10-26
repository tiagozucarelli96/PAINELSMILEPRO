<?php
// test_sidebar_funcional.php — Teste da sidebar funcional
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Simular sessão se não existir
if (empty($_SESSION['logado'])) {
    $_SESSION['logado'] = true;
    $_SESSION['nome'] = 'Tiago Zucarelli';
    $_SESSION['perfil'] = 'ADM';
    $_SESSION['user_id'] = 1;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste Sidebar Funcional - GRUPO Smile EVENTOS</title>
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
        
        .content-area {
            padding: 20px;
        }
        
        .test-info {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        
        .test-info h1 {
            color: #1e3a8a;
            margin-bottom: 20px;
        }
        
        .test-info p {
            color: #64748b;
            margin-bottom: 15px;
        }
        
        .status-indicator {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin: 5px;
        }
        
        .status-success {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-error {
            background: #fef2f2;
            color: #dc2626;
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
                    <div class="user-avatar">TI</div>
                    <div class="user-name">Tiago Zucarelli</div>
                    <div class="user-plan">ADMINISTRADOR</div>
                </div>
            </div>
            
            <nav class="sidebar-nav">
                <!-- Dashboard -->
                <div class="nav-section">
                    <a href="#" class="nav-item active" data-page="dashboard">
                        <span class="nav-item-icon">🏠</span>
                        Dashboard
                    </a>
                </div>
                
                <!-- Comercial -->
                <div class="nav-section">
                    <div class="nav-section-title">Comercial</div>
                    <a href="#" class="nav-item" data-page="comercial_degustacoes">
                        <span class="nav-item-icon">📋</span>
                        Contratos & Clientes
                    </a>
                    <a href="#" class="nav-item" data-page="comercial_degust_inscricoes">
                        <span class="nav-item-icon">💬</span>
                        Comunicação & Propostas
                    </a>
                    <a href="#" class="nav-item" data-page="comercial_clientes">
                        <span class="nav-item-icon">📊</span>
                        Indicadores de Vendas
                    </a>
                </div>
                
                <!-- Agenda -->
                <div class="nav-section">
                    <div class="nav-section-title">Agenda</div>
                    <a href="#" class="nav-item" data-page="agenda">
                        <span class="nav-item-icon">📅</span>
                        Agenda Geral
                    </a>
                    <a href="#" class="nav-item" data-page="agenda_config">
                        <span class="nav-item-icon">✅</span>
                        Checklist dos Eventos
                    </a>
                    <a href="#" class="nav-item" data-page="agenda_relatorios">
                        <span class="nav-item-icon">🏢</span>
                        Espaços & Reservas
                    </a>
                    <a href="#" class="nav-item" data-page="demandas">
                        <span class="nav-item-icon">⚡</span>
                        Demandas Operacionais
                    </a>
                </div>
                
                <!-- Administrativo -->
                <div class="nav-section">
                    <div class="nav-section-title">Administrativo</div>
                    <a href="#" class="nav-item" data-page="usuarios">
                        <span class="nav-item-icon">👥</span>
                        Equipe & Permissões
                    </a>
                    <a href="#" class="nav-item" data-page="usuarios">
                        <span class="nav-item-icon">👤</span>
                        Colaboradores
                    </a>
                    <a href="#" class="nav-item" data-page="pagamentos">
                        <span class="nav-item-icon">💰</span>
                        Pagamentos
                    </a>
                    <a href="#" class="nav-item" data-page="contab_link">
                        <span class="nav-item-icon">📊</span>
                        Contabilidade
                    </a>
                    <a href="#" class="nav-item" data-page="config_fornecedores">
                        <span class="nav-item-icon">🏢</span>
                        Fornecedores
                    </a>
                    <a href="#" class="nav-item" data-page="configuracoes">
                        <span class="nav-item-icon">🎯</span>
                        Metas & Configurações
                    </a>
                </div>
                
                <!-- Logístico -->
                <div class="nav-section">
                    <div class="nav-section-title">Logístico</div>
                    <a href="#" class="nav-item" data-page="lc_index">
                        <span class="nav-item-icon">🛒</span>
                        Lista de Compras & Encomendas
                    </a>
                    <a href="#" class="nav-item" data-page="estoque_logistico">
                        <span class="nav-item-icon">📦</span>
                        Estoque & Alertas
                    </a>
                    <a href="#" class="nav-item" data-page="ver">
                        <span class="nav-item-icon">📋</span>
                        Separação por Evento
                    </a>
                    <a href="#" class="nav-item" data-page="notas_fiscais">
                        <span class="nav-item-icon">📄</span>
                        Entrada por Nota Fiscal
                    </a>
                </div>
                
                <!-- Configurações -->
                <div class="nav-section">
                    <div class="nav-section-title">Configurações</div>
                    <a href="#" class="nav-item" data-page="configuracoes">
                        <span class="nav-item-icon">🔗</span>
                        Integrações
                    </a>
                    <a href="#" class="nav-item" data-page="verificacao_completa_erros">
                        <span class="nav-item-icon">🔧</span>
                        Diagnóstico & Manutenção
                    </a>
                </div>
            </nav>
        </div>
        
        <div class="main-content">
            <div class="content-area">
                <div class="test-info">
                    <h1>🧪 Teste da Sidebar Funcional</h1>
                    <p><strong>Status:</strong> <span class="status-indicator status-success">✅ Sidebar Carregada</span></p>
                    <p><strong>Sessão:</strong> <span class="status-indicator status-success">✅ Sessão Ativa</span></p>
                    <p><strong>Usuário:</strong> Tiago Zucarelli (ADMINISTRADOR)</p>
                    <p><strong>Funcionalidade:</strong> Clique nos itens da sidebar para testar a navegação</p>
                </div>
                
                <div id="pageContent">
                    <div class="test-info">
                        <h2>📊 Dashboard</h2>
                        <p>Esta é a página do dashboard. Clique nos itens da sidebar para navegar para outras páginas.</p>
                        <p><strong>Próximos passos:</strong></p>
                        <ul style="margin-left: 20px; color: #64748b;">
                            <li>Teste clicando em "Comercial" → "Contratos & Clientes"</li>
                            <li>Teste clicando em "Agenda" → "Agenda Geral"</li>
                            <li>Teste clicando em "Administrativo" → "Equipe & Permissões"</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Função para carregar conteúdo das páginas
        function loadPageContent(page) {
            const pageContent = document.getElementById('pageContent');
            if (!pageContent) return;
            
            // Mostrar loading
            pageContent.innerHTML = '<div style="text-align: center; padding: 50px; color: #64748b;"><div style="font-size: 24px; margin-bottom: 20px;">⏳</div><div>Carregando...</div></div>';
            
            // Simular carregamento de página
            setTimeout(() => {
                pageContent.innerHTML = `
                    <div class="test-info">
                        <h2>📄 ${getPageTitle(page)}</h2>
                        <p><strong>Página:</strong> ${page}</p>
                        <p><strong>Status:</strong> <span class="status-indicator status-success">✅ Página Carregada</span></p>
                        <p><strong>Funcionalidade:</strong> Esta é uma simulação da página ${page}</p>
                        <p><strong>Próximo passo:</strong> Integrar com o sistema real de roteamento</p>
                    </div>
                `;
            }, 1000);
        }
        
        function getPageTitle(page) {
            const titles = {
                'dashboard': 'Dashboard',
                'comercial_degustacoes': 'Contratos & Clientes',
                'comercial_degust_inscricoes': 'Comunicação & Propostas',
                'comercial_clientes': 'Indicadores de Vendas',
                'agenda': 'Agenda Geral',
                'agenda_config': 'Checklist dos Eventos',
                'agenda_relatorios': 'Espaços & Reservas',
                'demandas': 'Demandas Operacionais',
                'usuarios': 'Equipe & Permissões',
                'pagamentos': 'Pagamentos',
                'contab_link': 'Contabilidade',
                'config_fornecedores': 'Fornecedores',
                'configuracoes': 'Metas & Configurações',
                'lc_index': 'Lista de Compras & Encomendas',
                'estoque_logistico': 'Estoque & Alertas',
                'ver': 'Separação por Evento',
                'notas_fiscais': 'Entrada por Nota Fiscal',
                'verificacao_completa_erros': 'Diagnóstico & Manutenção'
            };
            return titles[page] || page;
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
        });
    </script>
</body>
</html>
