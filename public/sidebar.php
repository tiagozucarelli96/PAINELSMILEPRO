<?php
// sidebar.php — enxuto e sem CSS inline (usa apenas o seu estilo.css)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
?>
<aside class="sidebar">
  <img src="logo.png" alt="Logo Grupo Smile" style="max-width: 150px; max-height: 72px; width: auto; height: auto;">

  <nav>
    <a href="index.php?page=dashboard">🏠 Dashboard</a>

    <?php if (!empty($_SESSION['perm_tarefas'])): ?>
      <a href="index.php?page=tarefas">📋 Tarefas</a>
    <?php endif; ?>

    <?php if (!empty($_SESSION['perm_lista'])): ?>
      <a href="index.php?page=lista">🛒 Lista de Compras</a>
    <?php endif; ?>

    <?php if (!empty($_SESSION['perm_demandas'])): ?>
      <a href="index.php?page=pagamentos">🧾 Solicitar Pagamento</a>
    <?php endif; ?>

    <?php if (!empty($_SESSION['perm_portao'])): ?>
      <a href="index.php?page=portao">🚪 Portão</a>
    <?php endif; ?>

    <a href="logout.php">↩️ Sair</a>
  </nav>
</aside>
