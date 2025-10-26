<?php
// administrativo_relatorios.php — Relatórios Administrativos
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { die('<p>Erro de conexão com o banco de dados.</p>'); }

includeSidebar('Relatórios Administrativos');
?>

<div class="page-container">
    <div class="page-header">
        <h1 class="page-title">📊 Relatórios Administrativos</h1>
        <p class="page-subtitle">Relatórios gerenciais e análises</p>
    </div>
    
    <div class="dashboard-section">
        <div class="section-header">
            <h2>Relatórios Disponíveis</h2>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📈 Análise do Sistema</h3>
                    <span class="card-icon">📈</span>
                </div>
                <div class="card-content">
                    <p>Relatório completo de análise do sistema</p>
                    <a href="index.php?page=relatorio_analise_sistema" class="btn-primary">Acessar</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endSidebar(); ?>
