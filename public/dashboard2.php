<?php
// public/dashboard2.php — Dashboard moderna com KPIs e métricas
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
if (is_file(__DIR__ . '/permissoes_boot.php')) { require_once __DIR__ . '/permissoes_boot.php'; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$nomeUser = isset($_SESSION['nome']) ? $_SESSION['nome'] : 'Usuário';

// Buscar métricas do banco de dados
$stats = [];
try {
    // Total de categorias ativas
    $stats['categorias'] = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_categorias WHERE ativo = true")->fetchColumn();
    
    // Total de insumos ativos
    $stats['insumos'] = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_insumos WHERE ativo = true")->fetchColumn();
    
    // Total de receitas ativas
    $stats['receitas'] = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_receitas WHERE ativo = true")->fetchColumn();
    
    // Total de unidades ativas
    $stats['unidades'] = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_unidades WHERE ativo = true")->fetchColumn();
    
    // Receitas recentes (últimos 7 dias)
    $stats['receitas_recentes'] = $pdo->query("
        SELECT COUNT(*) FROM smilee12_painel_smile.lc_receitas 
        WHERE ativo = true AND created_at >= NOW() - INTERVAL '7 days'
    ")->fetchColumn();
    
    // Custo médio das receitas
    $custo_medio = $pdo->query("
        SELECT AVG(custo_total) FROM smilee12_painel_smile.lc_receitas 
        WHERE ativo = true AND custo_total > 0
    ")->fetchColumn();
    $stats['custo_medio'] = $custo_medio ? number_format($custo_medio, 2, ',', '.') : '0,00';
    
} catch (Exception $e) {
    // Se der erro, usar valores padrão
    $stats = [
        'categorias' => 0,
        'insumos' => 0,
        'receitas' => 0,
        'unidades' => 0,
        'receitas_recentes' => 0,
        'custo_medio' => '0,00'
    ];
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

.section-title {
  font-size: 1.5rem;
  font-weight: 700;
  color: #1e293b;
  margin: 0 0 1.5rem 0;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.section-title::before {
  content: '';
  width: 4px;
  height: 24px;
  background: linear-gradient(135deg, #3b82f6, #8b5cf6);
  border-radius: 2px;
}

.actions-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 1.5rem;
}

.action-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 1.5rem;
  text-decoration: none;
  color: inherit;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  gap: 1rem;
  position: relative;
  overflow: hidden;
}

.action-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: linear-gradient(135deg, rgba(59, 130, 246, 0.05) 0%, rgba(139, 92, 246, 0.05) 100%);
  opacity: 0;
  transition: opacity 0.3s ease;
}

.action-card:hover::before {
  opacity: 1;
}

.action-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
  border-color: #3b82f6;
}

.action-icon {
  font-size: 2.5rem;
  line-height: 1;
  filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.1));
  position: relative;
  z-index: 1;
}

.action-content {
  flex: 1;
  position: relative;
  z-index: 1;
}

.action-title {
  font-size: 1.125rem;
  font-weight: 600;
  color: #1e293b;
  margin: 0 0 0.25rem 0;
}

.action-description {
  font-size: 0.875rem;
  color: #64748b;
  margin: 0;
  line-height: 1.4;
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
  
  .actions-grid {
    grid-template-columns: 1fr;
  }
}

/* Loading Animation */
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}

.loading {
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}
</style>
</head>
<body class="panel">

<?php if (is_file(__DIR__ . '/sidebar.php')) { include __DIR__ . '/sidebar.php'; } ?>

<div class="main-content">
  <div class="dashboard-container">
    <!-- Header -->
    <div class="dashboard-header">
      <h1 class="dashboard-title">Bem-vindo, <?php echo h($nomeUser); ?>!</h1>
      <p class="dashboard-subtitle">Gerencie seu negócio de eventos com eficiência e controle total</p>
    </div>

    <!-- KPIs Section -->
    <div class="kpis-section">
      <h2 class="section-title">📊 Indicadores Principais</h2>
      <div class="kpis-grid">
        <div class="kpi-card">
          <div class="kpi-header">
            <div class="kpi-icon">📂</div>
            <div class="kpi-change positive">+<?= $stats['receitas_recentes'] ?> esta semana</div>
          </div>
          <div class="kpi-value"><?= $stats['categorias'] ?></div>
          <div class="kpi-label">Categorias Ativas</div>
        </div>

        <div class="kpi-card">
          <div class="kpi-header">
            <div class="kpi-icon">🥘</div>
            <div class="kpi-change positive">Disponíveis</div>
          </div>
          <div class="kpi-value"><?= $stats['insumos'] ?></div>
          <div class="kpi-label">Insumos Cadastrados</div>
        </div>

        <div class="kpi-card">
          <div class="kpi-header">
            <div class="kpi-icon">🍳</div>
            <div class="kpi-change positive">+<?= $stats['receitas_recentes'] ?> recentes</div>
          </div>
          <div class="kpi-value"><?= $stats['receitas'] ?></div>
          <div class="kpi-label">Receitas Criadas</div>
        </div>

        <div class="kpi-card">
          <div class="kpi-header">
            <div class="kpi-icon">📏</div>
            <div class="kpi-change positive">Configuradas</div>
          </div>
          <div class="kpi-value"><?= $stats['unidades'] ?></div>
          <div class="kpi-label">Unidades de Medida</div>
        </div>

        <div class="kpi-card">
          <div class="kpi-header">
            <div class="kpi-icon">💰</div>
            <div class="kpi-change positive">Média</div>
          </div>
          <div class="kpi-value">R$ <?= $stats['custo_medio'] ?></div>
          <div class="kpi-label">Custo Médio por Receita</div>
        </div>

        <div class="kpi-card">
          <div class="kpi-header">
            <div class="kpi-icon">⚡</div>
            <div class="kpi-change positive">Sistema</div>
          </div>
          <div class="kpi-value">100%</div>
          <div class="kpi-label">Sistema Operacional</div>
        </div>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
      <h2 class="section-title">🚀 Ações Rápidas</h2>
      <div class="actions-grid">
        <?php if (!empty($_SESSION['perm_tarefas'])): ?>
          <a class="action-card" href="index.php?page=tarefas">
            <div class="action-icon">📋</div>
            <div class="action-content">
              <h3 class="action-title">Tarefas</h3>
              <p class="action-description">Organize e acompanhe suas pendências e atividades do dia a dia.</p>
            </div>
          </a>
        <?php endif; ?>

        <?php if (!empty($_SESSION['perm_lista'])): ?>
          <a class="action-card" href="lc_index.php">
            <div class="action-icon">🛒</div>
            <div class="action-content">
              <h3 class="action-title">Lista de Compras</h3>
              <p class="action-description">Gere listas inteligentes por evento com base nas suas receitas.</p>
            </div>
          </a>
        <?php endif; ?>

        <?php if (!empty($_SESSION['perm_demandas'])): ?>
          <a class="action-card" href="index.php?page=pagamentos">
            <div class="action-icon">🧾</div>
            <div class="action-content">
              <h3 class="action-title">Solicitar Pagamento</h3>
              <p class="action-description">Envie solicitações de pagamento para o setor financeiro.</p>
            </div>
          </a>
        <?php endif; ?>

        <?php if (!empty($_SESSION['perm_pagamentos'])): ?>
          <a class="action-card" href="index.php?page=admin_pagamentos">
            <div class="action-icon">🏦</div>
            <div class="action-content">
              <h3 class="action-title">Gestão de Pagamentos</h3>
              <p class="action-description">Aprove, gerencie e exporte relatórios de pagamentos.</p>
            </div>
          </a>
        <?php endif; ?>

        <?php if (!empty($_SESSION['perm_usuarios'])): ?>
          <a class="action-card" href="index.php?page=usuarios">
            <div class="action-icon">👥</div>
            <div class="action-content">
              <h3 class="action-title">Usuários</h3>
              <p class="action-description">Gerencie acessos, permissões e perfis de usuários do sistema.</p>
            </div>
          </a>
        <?php endif; ?>

        <?php if (!empty($_SESSION['perm_portao'])): ?>
          <a class="action-card" href="index.php?page=portao">
            <div class="action-icon">🚪</div>
            <div class="action-content">
              <h3 class="action-title">Portão</h3>
              <p class="action-description">Abrir e registrar acionamentos do portão automaticamente.</p>
            </div>
          </a>
        <?php endif; ?>

        <?php if (!empty($_SESSION['perm_banco_smile'])): ?>
          <a class="action-card" href="index.php?page=banco_smile">
            <div class="action-icon">🏦</div>
            <div class="action-content">
              <h3 class="action-title">Banco Smile</h3>
              <p class="action-description">Consultas e operações internas do banco corporativo.</p>
            </div>
          </a>
        <?php endif; ?>

        <?php if (!empty($_SESSION['perm_banco_smile_admin'])): ?>
          <a class="action-card" href="index.php?page=banco_smile_admin">
            <div class="action-icon">💵</div>
            <div class="action-content">
              <h3 class="action-title">Administração Banco</h3>
              <p class="action-description">Configurações avançadas e auditoria do sistema bancário.</p>
            </div>
          </a>
        <?php endif; ?>

        <?php if (!empty($_SESSION['perm_notas_fiscais'])): ?>
          <a class="action-card" href="index.php?page=notas_fiscais">
            <div class="action-icon">📔</div>
            <div class="action-content">
              <h3 class="action-title">Notas Fiscais</h3>
              <p class="action-description">Emissão, acompanhamento e gestão de documentos fiscais.</p>
            </div>
          </a>
        <?php endif; ?>

        <?php if (!empty($_SESSION['perm_estoque_logistico'])): ?>
          <a class="action-card" href="index.php?page=estoque_logistico">
            <div class="action-icon">📦</div>
            <div class="action-content">
              <h3 class="action-title">Estoque Logístico</h3>
              <p class="action-description">Controle de entrada, saída e inventário de materiais.</p>
            </div>
          </a>
        <?php endif; ?>

        <?php if (!empty($_SESSION['perm_dados_contrato'])): ?>
          <a class="action-card" href="index.php?page=dados_contrato">
            <div class="action-icon">🧾</div>
            <div class="action-content">
              <h3 class="action-title">Dados para Contrato</h3>
              <p class="action-description">Informações e dados necessários para documentos contratuais.</p>
            </div>
          </a>
        <?php endif; ?>

        <?php if (!empty($_SESSION['perm_uso_fiorino'])): ?>
          <a class="action-card" href="index.php?page=uso_fiorino">
            <div class="action-icon">🚘</div>
            <div class="action-content">
              <h3 class="action-title">Uso de Fiorino</h3>
              <p class="action-description">Agendamento e controle de uso da frota de veículos.</p>
            </div>
          </a>
        <?php endif; ?>

        <!-- Configurações - sempre visível para admins -->
        <?php if (!empty($_SESSION['perm_usuarios']) || !empty($_SESSION['perm_pagamentos'])): ?>
          <a class="action-card" href="configuracoes.php">
            <div class="action-icon">⚙️</div>
            <div class="action-content">
              <h3 class="action-title">Configurações</h3>
              <p class="action-description">Gerencie categorias, insumos, receitas e configurações do sistema.</p>
            </div>
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
