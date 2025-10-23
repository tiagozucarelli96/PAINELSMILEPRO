<?php
// sidebar.php — Sidebar moderna para o sistema
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lc_permissions_enhanced.php';
$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>

<!-- Sidebar Overlay para Mobile -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- Botão Mobile Menu -->
<button class="mobile-menu-btn" onclick="toggleSidebar()">
  <span>☰</span>
</button>

<!-- Sidebar Moderna -->
<aside class="sidebar" id="sidebar">
  <!-- Header da Sidebar -->
  <div class="sidebar-header">
    <img src="logo.png" alt="GRUPO Smile EVENTOS" class="sidebar-logo">
  </div>

  <!-- Navegação -->
  <nav class="sidebar-nav">
    <a href="index.php?page=dashboard" class="nav-item <?= $current_page === 'index' && ($_GET['page'] ?? '') === 'dashboard' ? 'active' : '' ?>">
      <span class="nav-item-icon">🏠</span>
      <span class="nav-item-text">Dashboard</span>
    </a>

    <?php if (!empty($_SESSION['perm_tarefas'])): ?>
      <a href="index.php?page=tarefas" class="nav-item <?= $current_page === 'index' && ($_GET['page'] ?? '') === 'tarefas' ? 'active' : '' ?>">
        <span class="nav-item-icon">📋</span>
        <span class="nav-item-text">Tarefas</span>
      </a>
    <?php endif; ?>

    <?php if (!empty($_SESSION['perm_lista'])): ?>
      <a href="index.php?page=lista" class="nav-item <?= $current_page === 'index' && ($_GET['page'] ?? '') === 'lista' ? 'active' : '' ?>">
        <span class="nav-item-icon">🛒</span>
        <span class="nav-item-text">Lista de Compras</span>
      </a>
    <?php endif; ?>

    <?php if (!empty($_SESSION['perm_demandas'])): ?>
      <a href="index.php?page=pagamentos" class="nav-item <?= $current_page === 'index' && ($_GET['page'] ?? '') === 'pagamentos' ? 'active' : '' ?>">
        <span class="nav-item-icon">💳</span>
        <span class="nav-item-text">Solicitar Pagamento</span>
      </a>
    <?php endif; ?>

    <?php if (!empty($_SESSION['perm_portao'])): ?>
      <a href="index.php?page=portao" class="nav-item <?= $current_page === 'index' && ($_GET['page'] ?? '') === 'portao' ? 'active' : '' ?>">
        <span class="nav-item-icon">🚪</span>
        <span class="nav-item-text">Portão</span>
      </a>
    <?php endif; ?>

    <!-- Novos Módulos -->
    <?php if (lc_can_access_estoque()): ?>
      <div class="nav-section">
        <div class="nav-section-title">📦 Gestão de Estoque</div>
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
      </div>
    <?php endif; ?>

    <?php if (lc_can_access_rh()): ?>
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
      </div>
    <?php endif; ?>

    <?php if (lc_can_access_contabilidade()): ?>
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

    <!-- Comercial -->
    <?php if (lc_can_access_comercial()): ?>
      <div class="nav-section">
        <div class="nav-section-title">💼 Comercial</div>
        <a href="comercial_degustacoes.php" class="nav-item <?= $current_page === 'comercial_degustacoes' ? 'active' : '' ?>">
          <span class="nav-item-icon">🍽️</span>
          <span class="nav-item-text">Degustações</span>
        </a>
        <a href="comercial_degustacao_editar.php" class="nav-item <?= $current_page === 'comercial_degustacao_editar' ? 'active' : '' ?>">
          <span class="nav-item-icon">➕</span>
          <span class="nav-item-text">Nova Degustação</span>
        </a>
        <a href="comercial_degust_inscricoes.php" class="nav-item <?= $current_page === 'comercial_degust_inscricoes' ? 'active' : '' ?>">
          <span class="nav-item-icon">📋</span>
          <span class="nav-item-text">Inscritos (todas)</span>
        </a>
        <a href="dados_contrato.php" class="nav-item <?= $current_page === 'dados_contrato' ? 'active' : '' ?>">
          <span class="nav-item-icon">📄</span>
          <span class="nav-item-text">Dados para Contrato</span>
        </a>
        <?php if (lc_can_view_conversao()): ?>
        <a href="comercial_clientes.php" class="nav-item <?= $current_page === 'comercial_clientes' ? 'active' : '' ?>">
          <span class="nav-item-icon">📊</span>
          <span class="nav-item-text">Conversão</span>
        </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Administração -->
    <?php if (lc_is_admin()): ?>
      <div class="nav-section">
        <div class="nav-section-title">🔧 Administração</div>
        <a href="index.php?page=usuarios" class="nav-item <?= $current_page === 'index' && ($_GET['page'] ?? '') === 'usuarios' ? 'active' : '' ?>">
          <span class="nav-item-icon">👥</span>
          <span class="nav-item-text">Usuários</span>
        </a>
        <a href="configuracoes.php" class="nav-item <?= $current_page === 'configuracoes' ? 'active' : '' ?>">
          <span class="nav-item-icon">⚙️</span>
          <span class="nav-item-text">Configurações</span>
        </a>
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
        <div class="text-xs opacity-75"><?= htmlspecialchars($_SESSION['cargo'] ?? 'Usuário') ?></div>
      </div>
    </div>
    
    <a href="logout.php" class="nav-item">
      <span class="nav-item-icon">🚪</span>
      <span class="nav-item-text">Sair</span>
    </a>
  </div>
</aside>

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
