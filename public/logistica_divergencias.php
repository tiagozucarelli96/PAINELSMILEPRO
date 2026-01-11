<?php
// logistica_divergencias.php — placeholder divergências
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

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
    <h1>🧭 Divergências</h1>
    <p>Placeholder visual — área restrita de auditoria.</p>

    <div class="placeholder-card">
        <h2>Em construção</h2>
        <p>Fluxos de auditoria e divergências serão implementados nas próximas etapas.</p>
    </div>
</div>

<?php
$conteudo = ob_get_clean();
includeSidebar('Logística - Divergências');
echo $conteudo;
endSidebar();
?>
