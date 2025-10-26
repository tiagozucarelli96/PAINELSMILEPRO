<?php
// dashboard_agenda.php — Dashboard com Agenda do Dia
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/agenda_helper.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';
require_once __DIR__ . '/sidebar_integration.php';
if (is_file(__DIR__ . '/permissoes_boot.php')) { require_once __DIR__ . '/permissoes_boot.php'; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$nomeUser = isset($_SESSION['nome']) ? $_SESSION['nome'] : 'Usuário';
$usuario_id = $_SESSION['user_id'] ?? 1;

// Iniciar sidebar
includeSidebar();
setPageTitle('Dashboard');
addBreadcrumb([
    ['title' => 'Dashboard']
]);

// Buscar métricas consolidadas do banco de dados
$stats = [];
try {
    // Métricas básicas do sistema
    $stats['usuarios'] = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = true")->fetchColumn();
    $stats['fornecedores'] = $pdo->query("SELECT COUNT(*) FROM fornecedores WHERE ativo = true")->fetchColumn();
    
    // Métricas de compras e receitas
    $stats['categorias'] = $pdo->query("SELECT COUNT(*) FROM lc_categorias WHERE ativo = true")->fetchColumn();
    $stats['insumos'] = $pdo->query("SELECT COUNT(*) FROM lc_insumos WHERE ativo = true")->fetchColumn();
    $stats['receitas'] = $pdo->query("SELECT COUNT(*) FROM lc_receitas WHERE ativo = true")->fetchColumn();
    $stats['unidades'] = $pdo->query("SELECT COUNT(*) FROM lc_unidades WHERE ativo = true")->fetchColumn();
    
    // Métricas de atividade recente
    $stats['receitas_recentes'] = $pdo->query("
        SELECT COUNT(*) FROM lc_receitas 
        WHERE ativo = true AND created_at >= NOW() - INTERVAL '7 days'
    ")->fetchColumn();
    
    // Custo médio das receitas
    $custo_medio = $pdo->query("
        SELECT AVG(custo_total) FROM lc_receitas 
        WHERE ativo = true AND custo_total > 0
    ")->fetchColumn();
    $stats['custo_medio'] = $custo_medio ? number_format($custo_medio, 2, ',', '.') : '0,00';
    
    // Métricas específicas por módulo (com verificação de permissão)
    if (lc_can_access_module('compras')) {
        $stats['listas_ativas'] = $pdo->query("SELECT COUNT(*) FROM lc_listas WHERE status = 'ativa'")->fetchColumn();
        $stats['insumos_ativos'] = $pdo->query("SELECT COUNT(*) FROM lc_insumos WHERE ativo = true")->fetchColumn();
    }
    
    if (lc_can_access_module('estoque')) {
        $stats['contagens_abertas'] = $pdo->query("SELECT COUNT(*) FROM estoque_contagens WHERE status = 'aberta'")->fetchColumn();
    }
    
    if (lc_can_access_module('pagamentos')) {
        $stats['solicitacoes_pendentes'] = $pdo->query("SELECT COUNT(*) FROM lc_solicitacoes_pagamento WHERE status = 'aguardando'")->fetchColumn();
    }
    
    if (lc_can_access_module('comercial')) {
        $stats['degustacoes_ativas'] = $pdo->query("SELECT COUNT(*) FROM comercial_degustacoes WHERE status = 'publicado'")->fetchColumn();
    }
    
    // Métricas de estoque
    if (lc_can_access_estoque()) {
        $stats['contagens_abertas'] = $pdo->query("
            SELECT COUNT(*) FROM estoque_contagens 
            WHERE status = 'aberta'
        ")->fetchColumn();
    }
    
    // Métricas de RH
    if (lc_can_access_rh()) {
        $stats['holerites_mes'] = $pdo->query("
            SELECT COUNT(*) FROM rh_holerites 
            WHERE mes_competencia = DATE_FORMAT(NOW(), '%Y-%m')
        ")->fetchColumn();
    }
    
    // Métricas de Contabilidade
    if (lc_can_access_contabilidade()) {
        $stats['documentos_pendentes'] = $pdo->query("
            SELECT COUNT(*) FROM contab_documentos 
            WHERE status = 'pendente'
        ")->fetchColumn();
    }
    
} catch (Exception $e) {
    // Se der erro, usar valores padrão
    $stats = [
        'categorias' => 0,
        'insumos' => 0,
        'receitas' => 0,
        'unidades' => 0,
        'receitas_recentes' => 0,
        'custo_medio' => '0,00',
        'usuarios' => 0,
        'fornecedores' => 0,
        'solicitacoes_pendentes' => 0,
        'contagens_abertas' => 0,
        'holerites_mes' => 0,
        'documentos_pendentes' => 0
    ];
}

// Obter agenda do dia
$agenda_dia = [];
$agenda = new AgendaHelper();
if ($agenda->canAccessAgenda($usuario_id)) {
    $agenda_dia = $agenda->obterAgendaDia($usuario_id, 24);
}
?>

<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Dashboard - GRUPO Smile EVENTOS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="estilo.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* Dashboard Moderna */
.dashboard-container {
  max-width: 1400px;
  margin: 0 auto;
  padding: 2rem;
}

.dashboard-header {
  margin-bottom: 2rem;
}

.dashboard-title {
  font-size: 2.5rem;
  font-weight: 800;
  color: #1e3a8a;
  margin: 0 0 0.5rem 0;
  background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}

.dashboard-subtitle {
  font-size: 1.1rem;
  color: #64748b;
  margin: 0;
}

/* Agenda do Dia Section */
.agenda-section {
  margin-bottom: 3rem;
}

.agenda-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
}

.agenda-title {
  font-size: 1.5rem;
  font-weight: 700;
  color: #1e3a8a;
  margin: 0;
}

.agenda-link {
  color: #3b82f6;
  text-decoration: none;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  transition: color 0.3s ease;
}

.agenda-link:hover {
  color: #1d4ed8;
}

.agenda-card {
  background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  padding: 1.5rem;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
  position: relative;
  overflow: hidden;
}

.agenda-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
  background: linear-gradient(90deg, #10b981, #059669);
}

.agenda-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.agenda-item {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1rem 0;
  border-bottom: 1px solid #f1f5f9;
}

.agenda-item:last-child {
  border-bottom: none;
}

.agenda-time {
  font-size: 0.875rem;
  font-weight: 600;
  color: #64748b;
  min-width: 80px;
}

.agenda-color {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  flex-shrink: 0;
}

.agenda-content {
  flex: 1;
}

.agenda-title-item {
  font-weight: 600;
  color: #1e293b;
  margin: 0 0 0.25rem 0;
}

.agenda-meta {
  font-size: 0.875rem;
  color: #64748b;
  margin: 0;
}

.agenda-empty {
  text-align: center;
  padding: 2rem;
  color: #64748b;
}

.agenda-empty-icon {
  font-size: 3rem;
  margin-bottom: 1rem;
  opacity: 0.5;
}

/* KPIs Section */
.kpis-section {
  margin-bottom: 3rem;
}

.kpis-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 1.5rem;
  margin-bottom: 2rem;
}

.kpi-card {
  background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  padding: 1.5rem;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
  transition: all 0.3s ease;
  position: relative;
  overflow: hidden;
}

.kpi-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
  background: linear-gradient(90deg, #3b82f6, #8b5cf6);
}

.kpi-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}

.kpi-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1rem;
}

.kpi-icon {
  font-size: 2rem;
  opacity: 0.8;
}

.kpi-value {
  font-size: 2.5rem;
  font-weight: 800;
  color: #1e293b;
  margin: 0;
  line-height: 1;
}

.kpi-label {
  font-size: 0.875rem;
  color: #64748b;
  margin: 0.5rem 0 0 0;
  font-weight: 500;
}

.kpi-change {
  font-size: 0.75rem;
  padding: 0.25rem 0.5rem;
  border-radius: 9999px;
  font-weight: 600;
}

.kpi-change.positive {
  background: #dcfce7;
  color: #166534;
}

.kpi-change.negative {
  background: #fef2f2;
  color: #dc2626;
}

/* Quick Actions */
.quick-actions {
  margin-bottom: 3rem;
}

.quick-actions-title {
  font-size: 1.25rem;
  font-weight: 700;
  color: #1e3a8a;
  margin: 0 0 1rem 0;
}

.quick-actions-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1rem;
}

.quick-action {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 1rem;
  background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  text-decoration: none;
  color: #374151;
  font-weight: 600;
  transition: all 0.3s ease;
  box-shadow: 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}

.quick-action:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 15px -3px rgba(0, 0, 0, 0.1);
  color: #1e3a8a;
}

.quick-action-icon {
  font-size: 1.5rem;
  opacity: 0.8;
}

/* Responsive */
@media (max-width: 768px) {
  .dashboard-container {
    padding: 1rem;
  }
  
  .dashboard-title {
    font-size: 2rem;
  }
  
  .kpis-grid {
    grid-template-columns: 1fr;
  }
  
  .quick-actions-grid {
    grid-template-columns: 1fr;
  }
  
  .agenda-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 1rem;
  }
}
</style>
</head>
<body>
  <?php include __DIR__ . '/sidebar.php'; ?>

  <div class="dashboard-container">
    <div class="dashboard-header">
      <h1 class="dashboard-title">Bem-vindo, <?= h($nomeUser) ?>!</h1>
      <p class="dashboard-subtitle">Aqui está um resumo do que está acontecendo hoje</p>
    </div>

    <!-- Agenda do Dia -->
    <div class="agenda-section">
      <div class="agenda-header">
        <h2 class="agenda-title">📅 Agenda do Dia</h2>
        <?php if ($agenda->canAccessAgenda($usuario_id)): ?>
          <a href="agenda.php" class="agenda-link">
            Ver agenda completa →
          </a>
        <?php endif; ?>
      </div>
      
      <div class="agenda-card">
        <?php if (empty($agenda_dia)): ?>
          <div class="agenda-empty">
            <div class="agenda-empty-icon">📅</div>
            <h3>Nenhum evento agendado</h3>
            <p>Você não tem eventos agendados para hoje</p>
            <?php if ($agenda->canCreateEvents($usuario_id)): ?>
              <a href="agenda.php" class="agenda-link" style="margin-top: 1rem; display: inline-flex;">
                ➕ Agendar evento
              </a>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <ul class="agenda-list">
            <?php foreach ($agenda_dia as $evento): ?>
              <li class="agenda-item">
                <div class="agenda-time">
                  <?= date('H:i', strtotime($evento['inicio'])) ?>
                </div>
                <div class="agenda-color" style="background-color: <?= htmlspecialchars($evento['cor_evento']) ?>"></div>
                <div class="agenda-content">
                  <h4 class="agenda-title-item"><?= htmlspecialchars($evento['titulo']) ?></h4>
                  <p class="agenda-meta">
                    <?= date('H:i', strtotime($evento['fim'])) ?> • 
                    <?= htmlspecialchars($evento['espaco_nome'] ?: 'Sem espaço') ?>
                    <?php if ($evento['tipo'] === 'bloqueio'): ?>
                      • 🔒 Bloqueio
                    <?php elseif ($evento['tipo'] === 'visita'): ?>
                      • 👤 Visita
                    <?php endif; ?>
                  </p>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <!-- KPIs Section -->
    <div class="kpis-section">
      <h2 class="agenda-title">📊 Indicadores Principais</h2>
      <div class="kpis-grid">
        <div class="kpi-card">
          <div class="kpi-header">
            <div class="kpi-icon">📦</div>
          </div>
          <div class="kpi-value"><?= h($stats['insumos']) ?></div>
          <div class="kpi-label">Insumos Ativos</div>
        </div>
        
        <div class="kpi-card">
          <div class="kpi-header">
            <div class="kpi-icon">🍽️</div>
          </div>
          <div class="kpi-value"><?= h($stats['receitas']) ?></div>
          <div class="kpi-label">Receitas Ativas</div>
        </div>
        
        <div class="kpi-card">
          <div class="kpi-header">
            <div class="kpi-icon">👥</div>
          </div>
          <div class="kpi-value"><?= h($stats['usuarios']) ?></div>
          <div class="kpi-label">Usuários Ativos</div>
        </div>
        
        <div class="kpi-card">
          <div class="kpi-header">
            <div class="kpi-icon">🏢</div>
          </div>
          <div class="kpi-value"><?= h($stats['fornecedores']) ?></div>
          <div class="kpi-label">Fornecedores</div>
        </div>
        
        <?php if (lc_can_access_module('pagamentos') && $stats['solicitacoes_pendentes'] > 0): ?>
        <div class="kpi-card">
          <div class="kpi-header">
            <div class="kpi-icon">💰</div>
          </div>
          <div class="kpi-value"><?= h($stats['solicitacoes_pendentes']) ?></div>
          <div class="kpi-label">Solicitações Pendentes</div>
        </div>
        <?php endif; ?>
        
        <?php if (lc_can_access_estoque() && $stats['contagens_abertas'] > 0): ?>
        <div class="kpi-card">
          <div class="kpi-header">
            <div class="kpi-icon">📊</div>
          </div>
          <div class="kpi-value"><?= h($stats['contagens_abertas']) ?></div>
          <div class="kpi-label">Contagens Abertas</div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
      <h2 class="quick-actions-title">🚀 Ações Rápidas</h2>
      <div class="quick-actions-grid">
        <a href="index.php?page=lista" class="quick-action">
          <span class="quick-action-icon">🛒</span>
          <span>Gerar Lista de Compras</span>
        </a>
        
        <?php if ($agenda->canAccessAgenda($usuario_id)): ?>
        <a href="index.php?page=agenda" class="quick-action">
          <span class="quick-action-icon">📅</span>
          <span>Agenda Interna</span>
        </a>
        <?php endif; ?>
        
        <a href="index.php?page=lc_index" class="quick-action">
          <span class="quick-action-icon">📋</span>
          <span>Gestão de Compras</span>
        </a>
        
        <?php if (lc_can_access_estoque()): ?>
        <a href="index.php?page=estoque_logistico" class="quick-action">
          <span class="quick-action-icon">📦</span>
          <span>Controle de Estoque</span>
        </a>
        <?php endif; ?>
        
        <?php if (lc_can_access_module('pagamentos')): ?>
        <a href="index.php?page=pagamentos" class="quick-action">
          <span class="quick-action-icon">💰</span>
          <span>Solicitar Pagamento</span>
        </a>
        <?php endif; ?>
        
        <a href="index.php?page=usuarios" class="quick-action">
          <span class="quick-action-icon">👥</span>
          <span>Gerenciar Usuários</span>
        </a>
      </div>
    </div>
  </div>
<?php endSidebar(); ?>
</body>
</html>
