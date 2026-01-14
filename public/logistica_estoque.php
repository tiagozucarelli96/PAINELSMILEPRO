<?php
require_once __DIR__ . '/logistica_tz.php';
// logistica_estoque.php — HUB de estoque
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

if (empty($_SESSION['perm_superadmin']) && empty($_SESSION['perm_logistico'])) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

ob_start();
?>

<style>
.estoque-hub {
    max-width: 1400px;
    margin: 0 auto;
    padding: 1.5rem;
}
.estoque-cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 1.25rem;
}
.estoque-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    padding: 1.5rem;
    text-decoration: none;
    color: inherit;
    transition: all .2s ease;
    display: flex;
    flex-direction: column;
    gap: .75rem;
}
.estoque-card:hover {
    transform: translateY(-2px);
    border-color: #3b82f6;
    box-shadow: 0 6px 16px rgba(0,0,0,0.12);
}
.estoque-card span {
    font-size: 2rem;
}
.estoque-card h3 {
    margin: 0;
    font-size: 1.1rem;
}
.estoque-card p {
    margin: 0;
    color: #64748b;
    font-size: .95rem;
}
</style>

<div class="estoque-hub">
    <h1 style="margin-bottom:1rem;">Estoque</h1>
    <div class="estoque-cards">
        <a class="estoque-card" href="index.php?page=logistica_contagem">
            <span>🧮</span>
            <h3>Contagem semanal</h3>
            <p>Modo guiado item a item</p>
        </a>
        <a class="estoque-card" href="index.php?page=logistica_entrada">
            <span>📥</span>
            <h3>Entrada de mercadoria</h3>
            <p>Registrar recebimentos</p>
        </a>
        <a class="estoque-card" href="index.php?page=logistica_transferencias">
            <span>🚚</span>
            <h3>Transferências</h3>
            <p>Garden → unidades</p>
        </a>
        <a class="estoque-card" href="index.php?page=logistica_saldo">
            <span>📊</span>
            <h3>Saldo atual</h3>
            <p>Consulta rápida por unidade</p>
        </a>
    </div>
</div>

<?php
$conteudo = ob_get_clean();
includeSidebar('Estoque - Logística');
echo $conteudo;
endSidebar();
?>
