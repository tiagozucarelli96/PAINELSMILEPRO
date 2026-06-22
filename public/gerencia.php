<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

if (empty($_SESSION['logado'])) {
    header('Location: login.php');
    exit;
}

$canAccess = !empty($_SESSION['perm_gerencia']) || !empty($_SESSION['perm_superadmin']);
if (!$canAccess) {
    includeSidebar('Gerência');
    echo '<div style="padding: 2rem; text-align: center;">
            <h2 style="color: #dc2626;">Acesso negado</h2>
            <p>Você não tem permissão para acessar o módulo Gerência.</p>
            <a href="index.php?page=dashboard" style="color: #1e3a8a;">Voltar ao Dashboard</a>
          </div>';
    endSidebar();
    exit;
}

ob_start();
?>

<style>
    .gerencia-page {
        max-width: 1200px;
        margin: 0 auto;
        padding: 2rem;
    }

    .gerencia-header {
        margin-bottom: 1.5rem;
    }

    .gerencia-title {
        margin: 0;
        color: #1e293b;
        font-size: 1.875rem;
        font-weight: 800;
    }

    .gerencia-subtitle {
        margin: .35rem 0 0;
        color: #64748b;
        font-size: .95rem;
    }

    .gerencia-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 1rem;
    }

    .gerencia-card {
        display: block;
        min-height: 160px;
        padding: 1.25rem;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        box-shadow: 0 1px 4px rgba(15, 23, 42, .06);
        color: inherit;
        text-decoration: none;
        transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }

    .gerencia-card:hover {
        border-color: #94a3b8;
        box-shadow: 0 8px 18px rgba(15, 23, 42, .08);
        transform: translateY(-1px);
    }

    .gerencia-card-icon {
        width: 44px;
        height: 44px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: .9rem;
        border-radius: 8px;
        background: #eef2ff;
        color: #1e3a8a;
        font-size: 1.35rem;
    }

    .gerencia-card h2 {
        margin: 0 0 .45rem;
        color: #0f172a;
        font-size: 1.1rem;
    }

    .gerencia-card p {
        margin: 0;
        color: #64748b;
        line-height: 1.45;
        font-size: .9rem;
    }
</style>

<div class="gerencia-page">
    <header class="gerencia-header">
        <h1 class="gerencia-title">Gerência</h1>
        <p class="gerencia-subtitle">Acompanhamento gerencial dos fluxos operacionais.</p>
    </header>

    <div class="gerencia-grid">
        <a href="index.php?page=gerencia_lista_compras" class="gerencia-card">
            <div class="gerencia-card-icon">🛒</div>
            <h2>Lista de compras</h2>
            <p>Área reservada para a próxima etapa da lógica de compras.</p>
        </a>
    </div>
</div>

<?php
$conteudo = ob_get_clean();
includeSidebar('Gerência');
echo $conteudo;
endSidebar();
