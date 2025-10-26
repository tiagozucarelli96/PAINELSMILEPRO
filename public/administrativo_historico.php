<?php
// administrativo_historico.php — Histórico Administrativo
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { die('<p>Erro de conexão com o banco de dados.</p>'); }

includeSidebar('Histórico');
?>

<div class="page-container">
    <div class="page-header">
        <h1 class="page-title">📋 Histórico</h1>
        <p class="page-subtitle">Histórico de operações</p>
    </div>
    
    <div class="dashboard-section">
        <p>Em desenvolvimento...</p>
    </div>
</div>

<?php endSidebar(); ?>
