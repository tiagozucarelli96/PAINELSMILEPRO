<?php
// dashboard_inteligente.php — Dashboard inteligente com sugestões baseadas no uso
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/registrar_acesso.php';

class DashboardInteligente {
    private $pdo;
    private $usuarioId;
    private $perfil;
    private $registroAcesso;
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
        $this->usuarioId = $_SESSION['usuario_id'] ?? null;
        $this->perfil = $_SESSION['perfil'] ?? 'CONSULTA';
        $this->registroAcesso = new RegistroAcesso();
        
        // Registrar acesso ao dashboard
        $this->registroAcesso->registrar('Dashboard', 'acesso_pagina');
    }
    
    public function renderizar() {
        echo "<style>
            body { 
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
                margin: 0; 
                padding: 0; 
                background: #f8fafc; 
            }
            
            .dashboard-container {
                margin-left: 280px;
                padding: 20px;
                min-height: 100vh;
            }
            
            .dashboard-header {
                background: white;
                padding: 20px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                margin-bottom: 20px;
            }
            
            .dashboard-title {
                font-size: 2em;
                font-weight: 600;
                color: #1f2937;
                margin: 0;
            }
            
            .dashboard-subtitle {
                color: #6b7280;
                margin-top: 5px;
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
            
            .produtividade {
                background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
                color: white;
                border-radius: 10px;
                padding: 20px;
                margin-bottom: 20px;
            }
            
            .produtividade h3 {
                margin: 0 0 15px 0;
                font-size: 1.3em;
            }
            
            .produtividade-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 10px 0;
                border-bottom: 1px solid rgba(255,255,255,0.1);
            }
            
            .produtividade-item:last-child {
                border-bottom: none;
            }
            
            .produtividade-label {
                flex: 1;
            }
            
            .produtividade-valor {
                font-weight: 600;
            }
            
            .progress-bar {
                width: 100%;
                height: 8px;
                background: rgba(255,255,255,0.2);
                border-radius: 4px;
                overflow: hidden;
                margin-top: 5px;
            }
            
            .progress-fill {
                height: 100%;
                background: rgba(255,255,255,0.8);
                border-radius: 4px;
                transition: width 0.3s ease;
            }
            
            @media (max-width: 768px) {
                .dashboard-container {
                    margin-left: 0;
                    padding: 10px;
                }
                
                .dashboard-grid {
                    grid-template-columns: 1fr;
                }
                
                .stats-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
            }
        </style>";
        
        echo "<div class='dashboard-container'>";
        
        // Header
        echo "<div class='dashboard-header'>";
        echo "<h1 class='dashboard-title'>🏢 Dashboard Inteligente</h1>";
        echo "<p class='dashboard-subtitle'>Bem-vindo, " . ($_SESSION['nome'] ?? 'Usuário') . "! Aqui estão suas informações personalizadas.</p>";
        echo "</div>";
        
        // Estatísticas rápidas
        $this->renderizarEstatisticas();
        
        // Agenda do dia
        $this->renderizarAgendaDia();
        
        // Sugestões inteligentes
        $this->renderizarSugestoes();
        
        // Produtividade
        $this->renderizarProdutividade();
        
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
    
    private function renderizarProdutividade() {
        $produtividade = $this->registroAcesso->obterProdutividade(7);
        
        echo "<div class='produtividade'>";
        echo "<h3>📊 Produtividade (Últimos 7 dias)</h3>";
        
        if (empty($produtividade)) {
            echo "<p>Nenhum dado de produtividade disponível.</p>";
        } else {
            $totalAcessos = array_sum(array_column($produtividade, 'acessos'));
            $mediaAcessos = $totalAcessos / count($produtividade);
            
            echo "<div class='produtividade-item'>";
            echo "<div class='produtividade-label'>Total de Acessos</div>";
            echo "<div class='produtividade-valor'>{$totalAcessos}</div>";
            echo "</div>";
            
            echo "<div class='produtividade-item'>";
            echo "<div class='produtividade-label'>Média Diária</div>";
            echo "<div class='produtividade-valor'>" . round($mediaAcessos, 1) . "</div>";
            echo "</div>";
            
            echo "<div class='produtividade-item'>";
            echo "<div class='produtividade-label'>Dias Ativos</div>";
            echo "<div class='produtividade-valor'>" . count($produtividade) . "/7</div>";
            echo "</div>";
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
            [
                'titulo' => 'Compras',
                'descricao' => 'Controle de compras, fornecedores e listas de compras.',
                'link' => 'lc_index.php',
                'icone' => 'fas fa-shopping-cart',
                'cor' => '#3b82f6',
                'acoes' => [
                    ['texto' => 'Gerar Lista', 'link' => 'lista_compras.php', 'classe' => 'btn-success']
                ]
            ],
            [
                'titulo' => 'Estoque',
                'descricao' => 'Controle de estoque, contagens e movimentações.',
                'link' => 'estoque_contagens.php',
                'icone' => 'fas fa-boxes',
                'cor' => '#f59e0b',
                'acoes' => [
                    ['texto' => 'Nova Contagem', 'link' => 'estoque_contar.php', 'classe' => 'btn-success']
                ]
            ],
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
            [
                'titulo' => 'Financeiro',
                'descricao' => 'Solicitações de pagamento e controle financeiro.',
                'link' => 'pagamentos_painel.php',
                'icone' => 'fas fa-dollar-sign',
                'cor' => '#06b6d4',
                'acoes' => [
                    ['texto' => 'Nova Solicitação', 'link' => 'pagamentos_solicitar.php', 'classe' => 'btn-success']
                ]
            ]
        ];
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

// Renderizar dashboard
$dashboard = new DashboardInteligente();
$dashboard->renderizar();
?>
