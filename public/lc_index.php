<?php
// lc_index.php — Página principal do módulo Logístico
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_permissions_helper.php';
require_once __DIR__ . '/sidebar_integration.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
if (!lc_can_access_lc($perfil)) {
    header('Location: index.php?page=dashboard&erro=permissao_negada');
    exit;
}

$isAdmin = in_array($perfil, ['ADM', 'FIN']);

// Buscar estatísticas
$stats = [
    'fornecedores' => 0,
    'insumos' => 0,
    'categorias' => 0,
    'encomendas' => 0
];

try {
    $stats['fornecedores'] = $pdo->query("SELECT COUNT(*) FROM fornecedores WHERE ativo = true")->fetchColumn();
    $stats['insumos'] = $pdo->query("SELECT COUNT(*) FROM lc_insumos WHERE ativo = true")->fetchColumn();
    $stats['categorias'] = $pdo->query("SELECT COUNT(*) FROM lc_categorias WHERE ativo = true")->fetchColumn();
    $stats['encomendas'] = $pdo->query("SELECT COUNT(*) FROM lc_encomendas")->fetchColumn();
} catch (Exception $e) {
    // Ignorar erro
}

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

/* Estatísticas */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
    text-align: center;
}

.stat-card-icon {
    font-size: 2.5rem;
    margin-bottom: 0.75rem;
}

.stat-card-value {
    font-size: 2rem;
    font-weight: 700;
    color: #1e3a8a;
    margin: 0 0 0.5rem 0;
}

.stat-card-label {
    font-size: 0.875rem;
    color: #64748b;
    font-weight: 500;
}

/* Cards de Funcionalidades */
.funcionalidades-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
    align-items: stretch; /* Garantir que todos os cards tenham a mesma altura na linha */
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
    height: 100%; /* Garantir que todos os cards tenham altura uniforme */
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
}

.funcionalidade-card-item {
    display: flex;
    align-items: center;
    padding: 0.75rem;
    margin-bottom: 0.5rem;
    background: #f8fafc;
    border-radius: 8px;
    transition: all 0.2s ease;
}

.funcionalidade-card-item:hover {
    background: #e2e8f0;
}

.funcionalidade-card-item:last-child {
    margin-bottom: 0;
}

.funcionalidade-item-icon {
    font-size: 1.25rem;
    margin-right: 0.75rem;
    width: 24px;
    text-align: center;
}

.funcionalidade-item-text {
    flex: 1;
    font-weight: 500;
    color: #1e293b;
    font-size: 0.875rem;
}

.funcionalidade-item-arrow {
    color: #64748b;
    font-weight: bold;
}

/* Card simples (sem subitens) */
.funcionalidade-card-simples {
    text-decoration: none;
    color: inherit;
}

.funcionalidade-card-simples .funcionalidade-card-content {
    padding: 1.5rem;
    text-align: center;
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
}

.funcionalidade-card-simples .funcionalidade-card-content::after {
    content: '→';
    display: block;
    margin-top: 1rem;
    color: #64748b;
    font-weight: bold;
    font-size: 1.5rem;
}

/* Garantir que cards com conteúdo tenham altura mínima */
.funcionalidade-card-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
}
</style>

<div class="page-logistico-landing">
    <!-- Header -->
    <div class="page-logistico-header">
        <h1>📦 Área Logística</h1>
        <p>Controle de estoque e compras</p>
    </div>
    
    <!-- Estatísticas Rápidas -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-card-icon">🏢</div>
            <div class="stat-card-value"><?= $stats['fornecedores'] ?></div>
            <div class="stat-card-label">Fornecedores Ativos</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-card-icon">📦</div>
            <div class="stat-card-value"><?= $stats['insumos'] ?></div>
            <div class="stat-card-label">Insumos Cadastrados</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-card-icon">📁</div>
            <div class="stat-card-value"><?= $stats['categorias'] ?></div>
            <div class="stat-card-label">Categorias</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-card-icon">📋</div>
            <div class="stat-card-value"><?= $stats['encomendas'] ?></div>
            <div class="stat-card-label">Encomendas</div>
        </div>
    </div>
    
    <!-- Funcionalidades Principais -->
    <div class="funcionalidades-grid">
        <!-- Lista de Compras -->
        <div class="funcionalidade-card" style="cursor: default;">
            <div class="funcionalidade-card-header">
                <span class="funcionalidade-card-icon">📋</span>
                <div class="funcionalidade-card-title">Lista de Compras</div>
                <div class="funcionalidade-card-subtitle">Gerar e gerenciar listas de compras</div>
            </div>
            <div class="funcionalidade-card-content">
                <a href="index.php?page=lista_compras" class="funcionalidade-card-item" style="text-decoration: none; color: inherit;">
                    <span class="funcionalidade-item-icon">🛒</span>
                    <span class="funcionalidade-item-text">Gerar Lista</span>
                    <span class="funcionalidade-item-arrow">→</span>
                </a>
                <a href="index.php?page=lc_index" class="funcionalidade-card-item" style="text-decoration: none; color: inherit;">
                    <span class="funcionalidade-item-icon">📝</span>
                    <span class="funcionalidade-item-text">Gerenciar Listas</span>
                    <span class="funcionalidade-item-arrow">→</span>
                </a>
            </div>
        </div>
        
        <!-- Estoque -->
        <div class="funcionalidade-card" style="cursor: default;">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #10b981, #059669);">
                <span class="funcionalidade-card-icon">📦</span>
                <div class="funcionalidade-card-title">Estoque</div>
                <div class="funcionalidade-card-subtitle">Controle de estoque logístico</div>
            </div>
            <div class="funcionalidade-card-content">
                <a href="index.php?page=estoque_logistico" class="funcionalidade-card-item" style="text-decoration: none; color: inherit;">
                    <span class="funcionalidade-item-icon">📊</span>
                    <span class="funcionalidade-item-text">Visualizar Estoque</span>
                    <span class="funcionalidade-item-arrow">→</span>
                </a>
                <a href="index.php?page=estoque_kardex" class="funcionalidade-card-item" style="text-decoration: none; color: inherit;">
                    <span class="funcionalidade-item-icon">📈</span>
                    <span class="funcionalidade-item-text">Kardex de Movimentações</span>
                    <span class="funcionalidade-item-arrow">→</span>
                </a>
                <a href="index.php?page=estoque_contagens" class="funcionalidade-card-item" style="text-decoration: none; color: inherit;">
                    <span class="funcionalidade-item-icon">📉</span>
                    <span class="funcionalidade-item-text">Contagens de Estoque</span>
                    <span class="funcionalidade-item-arrow">→</span>
                </a>
            </div>
        </div>
        
        <!-- Encomendas -->
        <a href="index.php?page=ver" class="funcionalidade-card funcionalidade-card-simples">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                <span class="funcionalidade-card-icon">📄</span>
                <div class="funcionalidade-card-title">Ver Encomendas</div>
                <div class="funcionalidade-card-subtitle">Visualizar detalhes das encomendas</div>
            </div>
            <div class="funcionalidade-card-content"></div>
        </a>
        
        <!-- Alertas -->
        <a href="index.php?page=estoque_alertas" class="funcionalidade-card funcionalidade-card-simples">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                <span class="funcionalidade-card-icon">⚠️</span>
                <div class="funcionalidade-card-title">Alertas</div>
                <div class="funcionalidade-card-subtitle">Alertas de estoque</div>
            </div>
            <div class="funcionalidade-card-content"></div>
        </a>
        
        <!-- PDFs -->
        <a href="index.php?page=lc_pdf" class="funcionalidade-card funcionalidade-card-simples">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                <span class="funcionalidade-card-icon">📄</span>
                <div class="funcionalidade-card-title">PDFs</div>
                <div class="funcionalidade-card-subtitle">Gerar PDFs de compras</div>
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

includeSidebar('Logístico');
// O sidebar_unified.php já cria <div class="main-content"><div id="pageContent">
// Então só precisamos do conteúdo da página
echo $conteudo;
endSidebar();
?>
