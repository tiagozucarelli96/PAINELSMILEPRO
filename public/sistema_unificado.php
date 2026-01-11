<?php
// sistema_unificado.php — Sistema unificado com dashboard e sidebar integrados
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/registrar_acesso.php';

class SistemaUnificado {
    private $pdo;
    private $usuarioId;
    private $perfil;
    private $registroAcesso;
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
        $this->usuarioId = $_SESSION['usuario_id'] ?? null;
        $this->perfil = $_SESSION['perfil'] ?? 'CONSULTA';
        $this->registroAcesso = new RegistroAcesso();
        
        // Verificar autenticação
        if (!$this->usuarioId) {
            header('Location: login.php');
            exit;
        }
    }
    
    public function renderizar() {
        echo "<!DOCTYPE html>";
        echo "<html lang='pt-BR'>";
        echo "<head>";
        echo "<meta charset='UTF-8'>";
        echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
        echo "<title>Painel Smile PRO - Sistema Unificado</title>";
        echo "<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css'>";
        echo "<style>";
        $this->renderizarEstilos();
        echo "</style>";
        echo "</head>";
        echo "<body>";
        
        // Sidebar
        $this->renderizarSidebar();
        
        // Conteúdo principal
        echo "<div class='main-content'>";
        
        // Header
        $this->renderizarHeader();
        
        // Dashboard
        $this->renderizarDashboard();
        
        echo "</div>";
        
        // JavaScript
        $this->renderizarJavaScript();
        
        echo "</body>";
        echo "</html>";
    }
    
    private function renderizarEstilos() {
        echo "
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8fafc;
            color: #1f2937;
        }
        
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            overflow-y: auto;
            z-index: 1000;
            transition: all 0.3s ease;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            text-align: center;
        }
        
        .sidebar-header h3 {
            margin: 0;
            font-size: 1.5em;
            font-weight: 600;
        }
        
        .sidebar-header .subtitle {
            font-size: 0.9em;
            opacity: 0.8;
            margin-top: 5px;
        }
        
        .sidebar-section {
            margin: 20px 0;
        }
        
        .sidebar-section h4 {
            margin: 0 0 10px 0;
            padding: 0 20px;
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.8;
        }
        
        .sidebar-menu {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        
        .sidebar-menu li {
            margin: 0;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }
        
        .sidebar-menu a:hover {
            background: rgba(255,255,255,0.1);
            border-left-color: #10b981;
            transform: translateX(5px);
        }
        
        .sidebar-menu a.active {
            background: rgba(255,255,255,0.15);
            border-left-color: #10b981;
        }
        
        .sidebar-menu i {
            width: 20px;
            margin-right: 12px;
            text-align: center;
        }
        
        .acesso-rapido {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 8px;
            margin: 10px 20px;
            padding: 15px;
        }
        
        .acesso-rapido h5 {
            margin: 0 0 10px 0;
            font-size: 0.9em;
            color: #10b981;
        }
        
        .acesso-rapido .quick-link {
            display: block;
            padding: 8px 12px;
            margin: 5px 0;
            background: rgba(255,255,255,0.1);
            border-radius: 5px;
            color: white;
            text-decoration: none;
            font-size: 0.9em;
            transition: all 0.3s ease;
        }
        
        .acesso-rapido .quick-link:hover {
            background: rgba(255,255,255,0.2);
            transform: translateX(3px);
        }
        
        .sidebar-footer {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 20px;
            border-top: 1px solid rgba(255,255,255,0.1);
            text-align: center;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            font-weight: bold;
        }
        
        .user-details h6 {
            margin: 0;
            font-size: 0.9em;
        }
        
        .user-details small {
            opacity: 0.8;
            font-size: 0.8em;
        }
        
        .logout-btn {
            display: block;
            width: 100%;
            padding: 10px;
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 5px;
            color: white;
            text-decoration: none;
            text-align: center;
            font-size: 0.9em;
            transition: all 0.3s ease;
        }
        
        .logout-btn:hover {
            background: rgba(239, 68, 68, 0.3);
            transform: translateY(-2px);
        }
        
        .main-content {
            margin-left: 280px;
            min-height: 100vh;
        }
        
        .header {
            background: white;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .header-left {
            display: flex;
            align-items: center;
        }
        
        .header-title {
            font-size: 1.8em;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }
        
        .header-subtitle {
            color: #6b7280;
            margin-left: 15px;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .notification-btn {
            position: relative;
            background: none;
            border: none;
            font-size: 1.2em;
            color: #6b7280;
            cursor: pointer;
            padding: 10px;
            border-radius: 50%;
            transition: all 0.3s ease;
        }
        
        .notification-btn:hover {
            background: #f3f4f6;
            color: #3b82f6;
        }
        
        .notification-badge {
            position: absolute;
            top: 5px;
            right: 5px;
            background: #ef4444;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.7em;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .dashboard-container {
            padding: 20px;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .dashboard-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        
        .card-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .card-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 1.2em;
            color: white;
        }
        
        .card-title {
            font-size: 1.2em;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }
        
        .card-content {
            color: #6b7280;
            line-height: 1.6;
        }
        
        .card-actions {
            margin-top: 15px;
        }
        
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #3b82f6;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 0.9em;
            transition: background 0.3s ease;
            border: none;
            cursor: pointer;
        }
        
        .btn:hover {
            background: #1d4ed8;
        }
        
        .btn-success {
            background: #10b981;
        }
        
        .btn-success:hover {
            background: #059669;
        }
        
        .btn-warning {
            background: #f59e0b;
        }
        
        .btn-warning:hover {
            background: #d97706;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #3b82f6;
            margin: 0;
        }
        
        .stat-label {
            color: #6b7280;
            margin-top: 5px;
        }
        
        .agenda-dia {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .agenda-dia h3 {
            margin: 0 0 15px 0;
            font-size: 1.3em;
        }
        
        .evento-item {
            background: rgba(255,255,255,0.1);
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .evento-info {
            flex: 1;
        }
        
        .evento-titulo {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .evento-horario {
            font-size: 0.9em;
            opacity: 0.8;
        }
        
        .evento-acoes {
            display: flex;
            gap: 10px;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 0.8em;
        }
        
        .sugestoes {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .sugestoes h3 {
            margin: 0 0 15px 0;
            font-size: 1.3em;
        }
        
        .sugestao-item {
            background: rgba(255,255,255,0.1);
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .sugestao-texto {
            flex: 1;
        }
        
        .sugestao-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 0.9em;
            transition: all 0.3s ease;
        }
        
        .sugestao-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        .badge {
            background: #10b981;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.7em;
            margin-left: auto;
        }
        
        .badge.warning {
            background: #f59e0b;
        }
        
        .badge.danger {
            background: #ef4444;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                transform: translateX(-100%);
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        ";
    }
    
    private function renderizarSidebar() {
        echo "<nav class='sidebar' id='sidebar'>";
        
        // Header
        echo "<div class='sidebar-header'>";
        echo "<h3>🏢 Smile PRO</h3>";
        echo "<div class='subtitle'>Sistema Unificado</div>";
        echo "</div>";
        
        // Acesso Rápido
        $acessosRapidos = $this->registroAcesso->obterModulosFrequentes(5);
        if (!empty($acessosRapidos)) {
            echo "<div class='sidebar-section'>";
            echo "<h4>⚡ Acesso Rápido</h4>";
            echo "<div class='acesso-rapido'>";
            echo "<h5>Mais Acessados</h5>";
            foreach ($acessosRapidos as $acesso) {
                $modulo = $acesso['modulo'];
                $link = $this->getLinkModulo($modulo);
                $icone = $this->getIconeModulo($modulo);
                echo "<a href='{$link}' class='quick-link'>";
                echo "<i class='{$icone}'></i> {$modulo}";
                echo "<span class='badge'>{$acesso['acessos']}</span>";
                echo "</a>";
            }
            echo "</div>";
            echo "</div>";
        }
        
        // Menu Principal
        echo "<div class='sidebar-section'>";
        echo "<h4>📋 Menu Principal</h4>";
        echo "<ul class='sidebar-menu'>";
        
        $menuItems = $this->getMenuItems();
        foreach ($menuItems as $item) {
            $this->renderizarMenuItem($item);
        }
        
        echo "</ul>";
        echo "</div>";
        
        // Footer
        echo "<div class='sidebar-footer'>";
        echo "<div class='user-info'>";
        echo "<div class='user-avatar'>";
        echo strtoupper(substr($_SESSION['nome'] ?? 'U', 0, 1));
        echo "</div>";
        echo "<div class='user-details'>";
        echo "<h6>" . ($_SESSION['nome'] ?? 'Usuário') . "</h6>";
        echo "<small>" . $this->perfil . "</small>";
        echo "</div>";
        echo "</div>";
        echo "<a href='logout.php' class='logout-btn'>🚪 Sair</a>";
        echo "</div>";
        
        echo "</nav>";
    }
    
    private function renderizarHeader() {
        echo "<div class='header'>";
        echo "<div class='header-left'>";
        echo "<h1 class='header-title'>Dashboard</h1>";
        echo "<span class='header-subtitle'>Bem-vindo, " . ($_SESSION['nome'] ?? 'Usuário') . "!</span>";
        echo "</div>";
        echo "<div class='header-right'>";
        echo "<button class='notification-btn' id='notification-btn'>";
        echo "<i class='fas fa-bell'></i>";
        echo "<span class='notification-badge' id='notification-badge'>0</span>";
        echo "</button>";
        echo "<button class='notification-btn' id='sidebar-toggle'>";
        echo "<i class='fas fa-bars'></i>";
        echo "</button>";
        echo "</div>";
        echo "</div>";
    }
    
    private function renderizarDashboard() {
        echo "<div class='dashboard-container'>";
        
        // Estatísticas rápidas
        $this->renderizarEstatisticas();
        
        // Agenda do dia
        $this->renderizarAgendaDia();
        
        // Sugestões inteligentes
        $this->renderizarSugestoes();
        
        // Módulos principais
        $this->renderizarModulosPrincipais();
        
        echo "</div>";
    }
    
    private function renderizarEstatisticas() {
        $estatisticas = $this->obterEstatisticas();
        
        echo "<div class='stats-grid'>";
        
        $stats = [
            [
                'numero' => $estatisticas['total_acessos'] ?? 0,
                'label' => 'Acessos Hoje',
                'cor' => '#3b82f6'
            ],
            [
                'numero' => $estatisticas['modulos_unicos'] ?? 0,
                'label' => 'Módulos Usados',
                'cor' => '#10b981'
            ],
            [
                'numero' => $estatisticas['tarefas_pendentes'] ?? 0,
                'label' => 'Tarefas Pendentes',
                'cor' => '#f59e0b'
            ],
            [
                'numero' => $estatisticas['eventos_hoje'] ?? 0,
                'label' => 'Eventos Hoje',
                'cor' => '#8b5cf6'
            ]
        ];
        
        foreach ($stats as $stat) {
            echo "<div class='stat-card'>";
            echo "<div class='stat-number' style='color: {$stat['cor']}'>{$stat['numero']}</div>";
            echo "<div class='stat-label'>{$stat['label']}</div>";
            echo "</div>";
        }
        
        echo "</div>";
    }
    
    private function renderizarAgendaDia() {
        $eventos = $this->obterEventosHoje();
        
        echo "<div class='agenda-dia'>";
        echo "<h3>📅 Agenda do Dia</h3>";
        
        if (empty($eventos)) {
            echo "<p>Nenhum evento agendado para hoje.</p>";
        } else {
            foreach ($eventos as $evento) {
                echo "<div class='evento-item'>";
                echo "<div class='evento-info'>";
                echo "<div class='evento-titulo'>{$evento['titulo']}</div>";
                echo "<div class='evento-horario'>{$evento['horario']}</div>";
                echo "</div>";
                echo "<div class='evento-acoes'>";
                echo "<a href='agenda.php' class='btn btn-sm'>Ver</a>";
                echo "</div>";
                echo "</div>";
            }
        }
        
        echo "<div style='text-align: center; margin-top: 15px;'>";
        echo "<a href='agenda.php' class='btn'>Ver Agenda Completa</a>";
        echo "</div>";
        
        echo "</div>";
    }
    
    private function renderizarSugestoes() {
        $sugestoes = $this->registroAcesso->obterSugestoes();
        
        echo "<div class='sugestoes'>";
        echo "<h3>💡 Sugestões Inteligentes</h3>";
        
        if (empty($sugestoes)) {
            echo "<p>Nenhuma sugestão disponível no momento.</p>";
        } else {
            foreach ($sugestoes as $sugestao) {
                $link = $this->getLinkModulo($sugestao['modulo']);
                $icone = $this->getIconeModulo($sugestao['modulo']);
                
                echo "<div class='sugestao-item'>";
                echo "<div class='sugestao-texto'>";
                echo "<strong>{$sugestao['modulo']}</strong> - Baseado no uso de outros usuários";
                echo "</div>";
                echo "<a href='{$link}' class='sugestao-btn'>Acessar</a>";
                echo "</div>";
            }
        }
        
        echo "</div>";
    }
    
    private function renderizarModulosPrincipais() {
        $modulos = $this->getModulosPrincipais();
        
        echo "<div class='dashboard-grid'>";
        
        foreach ($modulos as $modulo) {
            $cor = $modulo['cor'];
            $icone = $modulo['icone'];
            $titulo = $modulo['titulo'];
            $descricao = $modulo['descricao'];
            $link = $modulo['link'];
            $acoes = $modulo['acoes'];
            
            echo "<div class='dashboard-card'>";
            echo "<div class='card-header'>";
            echo "<div class='card-icon' style='background: {$cor}'>";
            echo "<i class='{$icone}'></i>";
            echo "</div>";
            echo "<h3 class='card-title'>{$titulo}</h3>";
            echo "</div>";
            echo "<div class='card-content'>{$descricao}</div>";
            echo "<div class='card-actions'>";
            echo "<a href='{$link}' class='btn'>Acessar</a>";
            if (!empty($acoes)) {
                foreach ($acoes as $acao) {
                    echo "<a href='{$acao['link']}' class='btn {$acao['classe']}'>{$acao['texto']}</a>";
                }
            }
            echo "</div>";
            echo "</div>";
        }
        
        echo "</div>";
    }
    
    private function renderizarJavaScript() {
        echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                // Registrar acesso
                function registrarAcesso(modulo) {
                    fetch('registrar_acesso.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            modulo: modulo,
                            acao: 'acesso_pagina'
                        })
                    });
                }
                
                // Adicionar evento de clique aos links
                document.querySelectorAll('.sidebar-menu a, .quick-link').forEach(link => {
                    link.addEventListener('click', function() {
                        const modulo = this.textContent.trim();
                        registrarAcesso(modulo);
                    });
                });
                
                // Toggle sidebar em mobile
                const sidebar = document.getElementById('sidebar');
                const toggleBtn = document.getElementById('sidebar-toggle');
                
                if (toggleBtn) {
                    toggleBtn.addEventListener('click', function() {
                        sidebar.classList.toggle('open');
                    });
                }
                
                // Fechar sidebar ao clicar fora (mobile)
                document.addEventListener('click', function(e) {
                    if (window.innerWidth <= 768) {
                        if (!sidebar.contains(e.target) && !toggleBtn.contains(e.target)) {
                            sidebar.classList.remove('open');
                        }
                    }
                });
                
                // Botão voltar
                function adicionarBotaoVoltar() {
                    const botaoVoltar = document.createElement('button');
                    botaoVoltar.innerHTML = '← Voltar';
                    botaoVoltar.className = 'btn btn-warning';
                    botaoVoltar.style.position = 'fixed';
                    botaoVoltar.style.bottom = '20px';
                    botaoVoltar.style.right = '20px';
                    botaoVoltar.style.zIndex = '1000';
                    botaoVoltar.addEventListener('click', function() {
                        history.back();
                    });
                    document.body.appendChild(botaoVoltar);
                }
                
                adicionarBotaoVoltar();
            });
        </script>";
    }
    
    private function getMenuItems() {
        $menuItems = [
            [
                'titulo' => 'Dashboard',
                'link' => 'sistema_unificado.php',
                'icone' => 'fas fa-home',
                'perfis' => ['ADM', 'GERENTE', 'OPER', 'CONSULTA']
            ],
            [
                'titulo' => 'Comercial',
                'link' => 'comercial_degustacoes.php',
                'icone' => 'fas fa-handshake',
                'perfis' => ['ADM', 'GERENTE', 'OPER']
            ],
            // REMOVIDO: Módulo Compras (Lista de Compras)
            // REMOVIDO: Módulo Estoque
            [
                'titulo' => 'Tarefas',
                'link' => 'demandas_quadros.php',
                'icone' => 'fas fa-tasks',
                'perfis' => ['ADM', 'GERENTE', 'OPER']
            ],
            [
                'titulo' => 'Agenda',
                'link' => 'agenda.php',
                'icone' => 'fas fa-calendar',
                'perfis' => ['ADM', 'GERENTE', 'OPER']
            ],
            [
                'titulo' => 'RH',
                'link' => 'rh_funcionarios.php',
                'icone' => 'fas fa-users',
                'perfis' => ['ADM', 'GERENTE']
            ],
            [
                'titulo' => 'Contabilidade',
                'link' => 'contab_transacoes.php',
                'icone' => 'fas fa-calculator',
                'perfis' => ['ADM', 'GERENTE']
            ],
            [
                'titulo' => 'Configurações',
                'link' => 'configuracoes.php',
                'icone' => 'fas fa-cog',
                'perfis' => ['ADM', 'GERENTE']
            ]
        ];
        
        // Filtrar por perfil
        return array_filter($menuItems, function($item) {
            return in_array($this->perfil, $item['perfis']);
        });
    }
    
    private function renderizarMenuItem($item) {
        $ativo = $this->isPaginaAtiva($item['link']);
        $classe = $ativo ? 'active' : '';
        
        echo "<li>";
        echo "<a href='{$item['link']}' class='{$classe}'>";
        echo "<i class='{$item['icone']}'></i>";
        echo $item['titulo'];
        echo "</a>";
        echo "</li>";
    }
    
    private function isPaginaAtiva($link) {
        $paginaAtual = basename($_SERVER['PHP_SELF']);
        return $paginaAtual === basename($link);
    }
    
    private function getModulosPrincipais() {
        return [
            [
                'titulo' => 'Comercial',
                'descricao' => 'Gerencie degustações, inscrições e conversões de clientes.',
                'link' => 'comercial_degustacoes.php',
                'icone' => 'fas fa-handshake',
                'cor' => '#10b981',
                'acoes' => [
                    ['texto' => 'Nova Degustação', 'link' => 'comercial_degustacao_editar.php', 'classe' => 'btn-success']
                ]
            ],
            // REMOVIDO: Módulo Compras (Lista de Compras)
            // REMOVIDO: Módulo Estoque
            [
                'titulo' => 'Tarefas',
                'descricao' => 'Gerencie suas tarefas e demandas do dia a dia.',
                'link' => 'demandas_quadros.php',
                'icone' => 'fas fa-tasks',
                'cor' => '#8b5cf6',
                'acoes' => [
                    ['texto' => 'Minhas Tarefas', 'link' => 'demandas_minhas.php', 'classe' => 'btn-success']
                ]
            ],
            [
                'titulo' => 'Agenda',
                'descricao' => 'Agenda interna com eventos e visitas.',
                'link' => 'agenda.php',
                'icone' => 'fas fa-calendar',
                'cor' => '#ef4444',
                'acoes' => [
                    ['texto' => 'Novo Evento', 'link' => 'agenda_novo.php', 'classe' => 'btn-success']
                ]
            ],
            ]
        ];
    }
    
    private function obterEstatisticas() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_acessos,
                    COUNT(DISTINCT entidade) as modulos_unicos
                FROM demandas_logs 
                WHERE usuario_id = ? 
                AND acao = 'acesso_pagina'
                AND DATE(created_at) = CURDATE()
            ");
            $stmt->execute([$this->usuarioId]);
            $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Adicionar outras estatísticas
            $resultado['tarefas_pendentes'] = $this->contarTarefasPendentes();
            $resultado['eventos_hoje'] = $this->contarEventosHoje();
            
            return $resultado;
        } catch (Exception $e) {
            return [
                'total_acessos' => 0,
                'modulos_unicos' => 0,
                'tarefas_pendentes' => 0,
                'eventos_hoje' => 0
            ];
        }
    }
    
    private function obterEventosHoje() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    titulo,
                    CONCAT(TIME_FORMAT(data_inicio, '%H:%i'), ' - ', TIME_FORMAT(data_fim, '%H:%i')) as horario
                FROM agenda_eventos 
                WHERE DATE(data_inicio) = CURDATE()
                AND usuario_id = ?
                ORDER BY data_inicio
            ");
            $stmt->execute([$this->usuarioId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
    
    private function contarTarefasPendentes() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) 
                FROM demandas_cartoes 
                WHERE responsavel_id = ? 
                AND status = 'ativo'
                AND (data_vencimento IS NULL OR data_vencimento >= NOW())
            ");
            $stmt->execute([$this->usuarioId]);
            return $stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
    
    private function contarEventosHoje() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) 
                FROM agenda_eventos 
                WHERE DATE(data_inicio) = CURDATE()
                AND usuario_id = ?
            ");
            $stmt->execute([$this->usuarioId]);
            return $stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
    
    private function getLinkModulo($modulo) {
        $links = [
            'Dashboard' => 'sistema_unificado.php',
            'Usuários' => 'usuarios.php',
            'Eventos' => 'eventos.php',
            // 'Estoque' => 'estoque_contagens.php', // REMOVIDO
            // 'Compras' => 'lc_index.php', // REMOVIDO
            'Financeiro' => 'pagamentos_painel.php',
            'Demandas' => 'demandas_quadros.php',
            'Agenda' => 'agenda.php',
            'Comercial' => 'comercial_degustacoes.php',
            'RH' => 'rh_funcionarios.php',
            'Contabilidade' => 'contab_transacoes.php'
        ];
        
        return $links[$modulo] ?? 'sistema_unificado.php';
    }
    
    private function getIconeModulo($modulo) {
        $icones = [
            'Dashboard' => 'fas fa-home',
            'Usuários' => 'fas fa-users',
            'Eventos' => 'fas fa-calendar',
            // 'Estoque' => 'fas fa-boxes', // REMOVIDO
            // 'Compras' => 'fas fa-shopping-cart', // REMOVIDO
            'Financeiro' => 'fas fa-dollar-sign',
            'Demandas' => 'fas fa-tasks',
            'Agenda' => 'fas fa-calendar-alt',
            'Comercial' => 'fas fa-handshake',
            'RH' => 'fas fa-user-tie',
            'Contabilidade' => 'fas fa-calculator'
        ];
        
        return $icones[$modulo] ?? 'fas fa-circle';
    }
}

// Renderizar sistema unificado
$sistema = new SistemaUnificado();
$sistema->renderizar();
?>
