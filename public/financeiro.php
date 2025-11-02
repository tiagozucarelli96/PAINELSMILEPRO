<?php
// financeiro.php — Página principal do Financeiro
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/sidebar_integration.php';

// Suprimir warnings durante renderização
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
@ini_set('display_errors', 0);

// Criar conteúdo da página usando output buffering
ob_start();
?>

<style>
/* Container Principal */
.page-logistico-landing {
    width: 100%;
    max-width: 1400px;
    margin: 0 auto;
    padding: 1.5rem;
}

/* Header */
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

/* Cards de Funcionalidades */
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
    background: linear-gradient(135deg, #3b82f6, #2563eb);
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
    <!-- Header -->
    <div class="page-logistico-header">
        <h1>💰 Financeiro</h1>
        <p>Pagamentos e solicitações</p>
    </div>
    
    <!-- Funcionalidades Principais -->
    <div class="funcionalidades-grid">
        <!-- Solicitações -->
        <a href="index.php?page=pagamentos" class="funcionalidade-card">
            <div class="funcionalidade-card-header">
                <span class="funcionalidade-card-icon">💳</span>
                <div class="funcionalidade-card-title">Solicitações</div>
                <div class="funcionalidade-card-subtitle">Gerenciar solicitações de pagamento</div>
            </div>
            <div class="funcionalidade-card-content"></div>
        </a>
        
        <!-- Painel Admin -->
        <a href="index.php?page=pagamentos_painel" class="funcionalidade-card">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #10b981, #059669);">
                <span class="funcionalidade-card-icon">📋</span>
                <div class="funcionalidade-card-title">Painel Admin</div>
                <div class="funcionalidade-card-subtitle">Painel administrativo de pagamentos</div>
            </div>
            <div class="funcionalidade-card-content"></div>
        </a>
        
        <!-- Solicitar -->
        <a href="index.php?page=pagamentos_solicitar" class="funcionalidade-card">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                <span class="funcionalidade-card-icon">➕</span>
                <div class="funcionalidade-card-title">Solicitar</div>
                <div class="funcionalidade-card-subtitle">Criar nova solicitação de pagamento</div>
            </div>
            <div class="funcionalidade-card-content"></div>
        </a>
        
        <!-- Freelancers -->
        <a href="index.php?page=freelancer_cadastro" class="funcionalidade-card">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                <span class="funcionalidade-card-icon">👤</span>
                <div class="funcionalidade-card-title">Freelancers</div>
                <div class="funcionalidade-card-subtitle">Cadastro de freelancers</div>
            </div>
            <div class="funcionalidade-card-content"></div>
        </a>
    </div>
</div>

<?php
// Restaurar error_reporting antes de incluir sidebar
error_reporting(E_ALL);
@ini_set('display_errors', 0);

$conteudo = ob_get_clean();

// Verificar se houve algum erro no buffer
if (ob_get_level() > 0) {
    ob_end_clean();
}

includeSidebar('Financeiro');
echo $conteudo;
endSidebar();
?>
