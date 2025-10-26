<?php
// dashboard_integration.php — Dashboard usando sidebar_integration.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';

// Verificar login
if (empty($_SESSION['logado']) || $_SESSION['logado'] != 1) {
    header('Location: login.php');
    exit;
}

// Iniciar sidebar
includeSidebar();
setPageTitle('Dashboard');
addBreadcrumb([
    ['title' => 'Dashboard']
]);

$nomeUser = $_SESSION['nome'] ?? 'Usuário';
$perfil = $_SESSION['perfil'] ?? 'CONSULTA';
?>

<div class="page-container">
    <div class="page-header">
        <h1 class="page-title">🏠 Dashboard</h1>
        <p class="page-subtitle">Bem-vindo, <?= htmlspecialchars($nomeUser) ?>!</p>
    </div>
    
    <div class="dashboard-grid">
        <!-- Cards principais -->
        <div class="dashboard-card">
            <div class="card-header">
                <h3>📋 Comercial</h3>
                <span class="card-icon">📋</span>
            </div>
            <div class="card-content">
                <p>Gestão de degustações e conversões</p>
                <a href="index.php?page=comercial_degustacoes" class="btn-primary">Acessar</a>
            </div>
        </div>
        
        <div class="dashboard-card">
            <div class="card-header">
                <h3>📦 Logístico</h3>
                <span class="card-icon">📦</span>
            </div>
            <div class="card-content">
                <p>Controle de estoque e compras</p>
                <a href="index.php?page=lc_index" class="btn-primary">Acessar</a>
            </div>
        </div>
        
        <div class="dashboard-card">
            <div class="card-header">
                <h3>⚙️ Configurações</h3>
                <span class="card-icon">⚙️</span>
            </div>
            <div class="card-content">
                <p>Configurações do sistema</p>
                <a href="index.php?page=configuracoes" class="btn-primary">Acessar</a>
            </div>
        </div>
        
        <div class="dashboard-card">
            <div class="card-header">
                <h3>📝 Cadastros</h3>
                <span class="card-icon">📝</span>
            </div>
            <div class="card-content">
                <p>Gestão de usuários e fornecedores</p>
                <a href="index.php?page=usuarios" class="btn-primary">Acessar</a>
            </div>
        </div>
        
        <div class="dashboard-card">
            <div class="card-header">
                <h3>💰 Financeiro</h3>
                <span class="card-icon">💰</span>
            </div>
            <div class="card-content">
                <p>Pagamentos e solicitações</p>
                <a href="index.php?page=pagamentos" class="btn-primary">Acessar</a>
            </div>
        </div>
        
        <div class="dashboard-card">
            <div class="card-header">
                <h3>👥 Administrativo</h3>
                <span class="card-icon">👥</span>
            </div>
            <div class="card-content">
                <p>Relatórios e administração</p>
                <a href="index.php?page=administrativo" class="btn-primary">Acessar</a>
            </div>
        </div>
    </div>
</div>

<style>
.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.dashboard-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
}

.dashboard-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #e2e8f0;
}

.card-header h3 {
    margin: 0;
    color: #1e293b;
    font-size: 18px;
    font-weight: 600;
}

.card-icon {
    font-size: 24px;
}

.card-content p {
    color: #64748b;
    margin-bottom: 15px;
    line-height: 1.5;
}

.btn-primary {
    display: inline-block;
    background: #1e40af;
    color: white;
    padding: 10px 20px;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 500;
    transition: background 0.3s ease;
}

.btn-primary:hover {
    background: #1e3a8a;
}
</style>

<?php endSidebar(); ?>
