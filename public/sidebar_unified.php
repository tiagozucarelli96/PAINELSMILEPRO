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
            JOIN comercial_degustacoes cd ON ci.event_id = cd.id
            WHERE cd.status = 'publicado'
            AND DATE_TRUNC('month', ci.criado_em) = DATE_TRUNC('month', CURRENT_DATE)
            AND ci.status IN ('confirmado', 'lista_espera')
        ");
        $stmt->execute();
        $stats['inscritos_degustacao'] = $stmt->fetchColumn() ?: 0;
        
        // 2. Eventos Criados via ME Eventos (webhook)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM me_eventos_webhook 
            WHERE webhook_tipo = 'created'
            AND DATE_TRUNC('month', recebido_em) = DATE_TRUNC('month', CURRENT_DATE)
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
            SELECT ae.id, ae.titulo, ae.data_inicio, ae.data_fim, ae.tipo, ae.cor, ae.observacoes,
                   u.nome as responsavel_nome
            FROM agenda_eventos ae
            LEFT JOIN usuarios u ON u.id = ae.usuario_id
            WHERE DATE(ae.data_inicio) = CURRENT_DATE
            ORDER BY ae.data_inicio ASC
            LIMIT 10
        ");
        $stmt->execute();
        $agenda_hoje = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $agenda_hoje = [];
    }
    
    // Buscar demandas do dia atual
    $demandas_hoje = [];
    try {
        $stmt = $pdo->prepare("
            SELECT dc.id, dc.titulo, dc.descricao, dc.prazo, dc.status,
                   dq.nome as quadro_nome, u.nome as responsavel_nome
            FROM demandas_cartoes dc
            LEFT JOIN demandas_quadros dq ON dq.id = dc.quadro_id
            LEFT JOIN usuarios u ON u.id = dc.responsavel_id
            WHERE DATE(dc.prazo) = CURRENT_DATE
            AND dc.status NOT IN ('concluido', 'arquivado')
            ORDER BY dc.prazo ASC
            LIMIT 10
        ");
        $stmt->execute();
        $demandas_hoje = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $demandas_hoje = [];
    }
    
    $dashboard_content = '
    <div class="page-container">
        <div class="page-header">
            <h1 class="page-title">🏠 Dashboard</h1>
            <p class="page-subtitle">Bem-vindo, ' . htmlspecialchars($nomeUser) . '! | Email: ' . htmlspecialchars($user_email) . '</p>
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
                <span class="section-badge">' . count($demandas_hoje) . ' tarefas</span>
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
    </div>';
    
    // Inserir o conteúdo do dashboard no JavaScript
    $dashboard_js = "
    document.addEventListener('DOMContentLoaded', function() {
        const pageContent = document.getElementById('pageContent');
        if (pageContent) {
            pageContent.innerHTML = `$dashboard_content`;
        }
    });";
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
        </div>
    </div>';
    
    $dashboard_js = "
    document.addEventListener('DOMContentLoaded', function() {
        const pageContent = document.getElementById('pageContent');
        if (pageContent) {
            pageContent.innerHTML = `$comercial_content`;
        }
    });";
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
        </div>
    </div>';
    
    $dashboard_js = "
    document.addEventListener('DOMContentLoaded', function() {
        const pageContent = document.getElementById('pageContent');
        if (pageContent) {
            pageContent.innerHTML = `$logistico_content`;
        }
    });";
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
    
    $dashboard_js = "
    document.addEventListener('DOMContentLoaded', function() {
        const pageContent = document.getElementById('pageContent');
        if (pageContent) {
            pageContent.innerHTML = `$configuracoes_content`;
        }
    });";
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
        </div>
    </div>';
    
    $dashboard_js = "
    document.addEventListener('DOMContentLoaded', function() {
        const pageContent = document.getElementById('pageContent');
        if (pageContent) {
            pageContent.innerHTML = `$cadastros_content`;
        }
    });";
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
                    <h3>📊 Relatórios</h3>
                    <span class="card-icon">📊</span>
                </div>
                <div class="card-content">
                    <p>Relatórios financeiros e análises</p>
                    <a href="index.php?page=relatorios_financeiros" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>🏦 Contas</h3>
                    <span class="card-icon">🏦</span>
                </div>
                <div class="card-content">
                    <p>Gestão de contas bancárias</p>
                    <a href="index.php?page=contas_bancarias" class="btn-primary">Acessar</a>
                </div>
            </div>
        </div>
    </div>';
    
    $dashboard_js = "
    document.addEventListener('DOMContentLoaded', function() {
        const pageContent = document.getElementById('pageContent');
        if (pageContent) {
            pageContent.innerHTML = `$financeiro_content`;
        }
    });";
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
                    <a href="index.php?page=relatorios" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>🔍 Auditoria</h3>
                    <span class="card-icon">🔍</span>
                </div>
                <div class="card-content">
                    <p>Logs e auditoria do sistema</p>
                    <a href="index.php?page=auditoria" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>⚙️ Manutenção</h3>
                    <span class="card-icon">⚙️</span>
                </div>
                <div class="card-content">
                    <p>Manutenção e backup do sistema</p>
                    <a href="index.php?page=manutencao" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📈 Estatísticas</h3>
                    <span class="card-icon">📈</span>
                </div>
                <div class="card-content">
                    <p>Estatísticas e métricas do sistema</p>
                    <a href="index.php?page=estatisticas" class="btn-primary">Acessar</a>
                </div>
            </div>
        </div>
    </div>';
    
    $dashboard_js = "
    document.addEventListener('DOMContentLoaded', function() {
        const pageContent = document.getElementById('pageContent');
        if (pageContent) {
            pageContent.innerHTML = `$administrativo_content`;
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
            
            // Se for dashboard, comercial, logistico, configuracoes, cadastros, financeiro ou administrativo, não fazer AJAX - usar conteúdo já inserido
            if (['dashboard', 'comercial', 'logistico', 'configuracoes', 'cadastros', 'financeiro', 'administrativo'].includes(currentPage)) {
                // Conteúdo já está carregado via PHP, não fazer nada
                return;
            }
            
            // Para outras páginas, carregar via AJAX
            loadPageContent(currentPage);
        });
        
        <?= $dashboard_js ?>
        
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
