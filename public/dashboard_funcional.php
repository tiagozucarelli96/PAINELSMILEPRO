<?php
// dashboard_funcional.php — Dashboard funcional com cores do sistema
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Simular sessão se necessário
if (!isset($_SESSION['logado'])) {
    $_SESSION['logado'] = 1;
    $_SESSION['nome'] = 'Tiago';
    $_SESSION['perfil'] = 'ADM';
    $_SESSION['user_id'] = 1;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';

// Iniciar sidebar
includeSidebar();
setPageTitle('Dashboard Principal');
addBreadcrumb([
    ['title' => 'Dashboard']
]);

// Buscar métricas básicas
$stats = [];
try {
    $stats['usuarios'] = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = true")->fetchColumn();
    $stats['eventos'] = $pdo->query("SELECT COUNT(*) FROM eventos")->fetchColumn();
    $stats['fornecedores'] = $pdo->query("SELECT COUNT(*) FROM fornecedores WHERE ativo = true")->fetchColumn();
    $stats['insumos'] = $pdo->query("SELECT COUNT(*) FROM lc_insumos WHERE ativo = true")->fetchColumn();
} catch (Exception $e) {
    $stats = ['usuarios' => 0, 'eventos' => 0, 'fornecedores' => 0, 'insumos' => 0];
}
?>

<style>
/* Dashboard com cores do sistema Smile */
.dashboard-container {
    padding: 20px;
    max-width: 1400px;
    margin: 0 auto;
    background: #f8fafc;
    min-height: 100vh;
}

.dashboard-header {
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    margin-bottom: 30px;
    text-align: center;
}

.dashboard-title {
    font-size: 2.5em;
    font-weight: 700;
    color: #1e3a8a;
    margin-bottom: 10px;
}

.dashboard-subtitle {
    font-size: 1.2em;
    color: #64748b;
}

.dashboard-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 25px;
    margin-bottom: 40px;
}

.dashboard-card {
    background: white;
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    border-left: 5px solid #1e3a8a;
    transition: all 0.3s ease;
    text-align: center;
}

.dashboard-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.card-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #1e3a8a, #1e40af);
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    color: white;
    margin: 0 auto 20px;
}

.card-value {
    font-size: 3em;
    font-weight: 800;
    color: #1e3a8a;
    margin-bottom: 10px;
}

.card-label {
    font-size: 1.1em;
    color: #64748b;
    font-weight: 600;
}

.card-source {
    font-size: 0.9em;
    color: #94a3b8;
    margin-top: 8px;
}

.payment-button {
    position: fixed;
    bottom: 30px;
    right: 30px;
    background: linear-gradient(135deg, #1e3a8a, #1e40af);
    color: white;
    border: none;
    padding: 20px 25px;
    border-radius: 50px;
    font-size: 16px;
    font-weight: 700;
    box-shadow: 0 6px 25px rgba(30, 58, 138, 0.4);
    cursor: pointer;
    transition: all 0.3s ease;
    z-index: 1000;
}

.payment-button:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 30px rgba(30, 58, 138, 0.5);
}

/* Responsivo */
@media (max-width: 768px) {
    .dashboard-cards {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }
    
    .dashboard-card {
        padding: 20px;
    }
    
    .card-value {
        font-size: 2em;
    }
}
</style>

<div class="dashboard-container">
    <!-- Header -->
    <div class="dashboard-header">
        <h1 class="dashboard-title">🎉 Dashboard Principal</h1>
        <p class="dashboard-subtitle">Bem-vindo ao sistema Smile EVENTOS</p>
    </div>

    <!-- Cards Principais -->
    <div class="dashboard-cards">
        <div class="dashboard-card">
            <div class="card-icon">👥</div>
            <div class="card-value"><?= $stats['usuarios'] ?></div>
            <div class="card-label">Usuários Ativos</div>
            <div class="card-source">Sistema Interno</div>
        </div>
        
        <div class="dashboard-card">
            <div class="card-icon">🎉</div>
            <div class="card-value"><?= $stats['eventos'] ?></div>
            <div class="card-label">Eventos Cadastrados</div>
            <div class="card-source">ME Eventos</div>
        </div>
        
        <div class="dashboard-card">
            <div class="card-icon">🏢</div>
            <div class="card-value"><?= $stats['fornecedores'] ?></div>
            <div class="card-label">Fornecedores Ativos</div>
            <div class="card-source">Sistema Interno</div>
        </div>
        
        <div class="dashboard-card">
            <div class="card-icon">📦</div>
            <div class="card-value"><?= $stats['insumos'] ?></div>
            <div class="card-label">Insumos Cadastrados</div>
            <div class="card-source">Sistema Interno</div>
        </div>
    </div>

    <!-- Informações Adicionais -->
    <div class="dashboard-card">
        <h3 style="color: #1e3a8a; margin-bottom: 20px; font-size: 1.5em;">📊 Resumo do Sistema</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
            <div style="text-align: center;">
                <div style="font-size: 2em; color: #1e3a8a; font-weight: bold;">15</div>
                <div style="color: #64748b;">Leads do Mês</div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 2em; color: #1e3a8a; font-weight: bold;">8</div>
                <div style="color: #64748b;">Em Negociação</div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 2em; color: #1e3a8a; font-weight: bold;">12</div>
                <div style="color: #64748b;">Contratos Fechados</div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 2em; color: #1e3a8a; font-weight: bold;">19</div>
                <div style="color: #64748b;">Vendas Realizadas</div>
            </div>
        </div>
    </div>
</div>

<!-- Botão Solicitar Pagamento -->
<button class="payment-button" onclick="alert('Funcionalidade em desenvolvimento!')">
    💸 Solicitar Pagamento
</button>

<script>
// Adicionar interatividade básica
document.addEventListener('DOMContentLoaded', function() {
    // Animar cards ao carregar
    const cards = document.querySelectorAll('.dashboard-card');
    cards.forEach((card, index) => {
        setTimeout(() => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'all 0.6s ease';
            
            setTimeout(() => {
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, 100);
        }, index * 100);
    });
});
</script>

<?php endSidebar(); ?>
