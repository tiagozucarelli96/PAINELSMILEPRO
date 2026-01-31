<?php
require_once __DIR__ . '/logistica_tz.php';
// logistica_financeiro.php — HUB financeiro
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

$is_financeiro = !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_logistico_financeiro']);
if (!$is_financeiro) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

ob_start();
?>

<style>
.financeiro-container {
    max-width: 900px;
    margin: 0 auto;
    padding: 1.5rem;
}

.financeiro-header {
    margin-bottom: 2rem;
}

.financeiro-header h1 {
    font-size: 1.5rem;
    font-weight: 600;
    color: #1e3a5f;
    margin: 0 0 0.5rem 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.financeiro-header p {
    color: #64748b;
    margin: 0;
    font-size: 0.95rem;
}

.financeiro-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.25rem;
}

.financeiro-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    text-decoration: none;
    transition: all 0.2s ease;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.financeiro-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.1);
    border-color: #cbd5e1;
}

.financeiro-card-header {
    padding: 1.25rem;
    color: #fff;
    position: relative;
}

.financeiro-card-header.blue {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
}

.financeiro-card-header.orange {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
}

.financeiro-card-icon {
    font-size: 2rem;
    margin-bottom: 0.75rem;
    display: block;
}

.financeiro-card-title {
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 0.25rem;
}

.financeiro-card-subtitle {
    font-size: 0.85rem;
    opacity: 0.9;
}

.financeiro-card-body {
    padding: 1rem 1.25rem;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: #475569;
    font-size: 0.85rem;
}

.financeiro-card-body svg {
    width: 18px;
    height: 18px;
    color: #94a3b8;
}

/* Responsivo */
@media (max-width: 640px) {
    .financeiro-container {
        padding: 1rem;
    }
    
    .financeiro-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="financeiro-container">
    <div class="financeiro-header">
        <h1>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:28px;height:28px;color:#f59e0b;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Logística Financeira
        </h1>
        <p>Relatórios e revisão de custos.</p>
    </div>

    <div class="financeiro-grid">
        <a href="index.php?page=logistica_financeiro_estoque" class="financeiro-card">
            <div class="financeiro-card-header blue">
                <span class="financeiro-card-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:32px;height:32px;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                </span>
                <div class="financeiro-card-title">Valor do Estoque</div>
                <div class="financeiro-card-subtitle">Por unidade e total</div>
            </div>
            <div class="financeiro-card-body">
                <span>Visualizar relatório</span>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
            </div>
        </a>
        
        <a href="index.php?page=logistica_revisar_custos" class="financeiro-card">
            <div class="financeiro-card-header orange">
                <span class="financeiro-card-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:32px;height:32px;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                    </svg>
                </span>
                <div class="financeiro-card-title">Revisão de Custos</div>
                <div class="financeiro-card-subtitle">Wizard mensal</div>
            </div>
            <div class="financeiro-card-body">
                <span>Iniciar revisão</span>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
            </div>
        </a>
    </div>
</div>

<?php
$conteudo = ob_get_clean();
includeSidebar('Logística - Financeiro');
echo $conteudo;
endSidebar();
?>
