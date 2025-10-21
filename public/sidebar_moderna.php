<?php
// Sidebar moderna para o sistema
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
    <a href="dashboard.php" class="nav-item <?= $current_page === 'dashboard' ? 'active' : '' ?>">
      <span class="nav-item-icon">📊</span>
      <span class="nav-item-text">Dashboard</span>
    </a>

    <a href="tarefas.php" class="nav-item <?= $current_page === 'tarefas' ? 'active' : '' ?>">
      <span class="nav-item-icon">📋</span>
      <span class="nav-item-text">Tarefas</span>
    </a>

    <a href="lc_index.php" class="nav-item <?= $current_page === 'lc_index' ? 'active' : '' ?>">
      <span class="nav-item-icon">🛒</span>
      <span class="nav-item-text">Lista de Compras</span>
    </a>

    <a href="pagamentos.php" class="nav-item <?= $current_page === 'pagamentos' ? 'active' : '' ?>">
      <span class="nav-item-icon">💳</span>
      <span class="nav-item-text">Solicitar Pagamento</span>
    </a>

    <a href="portao.php" class="nav-item <?= $current_page === 'portao' ? 'active' : '' ?>">
      <span class="nav-item-icon">🚪</span>
      <span class="nav-item-text">Portão</span>
    </a>

    <a href="configuracoes.php" class="nav-item <?= $current_page === 'configuracoes' ? 'active' : '' ?>">
      <span class="nav-item-icon">⚙️</span>
      <span class="nav-item-text">Configurações</span>
    </a>

    <a href="usuarios.php" class="nav-item <?= $current_page === 'usuarios' ? 'active' : '' ?>">
      <span class="nav-item-icon">👥</span>
      <span class="nav-item-text">Usuários</span>
    </a>

    <a href="historico.php" class="nav-item <?= $current_page === 'historico' ? 'active' : '' ?>">
      <span class="nav-item-icon">📈</span>
      <span class="nav-item-text">Histórico</span>
    </a>
  </nav>

  <!-- Footer da Sidebar -->
  <div class="sidebar-footer">
    <div class="user-info">
      <div class="user-avatar">
        <?= strtoupper(substr($_SESSION['usuario'] ?? 'U', 0, 1)) ?>
      </div>
      <div>
        <div class="font-semibold"><?= htmlspecialchars($_SESSION['usuario'] ?? 'Usuário') ?></div>
        <div class="text-xs opacity-75">Administrador</div>
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
