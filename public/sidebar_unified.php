<?php
// sidebar_unified.php — Sidebar unificada com todos os módulos
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lc_permissions_unified.php';

$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>

<!-- Sidebar Overlay para Mobile -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- Botão Mobile Menu -->
<button class="mobile-menu-btn" onclick="toggleSidebar()">
  <span>☰</span>
</button>

<!-- Sidebar Unificada -->
<aside class="sidebar" id="sidebar">
  <!-- Header da Sidebar -->
  <div class="sidebar-header">
    <img src="logo.png" alt="GRUPO Smile EVENTOS" class="sidebar-logo">
  </div>

  <!-- Navegação -->
  <nav class="sidebar-nav">
    <!-- Dashboard -->
    <a href="dashboard.php" class="nav-item <?= $current_page === 'dashboard' ? 'active' : '' ?>">
      <span class="nav-item-icon">📊</span>
      <span class="nav-item-text">Dashboard</span>
    </a>

    <!-- Gestão de Compras -->
    <?php if (lc_can_access_module('estoque') || lc_can_access_module('demandas')): ?>
      <div class="nav-section">
        <div class="nav-section-title">🛒 Gestão de Compras</div>
        
        <?php if (lc_can_access_module('demandas')): ?>
          <a href="lc_index.php" class="nav-item <?= $current_page === 'lc_index' ? 'active' : '' ?>">
            <span class="nav-item-icon">📋</span>
            <span class="nav-item-text">Lista de Compras</span>
          </a>
        <?php endif; ?>
        
        <?php if (lc_can_access_module('estoque')): ?>
          <a href="estoque_contagens.php" class="nav-item <?= $current_page === 'estoque_contagens' ? 'active' : '' ?>">
            <span class="nav-item-icon">📦</span>
            <span class="nav-item-text">Controle de Estoque</span>
          </a>
          
          <a href="estoque_kardex.php" class="nav-item <?= $current_page === 'estoque_kardex' ? 'active' : '' ?>">
            <span class="nav-item-icon">📈</span>
            <span class="nav-item-text">Kardex</span>
          </a>
          
          <a href="estoque_alertas.php" class="nav-item <?= $current_page === 'estoque_alertas' ? 'active' : '' ?>">
            <span class="nav-item-icon">🚨</span>
            <span class="nav-item-text">Alertas de Ruptura</span>
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Recursos Humanos -->
    <?php if (lc_can_access_module('rh')): ?>
      <div class="nav-section">
        <div class="nav-section-title">👥 Recursos Humanos</div>
        
        <a href="rh_dashboard.php" class="nav-item <?= $current_page === 'rh_dashboard' ? 'active' : '' ?>">
          <span class="nav-item-icon">📊</span>
          <span class="nav-item-text">Dashboard RH</span>
        </a>
        
        <a href="rh_holerites.php" class="nav-item <?= $current_page === 'rh_holerites' ? 'active' : '' ?>">
          <span class="nav-item-icon">💰</span>
          <span class="nav-item-text">Holerites</span>
        </a>
        
        <a href="rh_colaboradores.php" class="nav-item <?= $current_page === 'rh_colaboradores' ? 'active' : '' ?>">
          <span class="nav-item-icon">👤</span>
          <span class="nav-item-text">Colaboradores</span>
        </a>
      </div>
    <?php endif; ?>

    <!-- Contabilidade -->
    <?php if (lc_can_access_module('contabilidade')): ?>
      <div class="nav-section">
        <div class="nav-section-title">💰 Contabilidade</div>
        
        <a href="contab_dashboard.php" class="nav-item <?= $current_page === 'contab_dashboard' ? 'active' : '' ?>">
          <span class="nav-item-icon">📊</span>
          <span class="nav-item-text">Dashboard Contábil</span>
        </a>
        
        <a href="contab_documentos.php" class="nav-item <?= $current_page === 'contab_documentos' ? 'active' : '' ?>">
          <span class="nav-item-icon">📄</span>
          <span class="nav-item-text">Documentos</span>
        </a>
        
        <a href="contab_parcelas.php" class="nav-item <?= $current_page === 'contab_parcelas' ? 'active' : '' ?>">
          <span class="nav-item-icon">📅</span>
          <span class="nav-item-text">Parcelas</span>
        </a>
      </div>
    <?php endif; ?>

    <!-- Sistema de Pagamentos -->
    <?php if (lc_can_access_module('pagamentos')): ?>
      <div class="nav-section">
        <div class="nav-section-title">💳 Pagamentos</div>
        
        <a href="pagamentos_solicitar.php" class="nav-item <?= $current_page === 'pagamentos_solicitar' ? 'active' : '' ?>">
          <span class="nav-item-icon">➕</span>
          <span class="nav-item-text">Solicitar Pagamento</span>
        </a>
        
        <a href="pagamentos_minhas.php" class="nav-item <?= $current_page === 'pagamentos_minhas' ? 'active' : '' ?>">
          <span class="nav-item-icon">📋</span>
          <span class="nav-item-text">Minhas Solicitações</span>
        </a>
        
        <?php if (lc_is_financeiro()): ?>
          <a href="pagamentos_painel.php" class="nav-item <?= $current_page === 'pagamentos_painel' ? 'active' : '' ?>">
            <span class="nav-item-icon">⚙️</span>
            <span class="nav-item-text">Painel Financeiro</span>
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Fornecedores -->
    <div class="nav-section">
      <div class="nav-section-title">🏢 Fornecedores</div>
      
      <a href="fornecedores.php" class="nav-item <?= $current_page === 'fornecedores' ? 'active' : '' ?>">
        <span class="nav-item-icon">🏢</span>
        <span class="nav-item-text">Cadastro de Fornecedores</span>
      </a>
    </div>

    <!-- Operacional -->
    <div class="nav-section">
      <div class="nav-section-title">⚙️ Operacional</div>
      
      <?php if (lc_can_access_module('tarefas')): ?>
        <a href="tarefas.php" class="nav-item <?= $current_page === 'tarefas' ? 'active' : '' ?>">
          <span class="nav-item-icon">📋</span>
          <span class="nav-item-text">Tarefas</span>
        </a>
      <?php endif; ?>
      
      <?php if (lc_can_access_module('portao')): ?>
        <a href="portao.php" class="nav-item <?= $current_page === 'portao' ? 'active' : '' ?>">
          <span class="nav-item-icon">🚪</span>
          <span class="nav-item-text">Portão</span>
        </a>
      <?php endif; ?>
      
      <a href="historico.php" class="nav-item <?= $current_page === 'historico' ? 'active' : '' ?>">
        <span class="nav-item-icon">📈</span>
        <span class="nav-item-text">Histórico</span>
      </a>
    </div>

    <!-- Administração -->
    <?php if (lc_can_access_module('configuracoes') || lc_can_access_module('usuarios')): ?>
      <div class="nav-section">
        <div class="nav-section-title">🔧 Administração</div>
        
        <?php if (lc_can_access_module('usuarios')): ?>
          <a href="usuarios.php" class="nav-item <?= $current_page === 'usuarios' ? 'active' : '' ?>">
            <span class="nav-item-icon">👥</span>
            <span class="nav-item-text">Usuários</span>
          </a>
        <?php endif; ?>
        
        <?php if (lc_can_access_module('configuracoes')): ?>
          <a href="configuracoes.php" class="nav-item <?= $current_page === 'configuracoes' ? 'active' : '' ?>">
            <span class="nav-item-icon">⚙️</span>
            <span class="nav-item-text">Configurações</span>
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </nav>

  <!-- Footer da Sidebar -->
  <div class="sidebar-footer">
    <div class="user-info">
      <div class="user-avatar">
        <?= strtoupper(substr($_SESSION['usuario'] ?? $_SESSION['nome'] ?? 'U', 0, 1)) ?>
      </div>
      <div>
        <div class="font-semibold"><?= htmlspecialchars($_SESSION['usuario'] ?? $_SESSION['nome'] ?? 'Usuário') ?></div>
        <div class="text-xs opacity-75"><?= htmlspecialchars($_SESSION['cargo'] ?? lc_get_user_perfil()) ?></div>
      </div>
    </div>
    
    <a href="logout.php" class="nav-item">
      <span class="nav-item-icon">🚪</span>
      <span class="nav-item-text">Sair</span>
    </a>
  </div>
</aside>

<style>
/* Estilos para seções da sidebar */
.nav-section {
  margin-bottom: 20px;
}

.nav-section-title {
  font-size: 12px;
  font-weight: 600;
  color: #6b7280;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-bottom: 8px;
  padding: 0 16px;
}

.nav-section .nav-item {
  margin-left: 8px;
  padding-left: 24px;
}
</style>

<script>
// Função para alternar sidebar em mobile
function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  
  sidebar.classList.toggle('open');
  overlay.classList.toggle('open');
}

// Fechar sidebar ao clicar em um link (mobile)
document.querySelectorAll('.nav-item').forEach(link => {
  link.addEventListener('click', () => {
    if (window.innerWidth <= 1024) {
      toggleSidebar();
    }
  });
});

// Fechar sidebar ao redimensionar para desktop
window.addEventListener('resize', () => {
  if (window.innerWidth > 1024) {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('open');
  }
});
</script>
