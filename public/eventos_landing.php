<?php
/**
 * eventos_landing.php
 * Página inicial do módulo Eventos
 * Acesso: Reunião Final, Calendário e Fornecedores
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';

// Verificar permissão
if (empty($_SESSION['perm_eventos']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

// Buscar estatísticas
$stats = [
    'reunioes_rascunho' => 0,
    'reunioes_concluidas' => 0,
    'fornecedores_ativos' => 0,
];

try {
    // Reuniões em rascunho
    $stmt = $pdo->query("SELECT COUNT(*) FROM eventos_reunioes WHERE status = 'rascunho'");
    $stats['reunioes_rascunho'] = (int)$stmt->fetchColumn();
    
    // Reuniões concluídas
    $stmt = $pdo->query("SELECT COUNT(*) FROM eventos_reunioes WHERE status = 'concluida'");
    $stats['reunioes_concluidas'] = (int)$stmt->fetchColumn();
    
    // Fornecedores ativos
    $stmt = $pdo->query("SELECT COUNT(*) FROM eventos_fornecedores WHERE ativo = TRUE");
    $stats['fornecedores_ativos'] = (int)$stmt->fetchColumn();
    
} catch (Exception $e) {
    error_log("Erro ao buscar stats eventos: " . $e->getMessage());
}

includeSidebar('Eventos');
?>

<style>
    .eventos-landing {
        padding: 2rem;
        max-width: 1400px;
        margin: 0 auto;
        background: #f8fafc;
    }
    
    .page-header {
        margin-bottom: 2rem;
    }
    
    .page-title {
        font-size: 1.75rem;
        font-weight: 700;
        color: #1e3a8a;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    
    .page-subtitle {
        color: #64748b;
        font-size: 0.95rem;
        margin-top: 0.5rem;
    }
    
    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2.5rem;
    }
    
    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 2px 8px rgba(30, 58, 138, 0.08);
        border: 1px solid #e0e7ff;
    }
    
    .stat-value {
        font-size: 2rem;
        font-weight: 700;
        color: #1e3a8a;
    }
    
    .stat-label {
        font-size: 0.875rem;
        color: #64748b;
        margin-top: 0.25rem;
    }
    
    /* Modules Grid */
    .modules-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 1.5rem;
    }
    
    .module-card {
        background: white;
        border-radius: 16px;
        padding: 2rem;
        box-shadow: 0 2px 8px rgba(30, 58, 138, 0.08);
        border: 1px solid #e0e7ff;
        transition: all 0.3s ease;
        text-decoration: none;
        display: block;
    }
    
    .module-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px rgba(30, 58, 138, 0.2);
        border-color: #1e3a8a;
    }
    
    .module-icon {
        width: 64px;
        height: 64px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        margin-bottom: 1rem;
    }
    
    .module-icon.blue { background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); }
    .module-icon.purple { background: linear-gradient(135deg, #7c3aed 0%, #a855f7 100%); }
    .module-icon.green { background: linear-gradient(135deg, #059669 0%, #10b981 100%); }
    .module-icon.orange { background: linear-gradient(135deg, #ea580c 0%, #f97316 100%); }
    
    .module-title {
        font-size: 1.25rem;
        font-weight: 700;
        color: #1e3a8a;
        margin-bottom: 0.5rem;
    }
    
    .module-desc {
        font-size: 0.875rem;
        color: #64748b;
        line-height: 1.5;
    }
    
    .module-badge {
        display: inline-block;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
        margin-top: 1rem;
    }
    
    .module-badge.internal {
        background: #dbeafe;
        color: #1e3a8a;
    }
    
    .module-badge.external {
        background: #fef3c7;
        color: #92400e;
    }
    
    /* Section Title */
    .section-title {
        font-size: 1.125rem;
        font-weight: 600;
        color: #1e3a8a;
        margin: 2rem 0 1rem 0;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #1e3a8a;
    }
    
    /* Responsivo */
    @media (max-width: 768px) {
        .eventos-landing {
            padding: 1rem;
        }
        
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .modules-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="eventos-landing">
    <div class="page-header">
        <h1 class="page-title">
            <span>🎉</span>
            Eventos
        </h1>
        <p class="page-subtitle">
            Gerencie reuniões finais e portais de fornecedores
        </p>
    </div>
    
    <!-- Estatísticas -->
    <div class="stats-grid">
        <a href="index.php?page=eventos_rascunhos" style="text-decoration: none; color: inherit;">
            <div class="stat-card" style="cursor: pointer; transition: all 0.2s;" onmouseover="this.style.borderColor='#1e3a8a'; this.style.boxShadow='0 4px 12px rgba(30,58,138,0.15)'" onmouseout="this.style.borderColor='#e0e7ff'; this.style.boxShadow='0 2px 8px rgba(30, 58, 138, 0.08)'">
                <div class="stat-value"><?= $stats['reunioes_rascunho'] ?></div>
                <div class="stat-label">Reuniões em Rascunho</div>
            </div>
        </a>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['reunioes_concluidas'] ?></div>
            <div class="stat-label">Reuniões Concluídas</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['fornecedores_ativos'] ?></div>
            <div class="stat-label">Fornecedores Ativos</div>
        </div>
    </div>
    
    <!-- Módulos Internos -->
    <h2 class="section-title">Módulos Internos</h2>
    <div class="modules-grid">
        <a href="index.php?page=eventos_reuniao_final" class="module-card">
            <div class="module-icon blue">📝</div>
            <div class="module-title">Reunião Final</div>
            <div class="module-desc">
                Crie e edite reuniões finais vinculadas aos eventos da ME. 
                Inclui seções de Decoração, Observações e DJ/Protocolos.
            </div>
            <span class="module-badge internal">Interno</span>
        </a>
        
        <a href="index.php?page=eventos_rascunhos" class="module-card">
            <div class="module-icon blue">📋</div>
            <div class="module-title">Rascunhos da Reunião</div>
            <div class="module-desc">
                Veja e exclua reuniões em rascunho. Abra para continuar editando ou remova as que não forem mais necessárias.
            </div>
            <span class="module-badge internal">Interno</span>
        </a>
        
        <a href="index.php?page=eventos_calendario" class="module-card">
            <div class="module-icon purple">📅</div>
            <div class="module-title">Calendário de Reuniões</div>
            <div class="module-desc">
                Visualize todas as reuniões em um calendário mensal. 
                Gere PDFs e crie links públicos de visualização.
            </div>
            <span class="module-badge internal">Interno</span>
        </a>
        
        <a href="index.php?page=eventos_fornecedores" class="module-card">
            <div class="module-icon orange">👥</div>
            <div class="module-title">Fornecedores</div>
            <div class="module-desc">
                Cadastre DJs e decoradores. Gerencie acessos aos portais externos 
                e vincule fornecedores às reuniões.
            </div>
            <span class="module-badge internal">Interno</span>
        </a>
    </div>
    
</div>

<?php endSidebar(); ?>
