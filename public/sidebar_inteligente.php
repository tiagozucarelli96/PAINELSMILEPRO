<?php
// sidebar_inteligente.php — Sidebar otimizada com acesso rápido e inteligência de uso
require_once __DIR__ . '/conexao.php';

class SidebarInteligente {
    private $pdo;
    private $usuarioId;
    private $perfil;
    private $acessosRapidos = [];
    private $modulosFrequentes = [];
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
        $this->usuarioId = $_SESSION['usuario_id'] ?? null;
        $this->perfil = $_SESSION['perfil'] ?? 'CONSULTA';
        $this->carregarAcessosRapidos();
        $this->carregarModulosFrequentes();
    }
    
    private function carregarAcessosRapidos() {
        if (!$this->usuarioId) return;
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT modulo, COUNT(*) as acessos 
                FROM demandas_logs 
                WHERE usuario_id = ? 
                AND acao LIKE '%acesso%' 
                AND created_at >= NOW() - INTERVAL '30 days'
                GROUP BY modulo 
                ORDER BY acessos DESC 
                LIMIT 5
            ");
            $stmt->execute([$this->usuarioId]);
            $this->acessosRapidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // Log error but continue
        }
    }
    
    private function carregarModulosFrequentes() {
        if (!$this->usuarioId) return;
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    CASE 
                        WHEN acao LIKE '%dashboard%' THEN 'Dashboard'
                        WHEN acao LIKE '%usuarios%' THEN 'Usuários'
                        WHEN acao LIKE '%eventos%' THEN 'Eventos'
                        WHEN acao LIKE '%estoque%' THEN 'Estoque'
                        WHEN acao LIKE '%compras%' THEN 'Compras'
                        WHEN acao LIKE '%financeiro%' THEN 'Financeiro'
                        WHEN acao LIKE '%demandas%' THEN 'Demandas'
                        WHEN acao LIKE '%agenda%' THEN 'Agenda'
                        WHEN acao LIKE '%comercial%' THEN 'Comercial'
                        WHEN acao LIKE '%rh%' THEN 'RH'
                        WHEN acao LIKE '%contab%' THEN 'Contabilidade'
                        ELSE 'Outros'
                    END as modulo,
                    COUNT(*) as acessos
                FROM demandas_logs 
                WHERE usuario_id = ? 
                AND created_at >= NOW() - INTERVAL '7 days'
                GROUP BY modulo 
                ORDER BY acessos DESC 
                LIMIT 8
            ");
            $stmt->execute([$this->usuarioId]);
            $this->modulosFrequentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // Log error but continue
        }
    }
    
    public function renderizar() {
        echo "<style>
            .sidebar-inteligente {
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
                .sidebar-inteligente {
                    width: 100%;
                    transform: translateX(-100%);
                }
                
                .sidebar-inteligente.open {
                    transform: translateX(0);
                }
            }
        </style>";
        
        echo "<nav class='sidebar-inteligente' id='sidebar'>";
        
        // Header
        echo "<div class='sidebar-header'>";
        echo "<h3>🏢 Smile PRO</h3>";
        echo "<div class='subtitle'>Sistema Inteligente</div>";
        echo "</div>";
        
        // Acesso Rápido
        if (!empty($this->acessosRapidos)) {
            echo "<div class='sidebar-section'>";
            echo "<h4>⚡ Acesso Rápido</h4>";
            echo "<div class='acesso-rapido'>";
            echo "<h5>Mais Acessados</h5>";
            foreach ($this->acessosRapidos as $acesso) {
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
        
        // Módulos Frequentes
        if (!empty($this->modulosFrequentes)) {
            echo "<div class='sidebar-section'>";
            echo "<h4>📊 Módulos Frequentes</h4>";
            echo "<div class='acesso-rapido'>";
            echo "<h5>Esta Semana</h5>";
            foreach ($this->modulosFrequentes as $modulo) {
                $link = $this->getLinkModulo($modulo['modulo']);
                $icone = $this->getIconeModulo($modulo['modulo']);
                echo "<a href='{$link}' class='quick-link'>";
                echo "<i class='{$icone}'></i> {$modulo['modulo']}";
                echo "<span class='badge'>{$modulo['acessos']}</span>";
                echo "</a>";
            }
            echo "</div>";
            echo "</div>";
        }
        
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
        
        // JavaScript para funcionalidades
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
            });
        </script>";
    }
    
    private function getMenuItems() {
        $menuItems = [
            [
                'titulo' => 'Dashboard',
                'link' => 'dashboard.php',
                'icone' => 'fas fa-home',
                'perfis' => ['ADM', 'GERENTE', 'OPER', 'CONSULTA']
            ],
            [
                'titulo' => 'Comercial',
                'link' => 'comercial_degustacoes.php',
                'icone' => 'fas fa-handshake',
                'perfis' => ['ADM', 'GERENTE', 'OPER'],
                'submenu' => [
                    ['titulo' => 'Degustações', 'link' => 'comercial_degustacoes.php'],
                    ['titulo' => 'Nova Degustação', 'link' => 'comercial_degustacao_editar.php'],
                    ['titulo' => 'Inscritos', 'link' => 'comercial_degust_inscricoes.php'],
                    ['titulo' => 'Conversão', 'link' => 'comercial_clientes.php']
                ]
            ],
            [
                'titulo' => 'Compras',
                'link' => 'lc_index.php',
                'icone' => 'fas fa-shopping-cart',
                'perfis' => ['ADM', 'GERENTE', 'OPER'],
                'submenu' => [
                    ['titulo' => 'Lista de Compras', 'link' => 'lc_index.php'],
                    ['titulo' => 'Gerar Lista', 'link' => 'lista_compras.php'],
                    ['titulo' => 'Fornecedores', 'link' => 'fornecedores.php'],
                    ['titulo' => 'Histórico', 'link' => 'historico.php']
                ]
            ],
            [
                'titulo' => 'Estoque',
                'link' => 'estoque_contagens.php',
                'icone' => 'fas fa-boxes',
                'perfis' => ['ADM', 'GERENTE', 'OPER'],
                'submenu' => [
                    ['titulo' => 'Contagens', 'link' => 'estoque_contagens.php'],
                    ['titulo' => 'Kardex', 'link' => 'estoque_kardex.php'],
                    ['titulo' => 'Alertas', 'link' => 'estoque_alertas.php'],
                    ['titulo' => 'Desvios', 'link' => 'estoque_desvios.php']
                ]
            ],
            [
                'titulo' => 'Financeiro',
                'link' => 'pagamentos_painel.php',
                'icone' => 'fas fa-dollar-sign',
                'perfis' => ['ADM', 'GERENTE'],
                'submenu' => [
                    ['titulo' => 'Solicitações', 'link' => 'pagamentos_painel.php'],
                    ['titulo' => 'Minhas Solicitações', 'link' => 'pagamentos_minhas.php'],
                    ['titulo' => 'Fornecedores', 'link' => 'fornecedores.php']
                ]
            ],
            [
                'titulo' => 'Tarefas',
                'link' => 'demandas_quadros.php',
                'icone' => 'fas fa-tasks',
                'perfis' => ['ADM', 'GERENTE', 'OPER'],
                'submenu' => [
                    ['titulo' => 'Quadros', 'link' => 'demandas_quadros.php'],
                    ['titulo' => 'Minhas Tarefas', 'link' => 'demandas_minhas.php'],
                    ['titulo' => 'Produtividade', 'link' => 'demandas_produtividade.php']
                ]
            ],
            [
                'titulo' => 'Agenda',
                'link' => 'agenda.php',
                'icone' => 'fas fa-calendar',
                'perfis' => ['ADM', 'GERENTE', 'OPER'],
                'submenu' => [
                    ['titulo' => 'Calendário', 'link' => 'agenda.php'],
                    ['titulo' => 'Meus Eventos', 'link' => 'agenda_meus.php'],
                    ['titulo' => 'Relatórios', 'link' => 'agenda_relatorios.php']
                ]
            ],
            [
                'titulo' => 'RH',
                'link' => 'rh_funcionarios.php',
                'icone' => 'fas fa-users',
                'perfis' => ['ADM', 'GERENTE'],
                'submenu' => [
                    ['titulo' => 'Funcionários', 'link' => 'rh_funcionarios.php'],
                    ['titulo' => 'Departamentos', 'link' => 'rh_departamentos.php'],
                    ['titulo' => 'Férias', 'link' => 'rh_ferias.php']
                ]
            ],
            [
                'titulo' => 'Contabilidade',
                'link' => 'contab_transacoes.php',
                'icone' => 'fas fa-calculator',
                'perfis' => ['ADM', 'GERENTE'],
                'submenu' => [
                    ['titulo' => 'Transações', 'link' => 'contab_transacoes.php'],
                    ['titulo' => 'Contas', 'link' => 'contab_contas.php'],
                    ['titulo' => 'Relatórios', 'link' => 'contab_relatorios.php']
                ]
            ],
            [
                'titulo' => 'Configurações',
                'link' => 'configuracoes.php',
                'icone' => 'fas fa-cog',
                'perfis' => ['ADM', 'GERENTE'],
                'submenu' => [
                    ['titulo' => 'Sistema', 'link' => 'configuracoes.php'],
                    ['titulo' => 'Usuários', 'link' => 'usuarios.php'],
                    ['titulo' => 'Permissões', 'link' => 'permissoes.php']
                ]
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
        
        if (isset($item['submenu']) && $ativo) {
            echo "<ul class='sidebar-menu' style='margin-left: 20px;'>";
            foreach ($item['submenu'] as $subitem) {
                $subAtivo = $this->isPaginaAtiva($subitem['link']);
                $subClasse = $subAtivo ? 'active' : '';
                echo "<li>";
                echo "<a href='{$subitem['link']}' class='{$subClasse}'>";
                echo $subitem['titulo'];
                echo "</a>";
                echo "</li>";
            }
            echo "</ul>";
        }
        
        echo "</li>";
    }
    
    private function isPaginaAtiva($link) {
        $paginaAtual = basename($_SERVER['PHP_SELF']);
        return $paginaAtual === basename($link);
    }
    
    private function getLinkModulo($modulo) {
        $links = [
            'Dashboard' => 'dashboard.php',
            'Usuários' => 'usuarios.php',
            'Eventos' => 'eventos.php',
            'Estoque' => 'estoque_contagens.php',
            'Compras' => 'lc_index.php',
            'Financeiro' => 'pagamentos_painel.php',
            'Demandas' => 'demandas_quadros.php',
            'Agenda' => 'agenda.php',
            'Comercial' => 'comercial_degustacoes.php',
            'RH' => 'rh_funcionarios.php',
            'Contabilidade' => 'contab_transacoes.php'
        ];
        
        return $links[$modulo] ?? 'dashboard.php';
    }
    
    private function getIconeModulo($modulo) {
        $icones = [
            'Dashboard' => 'fas fa-home',
            'Usuários' => 'fas fa-users',
            'Eventos' => 'fas fa-calendar',
            'Estoque' => 'fas fa-boxes',
            'Compras' => 'fas fa-shopping-cart',
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

// Renderizar sidebar
$sidebar = new SidebarInteligente();
$sidebar->renderizar();
?>
