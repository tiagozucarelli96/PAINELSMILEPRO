<?php
// logistica.php — HUB (placeholder) do módulo Logística
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

ob_start();
?>

<style>
.page-logistico-landing {
    width: 100%;
    max-width: 1400px;
    margin: 0 auto;
    padding: 1.5rem;
}

.page-logistico-header {
    text-align: center;
    margin-bottom: 2rem;
}

.page-logistico-header h1 {
    font-size: 2rem;
    font-weight: 700;
    color: #1e3a8a;
    margin: 0 0 0.5rem 0;
}

.page-logistico-header p {
    font-size: 1.125rem;
    color: #64748b;
    margin: 0;
}

.funcionalidades-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
    align-items: stretch;
}

.funcionalidade-card {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
    transition: all 0.3s ease;
    cursor: pointer;
    text-decoration: none;
    color: inherit;
    display: flex;
    flex-direction: column;
    height: 100%;
}

.funcionalidade-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
    border-color: #3b82f6;
}

.funcionalidade-card-header {
    background: linear-gradient(135deg, #22c55e, #16a34a);
    color: white;
    padding: 1.5rem;
}

.funcionalidade-card-icon {
    font-size: 2.5rem;
    margin-bottom: 0.75rem;
    display: block;
}

.funcionalidade-card-title {
    font-size: 1.25rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.funcionalidade-card-subtitle {
    font-size: 0.875rem;
    opacity: 0.9;
}

.funcionalidade-card-content {
    padding: 1.25rem;
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    text-align: center;
}

.funcionalidade-card-content::after {
    content: '→';
    display: block;
    margin-top: 1rem;
    color: #64748b;
    font-weight: bold;
    font-size: 1.5rem;
}
</style>

<div class="page-logistico-landing">
    <div class="page-logistico-header">
        <h1>📦 Logística</h1>
        <p>Hub operacional do módulo de Logística (placeholder)</p>
    </div>

    <div class="funcionalidades-grid">
        <?php $is_superadmin = !empty($_SESSION['perm_superadmin']); ?>
        <?php if (!empty($_SESSION['perm_logistico']) || $is_superadmin): ?>
        <a href="index.php?page=logistica_operacional" class="funcionalidade-card">
            <div class="funcionalidade-card-header">
                <span class="funcionalidade-card-icon">🗂️</span>
                <div class="funcionalidade-card-title">Operacional</div>
                <div class="funcionalidade-card-subtitle">Eventos, alertas e listas</div>
            </div>
            <div class="funcionalidade-card-content"></div>
        </a>

        <a href="index.php?page=logistica_gerar_lista" class="funcionalidade-card">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
                <span class="funcionalidade-card-icon">🛒</span>
                <div class="funcionalidade-card-title">Gerar Lista</div>
                <div class="funcionalidade-card-subtitle">Selecionar eventos e consolidar insumos</div>
            </div>
            <div class="funcionalidade-card-content"></div>
        </a>

        <a href="index.php?page=logistica_listas" class="funcionalidade-card">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #0f766e, #14b8a6);">
                <span class="funcionalidade-card-icon">📄</span>
                <div class="funcionalidade-card-title">Histórico</div>
                <div class="funcionalidade-card-subtitle">Listas geradas e PDF</div>
            </div>
            <div class="funcionalidade-card-content"></div>
        </a>
        <?php endif; ?>

        <?php if (!empty($_SESSION['perm_logistico_divergencias']) || $is_superadmin): ?>
        <a href="index.php?page=logistica_divergencias" class="funcionalidade-card">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #f97316, #ea580c);">
                <span class="funcionalidade-card-icon">🧭</span>
                <div class="funcionalidade-card-title">Divergências</div>
                <div class="funcionalidade-card-subtitle">Auditoria e conferências</div>
            </div>
            <div class="funcionalidade-card-content"></div>
        </a>
        <?php endif; ?>

        <?php if (!empty($_SESSION['perm_logistico_financeiro']) || $is_superadmin): ?>
        <a href="index.php?page=logistica_financeiro" class="funcionalidade-card">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
                <span class="funcionalidade-card-icon">💰</span>
                <div class="funcionalidade-card-title">Financeiro</div>
                <div class="funcionalidade-card-subtitle">Custos e valores</div>
            </div>
            <div class="funcionalidade-card-content"></div>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php
$conteudo = ob_get_clean();

includeSidebar('Logística');
echo $conteudo;
endSidebar();
?>
