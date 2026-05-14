<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';

if (empty($_SESSION['logado'])) {
    header('Location: index.php?page=login');
    exit;
}

includeSidebar('Pessoal');
?>

<style>
    .pessoal-placeholder {
        min-height: calc(100vh - 120px);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2rem;
        background:
            radial-gradient(circle at top left, rgba(30, 64, 175, 0.10), transparent 35%),
            linear-gradient(180deg, #f8fafc 0%, #eef2ff 100%);
    }
    .pessoal-placeholder-card {
        width: min(720px, 100%);
        background: #ffffff;
        border: 1px solid #dbeafe;
        border-radius: 20px;
        padding: 2rem;
        box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08);
    }
    .pessoal-placeholder-kicker {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.45rem 0.8rem;
        border-radius: 999px;
        background: #dbeafe;
        color: #1d4ed8;
        font-size: 0.85rem;
        font-weight: 700;
        margin-bottom: 1rem;
    }
    .pessoal-placeholder h1 {
        margin: 0 0 0.75rem 0;
        font-size: 2rem;
        color: #0f172a;
    }
    .pessoal-placeholder p {
        margin: 0;
        color: #475569;
        font-size: 1rem;
        line-height: 1.6;
    }
</style>

<div class="pessoal-placeholder">
    <div class="pessoal-placeholder-card">
        <div class="pessoal-placeholder-kicker">Em desenvolvimento</div>
        <h1>Módulo Pessoal</h1>
        <p>Esta página ainda está em construção. O botão já foi separado da área atual do colaborador e ficará disponível aqui conforme o desenvolvimento avançar.</p>
    </div>
</div>

<?php endSidebar(); ?>
