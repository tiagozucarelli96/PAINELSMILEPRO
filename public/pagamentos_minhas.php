<?php
// pagamentos_minhas.php
// Lista de solicitações do usuário logado

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
if (!in_array($perfil, ['ADM', 'FIN', 'GERENTE'])) {
    header('Location: dashboard.php?erro=permissao_negada');
    exit;
}

$sucesso = $_GET['sucesso'] ?? null;
$erro = $_GET['erro'] ?? null;

// Filtros
$status_filtro = $_GET['status'] ?? '';
$periodo_inicio = $_GET['periodo_inicio'] ?? '';
$periodo_fim = $_GET['periodo_fim'] ?? '';
$busca = $_GET['busca'] ?? '';

// Construir query
$where_conditions = [];
$params = [];

// Filtro por usuário (exceto ADM/FIN que veem tudo)
if (!in_array($perfil, ['ADM', 'FIN'])) {
    $where_conditions[] = "s.criador_id = ?";
    $params[] = $_SESSION['usuario_id'] ?? 0;
}

// Filtro por status
if ($status_filtro) {
    $where_conditions[] = "s.status = ?";
    $params[] = $status_filtro;
}

// Filtro por período
if ($periodo_inicio) {
    $where_conditions[] = "s.criado_em >= ?";
    $params[] = $periodo_inicio . ' 00:00:00';
}

if ($periodo_fim) {
    $where_conditions[] = "s.criado_em <= ?";
    $params[] = $periodo_fim . ' 23:59:59';
}

// Filtro por busca
if ($busca) {
    $where_conditions[] = "(f.nome_completo ILIKE ? OR fo.nome ILIKE ? OR s.observacoes ILIKE ?)";
    $search_term = '%' . $busca . '%';
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$where_sql = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Buscar solicitações
$sql = "
    SELECT 
        s.id,
        s.beneficiario_tipo,
        s.valor,
        s.data_desejada,
        s.observacoes,
        s.status,
        s.status_atualizado_em,
        s.origem,
        s.criado_em,
        s.pix_tipo,
        s.pix_chave,
        s.data_pagamento,
        s.observacao_pagamento,
        s.motivo_suspensao,
        s.motivo_recusa,
        -- Dados do freelancer
        f.nome_completo as freelancer_nome,
        f.cpf as freelancer_cpf,
        -- Dados do fornecedor
        fo.nome as fornecedor_nome,
        fo.cnpj as fornecedor_cnpj,
        -- Dados do criador
        u.nome as criador_nome,
        -- Dados do atualizador
        u2.nome as atualizador_nome
    FROM lc_solicitacoes_pagamento s
    LEFT JOIN lc_freelancers f ON f.id = s.freelancer_id
    LEFT JOIN fornecedores fo ON fo.id = s.fornecedor_id
    LEFT JOIN usuarios u ON u.id = s.criador_id
    LEFT JOIN usuarios u2 ON u2.id = s.status_atualizado_por
    {$where_sql}
    ORDER BY s.criado_em DESC
    LIMIT 50
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $solicitacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $erro = "Erro ao carregar solicitações: " . $e->getMessage();
    $solicitacoes = [];
}

// Estatísticas
$stats = [
    'total' => 0,
    'aguardando' => 0,
    'aprovado' => 0,
    'suspenso' => 0,
    'recusado' => 0,
    'pago' => 0
];

foreach ($solicitacoes as $solicitacao) {
    $stats['total']++;
    $stats[$solicitacao['status']]++;
}

// Função para obter classe CSS do status
function getStatusClass($status) {
    switch ($status) {
        case 'aguardando': return 'smile-badge-warning';
        case 'aprovado': return 'smile-badge-info';
        case 'suspenso': return 'smile-badge-danger';
        case 'recusado': return 'smile-badge-danger';
        case 'pago': return 'smile-badge-success';
        default: return 'smile-badge-secondary';
    }
}

// Função para obter texto do status
function getStatusText($status) {
    switch ($status) {
        case 'aguardando': return 'Aguardando';
        case 'aprovado': return 'Aprovado';
        case 'suspenso': return 'Suspenso';
        case 'recusado': return 'Recusado';
        case 'pago': return 'Pago';
        default: return ucfirst($status);
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minhas Solicitações - Sistema Smile</title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #1e40af;
        }
        
        .stat-label {
            font-size: 14px;
            color: #64748b;
            margin-top: 5px;
        }
        
        .filters-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .status-chips {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        
        .status-chip {
            padding: 8px 16px;
            border-radius: 20px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .status-chip.active {
            background: #1e40af;
            color: white;
        }
        
        .status-chip:not(.active) {
            background: #f1f5f9;
            color: #64748b;
        }
        
        .status-chip:not(.active):hover {
            background: #e2e8f0;
        }
        
        .table-actions {
            display: flex;
            gap: 5px;
        }
        
        .action-btn {
            padding: 4px 8px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
        }
        
        .action-btn.view {
            background: #3b82f6;
            color: white;
        }
        
        .action-btn.edit {
            background: #f59e0b;
            color: white;
        }
        
        .action-btn.resend {
            background: #10b981;
            color: white;
        }
    </style>
</head>
<body>
    <div class="smile-container">
        <div class="smile-card">
            <div class="smile-card-header">
                <h1>📋 Minhas Solicitações de Pagamento</h1>
                <p>Acompanhe o status das suas solicitações de pagamento</p>
            </div>
            
            <div class="smile-card-body">
                <!-- Mensagens -->
                <?php if ($sucesso): ?>
                    <div class="smile-alert smile-alert-success">
                        ✅ <?= htmlspecialchars($sucesso) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($erro): ?>
                    <div class="smile-alert smile-alert-danger">
                        ❌ <?= htmlspecialchars($erro) ?>
                    </div>
                <?php endif; ?>
                
                <!-- Estatísticas -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?= $stats['total'] ?></div>
                        <div class="stat-label">Total</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= $stats['aguardando'] ?></div>
                        <div class="stat-label">Aguardando</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= $stats['aprovado'] ?></div>
                        <div class="stat-label">Aprovado</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= $stats['suspenso'] ?></div>
                        <div class="stat-label">Suspenso</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= $stats['pago'] ?></div>
                        <div class="stat-label">Pago</div>
                    </div>
                </div>
                
                <!-- Filtros -->
                <div class="filters-section">
                    <h3>🔍 Filtros</h3>
                    
                    <!-- Status Chips -->
                    <div class="status-chips">
                        <a href="?" class="status-chip <?= !$status_filtro ? 'active' : '' ?>">
                            Todos
                        </a>
                        <a href="?status=aguardando" class="status-chip <?= $status_filtro === 'aguardando' ? 'active' : '' ?>">
                            Aguardando
                        </a>
                        <a href="?status=aprovado" class="status-chip <?= $status_filtro === 'aprovado' ? 'active' : '' ?>">
                            Aprovado
                        </a>
                        <a href="?status=suspenso" class="status-chip <?= $status_filtro === 'suspenso' ? 'active' : '' ?>">
                            Suspenso
                        </a>
                        <a href="?status=pago" class="status-chip <?= $status_filtro === 'pago' ? 'active' : '' ?>">
                            Pago
                        </a>
                        <a href="?status=recusado" class="status-chip <?= $status_filtro === 'recusado' ? 'active' : '' ?>">
                            Recusado
                        </a>
                    </div>
                    
                    <form method="GET" action="">
                        <div class="filters-grid">
                            <div class="smile-form-group">
                                <label for="periodo_inicio">Período Início</label>
                                <input type="date" name="periodo_inicio" id="periodo_inicio" 
                                       class="smile-form-control" value="<?= htmlspecialchars($periodo_inicio) ?>">
                            </div>
                            
                            <div class="smile-form-group">
                                <label for="periodo_fim">Período Fim</label>
                                <input type="date" name="periodo_fim" id="periodo_fim" 
                                       class="smile-form-control" value="<?= htmlspecialchars($periodo_fim) ?>">
                            </div>
                            
                            <div class="smile-form-group">
                                <label for="busca">Buscar</label>
                                <input type="text" name="busca" id="busca" 
                                       class="smile-form-control" placeholder="Nome, CPF, observações..."
                                       value="<?= htmlspecialchars($busca) ?>">
                            </div>
                            
                            <div class="smile-form-group">
                                <label>&nbsp;</label>
                                <button type="submit" class="smile-btn smile-btn-primary">
                                    🔍 Filtrar
                                </button>
                            </div>
                        </div>
                        
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status_filtro) ?>">
                    </form>
                </div>
                
                <!-- Tabela de Solicitações -->
                <div style="background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <table class="smile-table">
                        <thead>
                            <tr>
                                <th>Nº</th>
                                <th>Beneficiário</th>
                                <th>Tipo</th>
                                <th>Valor</th>
                                <th>Criado em</th>
                                <th>Status</th>
                                <th>Última Atualização</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($solicitacoes)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 40px; color: #64748b;">
                                        Nenhuma solicitação encontrada
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($solicitacoes as $solicitacao): ?>
                                    <tr>
                                        <td><?= $solicitacao['id'] ?></td>
                                        <td>
                                            <?php if ($solicitacao['beneficiario_tipo'] === 'freelancer'): ?>
                                                <strong><?= htmlspecialchars($solicitacao['freelancer_nome']) ?></strong><br>
                                                <small style="color: #64748b;"><?= htmlspecialchars($solicitacao['freelancer_cpf']) ?></small>
                                            <?php else: ?>
                                                <strong><?= htmlspecialchars($solicitacao['fornecedor_nome']) ?></strong><br>
                                                <?php if ($solicitacao['fornecedor_cnpj']): ?>
                                                    <small style="color: #64748b;"><?= htmlspecialchars($solicitacao['fornecedor_cnpj']) ?></small>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="smile-badge <?= $solicitacao['beneficiario_tipo'] === 'freelancer' ? 'smile-badge-info' : 'smile-badge-warning' ?>">
                                                <?= $solicitacao['beneficiario_tipo'] === 'freelancer' ? 'Freelancer' : 'Fornecedor' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong>R$ <?= number_format($solicitacao['valor'], 2, ',', '.') ?></strong>
                                        </td>
                                        <td>
                                            <?= date('d/m/Y H:i', strtotime($solicitacao['criado_em'])) ?>
                                        </td>
                                        <td>
                                            <span class="smile-badge <?= getStatusClass($solicitacao['status']) ?>">
                                                <?= getStatusText($solicitacao['status']) ?>
                                            </span>
                                            <?php if ($solicitacao['status'] === 'suspenso' && $solicitacao['motivo_suspensao']): ?>
                                                <br><small style="color: #dc2626;"><?= htmlspecialchars($solicitacao['motivo_suspensao']) ?></small>
                                            <?php endif; ?>
                                            <?php if ($solicitacao['status'] === 'recusado' && $solicitacao['motivo_recusa']): ?>
                                                <br><small style="color: #dc2626;"><?= htmlspecialchars($solicitacao['motivo_recusa']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($solicitacao['status_atualizado_em']): ?>
                                                <?= date('d/m/Y H:i', strtotime($solicitacao['status_atualizado_em'])) ?>
                                                <?php if ($solicitacao['atualizador_nome']): ?>
                                                    <br><small style="color: #64748b;">por <?= htmlspecialchars($solicitacao['atualizador_nome']) ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="table-actions">
                                                <a href="pagamentos_ver.php?id=<?= $solicitacao['id'] ?>" 
                                                   class="action-btn view">👁️ Ver</a>
                                                
                                                <?php if (in_array($solicitacao['status'], ['aguardando', 'suspenso'])): ?>
                                                    <a href="pagamentos_editar.php?id=<?= $solicitacao['id'] ?>" 
                                                       class="action-btn edit">✏️ Editar</a>
                                                <?php endif; ?>
                                                
                                                <?php if ($solicitacao['status'] === 'suspenso'): ?>
                                                    <a href="pagamentos_reenviar.php?id=<?= $solicitacao['id'] ?>" 
                                                       class="action-btn resend">🔄 Reenviar</a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Ações -->
                <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 30px;">
                    <a href="pagamentos_solicitar.php" class="smile-btn smile-btn-primary">
                        ➕ Nova Solicitação
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
