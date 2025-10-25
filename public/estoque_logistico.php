<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__.'/conexao.php';
require_once __DIR__.'/config.php';
require_once __DIR__ . '/sidebar_integration.php';
exigeLogin(); refreshPermissoes($pdo);
if (empty($_SESSION['perm_estoque_logistico'])) { echo '<div class="alert-error">Acesso negado.</div>'; exit; }

// Iniciar sidebar
includeSidebar();
setPageTitle('Estoque Logístico');
addBreadcrumb([
    ['title' => 'Dashboard', 'url' => 'index.php?page=dashboard'],
    ['title' => 'Estoque'],
    ['title' => 'Logístico']
]);
?>
<div class="page-container">
    <div class="page-header">
        <h1 class="page-title">📦 Estoque Logístico</h1>
        <p class="page-subtitle">Sistema de controle de estoque logístico</p>
    </div>
    
    <div class="card">
        <h2>Em Desenvolvimento</h2>
        <p>Esta funcionalidade está sendo desenvolvida e estará disponível em breve.</p>
    </div>
</div>

<?php endSidebar(); ?>
