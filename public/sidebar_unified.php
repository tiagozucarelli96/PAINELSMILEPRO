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

require_once __DIR__ . '/notifications_bar.php';

$nomeUser = $_SESSION['nome'] ?? 'Usuário';
$current_page = $_GET['page'] ?? 'dashboard';
$show_top_account_access = ($current_page === 'dashboard');
$push_user_id = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);

// Buscar cargo do usuário logado do banco de dados
$perfil = 'CONSULTA'; // Valor padrão
$foto_usuario = null;
$usuario_id_sidebar = $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? null;
if ($usuario_id_sidebar && isset($pdo)) {
    try {
        $stmt_cargo = $pdo->prepare("SELECT cargo, foto FROM usuarios WHERE id = :id");
        $stmt_cargo->execute([':id' => $usuario_id_sidebar]);
        $cargo_result = $stmt_cargo->fetch(PDO::FETCH_ASSOC);
        if ($cargo_result) {
            if (!empty($cargo_result['cargo'])) {
                $perfil = $cargo_result['cargo'];
            }
            if (!empty($cargo_result['foto'])) {
                $foto_usuario = trim((string)$cargo_result['foto']);
            }
        }
    } catch (Exception $e) {
        error_log("Erro ao buscar cargo do usuário: " . $e->getMessage());
    }
}

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
        
        // 3. Visitas Realizadas (Agenda + Google Calendar)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM (
                SELECT inicio 
                FROM agenda_eventos 
                WHERE tipo = 'visita'
                AND status = 'realizado'
                AND DATE_TRUNC('month', inicio) = DATE_TRUNC('month', CURRENT_DATE)
                UNION ALL
                SELECT inicio 
                FROM google_calendar_eventos 
                WHERE eh_visita_agendada = true
                AND DATE_TRUNC('month', inicio) = DATE_TRUNC('month', CURRENT_DATE)
            ) as todas_visitas
        ");
        $stmt->execute();
        $stats['visitas_realizadas'] = $stmt->fetchColumn() ?: 0;
        
        // 4. Fechamentos Realizados (Agenda + Google Calendar)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM (
                SELECT inicio 
                FROM agenda_eventos 
                WHERE fechou_contrato = true
                AND DATE_TRUNC('month', inicio) = DATE_TRUNC('month', CURRENT_DATE)
                UNION ALL
                SELECT inicio 
                FROM google_calendar_eventos 
                WHERE contrato_fechado = true
                AND DATE_TRUNC('month', inicio) = DATE_TRUNC('month', CURRENT_DATE)
            ) as todos_contratos
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
    
    // Buscar agenda do dia atual (incluindo eventos do Google Calendar)
    $agenda_hoje = [];
    try {
        $stmt = $pdo->prepare("
            SELECT 
                ae.id, 
                ae.titulo, 
                ae.inicio as data_inicio, 
                ae.fim as data_fim, 
                ae.tipo, 
                ae.cor_evento as cor, 
                ae.descricao as observacoes,
                u.nome as responsavel_nome,
                'interno' as origem
            FROM agenda_eventos ae
            LEFT JOIN usuarios u ON u.id = ae.responsavel_usuario_id
            WHERE DATE(ae.inicio) = CURRENT_DATE
            
            UNION ALL
            
            SELECT 
                gce.id, 
                gce.titulo, 
                gce.inicio as data_inicio, 
                gce.fim as data_fim, 
                'google' as tipo, 
                '#10b981' as cor, 
                gce.descricao as observacoes,
                COALESCE(gce.organizador_email, 'Google Calendar') as responsavel_nome,
                'google' as origem
            FROM google_calendar_eventos gce
            WHERE DATE(gce.inicio) = CURRENT_DATE
            AND EXISTS (
                SELECT 1 FROM google_calendar_config gcc 
                WHERE gcc.google_calendar_id = gce.google_calendar_id 
                AND gcc.ativo = TRUE
            )
            
            ORDER BY data_inicio ASC
            LIMIT 10
        ");
        $stmt->execute();
        $agenda_hoje = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("[SIDEBAR] Erro ao buscar agenda do dia: " . $e->getMessage());
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
    
    $notificacoes_html = function_exists('build_logistica_notifications_bar') ? build_logistica_notifications_bar($pdo) : '';
    
    $dashboard_content = '
    <style>
    .dashboard-header-container {
        margin-bottom: 1.5rem;
    }
    .dashboard-header-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1rem;
    }
    .dashboard-header-info h1 {
        font-size: 1.75rem;
        color: #1e293b;
        margin-bottom: 0.25rem;
    }
    .dashboard-header-info p {
        color: #64748b;
        font-size: 0.9rem;
    }
    .dashboard-notifications-wrapper {
        flex: 1;
        max-width: 100%;
    }
    @media (max-width: 1024px) {
        .dashboard-header-top {
            flex-direction: column;
        }
        .dashboard-notifications-wrapper {
            width: 100%;
        }
    }
    </style>
    <div class="page-container">
        <div class="dashboard-header-container">
            <div class="dashboard-header-top">
                <div class="dashboard-header-info">
                    <h1>🏠 Dashboard</h1>
                    <p>Bem-vindo, ' . htmlspecialchars($nomeUser) . '! | Email: ' . htmlspecialchars($user_email) . '</p>
                </div>
            </div>
            ' . (!empty($notificacoes_html) ? '<div class="dashboard-notifications-wrapper">' . $notificacoes_html . '</div>' : '') . '
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
                        // Para eventos de dia todo do Google, mostrar "Dia todo"
                        $is_all_day = ($evento['origem'] ?? 'interno') === 'google' && 
                                      date('H:i', strtotime($evento['data_inicio'])) === '00:00' &&
                                      date('H:i', strtotime($evento['data_fim'])) === '23:59';
                        $hora = $is_all_day ? 'Dia todo' : date('H:i', strtotime($evento['data_inicio']));
                        $tipo_icon = ($evento['origem'] ?? 'interno') === 'google' ? '📅' : 
                                     ($evento['tipo'] === 'visita' ? '🏠' : ($evento['tipo'] === 'bloqueio' ? '🚫' : '📅'));
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
    // NOTA: A página Comercial usa comercial_landing.php (definida no index.php)
    // Os cards duplicados foram removidos para evitar confusão
} elseif ($current_page === 'logistico') {
    // Conteúdo removido: módulo logístico desativado
    $logistico_content = '
    <div class="page-container">
        <div class="page-header">
            <h1 class="page-title">📦 Logístico</h1>
            <p class="page-subtitle">Módulo removido</p>
        </div>
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-content">
                    <p>Este módulo foi removido do sistema.</p>
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
            <p class="page-subtitle">Cadastros gerais do sistema</p>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-content" style="text-align: center; padding: 2rem;">
                    <div style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;">📝</div>
                    <p style="color: #64748b;">Módulo de cadastros em desenvolvimento.</p>
                    <p style="color: #94a3b8; font-size: 0.875rem; margin-top: 0.5rem;">Para gerenciar usuários, acesse Configurações.</p>
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
            <p class="page-subtitle">Módulo removido</p>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-content">
                    <p>Este módulo foi removido do sistema.</p>
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
    // Para outras páginas (que usam includeSidebar/endSidebar), 
    // NÃO renderizar conteúdo aqui - a página já renderiza seu próprio conteúdo
    // via ob_start() antes de chamar includeSidebar()
    // O conteúdo será incluído quando a página chamar includeSidebar() e endSidebar()
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
    <!-- Modais customizados (sucesso/erro/confirmação) em todo o sistema -->
    <link rel="stylesheet" href="assets/css/custom_modals.css">
    <script src="assets/js/custom_modals.js"></script>
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
        
        /* Botão Sair - Estilo diferenciado */
        .nav-item-logout {
            margin-top: 10px;
            background: rgba(239, 68, 68, 0.1);
            border-left-color: rgba(239, 68, 68, 0.5);
            color: rgba(255, 255, 255, 0.9);
        }
        
        .nav-item-logout:hover {
            background: rgba(239, 68, 68, 0.2);
            border-left-color: #ef4444;
            color: white;
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

        .top-account-access {
            position: absolute;
            top: 16px;
            right: 24px;
            z-index: 1100;
        }

        .top-account-link {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            background: #ffffff;
            border: 1px solid #dbe3f0;
            color: #1e293b;
            border-radius: 999px;
            padding: 6px 12px 6px 6px;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
            transition: all 0.2s ease;
        }

        .top-account-link:hover {
            transform: translateY(-1px);
            border-color: #bfdbfe;
            box-shadow: 0 10px 24px rgba(30, 58, 138, 0.16);
        }

        .top-account-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            color: #ffffff;
            font-weight: 700;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background-size: cover;
            background-position: center;
            flex-shrink: 0;
        }

        .top-account-text {
            font-size: 0.85rem;
            font-weight: 700;
            color: #1e3a8a;
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
        /* REMOVIDO left: 0 - estava conflitando com margin-left: 280px */
        .main-content {
            position: relative !important;
            top: 0 !important;
            /* left removido para permitir margin-left funcionar */
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
            z-index: 1201;
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
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
        }

        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.5);
            z-index: 1190;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
        }

        .sidebar-overlay.open {
            opacity: 1;
            pointer-events: auto;
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
            padding: 0.5rem;
            border-radius: 50%;
            background: transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 128px;
            min-height: 128px;
            transition: transform 0.2s;
        }
        
        .dashboard-notificacoes-badge:hover {
            transform: scale(1.05);
        }
        
        .dashboard-notificacoes-badge img {
            width: 112px;
            height: 112px;
            object-fit: contain;
        }
        
        .dashboard-notificacoes-count {
            position: absolute;
            top: 8px;
            right: 8px;
            background: transparent;
            color: white;
            font-size: 1.2rem;
            font-weight: 700;
            min-width: 24px;
            text-align: center;
            line-height: 1.2;
            pointer-events: none;
            text-shadow: 0 2px 4px rgba(0,0,0,0.4);
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
                z-index: 1200 !important;
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
                top: calc(env(safe-area-inset-top, 0px) + 12px);
                left: 12px;
                min-width: 44px;
                min-height: 44px;
                padding: 10px 12px;
                border-radius: 10px;
            }

            .top-account-access {
                display: none;
            }

            .top-account-text {
                display: none;
            }

            .dashboard-header-info {
                padding-left: 54px;
            }

            .dashboard-header-info h1 {
                font-size: 2rem;
                line-height: 1.15;
            }

            body.sidebar-mobile-open {
                overflow: hidden !important;
                touch-action: none;
            }
        }
    </style>
</head>
<body>
    <button class="sidebar-toggle" onclick="toggleSidebar()">☰</button>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar(false)"></div>
    
    <!-- Sidebar fixa -->
    <div class="sidebar" id="sidebar" style="position: fixed !important; top: 0 !important; left: 0 !important; width: 280px !important; height: 100vh !important; z-index: 1200 !important;">
            <div class="sidebar-header">
                <div class="user-info">
                    <div class="user-avatar" style="<?= $foto_usuario ? "background-image: url('" . htmlspecialchars($foto_usuario) . "'); background-size: cover; background-position: center; color: transparent;" : '' ?>">
                        <?php if (!$foto_usuario): ?>
                            <?= strtoupper(substr($nomeUser, 0, 2)) ?>
                        <?php endif; ?>
                    </div>
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
                
                <?php if (!empty($_SESSION['perm_agenda'])): ?>
                <a href="index.php?page=agenda" class="nav-item <?= isActiveUnified('agenda') ?>">
                    <span class="nav-item-icon">📅</span>
                    Agenda
                </a>
                <?php endif; ?>
                
                <?php if (!empty($_SESSION['perm_demandas'])): ?>
                <a href="index.php?page=demandas" class="nav-item <?= isActiveUnified('demandas') ?>">
                    <span class="nav-item-icon">📝</span>
                    Demandas
                </a>
                <?php endif; ?>
                
                <?php if (!empty($_SESSION['perm_comercial'])): ?>
                <a href="index.php?page=comercial" class="nav-item <?= isActiveUnified('comercial') ?>">
                    <span class="nav-item-icon">📋</span>
                    Comercial
                </a>
                <?php endif; ?>
                
                <?php if (!empty($_SESSION['perm_eventos'])): ?>
                <a href="index.php?page=eventos" class="nav-item <?= isActiveUnified('eventos') ?>">
                    <span class="nav-item-icon">🎉</span>
                    Eventos
                </a>
                <?php endif; ?>
                
                <?php
                $scope_none = (($_SESSION['unidade_scope'] ?? 'todas') === 'nenhuma') && empty($_SESSION['perm_superadmin']);
                ?>
                <?php if ((!empty($_SESSION['perm_logistico']) || !empty($_SESSION['perm_superadmin'])) && !$scope_none): ?>
                <a href="index.php?page=logistica" class="nav-item <?= isActiveUnified('logistica') ?>">
                    <span class="nav-item-icon">📦</span>
                    Logística
                </a>
                <?php endif; ?>
                
                <?php /* REMOVIDO: Módulo Logístico (Estoque + Lista de Compras) */ ?>
                
                <?php if (!empty($_SESSION['perm_configuracoes'])): ?>
                <a href="index.php?page=configuracoes" class="nav-item <?= isActiveUnified('configuracoes') ?>">
                    <span class="nav-item-icon">⚙️</span>
                    Configurações
                </a>
                <?php endif; ?>
                
                <?php if (!empty($_SESSION['perm_cadastros'])): ?>
                <a href="index.php?page=cadastros" class="nav-item <?= isActiveUnified('cadastros') ?>">
                    <span class="nav-item-icon">📝</span>
                    Cadastros
                </a>
                <?php endif; ?>
                
                <?php if (!empty($_SESSION['perm_financeiro'])): ?>
                <a href="index.php?page=financeiro" class="nav-item <?= isActiveUnified('financeiro') ?>">
                    <span class="nav-item-icon">💰</span>
                    Financeiro
                </a>
                <?php endif; ?>
                
                <?php if (!empty($_SESSION['perm_administrativo'])): ?>
                <a href="index.php?page=administrativo" class="nav-item <?= isActiveUnified('administrativo') ?>">
                    <span class="nav-item-icon">👥</span>
                    Administrativo
                </a>
                <?php endif; ?>

                <?php if (!empty($_SESSION['perm_administrativo'])): ?>
                <a href="index.php?page=contabilidade" class="nav-item <?= isActiveUnified('contabilidade') ?>">
                    <span class="nav-item-icon">📑</span>
                    Contabilidade
                </a>
                <?php endif; ?>
                
                <?php if (!empty($_SESSION['perm_banco_smile'])): ?>
                <a href="index.php?page=banco_smile" class="nav-item <?= isActiveUnified('banco_smile') ?>">
                    <span class="nav-item-icon">🏦</span>
                    Banco Smile
                </a>
                <?php endif; ?>
                
                <!-- Separador visual antes do botão Sair -->
                <div style="height: 1px; background: rgba(255, 255, 255, 0.1); margin: 20px 0;"></div>
                
                <!-- Botão Sair -->
                <a href="logout.php" class="nav-item nav-item-logout">
                    <span class="nav-item-icon">🚪</span>
                    Sair
                </a>
            </nav>
    </div>
    <!-- Sidebar fechada -->
    
    <!-- Conteúdo principal -->
    <div class="main-content" id="mainContent" style="margin-left: 280px !important; width: calc(100% - 280px) !important; position: relative !important; top: 0 !important;">
            <?php if ($show_top_account_access): ?>
            <div class="top-account-access">
                <a class="top-account-link" href="index.php?page=minha_conta" title="Minha Conta">
                    <span class="top-account-avatar" style="<?= $foto_usuario ? "background-image: url('" . htmlspecialchars($foto_usuario) . "'); color: transparent;" : '' ?>">
                        <?php if (!$foto_usuario): ?>
                            <?= strtoupper(substr($nomeUser, 0, 2)) ?>
                        <?php endif; ?>
                    </span>
                    <span class="top-account-text">Minha Conta</span>
                </a>
            </div>
            <?php endif; ?>
            <div id="pageContent">
                <?php
                $logistica_breadcrumbs = [
                    'logistica' => 'Logística',
                    'logistica_estoque' => 'Logística > Estoque',
                    'logistica_contagem' => 'Logística > Estoque > Contagem',
                    'logistica_entrada' => 'Logística > Estoque > Entrada',
                    'logistica_saldo' => 'Logística > Estoque > Saldo',
                    'logistica_transferencias' => 'Logística > Estoque > Transferências',
                    'logistica_transferencia_ver' => 'Logística > Estoque > Transferências > Detalhe',
                    'logistica_transferencia_receber' => 'Logística > Estoque > Transferências > Receber',
                    'logistica_gerar_lista' => 'Logística > Listas > Gerar',
                    'logistica_listas' => 'Logística > Listas > Histórico',
                    'logistica_separacao_lista' => 'Logística > Listas > Separação',
                    'logistica_faltas_evento' => 'Logística > Alertas > Faltas',
                    'logistica_divergencias' => 'Logística > Divergências',
                    'logistica_resolver_conflitos' => 'Logística > Divergências > Resolver conflitos',
                    'logistica_financeiro' => 'Logística > Financeiro',
                    'logistica_financeiro_estoque' => 'Logística > Financeiro > Estoque',
                    'logistica_financeiro_lista' => 'Logística > Financeiro > Lista',
                    'logistica_revisar_custos' => 'Logística > Financeiro > Revisar custos',
                    'logistica_catalogo' => 'Logística > Catálogo',
                    'logistica_tipologias' => 'Logística > Catálogo > Tipologias',
                    'logistica_insumos' => 'Logística > Catálogo > Insumos',
                    'logistica_receitas' => 'Logística > Catálogo > Receitas',
                    'logistica_conexao' => 'Logística > Configurações > Conexão',
                    'logistica_unidades_medida' => 'Logística > Configurações > Unidades de medida',
                    'logistica_diagnostico' => 'Logística > Configurações > Diagnóstico',
                    'logistica_upload' => 'Logística > Upload'
                ];
                if (isset($logistica_breadcrumbs[$current_page])): ?>
                    <div style="margin: 12px 24px 0 24px; font-size: 0.9rem; color: #64748b;">
                        <?= htmlspecialchars($logistica_breadcrumbs[$current_page]) ?>
                    </div>
                <?php endif; ?>
                <?php 
                // Renderizar conteúdo diretamente se for uma página especial
                // NOTA: 'logistico', 'configuracoes', 'cadastros', 'financeiro', 'administrativo' agora usam
                // arquivos separados que gerenciam seu próprio conteúdo via includeSidebar
                if (in_array($current_page, ['dashboard', 'demandas', 'demandas_quadro'])) {
                    // O conteúdo já foi definido nas variáveis acima
                    if ($current_page === 'dashboard' && !empty($dashboard_content)) {
                        echo $dashboard_content;
                    }
                }
                // Outras páginas (logistico, configuracoes, cadastros, financeiro, administrativo) 
                // não renderizam aqui - seus arquivos PHP gerenciam seu próprio conteúdo via echo $conteudo
                
                // Fechar divs apenas se for página especial que já renderizou o conteúdo
                // NOTA: comercial usa comercial_landing.php que gerencia seus próprios divs
                // NOTA: logistico, configuracoes, cadastros, financeiro, administrativo agora 
                // usam arquivos separados que gerenciam seus próprios divs
                if (in_array($current_page, ['dashboard'])) {
                    echo '</div>'; // fecha #pageContent
                    echo '</div>'; // fecha .main-content
                }
                ?>
    
    <script src="/js/push-notifications.js"></script>
    <script>
        // Exibir avisos de sucesso/erro vindos da URL (ex.: redirect com ?success= ou ?error=)
        (function() {
            var params = new URLSearchParams(window.location.search);
            var success = params.get('success');
            var error = params.get('error');
            if (typeof customAlert === 'function') {
                if (success) {
                    customAlert(decodeURIComponent(success), 'Sucesso');
                } else if (error) {
                    var decodedError = decodeURIComponent(error);
                    var looksSuccess = /sucesso|sucess|atualizado|salvo/i.test(decodedError);
                    customAlert(decodedError, looksSuccess ? 'Sucesso' : 'Erro');
                }
            }
        })();

        // Push notifications para qualquer usuário logado (por usuário, sem bloqueio de navegação)
        (function () {
            var userId = <?= $push_user_id ?>;
            if (!userId || !window.pushNotificationsManager) return;

            async function initPushForUser() {
                try {
                    var manager = window.pushNotificationsManager;
                    var state = await manager.init(userId, true);
                    if (state && state.subscribed) {
                        return;
                    }

                    if (!('Notification' in window)) {
                        return;
                    }

                    if (Notification.permission === 'granted') {
                        await manager.registerServiceWorker();
                        await manager.subscribe();
                        return;
                    }

                    // Solicita permissão uma vez por usuário/navegador para não incomodar a cada página.
                    var promptKey = 'push_prompted_user_' + String(userId);
                    var prompted = localStorage.getItem(promptKey) === '1';
                    if (Notification.permission === 'default' && !prompted) {
                        localStorage.setItem(promptKey, '1');
                        await manager.requestPermission();
                        await manager.registerServiceWorker();
                        await manager.subscribe();
                    }
                } catch (err) {
                    console.warn('Push init falhou:', err && err.message ? err.message : err);
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initPushForUser);
            } else {
                initPushForUser();
            }
        })();
        
        // Função para alternar sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            
            if (!sidebar || !mainContent) {
                console.error('Sidebar ou mainContent não encontrados');
                return;
            }

            const isMobile = window.matchMedia('(max-width: 768px)').matches;

            // Controle dedicado para mobile: usa classe .open
            if (isMobile) {
                const desiredState = typeof arguments[0] === 'boolean' ? arguments[0] : !sidebar.classList.contains('open');
                if (desiredState) {
                    sidebar.classList.add('open');
                    document.body.classList.add('sidebar-mobile-open');
                    if (sidebarOverlay) {
                        sidebarOverlay.classList.add('open');
                    }
                } else {
                    sidebar.classList.remove('open');
                    document.body.classList.remove('sidebar-mobile-open');
                    if (sidebarOverlay) {
                        sidebarOverlay.classList.remove('open');
                    }
                }
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

            document.body.classList.remove('sidebar-mobile-open');
            if (sidebarOverlay) {
                sidebarOverlay.classList.remove('open');
            }

            // Forçar recalculo de layouts dependentes de largura (ex: FullCalendar).
            setTimeout(() => window.dispatchEvent(new Event('resize')), 150);
        }

        function syncSidebarByViewport() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            if (!sidebar || !mainContent) return;

            const isMobile = window.matchMedia('(max-width: 768px)').matches;
            if (typeof window.__lastSidebarMobileMode === 'undefined') {
                window.__lastSidebarMobileMode = null;
            }
            if (window.__lastSidebarMobileMode === isMobile && arguments[0] !== true) {
                return;
            }
            window.__lastSidebarMobileMode = isMobile;

            if (isMobile) {
                // Garante estado inicial fechado no mobile
                sidebar.classList.remove('collapsed');
                sidebar.classList.remove('open');
                mainContent.classList.remove('expanded');
                mainContent.style.marginLeft = '0';
                mainContent.style.width = '100%';
                document.body.classList.remove('sidebar-mobile-open');
                if (sidebarOverlay) {
                    sidebarOverlay.classList.remove('open');
                }
                return;
            }

            // Em desktop, restaura comportamento padrão
            sidebar.classList.remove('open');
            document.body.classList.remove('sidebar-mobile-open');
            if (sidebarOverlay) {
                sidebarOverlay.classList.remove('open');
            }
            if (sidebar.classList.contains('collapsed')) {
                mainContent.classList.add('expanded');
                mainContent.style.marginLeft = '0';
                mainContent.style.width = '100%';
            } else {
                mainContent.classList.remove('expanded');
                mainContent.style.marginLeft = '280px';
                mainContent.style.width = 'calc(100% - 280px)';
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
            syncSidebarByViewport();

            // Em mobile, fecha o menu ao tocar em uma opção da sidebar.
            document.querySelectorAll('#sidebar .nav-item').forEach(function(link) {
                link.addEventListener('click', function() {
                    if (window.matchMedia('(max-width: 768px)').matches) {
                        toggleSidebar(false);
                    }
                });
            });
            
            // Se for dashboard, logistico, configurações, cadastros, financeiro ou administrativo, 
            // o conteúdo já foi renderizado via PHP, não fazer nada
            // Para agenda, o conteúdo também já foi renderizado via PHP após sidebar_unified.php
            // NOTA: Comercial usa comercial_landing.php via index.php, não precisa renderizar aqui
            // NOTA: Páginas que usam includeSidebar/endSidebar já renderizam o conteúdo, não carregar via AJAX
            const pagesWithOwnRender = [
                'dashboard', 'logistico', 'configuracoes', 'cadastros', 'financeiro', 'administrativo', 
                'agenda', 'demandas', 'demandas_quadro',
                'comercial', 'comercial_degust_inscritos', 'comercial_degust_inscricoes', 'comercial_degustacao_editar',
                'comercial_degust_public', 'comercial_pagamento',
                // Vendas (páginas com JS próprio — não carregar via AJAX)
                'vendas_pre_contratos', 'vendas_administracao', 'vendas_lancamento_presencial',
                'vendas_kanban', 'vendas_links_publicos',
                // Eventos (formulários reutilizáveis)
                'formularios_eventos',
                // Eventos (módulo com includeSidebar — não carregar via AJAX)
                'eventos', 'eventos_reuniao_final', 'eventos_rascunhos', 'eventos_calendario', 'eventos_galeria', 'eventos_fornecedores',
                // Minha conta e gestão de documentos (includeSidebar)
                'minha_conta', 'contabilidade_holerite_individual', 'administrativo_gestao_documentos'
            ];

            const isLogistica = currentPage === 'logistica' || currentPage.startsWith('logistica_');
            const isEventos = currentPage === 'eventos' || currentPage.startsWith('eventos_');

            if (!pagesWithOwnRender.includes(currentPage) && !isLogistica && !isEventos) {
                // Para outras páginas, carregar via AJAX
                loadPageContent(currentPage);
            }

            // Auto-sync Google Calendar em background (com webhook + fallback)
            startGoogleCalendarAutoSync();
        });

        window.addEventListener('resize', syncSidebarByViewport);

        function startGoogleCalendarAutoSync() {
            const syncUrl = 'google_calendar_auto_sync_ping.php';
            const run = () => {
                fetch(syncUrl, {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                }).catch(() => {});
            };
            run();
            setInterval(run, 300000);
        }
        
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
                            countEl.textContent = dashboardNotificacoesNaoLidas > 99 ? '99+' : dashboardNotificacoesNaoLidas;
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
