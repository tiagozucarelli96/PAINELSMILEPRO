<?php
// config_logistica.php — Hub de Configurações da Logística (cards)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/sidebar_integration.php';

includeSidebar('Configurações - Logística');
?>

<style>
.logistica-config {
    max-width: 1400px;
    margin: 0 auto;
    padding: 1.5rem;
}

.logistica-config h1 {
    font-size: 2rem;
    font-weight: 700;
    color: #1e3a8a;
    margin-bottom: 1rem;
}

.funcionalidades-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 1rem;
}

.funcionalidade-card {
    background: #ffffff;
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
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
    border-color: #3b82f6;
}

.funcionalidade-card-header {
    color: #ffffff;
    padding: 1.25rem;
}

.funcionalidade-card-icon {
    font-size: 2rem;
    margin-bottom: 0.5rem;
    display: block;
}

.funcionalidade-card-title {
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 0.25rem;
}

.funcionalidade-card-subtitle {
    font-size: 0.85rem;
    opacity: 0.9;
}

.funcionalidade-card-content {
    padding: 1rem;
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
}

.funcionalidade-card-content::after {
    content: '→';
    color: #64748b;
    font-weight: bold;
    font-size: 1.25rem;
}
</style>

<div class="logistica-config">
    <h1>Configurações Logística</h1>

    <div class="funcionalidades-grid">
        <a href="index.php?page=logistica_conexao" class="funcionalidade-card">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #6366f1, #4f46e5);">
                <span class="funcionalidade-card-icon">🔌</span>
                <div class="funcionalidade-card-title">Conexão</div>
                <div class="funcionalidade-card-subtitle">Mapeamento, sync e diagnóstico</div>
            </div>
            <div class="funcionalidade-card-content"></div>
        </a>

        <a href="index.php?page=logistica_unidades_medida" class="funcionalidade-card">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #0f766e, #14b8a6);">
                <span class="funcionalidade-card-icon">📏</span>
                <div class="funcionalidade-card-title">Unidades de Medida</div>
                <div class="funcionalidade-card-subtitle">Cadastro de unidades</div>
            </div>
            <div class="funcionalidade-card-content"></div>
        </a>

        <a href="index.php?page=logistica_tipologias" class="funcionalidade-card">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #38bdf8, #0ea5e9);">
                <span class="funcionalidade-card-icon">🏷️</span>
                <div class="funcionalidade-card-title">Tipologias</div>
                <div class="funcionalidade-card-subtitle">Insumos e Receitas</div>
            </div>
            <div class="funcionalidade-card-content"></div>
        </a>

        <a href="index.php?page=logistica_insumos" class="funcionalidade-card">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #22c55e, #16a34a);">
                <span class="funcionalidade-card-icon">🧺</span>
                <div class="funcionalidade-card-title">Insumos</div>
                <div class="funcionalidade-card-subtitle">Catálogo de insumos</div>
            </div>
            <div class="funcionalidade-card-content"></div>
        </a>

        <a href="index.php?page=logistica_receitas" class="funcionalidade-card">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #f97316, #ea580c);">
                <span class="funcionalidade-card-icon">📘</span>
                <div class="funcionalidade-card-title">Receitas</div>
                <div class="funcionalidade-card-subtitle">Fichas técnicas</div>
            </div>
            <div class="funcionalidade-card-content"></div>
        </a>
    </div>
</div>

<?php endSidebar(); ?>
