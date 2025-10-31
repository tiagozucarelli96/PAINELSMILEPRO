<?php
// sidebar_unified.php — Sistema unificado de sidebar para todas as páginas
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Headers para evitar cache na dashboard (especialmente demandas do dia)
if (isset($_GET['page']) && $_GET['page'] === 'dashboard') {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Garantir que $pdo está disponível
if (!isset($pdo)) {
    require_once __DIR__ . '/conexao.php';
}
// Garantir que $pdo está disponível globalmente
if (!isset($pdo) && isset($GLOBALS['pdo'])) {
    $pdo = $GLOBALS['pdo'];
}

$nomeUser = $_SESSION['nome'] ?? 'Usuário';
$perfil = $_SESSION['perfil'] ?? 'CONSULTA';
$current_page = $_GET['page'] ?? 'dashboard';

// Inicializar variáveis de conteúdo (para evitar erros undefined)
$dashboard_content = '';
$comercial_content = '';
$logistico_content = '';
$configuracoes_content = '';
$cadastros_content = '';
$financeiro_content = '';
$administrativo_content = '';

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

// Se for dashboard, comercial ou logistico, criar conteúdo diretamente
if ($current_page === 'dashboard') {
    // Buscar dados reais do banco
    require_once __DIR__ . '/conexao.php';
    
    $stats = [];
    $user_email = $_SESSION['email'] ?? $_SESSION['user_email'] ?? 'Não informado';
    
    try {
        // 1. Inscritos em Degustações Ativas do Mês
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM comercial_inscricoes ci
            JOIN comercial_degustacoes cd ON ci.degustacao_id = cd.id
            WHERE cd.status = 'publicado'
            AND DATE_TRUNC('month', ci.criado_em) = DATE_TRUNC('month', CURRENT_DATE)
            AND ci.status IN ('confirmado', 'lista_espera')
        ");
        $stmt->execute();
        $stats['inscritos_degustacao'] = $stmt->fetchColumn() ?: 0;
        
        // 2. Eventos Criados via ME Eventos (webhook)
        // Conta APENAS eventos event_created do mês atual
        // Automaticamente zera no primeiro dia do mês (só conta eventos do mês atual)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM me_eventos_webhook 
            WHERE webhook_tipo = 'created'
            AND DATE_TRUNC('month', recebido_em) = DATE_TRUNC('month', CURRENT_DATE)
            -- Filtra apenas eventos do mês atual, automaticamente zera no dia 1
        ");
        $stmt->execute();
        $stats['eventos_criados'] = $stmt->fetchColumn() ?: 0;
        
        // 3. Visitas Realizadas (Agenda)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM agenda_eventos 
            WHERE tipo = 'visita'
            AND status = 'realizado'
            AND DATE_TRUNC('month', inicio) = DATE_TRUNC('month', CURRENT_DATE)
        ");
        $stmt->execute();
        $stats['visitas_realizadas'] = $stmt->fetchColumn() ?: 0;
        
        // 4. Fechamentos Realizados (Agenda)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM agenda_eventos 
            WHERE fechou_contrato = true
            AND DATE_TRUNC('month', inicio) = DATE_TRUNC('month', CURRENT_DATE)
        ");
        $stmt->execute();
        $stats['fechamentos_realizados'] = $stmt->fetchColumn() ?: 0;
        
    } catch (Exception $e) {
        // Se der erro, usar valores padrão
        $stats = [
            'inscritos_degustacao' => 0,
            'eventos_criados' => 0,
            'visitas_realizadas' => 0,
            'fechamentos_realizados' => 0
        ];
    }
    
    // Buscar agenda do dia atual
    $agenda_hoje = [];
    try {
        $stmt = $pdo->prepare("
            SELECT ae.id, ae.titulo, ae.inicio as data_inicio, ae.fim as data_fim, ae.tipo, ae.cor_evento as cor, ae.descricao as observacoes,
                   u.nome as responsavel_nome
            FROM agenda_eventos ae
            LEFT JOIN usuarios u ON u.id = ae.responsavel_usuario_id
            WHERE DATE(ae.inicio) = CURRENT_DATE
            ORDER BY ae.inicio ASC
            LIMIT 10
        ");
        $stmt->execute();
        $agenda_hoje = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $agenda_hoje = [];
    }
    
    // Buscar demandas do dia atual (sistema Trello - demandas_cards)
    // IMPORTANTE: Sistema atual usa demandas_cards (Trello), não a tabela antiga 'demandas'
    // Removido fallback para tabela antiga - só mostra cards do sistema atual
    $demandas_hoje = [];
    try {
        // Primeiro tentar buscar cards com prazo de hoje
        $stmt = $pdo->prepare("
            SELECT DISTINCT ON (dc.id)
                   dc.id, 
                   dc.titulo,
                   dc.titulo as descricao,
                   dc.prazo, 
                   dc.status,
                   db.nome as quadro_nome,
                   u.nome as responsavel_nome
            FROM demandas_cards dc
            JOIN demandas_listas dl ON dl.id = dc.lista_id
            JOIN demandas_boards db ON db.id = dl.board_id
            LEFT JOIN demandas_cards_usuarios dcu ON dcu.card_id = dc.id
            LEFT JOIN usuarios u ON u.id = dcu.usuario_id
            WHERE DATE(dc.prazo) = CURRENT_DATE
            AND dc.status NOT IN ('concluido', 'cancelado')
            ORDER BY dc.id, dc.prazo ASC
            LIMIT 10
        ");
        $stmt->execute();
        $demandas_hoje = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Se não encontrou cards com prazo de hoje, buscar os mais recentes ativos
        // (mostra cards pendentes criados recentemente sem prazo definido)
        if (empty($demandas_hoje)) {
            try {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT ON (dc.id)
                           dc.id, 
                           dc.titulo,
                           dc.titulo as descricao,
                           dc.prazo, 
                           dc.status,
                           db.nome as quadro_nome,
                           u.nome as responsavel_nome
                    FROM demandas_cards dc
                    JOIN demandas_listas dl ON dl.id = dc.lista_id
                    JOIN demandas_boards db ON db.id = dl.board_id
                    LEFT JOIN demandas_cards_usuarios dcu ON dcu.card_id = dc.id
                    LEFT JOIN usuarios u ON u.id = dcu.usuario_id
                    WHERE dc.status NOT IN ('concluido', 'cancelado')
                    AND DATE(dc.criado_em) >= CURRENT_DATE - INTERVAL '7 days'
                    ORDER BY dc.id, dc.criado_em DESC
                    LIMIT 5
                ");
                $stmt->execute();
                $demandas_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Mostrar apenas se houver cards recentes, senão deixa vazio
                if (!empty($demandas_recentes)) {
                    $demandas_hoje = $demandas_recentes;
                }
            } catch (Exception $e2) {
                error_log("Erro ao buscar demandas recentes: " . $e2->getMessage());
            }
        }
    } catch (Exception $e) {
        error_log("Erro ao buscar demandas do dia (Trello): " . $e->getMessage());
        $demandas_hoje = [];
    }
    
    // Buscar notificações não lidas para o usuário atual
    $notificacoes_nao_lidas = 0;
    $usuario_id_dashboard = $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? null;
    if ($usuario_id_dashboard) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM demandas_notificacoes WHERE usuario_id = :user_id AND lida = FALSE");
            $stmt->execute([':user_id' => $usuario_id_dashboard]);
            $notificacoes_nao_lidas = (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("Erro ao contar notificações: " . $e->getMessage());
        }
    }
    
    $dashboard_content = '
    <div class="page-container">
        <div class="page-header">
            <div style="flex: 1;">
                <h1 class="page-title">🏠 Dashboard</h1>
                <p class="page-subtitle">Bem-vindo, ' . htmlspecialchars($nomeUser) . '! | Email: ' . htmlspecialchars($user_email) . '</p>
            </div>
            <div class="dashboard-notificacoes-badge" onclick="toggleDashboardNotificacoes(event)" aria-label="Notificações" style="position: relative; cursor: pointer; padding: 0.75rem; border-radius: 50%; background: #3b82f6; color: white; display: flex; align-items: center; justify-content: center; min-width: 48px; min-height: 48px; transition: background 0.2s;">
                <img src="assets/icons/bell.svg" alt="Notificações" style="width: 24px; height: 24px; filter: brightness(0) invert(1);" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'inline\';">
                <span style="font-size: 1.5rem; display: none;">🔔</span>
                <span id="dashboard-notificacoes-count" class="dashboard-notificacoes-count" style="position: absolute; top: 4px; right: 4px; background: #ef4444; color: white; border-radius: 10px; padding: 2px 6px; font-size: 0.7rem; font-weight: 600; min-width: 18px; text-align: center; line-height: 1.4; ' . ($notificacoes_nao_lidas > 0 ? '' : 'display: none;') . '">' . htmlspecialchars($notificacoes_nao_lidas) . '</span>
            </div>
        </div>
        
        <!-- Métricas Principais -->
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-icon">📋</div>
                <div class="metric-content">
                    <h3>' . $stats['inscritos_degustacao'] . '</h3>
                    <p>Inscritos em Degustações</p>
                </div>
            </div>
            
            <div class="metric-card">
                <div class="metric-icon">🎉</div>
                <div class="metric-content">
                    <h3>' . $stats['eventos_criados'] . '</h3>
                    <p>Eventos Criados (ME Eventos)</p>
                </div>
            </div>
            
            <div class="metric-card">
                <div class="metric-icon">📅</div>
                <div class="metric-content">
                    <h3>' . $stats['visitas_realizadas'] . '</h3>
                    <p>Visitas Realizadas</p>
                </div>
            </div>
            
            <div class="metric-card">
                <div class="metric-icon">✅</div>
                <div class="metric-content">
                    <h3>' . $stats['fechamentos_realizados'] . '</h3>
                    <p>Fechamentos Realizados</p>
                </div>
            </div>
        </div>
        
        <!-- Agenda do Dia -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2>📅 Agenda do Dia</h2>
                <span class="section-badge">' . count($agenda_hoje) . ' eventos</span>
            </div>
            <div class="agenda-list">
                ' . (empty($agenda_hoje) ? 
                    '<div class="empty-state">
                        <div class="empty-icon">📅</div>
                        <p>Nenhum evento agendado para hoje</p>
                    </div>' : 
                    implode('', array_map(function($evento) {
                        $hora = date('H:i', strtotime($evento['data_inicio']));
                        $tipo_icon = $evento['tipo'] === 'visita' ? '🏠' : ($evento['tipo'] === 'bloqueio' ? '🚫' : '📅');
                        return '
                        <div class="agenda-item">
                            <div class="agenda-time">' . $hora . '</div>
                            <div class="agenda-content">
                                <div class="agenda-title">' . $tipo_icon . ' ' . htmlspecialchars($evento['titulo']) . '</div>
                                <div class="agenda-meta">' . htmlspecialchars($evento['responsavel_nome'] ?? 'Sem responsável') . '</div>
                            </div>
                        </div>';
                    }, $agenda_hoje))
                ) . '
            </div>
        </div>
        
        <!-- Demandas do Dia -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2>📋 Demandas do Dia</h2>
                <span class="section-badge">' . count($demandas_hoje) . ' demandas</span>
            </div>
            <div class="demandas-list">
                ' . (empty($demandas_hoje) ? 
                    '<div class="empty-state">
                        <div class="empty-icon">📋</div>
                        <p>Nenhuma demanda para hoje</p>
                    </div>' : 
                    implode('', array_map(function($demanda) {
                        $status_color = $demanda['status'] === 'concluido' ? '#10b981' : 
                                     ($demanda['status'] === 'em_andamento' ? '#f59e0b' : '#6b7280');
                        $status_icon = $demanda['status'] === 'concluido' ? '✅' : 
                                     ($demanda['status'] === 'em_andamento' ? '🔄' : '⏳');
                        return '
                        <div class="demanda-item">
                            <div class="demanda-status" style="background-color: ' . $status_color . '">' . $status_icon . '</div>
                            <div class="demanda-content">
                                <div class="demanda-title">' . htmlspecialchars($demanda['titulo']) . '</div>
                                <div class="demanda-meta">' . htmlspecialchars($demanda['quadro_nome'] ?? 'Sem quadro') . ' • ' . htmlspecialchars($demanda['responsavel_nome'] ?? 'Sem responsável') . '</div>
                            </div>
                        </div>';
                    }, $demandas_hoje))
                ) . '
            </div>
        </div>
        
        <!-- Botão Flutuante de Solicitar Pagamento -->
        <div class="floating-payment-btn" onclick="openPaymentModal()">
            <span class="payment-icon">💳</span>
            <span class="payment-text">Solicitar Pagamento</span>
        </div>
        
        <!-- Modal de Solicitar Pagamento -->
        <div id="paymentModal" class="modal-overlay" style="display: none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>💳 Solicitar Pagamento</h3>
                    <button class="modal-close" onclick="closePaymentModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="paymentForm">
                        <div class="form-group">
                            <label>Valor:</label>
                            <input type="number" name="valor" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label>Descrição:</label>
                            <textarea name="descricao" required></textarea>
                        </div>
                        <div class="form-group">
                            <label>Chave PIX:</label>
                            <input type="text" name="chave_pix" required>
                        </div>
                        <button type="submit" class="btn-primary">Solicitar</button>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Modal de Notificações na Dashboard -->
        <div id="dashboard-notificacoes-dropdown" class="dashboard-notificacoes-dropdown">
            <div class="dashboard-notificacoes-header">
                <h3 style="margin: 0; font-size: 1.125rem; font-weight: 600;">🔔 Notificações</h3>
                <button onclick="marcarTodasDashboardNotificacoesLidas()" style="background: transparent; border: none; color: #3b82f6; cursor: pointer; font-size: 0.875rem; padding: 0.5rem;" title="Marcar todas como lidas">Marcar todas</button>
            </div>
            <div class="dashboard-notificacoes-content" id="dashboard-notificacoes-content">
                <div class="dashboard-notificacoes-empty">
                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">🔔</div>
                    <div>Nenhuma notificação</div>
                </div>
            </div>
        </div>
    </div>';
    
    // Conteúdo será renderizado diretamente via PHP (linha 1548-1563)
} elseif ($current_page === 'comercial') {
    // Conteúdo da página Comercial
    $comercial_content = '
    <div class="page-container">
        <div class="page-header">
            <h1 class="page-title">📋 Comercial</h1>
            <p class="page-subtitle">Gestão de degustações e conversões</p>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>🎉 Degustações</h3>
                    <span class="card-icon">🎉</span>
                </div>
                <div class="card-content">
                    <p>Gerenciar degustações e eventos</p>
                    <a href="index.php?page=comercial_degustacoes" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>👥 Inscritos</h3>
                    <span class="card-icon">👥</span>
                </div>
                <div class="card-content">
                    <p>Visualizar inscrições e participantes</p>
                    <a href="index.php?page=comercial_degust_inscricoes" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📊 Clientes</h3>
                    <span class="card-icon">📊</span>
                </div>
                <div class="card-content">
                    <p>Funil de conversão e clientes</p>
                    <a href="index.php?page=comercial_clientes" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>✏️ Nova Degustação</h3>
                    <span class="card-icon">✏️</span>
                </div>
                <div class="card-content">
                    <p>Criar nova degustação</p>
                    <a href="index.php?page=comercial_degustacao_editar" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📋 Inscritos (Todas)</h3>
                    <span class="card-icon">📋</span>
                </div>
                <div class="card-content">
                    <p>Lista completa de inscritos</p>
                    <a href="index.php?page=comercial_degust_inscritos" class="btn-primary">Acessar</a>
                </div>
            </div>
        </div>
    </div>';
    
    // Conteúdo será renderizado diretamente via PHP (linha 1548-1563)
} elseif ($current_page === 'logistico') {
    // Conteúdo da página Logístico
    $logistico_content = '
    <div class="page-container">
        <div class="page-header">
            <h1 class="page-title">📦 Logístico</h1>
            <p class="page-subtitle">Controle de estoque e compras</p>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📋 Lista de Compras</h3>
                    <span class="card-icon">📋</span>
                </div>
                <div class="card-content">
                    <p>Gerar e gerenciar listas de compras</p>
                    <a href="index.php?page=lc_index" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>🛒 Gerar Lista</h3>
                    <span class="card-icon">🛒</span>
                </div>
                <div class="card-content">
                    <p>Criar nova lista de compras</p>
                    <a href="index.php?page=lista_compras" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📦 Estoque</h3>
                    <span class="card-icon">📦</span>
                </div>
                <div class="card-content">
                    <p>Controle de estoque logístico</p>
                    <a href="index.php?page=estoque_logistico" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>🔍 Ver Encomendas</h3>
                    <span class="card-icon">🔍</span>
                </div>
                <div class="card-content">
                    <p>Visualizar detalhes das encomendas</p>
                    <a href="index.php?page=ver" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📊 Kardex</h3>
                    <span class="card-icon">📊</span>
                </div>
                <div class="card-content">
                    <p>Kardex de movimentações</p>
                    <a href="index.php?page=estoque_kardex" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📈 Contagens</h3>
                    <span class="card-icon">📈</span>
                </div>
                <div class="card-content">
                    <p>Contagens de estoque</p>
                    <a href="index.php?page=estoque_contagens" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>⚠️ Alertas</h3>
                    <span class="card-icon">⚠️</span>
                </div>
                <div class="card-content">
                    <p>Alertas de estoque</p>
                    <a href="index.php?page=estoque_alertas" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📄 PDFs</h3>
                    <span class="card-icon">📄</span>
                </div>
                <div class="card-content">
                    <p>Gerar PDFs de compras</p>
                    <a href="index.php?page=lc_pdf" class="btn-primary">Acessar</a>
                </div>
            </div>
        </div>
    </div>';
} elseif ($current_page === 'configuracoes') {
    // Conteúdo da página Configurações
    $configuracoes_content = '
    <div class="page-container">
        <div class="page-header">
            <h1 class="page-title">⚙️ Configurações</h1>
            <p class="page-subtitle">Configurações do sistema</p>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>👥 Usuários</h3>
                    <span class="card-icon">👥</span>
                </div>
                <div class="card-content">
                    <p>Gerenciar usuários e permissões</p>
                    <a href="index.php?page=usuarios" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>🏢 Fornecedores</h3>
                    <span class="card-icon">🏢</span>
                </div>
                <div class="card-content">
                    <p>Cadastro e gestão de fornecedores</p>
                    <a href="index.php?page=config_fornecedores" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📦 Insumos</h3>
                    <span class="card-icon">📦</span>
                </div>
                <div class="card-content">
                    <p>Configurar insumos e categorias</p>
                    <a href="index.php?page=config_insumos" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📋 Categorias</h3>
                    <span class="card-icon">📋</span>
                </div>
                <div class="card-content">
                    <p>Organizar categorias de produtos</p>
                    <a href="index.php?page=config_categorias" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>🔧 Sistema</h3>
                    <span class="card-icon">🔧</span>
                </div>
                <div class="card-content">
                    <p>Configurações gerais do sistema</p>
                    <a href="index.php?page=configuracoes" class="btn-primary">Acessar</a>
                </div>
            </div>
        </div>
    </div>';
} elseif ($current_page === 'cadastros') {
    // Conteúdo da página Cadastros
    $cadastros_content = '
    <div class="page-container">
        <div class="page-header">
            <h1 class="page-title">📝 Cadastros</h1>
            <p class="page-subtitle">Gestão de usuários e fornecedores</p>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>👥 Usuários</h3>
                    <span class="card-icon">👥</span>
                </div>
                <div class="card-content">
                    <p>Gerenciar usuários e permissões</p>
                    <a href="index.php?page=usuarios" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>🏢 Fornecedores</h3>
                    <span class="card-icon">🏢</span>
                </div>
                <div class="card-content">
                    <p>Cadastro e gestão de fornecedores</p>
                    <a href="index.php?page=config_fornecedores" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📦 Insumos</h3>
                    <span class="card-icon">📦</span>
                </div>
                <div class="card-content">
                    <p>Configurar insumos e categorias</p>
                    <a href="index.php?page=config_insumos" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📋 Categorias</h3>
                    <span class="card-icon">📋</span>
                </div>
                <div class="card-content">
                    <p>Organizar categorias de produtos</p>
                    <a href="index.php?page=config_categorias" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📄 Fichas</h3>
                    <span class="card-icon">📄</span>
                </div>
                <div class="card-content">
                    <p>Configurar fichas técnicas</p>
                    <a href="index.php?page=config_fichas" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>🔧 Itens</h3>
                    <span class="card-icon">🔧</span>
                </div>
                <div class="card-content">
                    <p>Configurar itens e produtos</p>
                    <a href="index.php?page=config_itens" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📌 Itens Fixos</h3>
                    <span class="card-icon">📌</span>
                </div>
                <div class="card-content">
                    <p>Configurar itens fixos</p>
                    <a href="index.php?page=config_itens_fixos" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>➕ Novo Usuário</h3>
                    <span class="card-icon">➕</span>
                </div>
                <div class="card-content">
                    <p>Criar novo usuário</p>
                    <a href="index.php?page=usuario_novo" class="btn-primary">Acessar</a>
                </div>
            </div>
        </div>
    </div>';
} elseif ($current_page === 'financeiro') {
    // Conteúdo da página Financeiro
    $financeiro_content = '
    <div class="page-container">
        <div class="page-header">
            <h1 class="page-title">💰 Financeiro</h1>
            <p class="page-subtitle">Pagamentos e solicitações</p>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>💳 Solicitações</h3>
                    <span class="card-icon">💳</span>
                </div>
                <div class="card-content">
                    <p>Gerenciar solicitações de pagamento</p>
                    <a href="index.php?page=pagamentos" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📋 Painel Admin</h3>
                    <span class="card-icon">📋</span>
                </div>
                <div class="card-content">
                    <p>Painel administrativo de pagamentos</p>
                    <a href="index.php?page=pagamentos_painel" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>➕ Solicitar</h3>
                    <span class="card-icon">➕</span>
                </div>
                <div class="card-content">
                    <p>Criar nova solicitação de pagamento</p>
                    <a href="index.php?page=pagamentos_solicitar" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>👤 Freelancers</h3>
                    <span class="card-icon">👤</span>
                </div>
                <div class="card-content">
                    <p>Cadastro de freelancers</p>
                    <a href="index.php?page=freelancer_cadastro" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>🏢 Fornecedores</h3>
                    <span class="card-icon">🏢</span>
                </div>
                <div class="card-content">
                    <p>Gestão de fornecedores</p>
                    <a href="index.php?page=fornecedores" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>🔗 Portal Fornecedor</h3>
                    <span class="card-icon">🔗</span>
                </div>
                <div class="card-content">
                    <p>Portal público do fornecedor</p>
                    <a href="index.php?page=fornecedor_link" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📊 Minhas Solicitações</h3>
                    <span class="card-icon">📊</span>
                </div>
                <div class="card-content">
                    <p>Minhas solicitações de pagamento</p>
                    <a href="index.php?page=pagamentos_minhas" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>👁️ Ver Solicitação</h3>
                    <span class="card-icon">👁️</span>
                </div>
                <div class="card-content">
                    <p>Visualizar detalhes da solicitação</p>
                    <a href="index.php?page=pagamentos_ver" class="btn-primary">Acessar</a>
                </div>
            </div>
        </div>
    </div>';
} elseif ($current_page === 'administrativo') {
    // Conteúdo da página Administrativo
    $administrativo_content = '
    <div class="page-container">
        <div class="page-header">
            <h1 class="page-title">👥 Administrativo</h1>
            <p class="page-subtitle">Relatórios e administração</p>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📊 Relatórios</h3>
                    <span class="card-icon">📊</span>
                </div>
                <div class="card-content">
                    <p>Relatórios gerenciais e análises</p>
                    <a href="index.php?page=relatorio_analise_sistema" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>🔍 Auditoria</h3>
                    <span class="card-icon">🔍</span>
                </div>
                <div class="card-content">
                    <p>Logs e auditoria do sistema</p>
                    <a href="index.php?page=verificacao_completa_erros" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>🏦 Banco Smile</h3>
                    <span class="card-icon">🏦</span>
                </div>
                <div class="card-content">
                    <p>Acesso ao banco Smile</p>
                    <a href="index.php?page=banco_smile" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📄 Notas Fiscais</h3>
                    <span class="card-icon">📄</span>
                </div>
                <div class="card-content">
                    <p>Gestão de notas fiscais</p>
                    <a href="index.php?page=notas_fiscais" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📋 Histórico</h3>
                    <span class="card-icon">📋</span>
                </div>
                <div class="card-content">
                    <p>Histórico de operações</p>
                    <a href="index.php?page=historico" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>🚪 Portão</h3>
                    <span class="card-icon">🚪</span>
                </div>
                <div class="card-content">
                    <p>Controle de acesso</p>
                    <a href="index.php?page=portao" class="btn-primary">Acessar</a>
                </div>
            </div>
        </div>
    </div>';
} else {
    // Para outras páginas, incluir o conteúdo da página atual
    if (file_exists($page_path)) {
        // Páginas com conteúdo próprio - permitir que sejam processadas normalmente pelo index.php
        // Sem verificação especial - essas páginas renderizarão conteúdo vazio aqui
        // mas serão incluídas corretamente pelo index.php
        
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
        
        // Conteúdo será renderizado via PHP para outras páginas
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
    
    <!-- FullCalendar para Agenda -->
    <?php if ($current_page === 'agenda'): ?>
        <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/locales/pt-br.global.min.js"></script>
    <?php endif; ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box !important;
        }
        
        html {
            margin: 0 !important;
            padding: 0 !important;
            height: 100%;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #1e293b;
            overflow-x: hidden;
            margin: 0 !important;
            padding: 0 !important;
            height: 100%;
            display: block;
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
        
        /* Main Content - Sobrescrever estilo.css */
        .main-content {
            margin-left: 280px !important;
            width: calc(100% - 280px) !important;
            min-height: 100vh !important;
            background: #f8fafc !important;
            transition: margin-left 0.3s ease, width 0.3s ease !important;
            padding: 0 !important;
            flex: 0 0 auto !important;
            min-width: auto !important;
        }
        
        .main-content.expanded {
            margin-left: 0 !important;
            width: 100% !important;
        }
        
        /* Page Content */
        #pageContent {
            width: 100%;
            margin: 0 !important;
            padding: 0 !important;
            display: block !important;
        }
        
        /* Garantir que agenda-page-content comece do topo */
        .agenda-page-content {
            margin-top: 0 !important;
            padding-top: 0 !important;
        }
        
        /* Garantir que o primeiro elemento não tenha margin-top */
        #pageContent > div:first-child {
            margin-top: 0 !important;
            padding-top: 0 !important;
        }
        
        /* Garantir que body e html não tenham espaço em branco */
        html, body {
            height: auto !important;
            overflow-x: hidden !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        /* Forçar main-content a começar do topo */
        .main-content {
            position: relative !important;
            top: 0 !important;
            left: 0 !important;
        }
        
        /* Remove qualquer espaço em branco antes do conteúdo */
        .main-content::before,
        #pageContent::before {
            content: none !important;
            display: none !important;
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
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }
        
        /* Notificações na Dashboard */
        .dashboard-notificacoes-badge {
            position: relative;
            cursor: pointer;
            padding: 0.75rem;
            border-radius: 50%;
            background: #3b82f6;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 48px;
            min-height: 48px;
            transition: background 0.2s;
        }
        
        .dashboard-notificacoes-badge:hover {
            background: #2563eb;
        }
        
        .dashboard-notificacoes-count {
            position: absolute;
            top: 4px;
            right: 4px;
            background: #ef4444;
            color: white;
            border-radius: 10px;
            padding: 2px 6px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 18px;
            text-align: center;
            line-height: 1.4;
        }
        
        /* Modal de Notificações na Dashboard */
        .dashboard-notificacoes-dropdown {
            position: fixed;
            top: 120px;
            right: 2rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
            min-width: 400px;
            max-width: 90vw;
            max-height: 600px;
            overflow: hidden;
            z-index: 2000;
            display: none;
            animation: slideDown 0.2s;
        }
        
        .dashboard-notificacoes-dropdown.open {
            display: flex;
            flex-direction: column;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .dashboard-notificacoes-header {
            padding: 1.25rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        
        .dashboard-notificacoes-content {
            flex: 1;
            overflow-y: auto;
            padding: 0;
        }
        
        .dashboard-notificacao-item {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #f3f4f6;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .dashboard-notificacao-item:hover {
            background: #f9fafb;
        }
        
        .dashboard-notificacao-item.nao-lida {
            background: #eff6ff;
            border-left: 3px solid #3b82f6;
        }
        
        .dashboard-notificacao-titulo {
            font-weight: 500;
            margin-bottom: 0.25rem;
            color: #172b4d;
            font-size: 0.875rem;
        }
        
        .dashboard-notificacao-trecho {
            font-size: 0.75rem;
            color: #6b7280;
            margin-bottom: 0.25rem;
        }
        
        .dashboard-notificacao-meta {
            font-size: 0.7rem;
            color: #9ca3af;
            display: flex;
            justify-content: space-between;
        }
        
        .dashboard-notificacoes-empty {
            padding: 3rem;
            text-align: center;
            color: #9ca3af;
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
        
        /* Métricas */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .metric-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s ease;
        }
        
        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }
        
        .metric-icon {
            font-size: 32px;
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #1e40af, #3b82f6);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .metric-content h3 {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 5px 0;
        }
        
        .metric-content p {
            color: #64748b;
            margin: 0;
            font-size: 14px;
        }
        
        /* Seções da Dashboard */
        .dashboard-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f1f5f9;
        }
        
        .section-header h2 {
            margin: 0;
            color: #1e293b;
            font-size: 18px;
            font-weight: 600;
        }
        
        .section-badge {
            background: #3b82f6;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        /* Lista de Agenda */
        .agenda-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .agenda-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px;
            background: #f8fafc;
            border-radius: 8px;
            border-left: 4px solid #3b82f6;
            transition: all 0.2s ease;
        }
        
        .agenda-item:hover {
            background: #f1f5f9;
            transform: translateX(2px);
        }
        
        .agenda-time {
            background: #1e40af;
            color: white;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            min-width: 60px;
            text-align: center;
        }
        
        .agenda-content {
            flex: 1;
        }
        
        .agenda-title {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 4px;
        }
        
        .agenda-meta {
            font-size: 12px;
            color: #64748b;
        }
        
        /* Lista de Demandas */
        .demandas-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .demanda-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px;
            background: #f8fafc;
            border-radius: 8px;
            border-left: 4px solid #6b7280;
            transition: all 0.2s ease;
        }
        
        .demanda-item:hover {
            background: #f1f5f9;
            transform: translateX(2px);
        }
        
        .demanda-status {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: white;
        }
        
        .demanda-content {
            flex: 1;
        }
        
        .demanda-title {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 4px;
        }
        
        .demanda-meta {
            font-size: 12px;
            color: #64748b;
        }
        
        /* Estado Vazio */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }
        
        .empty-icon {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .empty-state p {
            margin: 0;
            font-size: 14px;
        }
        
        /* Botão Flutuante */
        .floating-payment-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 15px 20px;
            border-radius: 50px;
            cursor: pointer;
            box-shadow: 0 4px 20px rgba(16, 185, 129, 0.3);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            z-index: 1000;
        }
        
        .floating-payment-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(16, 185, 129, 0.4);
        }
        
        .payment-icon {
            font-size: 20px;
        }
        
        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 0;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
            color: #1e293b;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #64748b;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #374151;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .form-group textarea {
            height: 80px;
            resize: vertical;
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
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            .sidebar-toggle {
                display: block;
            }
        }
    </style>
</head>
<body>
    <button class="sidebar-toggle" onclick="toggleSidebar()">☰</button>
    
    <!-- Sidebar fixa -->
    <div class="sidebar" id="sidebar" style="position: fixed !important; top: 0 !important; left: 0 !important; width: 280px !important; height: 100vh !important; z-index: 1000 !important;">
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
                
                <a href="index.php?page=agenda" class="nav-item <?= isActiveUnified('agenda') ?>">
                    <span class="nav-item-icon">📅</span>
                    Agenda
                </a>
                
                <a href="index.php?page=demandas" class="nav-item <?= isActiveUnified('demandas') ?>">
                    <span class="nav-item-icon">📝</span>
                    Demandas
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
    <!-- Sidebar fechada -->
    
    <!-- Conteúdo principal -->
    <div class="main-content" id="mainContent" style="margin-left: 280px !important; width: calc(100% - 280px) !important; position: relative !important; top: 0 !important;">
            <div id="pageContent">
                <?php 
                // Renderizar conteúdo diretamente se for uma página especial
                if (in_array($current_page, ['dashboard', 'comercial', 'logistico', 'configuracoes', 'cadastros', 'financeiro', 'administrativo', 'demandas', 'demandas_quadro'])) {
                    // O conteúdo já foi definido nas variáveis acima
                    if ($current_page === 'dashboard' && !empty($dashboard_content)) {
                        echo $dashboard_content;
                    } elseif ($current_page === 'comercial' && !empty($comercial_content)) {
                        echo $comercial_content;
                    } elseif ($current_page === 'logistico' && !empty($logistico_content)) {
                        echo $logistico_content;
                    } elseif ($current_page === 'configuracoes' && !empty($configuracoes_content)) {
                        echo $configuracoes_content;
                    } elseif ($current_page === 'cadastros' && !empty($cadastros_content)) {
                        echo $cadastros_content;
                    } elseif ($current_page === 'financeiro' && !empty($financeiro_content)) {
                        echo $financeiro_content;
                    } elseif ($current_page === 'administrativo' && !empty($administrativo_content)) {
                        echo $administrativo_content;
                    }
                }
                
                // Fechar divs apenas se for página especial que já renderizou o conteúdo
                // NOTA: comercial agora usa comercial_landing.php que gerencia seus próprios divs
                if (in_array($current_page, ['dashboard', 'logistico', 'configuracoes', 'cadastros', 'financeiro', 'administrativo'])) {
                    echo '</div>'; // fecha #pageContent
                    echo '</div>'; // fecha .main-content
                }
                ?>
                
    <script>
        // Função para alternar sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            if (!sidebar || !mainContent) {
                console.error('Sidebar ou mainContent não encontrados');
                return;
            }
            
            const isCollapsed = sidebar.classList.contains('collapsed');
            
            if (isCollapsed) {
                // Mostrar sidebar
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
                mainContent.style.marginLeft = '280px';
                mainContent.style.width = 'calc(100% - 280px)';
            } else {
                // Esconder sidebar
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
                mainContent.style.marginLeft = '0';
                mainContent.style.width = '100%';
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
            
            // Se for dashboard, comercial, logistico, configurações, cadastros, financeiro ou administrativo, 
            // o conteúdo já foi renderizado via PHP, não fazer nada
            // Para agenda, o conteúdo também já foi renderizado via PHP após sidebar_unified.php
            if (!['dashboard', 'comercial', 'logistico', 'configuracoes', 'cadastros', 'financeiro', 'administrativo', 'agenda', 'demandas', 'demandas_quadro'].includes(currentPage)) {
                // Para outras páginas, carregar via AJAX
                loadPageContent(currentPage);
            }
        });
        
        // Funções do modal de pagamento
        function openPaymentModal() {
            document.getElementById('paymentModal').style.display = 'flex';
        }
        
        function closePaymentModal() {
            document.getElementById('paymentModal').style.display = 'none';
        }
        
        // Fechar modal ao clicar fora
        document.addEventListener('click', function(e) {
            const modal = document.getElementById('paymentModal');
            if (e.target === modal) {
                closePaymentModal();
            }
        });
        
        // ============================================
        // SISTEMA GLOBAL DE NOTIFICAÇÕES (Dashboard)
        // ============================================
        let dashboardNotificacoes = [];
        let dashboardNotificacoesNaoLidas = 0;
        const DASHBOARD_API_BASE = 'demandas_trello_api.php';
        
        // Carregar notificações ao entrar na dashboard
        <?php if ($current_page === 'dashboard'): ?>
        document.addEventListener('DOMContentLoaded', function() {
            carregarDashboardNotificacoes();
            // Polling a cada 30 segundos
            setInterval(carregarDashboardNotificacoes, 30000);
        });
        <?php endif; ?>
        
        async function carregarDashboardNotificacoes() {
            try {
                const response = await fetch(DASHBOARD_API_BASE + '?action=notificacoes', {
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                
                if (!response.ok) {
                    console.warn('Erro ao carregar notificações:', response.status);
                    return;
                }
                
                const data = await response.json();
                
                if (data.success) {
                    dashboardNotificacoes = data.data || [];
                    dashboardNotificacoesNaoLidas = data.nao_lidas || 0;
                    
                    // Atualizar contador
                    const countEl = document.getElementById('dashboard-notificacoes-count');
                    if (countEl) {
                        if (dashboardNotificacoesNaoLidas > 0) {
                            countEl.textContent = dashboardNotificacoesNaoLidas;
                            countEl.style.display = 'block';
                            countEl.style.visibility = 'visible';
                        } else {
                            countEl.style.display = 'none';
                            countEl.style.visibility = 'hidden';
                        }
                    }
                    
                    // Renderizar se o dropdown estiver aberto
                    const dropdown = document.getElementById('dashboard-notificacoes-dropdown');
                    if (dropdown && dropdown.classList.contains('open')) {
                        renderizarDashboardNotificacoes();
                    }
                }
            } catch (error) {
                console.error('Erro ao carregar notificações:', error);
            }
        }
        
        function toggleDashboardNotificacoes(event) {
            event.stopPropagation();
            const dropdown = document.getElementById('dashboard-notificacoes-dropdown');
            if (!dropdown) return;
            
            const isOpen = dropdown.classList.contains('open');
            
            if (isOpen) {
                dropdown.classList.remove('open');
            } else {
                dropdown.classList.add('open');
                renderizarDashboardNotificacoes();
            }
        }
        
        function renderizarDashboardNotificacoes() {
            const container = document.getElementById('dashboard-notificacoes-content');
            if (!container) return;
            
            if (dashboardNotificacoes.length === 0) {
                container.innerHTML = '<div class="dashboard-notificacoes-empty"><div style="font-size: 2rem; margin-bottom: 0.5rem;">🔔</div><div>Nenhuma notificação</div></div>';
                return;
            }
            
            // Agrupar por data
            const hoje = new Date();
            hoje.setHours(0,0,0,0);
            
            const grupos = {
                hoje: [],
                ontem: [],
                semana: [],
                antigas: []
            };
            
            dashboardNotificacoes.forEach(notif => {
                const dataNotif = new Date(notif.data_notificacao || notif.criada_em || notif.criado_em || notif.created_at);
                const diffMs = hoje - dataNotif;
                const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
                
                if (diffDays === 0) grupos.hoje.push(notif);
                else if (diffDays === 1) grupos.ontem.push(notif);
                else if (diffDays <= 7) grupos.semana.push(notif);
                else grupos.antigas.push(notif);
            });
            
            let html = '';
            
            if (grupos.hoje.length > 0) {
                html += '<div style="padding: 0.75rem 1.25rem; font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; background: #f9fafb; border-bottom: 1px solid #e5e7eb;">Hoje</div>';
                html += grupos.hoje.map(n => renderizarDashboardNotificacaoItem(n)).join('');
            }
            
            if (grupos.ontem.length > 0) {
                html += '<div style="padding: 0.75rem 1.25rem; font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; background: #f9fafb; border-bottom: 1px solid #e5e7eb; margin-top: 0.5rem;">Ontem</div>';
                html += grupos.ontem.map(n => renderizarDashboardNotificacaoItem(n)).join('');
            }
            
            if (grupos.semana.length > 0) {
                html += '<div style="padding: 0.75rem 1.25rem; font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; background: #f9fafb; border-bottom: 1px solid #e5e7eb; margin-top: 0.5rem;">Esta semana</div>';
                html += grupos.semana.map(n => renderizarDashboardNotificacaoItem(n)).join('');
            }
            
            if (grupos.antigas.length > 0) {
                html += '<div style="padding: 0.75rem 1.25rem; font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; background: #f9fafb; border-bottom: 1px solid #e5e7eb; margin-top: 0.5rem;">Mais antigas</div>';
                html += grupos.antigas.map(n => renderizarDashboardNotificacaoItem(n)).join('');
            }
            
            container.innerHTML = html;
        }
        
        function renderizarDashboardNotificacaoItem(notif) {
            const dataNotif = new Date(notif.data_notificacao || notif.criada_em || notif.criado_em || notif.created_at);
            const tempoRelativo = getTempoRelativoDashboard(dataNotif);
            const naoLida = !notif.lida;
            
            // Determinar URL de destino baseado no tipo de notificação
            let urlDestino = '#';
            let tipoNotificacao = notif.tipo || 'demanda';
            
            if (tipoNotificacao.includes('demanda') || tipoNotificacao.includes('card') || tipoNotificacao.includes('menção') || notif.referencia_id) {
                // Notificação de demanda - ir para a página de demandas
                urlDestino = 'index.php?page=demandas';
                // Se tiver referencia_id (card_id), pode adicionar parâmetro para destacar o card
                if (notif.referencia_id || notif.card_id) {
                    urlDestino += '#card-' + (notif.referencia_id || notif.card_id);
                }
            } else if (tipoNotificacao.includes('agenda')) {
                urlDestino = 'index.php?page=agenda';
            } else if (tipoNotificacao.includes('comercial')) {
                urlDestino = 'index.php?page=comercial';
            }
            // Preparado para expansão futura - outros tipos podem ser adicionados aqui
            
            return `
                <div class="dashboard-notificacao-item ${naoLida ? 'nao-lida' : ''}" 
                     onclick="navegarParaNotificacao('${urlDestino}', ${notif.id})">
                    <div class="dashboard-notificacao-titulo">${escapeHtml(notif.titulo || 'Notificação')}</div>
                    ${notif.mensagem && notif.mensagem !== notif.titulo ? `<div class="dashboard-notificacao-trecho">${escapeHtml(notif.mensagem.substring(0, 100))}${notif.mensagem.length > 100 ? '...' : ''}</div>` : ''}
                    <div class="dashboard-notificacao-meta">
                        <span>${tempoRelativo}</span>
                        ${notif.autor_nome ? `<span>${escapeHtml(notif.autor_nome)}</span>` : ''}
                    </div>
                </div>
            `;
        }
        
        function getTempoRelativoDashboard(data) {
            const agora = new Date();
            const diffMs = agora - data;
            const diffSeg = Math.floor(diffMs / 1000);
            const diffMin = Math.floor(diffSeg / 60);
            const diffHora = Math.floor(diffMin / 60);
            const diffDia = Math.floor(diffHora / 24);
            
            if (diffSeg < 60) return 'Agora';
            if (diffMin < 60) return diffMin + 'm atrás';
            if (diffHora < 24) return diffHora + 'h atrás';
            if (diffDia === 1) return 'Ontem';
            if (diffDia < 7) return diffDia + 'd atrás';
            return data.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        async function navegarParaNotificacao(url, notificacaoId) {
            // Marcar como lida
            try {
                await fetch(DASHBOARD_API_BASE + '?action=marcar_notificacao&id=' + notificacaoId, {
                    method: 'POST',
                    credentials: 'same-origin'
                });
            } catch (error) {
                console.error('Erro ao marcar notificação como lida:', error);
            }
            
            // Fechar dropdown
            const dropdown = document.getElementById('dashboard-notificacoes-dropdown');
            if (dropdown) {
                dropdown.classList.remove('open');
            }
            
            // Navegar
            if (url && url !== '#') {
                window.location.href = url;
            }
        }
        
        async function marcarTodasDashboardNotificacoesLidas() {
            try {
                const naoLidas = dashboardNotificacoes.filter(n => !n.lida);
                for (const notif of naoLidas) {
                    try {
                        await fetch(DASHBOARD_API_BASE + '?action=marcar_notificacao&id=' + notif.id, {
                            method: 'POST',
                            credentials: 'same-origin'
                        });
                    } catch (error) {
                        console.error('Erro ao marcar notificação:', error);
                    }
                }
                
                // Recarregar notificações
                await carregarDashboardNotificacoes();
                renderizarDashboardNotificacoes();
            } catch (error) {
                console.error('Erro ao marcar todas como lidas:', error);
            }
        }
        
        // Fechar dropdown ao clicar fora
        document.addEventListener('click', function(e) {
            const dropdown = document.getElementById('dashboard-notificacoes-dropdown');
            const badge = document.querySelector('.dashboard-notificacoes-badge');
            
            if (dropdown && badge && !dropdown.contains(e.target) && !badge.contains(e.target)) {
                dropdown.classList.remove('open');
            }
        });
        
        // Função para carregar conteúdo das páginas
        function loadPageContent(page) {
            const pageContent = document.getElementById('pageContent');
            if (!pageContent) return;
            
            // Mostrar loading
            pageContent.innerHTML = '<div style="text-align: center; padding: 50px; color: #64748b;"><div style="font-size: 24px; margin-bottom: 20px;">⏳</div><div>Carregando...</div></div>';
            
            // Carregar página via AJAX
            fetch('index.php?page=' + page)
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
