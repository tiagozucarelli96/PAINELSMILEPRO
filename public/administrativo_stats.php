<?php
// administrativo_stats.php — Estatísticas Administrativas
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { die('<p>Erro de conexão com o banco de dados.</p>'); }

includeSidebar('Estatísticas');
?>

<div class="page-container">
    <div class="page-header">
        <h1 class="page-title">📈 Estatísticas</h1>
        <p class="page-subtitle">Estatísticas e métricas do sistema</p>
    </div>
    
    <div class="dashboard-section">
        <p>Em desenvolvimento...</p>
    </div>
</div>

<?php endSidebar(); ?>
