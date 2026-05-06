<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/sidebar_integration.php';

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
@ini_set('display_errors', 0);

ob_start();
?>

<style>
.page-marketing-landing {
    width: 100%;
    max-width: 1400px;
    margin: 0 auto;
    padding: 1.5rem;
}

.page-marketing-header {
    text-align: center;
    margin-bottom: 2rem;
}

.page-marketing-header h1 {
    font-size: 2rem;
    font-weight: 700;
    color: #1e3a8a;
    margin: 0 0 0.5rem 0;
}

.page-marketing-header p {
    font-size: 1.125rem;
    color: #64748b;
    margin: 0;
}

.marketing-card {
    max-width: 720px;
    margin: 0 auto;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    overflow: hidden;
}

.marketing-card-header {
    background: linear-gradient(135deg, #ec4899, #db2777);
    color: #fff;
    padding: 1.5rem;
}

.marketing-card-header h2 {
    margin: 0;
    font-size: 1.4rem;
}

.marketing-card-body {
    padding: 1.5rem;
    color: #475569;
    line-height: 1.6;
}
</style>

<div class="page-marketing-landing">
    <div class="page-marketing-header">
        <h1>📣 Marketing</h1>
        <p>Área inicial do módulo de marketing</p>
    </div>

    <div class="marketing-card">
        <div class="marketing-card-header">
            <h2>Módulo liberado</h2>
        </div>
        <div class="marketing-card-body">
            Esta página foi criada para receber as funcionalidades de marketing.
        </div>
    </div>
</div>

<?php
error_reporting(E_ALL);
@ini_set('display_errors', 0);

$conteudo = ob_get_clean();

if (ob_get_level() > 0) {
    ob_end_clean();
}

includeSidebar('Marketing');
echo $conteudo;
endSidebar();
?>
