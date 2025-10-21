<?php
// sidebar.php — Sidebar moderna para o sistema
if (session_status() === PHP_SESSION_NONE) { session_start(); }
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
