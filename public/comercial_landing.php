<?php
/**
 * comercial_landing.php — Página inicial do módulo Comercial
 * Landing page organizada com estatísticas e navegação clara
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/vendas_helper.php';

// Verificar permissões
if (!lc_can_access_comercial()) {
    header('Location: index.php?page=dashboard&error=permission_denied');
    exit;
}

$pdo = $GLOBALS['pdo'];

// Buscar estatísticas comerciais
$stats = [
    'degustacoes_total' => 0,
    'degustacoes_publicadas' => 0,
    'degustacoes_encerradas' => 0,
    'inscritos_total' => 0,
    'inscritos_confirmados' => 0,
    'inscritos_lista_espera' => 0,
    'fecharam_contrato' => 0,
    'nao_fecharam_contrato' => 0,
    'taxa_conversao' => 0,
    'pagamentos_pagos' => 0,
    'pagamentos_pendentes' => 0
];

try {
    // Total de degustações (apenas para referência)
    $stats['degustacoes_total'] = (int)$pdo->query("SELECT COUNT(*) FROM comercial_degustacoes")->fetchColumn();
    $stats['degustacoes_publicadas'] = (int)$pdo->query("SELECT COUNT(*) FROM comercial_degustacoes WHERE status = 'publicado'")->fetchColumn();
    
    // Inscritos confirmados APENAS de degustações ATIVAS (publicado)
    $stats['inscritos_confirmados'] = (int)$pdo->query("
        SELECT COUNT(*) 
        FROM comercial_inscricoes ci
        JOIN comercial_degustacoes cd ON ci.degustacao_id = cd.id
        WHERE cd.status = 'publicado'
        AND ci.status = 'confirmado'
    ")->fetchColumn();
    
    $stats['inscritos_total'] = (int)$pdo->query("
        SELECT COUNT(*) 
        FROM comercial_inscricoes ci
        JOIN comercial_degustacoes cd ON ci.degustacao_id = cd.id
        WHERE cd.status = 'publicado'
    ")->fetchColumn();
    
    $stats['inscritos_lista_espera'] = (int)$pdo->query("
        SELECT COUNT(*) 
        FROM comercial_inscricoes ci
        JOIN comercial_degustacoes cd ON ci.degustacao_id = cd.id
        WHERE cd.status = 'publicado'
        AND ci.status = 'lista_espera'
    ")->fetchColumn();
    
    // Contratos fechados: Calcular conversão das 3 ÚLTIMAS degustações realizadas
    // Considerando apenas inscritos que NÃO fecharam (excluir quem já fechou antes)
    $ultimas_3_degustacoes = $pdo->query("
        SELECT d.id, d.nome, d.data
        FROM comercial_degustacoes d
        WHERE d.status IN ('publicado', 'encerrado')
        AND d.data IS NOT NULL
        ORDER BY d.data DESC
        LIMIT 3
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $total_inscritos_sem_contrato = 0;
    $total_fecharam_contrato = 0;
    
    foreach ($ultimas_3_degustacoes as $deg) {
        // Total de inscritos confirmados desta degustação que NÃO fecharam contrato ainda
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM comercial_inscricoes 
            WHERE degustacao_id = :deg_id 
            AND status = 'confirmado'
            AND (fechou_contrato IS NULL OR fechou_contrato = 'nao' OR fechou_contrato = '')
        ");
        $stmt->execute([':deg_id' => $deg['id']]);
        $inscritos_sem_contrato = (int)$stmt->fetchColumn();
        
        // Inscritos desta degustação que FECHARAM contrato
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM comercial_inscricoes 
            WHERE degustacao_id = :deg_id 
            AND status = 'confirmado'
            AND fechou_contrato = 'sim'
        ");
        $stmt->execute([':deg_id' => $deg['id']]);
        $fecharam = (int)$stmt->fetchColumn();
        
        $total_inscritos_sem_contrato += $inscritos_sem_contrato;
        $total_fecharam_contrato += $fecharam;
    }
    
    $stats['fecharam_contrato'] = $total_fecharam_contrato;
    $stats['nao_fecharam_contrato'] = $total_inscritos_sem_contrato;
    
    // Taxa de conversão baseada apenas nos que NÃO fecharam (denominador correto)
    // Total relevante = inscritos que não fecharam + inscritos que fecharam
    $total_relevante = $total_inscritos_sem_contrato + $total_fecharam_contrato;
    if ($total_relevante > 0) {
        $stats['taxa_conversao'] = round(($total_fecharam_contrato / $total_relevante) * 100, 1);
    } else {
        $stats['taxa_conversao'] = 0;
    }
    
    // Degustações recentes (próximas 5)
    $degustacoes_recentes = $pdo->query("
        SELECT d.*, 
               (SELECT COUNT(*) FROM comercial_inscricoes WHERE degustacao_id = d.id AND status = 'confirmado') as inscritos_confirmados,
               (SELECT COUNT(*) FROM comercial_inscricoes WHERE degustacao_id = d.id) as total_inscritos
        FROM comercial_degustacoes d
        WHERE d.status = 'publicado'
        ORDER BY d.data ASC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Erro ao buscar estatísticas comerciais: " . $e->getMessage());
    $degustacoes_recentes = [];
}

includeSidebar('Comercial');
?>

<style>
.page-comercial-landing {
    padding: 2rem;
    max-width: 1400px;
    margin: 0 auto;
}

.page-comercial-header {
    margin-bottom: 2rem;
}

.page-comercial-header h1 {
    font-size: 2rem;
    font-weight: 700;
    color: #1e3a8a;
    margin-bottom: 0.5rem;
}

.page-comercial-header p {
    color: #64748b;
    font-size: 1rem;
}

/* Estatísticas Grid */
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
    transition: all 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
}

.stat-card-icon {
    font-size: 2rem;
    margin-bottom: 0.75rem;
}

.stat-card-value {
    font-size: 2rem;
    font-weight: 700;
    color: #1e3a8a;
    margin-bottom: 0.5rem;
}

.stat-card-label {
    font-size: 0.875rem;
    color: #64748b;
    font-weight: 500;
}

.stat-card-subtext {
    font-size: 0.75rem;
    color: #94a3b8;
    margin-top: 0.25rem;
}

.stat-progress-bar {
    width: 100%;
    height: 6px;
    background: #e5e7eb;
    border-radius: 3px;
    margin-top: 0.75rem;
    overflow: hidden;
}

.stat-progress-fill {
    height: 100%;
    border-radius: 3px;
    transition: width 0.5s ease;
}

/* Cards de Funcionalidades */
.funcionalidades-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
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
    display: block;
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

/* Degustações Recentes */
.degustacoes-recentes {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
}

.degustacoes-recentes-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.25rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #e5e7eb;
}

.degustacoes-recentes-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1e3a8a;
}

.degustacoes-recentes-link {
    color: #3b82f6;
    text-decoration: none;
    font-size: 0.875rem;
    font-weight: 500;
    transition: color 0.2s;
}

.degustacoes-recentes-link:hover {
    color: #2563eb;
    text-decoration: underline;
}

.degustacao-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    margin-bottom: 0.75rem;
    background: #f8fafc;
    border-radius: 8px;
    border-left: 3px solid #3b82f6;
    transition: all 0.2s ease;
}

.degustacao-item:hover {
    background: #e2e8f0;
    transform: translateX(4px);
}

.degustacao-item:last-child {
    margin-bottom: 0;
}

.degustacao-info {
    flex: 1;
}

.degustacao-nome {
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 0.25rem;
}

.degustacao-meta {
    font-size: 0.875rem;
    color: #64748b;
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.degustacao-stats {
    display: flex;
    gap: 1rem;
    align-items: center;
}

.degustacao-stat {
    text-align: center;
    padding: 0.5rem;
    min-width: 60px;
}

.degustacao-stat-value {
    font-weight: 700;
    color: #3b82f6;
    font-size: 1.125rem;
}

.degustacao-stat-label {
    font-size: 0.75rem;
    color: #94a3b8;
}

.degustacao-action {
    padding: 0.5rem 1rem;
    background: #3b82f6;
    color: white;
    border-radius: 6px;
    text-decoration: none;
    font-size: 0.875rem;
    font-weight: 500;
    transition: all 0.2s;
}

.degustacao-action:hover {
    background: #2563eb;
    transform: scale(1.05);
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: #64748b;
}

.empty-state-icon {
    font-size: 3rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

@media (max-width: 768px) {
    .page-comercial-landing {
        padding: 1rem;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }
    
    .funcionalidades-grid {
        grid-template-columns: 1fr;
    }
    
    .degustacao-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }
    
    .degustacao-stats {
        width: 100%;
        justify-content: space-around;
    }
}
</style>

<div class="page-comercial-landing">
    <!-- Header -->
    <div class="page-comercial-header">
        <h1>💼 Área Comercial</h1>
        <p>Gestão de degustações, inscrições e conversões de clientes</p>
    </div>
    
    <!-- Filtros Rápidos -->
    <div style="background: white; padding: 1.5rem; border-radius: 12px; margin-bottom: 2rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);">
        <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: center;">
            <select id="filtroPeriodo" onchange="filtrarPeriodo()" style="padding: 0.75rem; border: 1px solid #e5e7eb; border-radius: 8px; background: white; font-size: 0.875rem;">
                <option value="todos">Todos os Períodos</option>
                <option value="hoje">Hoje</option>
                <option value="semana">Esta Semana</option>
                <option value="mes">Este Mês</option>
                <option value="trimestre">Este Trimestre</option>
                <option value="ano">Este Ano</option>
            </select>
            <select id="filtroStatus" onchange="filtrarStatus()" style="padding: 0.75rem; border: 1px solid #e5e7eb; border-radius: 8px; background: white; font-size: 0.875rem;">
                <option value="todos">Todos os Status</option>
                <option value="publicado">Publicadas</option>
                <option value="encerrado">Encerradas</option>
                <option value="rascunho">Rascunhos</option>
            </select>
            <button onclick="resetarFiltros()" style="padding: 0.75rem 1.5rem; background: #e5e7eb; color: #374151; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">
                🔄 Limpar Filtros
            </button>
        </div>
    </div>
    
    <!-- Estatísticas Rápidas com Gráficos -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-card-icon">🍽️</div>
            <div class="stat-card-value"><?= $stats['degustacoes_publicadas'] ?></div>
            <div class="stat-card-label">Degustações Ativas</div>
            <div class="stat-card-subtext"><?= $stats['degustacoes_total'] ?> total</div>
            <?php if ($stats['degustacoes_total'] > 0): 
                $percent = round(($stats['degustacoes_publicadas'] / $stats['degustacoes_total']) * 100);
            ?>
            <div class="stat-progress-bar">
                <div class="stat-progress-fill" style="width: <?= $percent ?>%; background: linear-gradient(90deg, #3b82f6, #2563eb);"></div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="stat-card">
            <div class="stat-card-icon">👥</div>
            <div class="stat-card-value"><?= $stats['inscritos_confirmados'] ?></div>
            <div class="stat-card-label">Inscritos Confirmados</div>
            <div class="stat-card-subtext"><?= $stats['inscritos_total'] ?> total</div>
            <?php if ($stats['inscritos_total'] > 0): 
                $percent = round(($stats['inscritos_confirmados'] / $stats['inscritos_total']) * 100);
            ?>
            <div class="stat-progress-bar">
                <div class="stat-progress-fill" style="width: <?= $percent ?>%; background: linear-gradient(90deg, #10b981, #059669);"></div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="stat-card">
            <div class="stat-card-icon">✅</div>
            <div class="stat-card-value"><?= $stats['fecharam_contrato'] ?></div>
            <div class="stat-card-label">Contratos Fechados</div>
            <div class="stat-card-subtext"><?= $stats['taxa_conversao'] ?>% conversão (3 últimas degustações)</div>
            <?php 
                $total_relevante_contratos = $stats['fecharam_contrato'] + $stats['nao_fecharam_contrato'];
                if ($total_relevante_contratos > 0): 
                    $percent = round(($stats['fecharam_contrato'] / $total_relevante_contratos) * 100);
            ?>
            <div class="stat-progress-bar">
                <div class="stat-progress-fill" style="width: <?= $percent ?>%; background: linear-gradient(90deg, #8b5cf6, #7c3aed);"></div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="stat-card">
            <div class="stat-card-icon">⏳</div>
            <div class="stat-card-value"><?= $stats['inscritos_lista_espera'] ?></div>
            <div class="stat-card-label">Lista de Espera</div>
            <div class="stat-card-subtext">Degustações ativas</div>
        </div>
    </div>
    
    <!-- Funcionalidades Principais -->
    <div class="funcionalidades-grid">
        <!-- Degustações -->
        <div class="funcionalidade-card" style="cursor: default;">
            <div class="funcionalidade-card-header">
                <span class="funcionalidade-card-icon">🍽️</span>
                <div class="funcionalidade-card-title">Degustações</div>
                <div class="funcionalidade-card-subtitle">Gerenciar degustações e eventos</div>
            </div>
            <div class="funcionalidade-card-content">
                <a href="index.php?page=comercial_degustacao_editar" class="funcionalidade-card-item" style="text-decoration: none; color: inherit;">
                    <span class="funcionalidade-item-icon">➕</span>
                    <span class="funcionalidade-item-text">Criar Nova Degustação</span>
                    <span class="funcionalidade-item-arrow">→</span>
                </a>
                <a href="index.php?page=comercial_degustacoes" class="funcionalidade-card-item" style="text-decoration: none; color: inherit;">
                    <span class="funcionalidade-item-icon">📋</span>
                    <span class="funcionalidade-item-text">Ver Todas as Degustações</span>
                    <span class="funcionalidade-item-arrow">→</span>
                </a>
                <a href="index.php?page=comercial_realizar_degustacao" class="funcionalidade-card-item" style="text-decoration: none; color: inherit;">
                    <span class="funcionalidade-item-icon">🍽️</span>
                    <span class="funcionalidade-item-text">Realizar Degustação</span>
                    <span class="funcionalidade-item-arrow">→</span>
                </a>
            </div>
        </div>
        
        <!-- Inscrições -->
        <div class="funcionalidade-card" style="cursor: default;">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #10b981, #059669);">
                <span class="funcionalidade-card-icon">👥</span>
                <div class="funcionalidade-card-title">Inscrições</div>
                <div class="funcionalidade-card-subtitle">Visualizar e gerenciar inscrições</div>
            </div>
            <div class="funcionalidade-card-content">
                <a href="index.php?page=comercial_inscritos_cadastrados" class="funcionalidade-card-item" style="text-decoration: none; color: inherit;">
                    <span class="funcionalidade-item-icon">📋</span>
                    <span class="funcionalidade-item-text">Inscritos Cadastrados</span>
                    <span class="funcionalidade-item-arrow">→</span>
                </a>
                <a href="index.php?page=comercial_degust_inscricoes" class="funcionalidade-card-item" style="text-decoration: none; color: inherit;">
                    <span class="funcionalidade-item-icon">📝</span>
                    <span class="funcionalidade-item-text">Todas as Inscrições</span>
                    <span class="funcionalidade-item-arrow">→</span>
                </a>
                <a href="index.php?page=comercial_lista_espera" class="funcionalidade-card-item" style="text-decoration: none; color: inherit;">
                    <span class="funcionalidade-item-icon">⏳</span>
                    <span class="funcionalidade-item-text">Lista de Espera</span>
                    <span class="funcionalidade-item-arrow">→</span>
                </a>
            </div>
        </div>
        
        <!-- Funil de Conversão -->
        <a href="index.php?page=comercial_clientes" class="funcionalidade-card">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                <span class="funcionalidade-card-icon">📊</span>
                <div class="funcionalidade-card-title">Funil de Conversão</div>
                <div class="funcionalidade-card-subtitle">Análise de conversões e clientes</div>
            </div>
            <div class="funcionalidade-card-content">
                <div class="funcionalidade-card-item">
                    <span class="funcionalidade-item-icon">📈</span>
                    <span class="funcionalidade-item-text">Taxa de Conversão</span>
                    <span class="funcionalidade-item-arrow">→</span>
                </div>
                <div class="funcionalidade-card-item">
                    <span class="funcionalidade-item-icon">✅</span>
                    <span class="funcionalidade-item-text">Contratos Fechados</span>
                    <span class="funcionalidade-item-arrow">→</span>
                </div>
                <div class="funcionalidade-card-item">
                    <span class="funcionalidade-item-icon">📋</span>
                    <span class="funcionalidade-item-text">Relatórios Detalhados</span>
                    <span class="funcionalidade-item-arrow">→</span>
                </div>
            </div>
        </a>
        
        <!-- Vendas -->
        <div class="funcionalidade-card" style="cursor: default;">
            <div class="funcionalidade-card-header" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                <span class="funcionalidade-card-icon">💰</span>
                <div class="funcionalidade-card-title">Vendas</div>
                <div class="funcionalidade-card-subtitle">Pré-contratos e acompanhamento</div>
            </div>
            <div class="funcionalidade-card-content">
                <a href="index.php?page=vendas_pre_contratos" class="funcionalidade-card-item" style="text-decoration: none; color: inherit;">
                    <span class="funcionalidade-item-icon">📝</span>
                    <span class="funcionalidade-item-text">Pré-contratos</span>
                    <span class="funcionalidade-item-arrow">→</span>
                </a>
                <a href="index.php?page=vendas_lancamento_presencial" class="funcionalidade-card-item" style="text-decoration: none; color: inherit;">
                    <span class="funcionalidade-item-icon">🧾</span>
                    <span class="funcionalidade-item-text">Lançamento (Presencial)</span>
                    <span class="funcionalidade-item-arrow">→</span>
                </a>
                <?php if (vendas_is_admin()): ?>
                    <a href="index.php?page=vendas_administracao" class="funcionalidade-card-item" style="text-decoration: none; color: inherit;">
                        <span class="funcionalidade-item-icon">🛡️</span>
                        <span class="funcionalidade-item-text">Administração (Tiago)</span>
                        <span class="funcionalidade-item-arrow">→</span>
                    </a>
                <?php endif; ?>
                <a href="index.php?page=vendas_kanban" class="funcionalidade-card-item" style="text-decoration: none; color: inherit;">
                    <span class="funcionalidade-item-icon">📋</span>
                    <span class="funcionalidade-item-text">Acompanhamento de Contratos</span>
                    <span class="funcionalidade-item-arrow">→</span>
                </a>
            </div>
        </div>
    </div>
    
</div>

<!-- Custom Modals CSS -->
<link rel="stylesheet" href="assets/css/custom_modals.css">
<!-- Custom Modals JS -->
<script src="assets/js/custom_modals.js"></script>

<script>
    function filtrarPeriodo() {
        const periodo = document.getElementById('filtroPeriodo').value;
        // Por enquanto, apenas redireciona para página de degustações com filtro
        if (periodo !== 'todos') {
            window.location.href = `index.php?page=comercial_degustacoes&periodo=${periodo}`;
        } else {
            window.location.href = 'index.php?page=comercial_degustacoes';
        }
    }
    
    function filtrarStatus() {
        const status = document.getElementById('filtroStatus').value;
        if (status !== 'todos') {
            window.location.href = `index.php?page=comercial_degustacoes&status=${status}`;
        } else {
            window.location.href = 'index.php?page=comercial_degustacoes';
        }
    }
    
    function resetarFiltros() {
        document.getElementById('filtroPeriodo').value = 'todos';
        document.getElementById('filtroStatus').value = 'todos';
        window.location.href = 'index.php?page=comercial';
    }
</script>

<?php
endSidebar();
?>

