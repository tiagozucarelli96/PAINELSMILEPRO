<?php
// lc_index.php — Página principal do sistema de lista de compras
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_helper.php';
require_once __DIR__ . '/sidebar_unified.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
if (!lc_can_access_lc($perfil)) {
    header('Location: dashboard.php?erro=permissao_negada');
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

// Buscar fornecedores recentes
$fornecedores_recentes = [];
try {
    $stmt = $pdo->query("SELECT id, nome, email, telefone FROM fornecedores WHERE ativo = true ORDER BY id DESC LIMIT 5");
    $fornecedores_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignorar erro
}
?>

<div class="dashboard-container">
    <!-- Header -->
    <div class="dashboard-header">
        <h1 class="dashboard-title">🛒 Lista de Compras</h1>
        <p class="dashboard-subtitle">Sistema de gestão de compras e fornecedores</p>
    </div>

    <!-- Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">🏢</div>
            <div class="stat-value"><?= $stats['fornecedores'] ?></div>
            <div class="stat-label">Fornecedores Ativos</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">📦</div>
            <div class="stat-value"><?= $stats['insumos'] ?></div>
            <div class="stat-label">Insumos Cadastrados</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">📁</div>
            <div class="stat-value"><?= $stats['categorias'] ?></div>
            <div class="stat-label">Categorias</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">📋</div>
            <div class="stat-value"><?= $stats['encomendas'] ?></div>
            <div class="stat-label">Encomendas</div>
        </div>
    </div>

    <!-- Ações Principais -->
    <div class="actions-grid">
        <div class="action-card">
            <div class="action-icon">📝</div>
            <div class="action-title">Gerar Lista</div>
            <div class="action-description">Criar nova lista de compras baseada em eventos e necessidades</div>
            <a href="index.php?page=lista_compras" class="smile-btn smile-btn-primary">
                Gerar Lista
            </a>
        </div>
        
        <div class="action-card">
            <div class="action-icon">🏢</div>
            <div class="action-title">Fornecedores</div>
            <div class="action-description">Gerenciar fornecedores e seus dados</div>
            <a href="index.php?page=config_fornecedores" class="smile-btn smile-btn-primary">
                Gerenciar Fornecedores
            </a>
        </div>
        
        <div class="action-card">
            <div class="action-icon">📦</div>
            <div class="action-title">Insumos</div>
            <div class="action-description">Cadastrar e gerenciar insumos</div>
            <a href="index.php?page=config_insumos" class="smile-btn smile-btn-primary">
                Gerenciar Insumos
            </a>
        </div>
        
        <div class="action-card">
            <div class="action-icon">📋</div>
            <div class="action-title">Encomendas</div>
            <div class="action-description">Visualizar e gerenciar encomendas</div>
            <a href="index.php?page=ver" class="smile-btn smile-btn-primary">
                Ver Encomendas
            </a>
        </div>
    </div>

    <!-- Fornecedores Recentes -->
    <?php if (!empty($fornecedores_recentes)): ?>
    <div class="recent-section">
        <h3 class="recent-title">🏢 Fornecedores Recentes</h3>
        <?php foreach ($fornecedores_recentes as $fornecedor): ?>
        <div class="recent-item">
            <div class="recent-item-info">
                <div class="recent-item-name"><?= htmlspecialchars($fornecedor['nome']) ?></div>
                <div class="recent-item-details">
                    📧 <?= htmlspecialchars($fornecedor['email']) ?>
                    <?php if ($fornecedor['telefone']): ?>
                    | 📞 <?= htmlspecialchars($fornecedor['telefone']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <a href="index.php?page=config_fornecedores" class="recent-item-action">
                Ver →
            </a>
        </div>
        <?php endforeach; ?>
        <div style="text-align: center; margin-top: 15px;">
            <a href="index.php?page=config_fornecedores" class="smile-btn smile-btn-primary">
                Ver Todos os Fornecedores
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
/* Dashboard Styles */
.dashboard-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.dashboard-header {
    text-align: center;
    margin-bottom: 40px;
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

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 25px;
    margin-bottom: 40px;
}

.stat-card {
    background: white;
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    text-align: center;
    border-left: 5px solid #1e3a8a;
    transition: all 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.stat-icon {
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

.stat-value {
    font-size: 3em;
    font-weight: 800;
    color: #1e3a8a;
    margin-bottom: 10px;
}

.stat-label {
    font-size: 1.1em;
    color: #64748b;
    font-weight: 600;
}

.actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 25px;
    margin-bottom: 40px;
}

.action-card {
    background: white;
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    text-align: center;
    transition: all 0.3s ease;
}

.action-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.action-icon {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #1e3a8a, #1e40af);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    color: white;
    margin: 0 auto 20px;
}

.action-title {
    font-size: 1.4em;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 10px;
}

.action-description {
    color: #64748b;
    margin-bottom: 20px;
    line-height: 1.5;
}

.smile-btn {
    display: inline-block;
    padding: 12px 24px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.smile-btn-primary {
    background: linear-gradient(135deg, #1e3a8a, #1e40af);
    color: white;
}

.smile-btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(30, 58, 138, 0.4);
}

.recent-section {
    background: white;
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    margin-bottom: 30px;
}

.recent-title {
    font-size: 1.5em;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 20px;
}

.recent-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    border-bottom: 1px solid #f1f5f9;
    transition: all 0.3s ease;
}

.recent-item:hover {
    background: #f8fafc;
}

.recent-item:last-child {
    border-bottom: none;
}

.recent-item-info {
    flex: 1;
}

.recent-item-name {
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 5px;
}

.recent-item-details {
    color: #64748b;
    font-size: 0.9em;
}

.recent-item-action {
    color: #1e3a8a;
    text-decoration: none;
    font-weight: 500;
    padding: 8px 16px;
    border-radius: 6px;
    background: #f1f5f9;
    transition: all 0.3s ease;
}

.recent-item-action:hover {
    background: #1e3a8a;
    color: white;
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }
    
    .actions-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .dashboard-title {
        font-size: 2em;
    }
}
</style>

<?php
// Finalizar sidebar
endSidebar();
?>