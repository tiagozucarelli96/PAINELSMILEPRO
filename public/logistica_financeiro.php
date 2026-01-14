<?php
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
.page-logistica-placeholder {
    max-width: 1200px;
    margin: 0 auto;
    padding: 2rem;
}

.page-logistica-placeholder h1 {
    font-size: 1.75rem;
    font-weight: 700;
    color: #1e3a8a;
    margin-bottom: 0.5rem;
}

.page-logistica-placeholder p {
    color: #64748b;
    margin-bottom: 1.5rem;
}

.placeholder-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.placeholder-card h2 {
    margin: 0 0 0.5rem 0;
    font-size: 1.25rem;
    color: #0f172a;
}
</style>

<div class="page-logistica-placeholder">
    <h1>💰 Logística Financeira</h1>
    <p>Relatórios e revisão de custos.</p>

    <div class="funcionalidades-grid">
        <a href="index.php?page=logistica_financeiro_estoque" class="funcionalidade-card">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #1d4ed8, #2563eb);">
                <span class="funcionalidade-card-icon">📊</span>
                <div class="funcionalidade-card-title">Valor do Estoque</div>
                <div class="funcionalidade-card-subtitle">Por unidade e total</div>
            </div>
            <div class="funcionalidade-card-content"></div>
        </a>
        <a href="index.php?page=logistica_revisar_custos" class="funcionalidade-card">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                <span class="funcionalidade-card-icon">🧾</span>
                <div class="funcionalidade-card-title">Revisão de Custos</div>
                <div class="funcionalidade-card-subtitle">Wizard mensal</div>
            </div>
            <div class="funcionalidade-card-content"></div>
        </a>
    </div>
</div>

<?php
$conteudo = ob_get_clean();
includeSidebar('Logística - Financeiro');
echo $conteudo;
endSidebar();
?>
