<?php
// dashboard_principal.php — Dashboard principal no estilo ME Eventos
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/agenda_helper.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';
require_once __DIR__ . '/sidebar_unified.php';
require_once __DIR__ . '/core/helpers.php';
if (is_file(__DIR__ . '/permissoes_boot.php')) { require_once __DIR__ . '/permissoes_boot.php'; }

$nomeUser = isset($_SESSION['nome']) ? $_SESSION['nome'] : 'Usuário';
$usuario_id = $_SESSION['user_id'] ?? 1;

// Iniciar sidebar
includeSidebar();
setPageTitle('Dashboard Principal');
addBreadcrumb([
    ['title' => 'Dashboard']
]);

// Filtros
$mes_atual = $_GET['mes'] ?? date('m');
$ano_atual = $_GET['ano'] ?? date('Y');
$unidade_filtro = $_GET['unidade'] ?? 'todas';

// Buscar métricas do mês atual
$stats = [];
try {
    // Leads do mês (simulado - pode ser integrado com ME API)
    $stats['leads_mes'] = $pdo->query("
        SELECT COUNT(*) FROM eventos 
        WHERE EXTRACT(MONTH FROM created_at) = $mes_atual 
        AND EXTRACT(YEAR FROM created_at) = $ano_atual
    ")->fetchColumn();
    
    // Leads em negociação (simulado)
    $stats['leads_negociacao'] = $pdo->query("
        SELECT COUNT(*) FROM eventos 
        WHERE status = 'negociacao'
    ")->fetchColumn();
    
    // Contratos fechados (simulado)
    $stats['contratos_fechados'] = $pdo->query("
        SELECT COUNT(*) FROM eventos 
        WHERE status = 'confirmado'
        AND EXTRACT(MONTH FROM created_at) = $mes_atual 
        AND EXTRACT(YEAR FROM created_at) = $ano_atual
    ")->fetchColumn();
    
    // Vendas realizadas
    $stats['vendas_realizadas'] = $pdo->query("
        SELECT COUNT(*) FROM eventos 
        WHERE status IN ('confirmado', 'realizado')
        AND EXTRACT(MONTH FROM created_at) = $mes_atual 
        AND EXTRACT(YEAR FROM created_at) = $ano_atual
    ")->fetchColumn();
    
    // Meta mensal (buscar da configuração)
    $meta_mensal = $pdo->query("
        SELECT valor FROM configuracoes 
        WHERE chave = 'meta_mensal_$mes_atual$ano_atual'
    ")->fetchColumn();
    $stats['meta_mensal'] = $meta_mensal ?: 10; // Meta padrão
    
    // Próximos eventos
    $agenda = new AgendaHelper();
    $proximos_eventos = $agenda->obterAgendaDia($usuario_id, 168); // Próximos 7 dias
    
    // Tarefas próximas (simulado)
    $tarefas_proximas = $pdo->query("
        SELECT * FROM demandas 
        WHERE data_vencimento BETWEEN NOW() AND NOW() + INTERVAL '7 days'
        ORDER BY data_vencimento ASC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Notificações (simulado)
    $notificacoes = $pdo->query("
        SELECT * FROM notificacoes 
        ORDER BY created_at DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // E-mails (simulado)
    $emails_hoje = $pdo->query("
        SELECT COUNT(*) FROM emails 
        WHERE DATE(created_at) = CURRENT_DATE
    ")->fetchColumn();
    
} catch (Exception $e) {
    $stats = [
        'leads_mes' => 0,
        'leads_negociacao' => 0,
        'contratos_fechados' => 0,
        'vendas_realizadas' => 0,
        'meta_mensal' => 10
    ];
    $proximos_eventos = [];
    $tarefas_proximas = [];
    $notificacoes = [];
    $emails_hoje = 0;
}

// Dados para gráfico de conversão (últimos 12 meses)
$dados_grafico = [];
for ($i = 11; $i >= 0; $i--) {
    $mes = date('m', strtotime("-$i months"));
    $ano = date('Y', strtotime("-$i months"));
    $mes_nome = date('M Y', strtotime("-$i months"));
    
    $leads = $pdo->query("
        SELECT COUNT(*) FROM eventos 
        WHERE EXTRACT(MONTH FROM created_at) = $mes 
        AND EXTRACT(YEAR FROM created_at) = $ano
    ")->fetchColumn();
    
    $contratos = $pdo->query("
        SELECT COUNT(*) FROM eventos 
        WHERE status = 'confirmado'
        AND EXTRACT(MONTH FROM created_at) = $mes 
        AND EXTRACT(YEAR FROM created_at) = $ano
    ")->fetchColumn();
    
    $dados_grafico[] = [
        'mes' => $mes_nome,
        'leads' => $leads,
        'contratos' => $contratos,
        'conversao' => $leads > 0 ? round(($contratos / $leads) * 100, 1) : 0
    ];
}
?>

<style>
        /* Dashboard Principal - Cores do Sistema Smile */
        .dashboard-container {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
            background: #f8fafc;
        }

/* Filtros */
.dashboard-filters {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    margin-bottom: 20px;
    display: flex;
    gap: 20px;
    align-items: center;
    flex-wrap: wrap;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.filter-label {
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filter-select {
    padding: 8px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 14px;
    background: white;
    min-width: 120px;
}

/* Cards do Topo */
.dashboard-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

        .dashboard-card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #1e3a8a;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

.dashboard-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
}

.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}

        .card-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #1e3a8a, #1e40af);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
        }

.card-period {
    background: #f1f5f9;
    color: #64748b;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.card-value {
    font-size: 32px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 8px;
}

.card-label {
    font-size: 14px;
    color: #64748b;
    font-weight: 500;
}

.card-source {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 4px;
}

/* Gráfico */
.dashboard-chart {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    margin-bottom: 30px;
}

.chart-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}

.chart-title {
    font-size: 18px;
    font-weight: 600;
    color: #1e293b;
}

.chart-container {
    height: 300px;
    position: relative;
}

/* Grid de Conteúdo */
.dashboard-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 30px;
}

.dashboard-widget {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
}

.widget-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}

.widget-title {
    font-size: 16px;
    font-weight: 600;
    color: #1e293b;
}

.widget-icon {
    width: 32px;
    height: 32px;
    background: linear-gradient(135deg, #10b981, #059669);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    color: white;
}

/* Lista de Eventos */
.event-list {
    max-height: 400px;
    overflow-y: auto;
}

.event-item {
    display: flex;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #f1f5f9;
}

.event-item:last-child {
    border-bottom: none;
}

.event-info {
    flex: 1;
}

.event-name {
    font-size: 14px;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 4px;
}

.event-details {
    font-size: 12px;
    color: #64748b;
    display: flex;
    gap: 12px;
}

.event-status {
    display: flex;
    align-items: center;
    gap: 8px;
}

.event-progress {
    width: 60px;
    height: 4px;
    background: #e2e8f0;
    border-radius: 2px;
    overflow: hidden;
}

.event-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #10b981, #059669);
    border-radius: 2px;
}

.event-alert {
    background: #fef2f2;
    color: #dc2626;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 600;
}

/* Lista de Tarefas */
.task-list {
    max-height: 400px;
    overflow-y: auto;
}

.task-item {
    display: flex;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #f1f5f9;
}

.task-item:last-child {
    border-bottom: none;
}

.task-info {
    flex: 1;
}

.task-name {
    font-size: 14px;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 4px;
}

.task-details {
    font-size: 12px;
    color: #64748b;
}

.task-status {
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.task-status.pendente {
    background: #fef3c7;
    color: #d97706;
}

.task-status.concluida {
    background: #d1fae5;
    color: #059669;
}

.task-status.atrasada {
    background: #fef2f2;
    color: #dc2626;
}

/* Meta Mensal */
.meta-container {
    text-align: center;
}

.meta-circle {
    width: 120px;
    height: 120px;
    margin: 0 auto 16px;
    position: relative;
}

.meta-progress {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    background: conic-gradient(#3b82f6 0deg, #e2e8f0 0deg);
    display: flex;
    align-items: center;
    justify-content: center;
}

.meta-text {
    font-size: 24px;
    font-weight: 700;
    color: #1e293b;
}

.meta-label {
    font-size: 12px;
    color: #64748b;
    margin-bottom: 8px;
}

.meta-value {
    font-size: 14px;
    font-weight: 600;
    color: #1e293b;
}

/* Notificações */
.notification-list {
    max-height: 300px;
    overflow-y: auto;
}

.notification-item {
    display: flex;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #f1f5f9;
}

.notification-item:last-child {
    border-bottom: none;
}

.notification-icon {
    width: 32px;
    height: 32px;
    background: #f1f5f9;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    font-size: 14px;
}

.notification-content {
    flex: 1;
}

.notification-title {
    font-size: 13px;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 2px;
}

.notification-time {
    font-size: 11px;
    color: #94a3b8;
}

/* Botão Solicitar Pagamento */
.payment-button {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    border: none;
    padding: 16px 20px;
    border-radius: 50px;
    font-size: 14px;
    font-weight: 600;
    box-shadow: 0 4px 20px rgba(16, 185, 129, 0.3);
    cursor: pointer;
    transition: all 0.3s ease;
    z-index: 1000;
}

.payment-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(16, 185, 129, 0.4);
}

/* Card de E-mail */
.email-card {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: white;
    padding: 16px;
    border-radius: 12px;
    text-align: center;
    margin-bottom: 20px;
}

.email-icon {
    font-size: 24px;
    margin-bottom: 8px;
}

.email-count {
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 4px;
}

.email-label {
    font-size: 12px;
    opacity: 0.9;
}

/* Responsivo */
@media (max-width: 768px) {
    .dashboard-grid {
        grid-template-columns: 1fr;
    }
    
    .dashboard-cards {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .dashboard-filters {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>

<div class="dashboard-container">
    <!-- Filtros -->
    <div class="dashboard-filters">
        <div class="filter-group">
            <label class="filter-label">Período</label>
            <select class="filter-select" id="mes-filter">
                <option value="01" <?= $mes_atual == '01' ? 'selected' : '' ?>>Janeiro</option>
                <option value="02" <?= $mes_atual == '02' ? 'selected' : '' ?>>Fevereiro</option>
                <option value="03" <?= $mes_atual == '03' ? 'selected' : '' ?>>Março</option>
                <option value="04" <?= $mes_atual == '04' ? 'selected' : '' ?>>Abril</option>
                <option value="05" <?= $mes_atual == '05' ? 'selected' : '' ?>>Maio</option>
                <option value="06" <?= $mes_atual == '06' ? 'selected' : '' ?>>Junho</option>
                <option value="07" <?= $mes_atual == '07' ? 'selected' : '' ?>>Julho</option>
                <option value="08" <?= $mes_atual == '08' ? 'selected' : '' ?>>Agosto</option>
                <option value="09" <?= $mes_atual == '09' ? 'selected' : '' ?>>Setembro</option>
                <option value="10" <?= $mes_atual == '10' ? 'selected' : '' ?>>Outubro</option>
                <option value="11" <?= $mes_atual == '11' ? 'selected' : '' ?>>Novembro</option>
                <option value="12" <?= $mes_atual == '12' ? 'selected' : '' ?>>Dezembro</option>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">Ano</label>
            <select class="filter-select" id="ano-filter">
                <option value="2024" <?= $ano_atual == '2024' ? 'selected' : '' ?>>2024</option>
                <option value="2025" <?= $ano_atual == '2025' ? 'selected' : '' ?>>2025</option>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">Unidade</label>
            <select class="filter-select" id="unidade-filter">
                <option value="todas" <?= $unidade_filtro == 'todas' ? 'selected' : '' ?>>Todas</option>
                <option value="lisbon" <?= $unidade_filtro == 'lisbon' ? 'selected' : '' ?>>Lisbon</option>
                <option value="garden" <?= $unidade_filtro == 'garden' ? 'selected' : '' ?>>Garden</option>
                <option value="diver" <?= $unidade_filtro == 'diver' ? 'selected' : '' ?>>Diver</option>
            </select>
        </div>
    </div>

    <!-- Cards do Topo -->
    <div class="dashboard-cards">
        <div class="dashboard-card">
            <div class="card-header">
                <div class="card-icon">📊</div>
                <div class="card-period"><?= strtoupper(date('M', mktime(0, 0, 0, $mes_atual, 1))) ?></div>
            </div>
            <div class="card-value"><?= $stats['leads_mes'] ?></div>
            <div class="card-label">Leads do Mês</div>
            <div class="card-source">Fonte: ME Eventos</div>
        </div>
        
        <div class="dashboard-card">
            <div class="card-header">
                <div class="card-icon">🤝</div>
                <div class="card-period">TODO PERÍODO</div>
            </div>
            <div class="card-value"><?= $stats['leads_negociacao'] ?></div>
            <div class="card-label">Leads em Negociação</div>
            <div class="card-source">Fonte: Interno</div>
        </div>
        
        <div class="dashboard-card">
            <div class="card-header">
                <div class="card-icon">✅</div>
                <div class="card-period"><?= strtoupper(date('M', mktime(0, 0, 0, $mes_atual, 1))) ?></div>
            </div>
            <div class="card-value"><?= $stats['contratos_fechados'] ?></div>
            <div class="card-label">Contratos Fechados</div>
            <div class="card-source">Fonte: ME Eventos</div>
        </div>
        
        <div class="dashboard-card">
            <div class="card-header">
                <div class="card-icon">💰</div>
                <div class="card-period"><?= strtoupper(date('M', mktime(0, 0, 0, $mes_atual, 1))) ?></div>
            </div>
            <div class="card-value"><?= $stats['vendas_realizadas'] ?></div>
            <div class="card-label">Vendas Realizadas</div>
            <div class="card-source">Fonte: ME Eventos</div>
        </div>
    </div>

    <!-- Gráfico de Conversão -->
    <div class="dashboard-chart">
        <div class="chart-header">
            <h3 class="chart-title">Taxa de Conversão de Vendas Mês a Mês</h3>
        </div>
        <div class="chart-container">
            <canvas id="conversionChart"></canvas>
        </div>
    </div>

    <!-- Grid de Conteúdo -->
    <div class="dashboard-grid">
        <!-- Próximos Eventos -->
        <div class="dashboard-widget">
            <div class="widget-header">
                <h3 class="widget-title">Próximos Eventos</h3>
                <div class="widget-icon">📅</div>
            </div>
            <div class="event-list">
                <?php if (empty($proximos_eventos)): ?>
                    <div style="text-align: center; padding: 40px; color: #94a3b8;">
                        <div style="font-size: 24px; margin-bottom: 8px;">📅</div>
                        <div>Nenhum evento próximo</div>
                    </div>
                <?php else: ?>
                    <?php foreach (array_slice($proximos_eventos, 0, 5) as $evento): ?>
                        <div class="event-item">
                            <div class="event-info">
                                <div class="event-name"><?= h($evento['titulo'] ?? 'Evento sem título') ?></div>
                                <div class="event-details">
                                    <span><?= date('d/m/Y', strtotime($evento['data_inicio'] ?? 'now')) ?></span>
                                    <span><?= date('H:i', strtotime($evento['data_inicio'] ?? 'now')) ?></span>
                                    <span><?= h($evento['local'] ?? 'Local não definido') ?></span>
                                </div>
                            </div>
                            <div class="event-status">
                                <div class="event-progress">
                                    <div class="event-progress-bar" style="width: 85%"></div>
                                </div>
                                <?php if (empty($evento['convidados'])): ?>
                                    <div class="event-alert">Sem nº convidados</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tarefas Próximas -->
        <div class="dashboard-widget">
            <div class="widget-header">
                <h3 class="widget-title">Tarefas Próximas</h3>
                <div class="widget-icon">📋</div>
            </div>
            <div class="task-list">
                <?php if (empty($tarefas_proximas)): ?>
                    <div style="text-align: center; padding: 40px; color: #94a3b8;">
                        <div style="font-size: 24px; margin-bottom: 8px;">✅</div>
                        <div>Nenhuma tarefa próxima</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($tarefas_proximas as $tarefa): ?>
                        <div class="task-item">
                            <div class="task-info">
                                <div class="task-name"><?= h($tarefa['titulo'] ?? 'Tarefa sem título') ?></div>
                                <div class="task-details">
                                    <?= h($tarefa['evento_nome'] ?? 'Sem evento') ?> • 
                                    Vence em <?= date('d/m/Y', strtotime($tarefa['data_vencimento'] ?? 'now')) ?>
                                </div>
                            </div>
                            <div class="task-status <?= $tarefa['status'] ?? 'pendente' ?>">
                                <?= ucfirst($tarefa['status'] ?? 'pendente') ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Meta Mensal e Notificações -->
    <div class="dashboard-grid">
        <!-- Meta Mensal -->
        <div class="dashboard-widget">
            <div class="widget-header">
                <h3 class="widget-title">Meta Pedidos</h3>
                <div class="widget-icon">🎯</div>
            </div>
            <div class="meta-container">
                <div class="meta-circle">
                    <div class="meta-progress" id="metaProgress">
                        <div class="meta-text" id="metaPercent">0%</div>
                    </div>
                </div>
                <div class="meta-label">Meta R$ <?= number_format($stats['meta_mensal'] * 1000, 0, ',', '.') ?></div>
                <div class="meta-value">Alcançado R$ <?= number_format($stats['contratos_fechados'] * 1000, 0, ',', '.') ?></div>
            </div>
        </div>

        <!-- Notificações -->
        <div class="dashboard-widget">
            <div class="widget-header">
                <h3 class="widget-title">Notificações</h3>
                <div class="widget-icon">🔔</div>
            </div>
            <div class="notification-list">
                <?php if (empty($notificacoes)): ?>
                    <div style="text-align: center; padding: 40px; color: #94a3b8;">
                        <div style="font-size: 24px; margin-bottom: 8px;">🔔</div>
                        <div>Nenhuma notificação no momento</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($notificacoes as $notificacao): ?>
                        <div class="notification-item">
                            <div class="notification-icon">📢</div>
                            <div class="notification-content">
                                <div class="notification-title"><?= h($notificacao['titulo'] ?? 'Notificação') ?></div>
                                <div class="notification-time"><?= date('d/m/Y H:i', strtotime($notificacao['created_at'] ?? 'now')) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Card de E-mail -->
    <div class="email-card">
        <div class="email-icon">📧</div>
        <div class="email-count"><?= $emails_hoje ?></div>
        <div class="email-label">Novas mensagens (hoje/últimas 24h)</div>
    </div>
</div>

<!-- Botão Solicitar Pagamento -->
<button class="payment-button" onclick="openPaymentModal()">
    💸 Solicitar Pagamento
</button>

<!-- Modal de Solicitar Pagamento -->
<div id="paymentModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 12px; width: 90%; max-width: 400px;">
        <h3 style="margin-bottom: 20px; color: #1e293b;">Solicitar Pagamento</h3>
        <form id="paymentForm">
            <div style="margin-bottom: 16px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151;">Valor</label>
                <input type="number" name="valor" step="0.01" required style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 16px;">
            </div>
            <div style="margin-bottom: 16px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151;">Motivo/Descrição</label>
                <textarea name="descricao" required style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; height: 80px; resize: vertical;"></textarea>
            </div>
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151;">Chave PIX</label>
                <input type="text" name="chave_pix" required style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 16px;">
            </div>
            <div style="display: flex; gap: 12px;">
                <button type="submit" style="flex: 1; background: #10b981; color: white; border: none; padding: 12px; border-radius: 8px; font-weight: 600; cursor: pointer;">Enviar</button>
                <button type="button" onclick="closePaymentModal()" style="flex: 1; background: #6b7280; color: white; border: none; padding: 12px; border-radius: 8px; font-weight: 600; cursor: pointer;">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Gráfico de Conversão
const ctx = document.getElementById('conversionChart').getContext('2d');
const chartData = <?= json_encode($dados_grafico) ?>;

new Chart(ctx, {
    type: 'bar',
    data: {
        labels: chartData.map(d => d.mes),
        datasets: [{
            label: 'Leads',
            data: chartData.map(d => d.leads),
            backgroundColor: 'rgba(59, 130, 246, 0.8)',
            borderColor: 'rgba(59, 130, 246, 1)',
            borderWidth: 1
        }, {
            label: 'Contratos',
            data: chartData.map(d => d.contratos),
            backgroundColor: 'rgba(16, 185, 129, 0.8)',
            borderColor: 'rgba(16, 185, 129, 1)',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true
            }
        },
        plugins: {
            legend: {
                position: 'top',
            }
        }
    }
});

// Meta Mensal - Atualizar círculo de progresso
const metaPercent = Math.min(100, (<?= $stats['contratos_fechados'] ?> / <?= $stats['meta_mensal'] ?>) * 100);
document.getElementById('metaPercent').textContent = Math.round(metaPercent) + '%';
document.getElementById('metaProgress').style.background = `conic-gradient(#3b82f6 ${metaPercent * 3.6}deg, #e2e8f0 0deg)`;

// Modal de Pagamento
function openPaymentModal() {
    document.getElementById('paymentModal').style.display = 'block';
}

function closePaymentModal() {
    document.getElementById('paymentModal').style.display = 'none';
    document.getElementById('paymentForm').reset();
}

// Envio do formulário de pagamento
document.getElementById('paymentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    // Simular envio (substituir por chamada real para pagamentos_solicitar.php)
    fetch('pagamentos_solicitar.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Pagamento solicitado com sucesso!');
            closePaymentModal();
        } else {
            alert('Erro ao solicitar pagamento: ' + data.message);
        }
    })
    .catch(error => {
        alert('Erro ao solicitar pagamento');
        console.error(error);
    });
});

// Filtros
document.getElementById('mes-filter').addEventListener('change', updateFilters);
document.getElementById('ano-filter').addEventListener('change', updateFilters);
document.getElementById('unidade-filter').addEventListener('change', updateFilters);

function updateFilters() {
    const mes = document.getElementById('mes-filter').value;
    const ano = document.getElementById('ano-filter').value;
    const unidade = document.getElementById('unidade-filter').value;
    
    const url = new URL(window.location);
    url.searchParams.set('mes', mes);
    url.searchParams.set('ano', ano);
    url.searchParams.set('unidade', unidade);
    
    window.location.href = url.toString();
}

// Fechar modal ao clicar fora
document.getElementById('paymentModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePaymentModal();
    }
});
</script>

<?php
// Página finalizada - sidebar já está incluída no sidebar_unified.php
?>
