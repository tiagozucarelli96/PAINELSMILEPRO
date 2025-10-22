<?php
// pagamentos_painel.php
// Painel financeiro para ADM/Financeiro

session_start();
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
if (!in_array($perfil, ['ADM', 'FIN'])) {
    header('Location: dashboard.php?erro=permissao_negada');
    exit;
}

$sucesso = $_GET['sucesso'] ?? null;
$erro = $_GET['erro'] ?? null;

// Processar ações em massa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_massa'])) {
    try {
        $acao = $_POST['acao_massa'];
        $solicitacoes_ids = $_POST['solicitacoes_ids'] ?? [];
        $motivo = trim($_POST['motivo'] ?? '');
        $data_pagamento = $_POST['data_pagamento'] ?? null;
        $observacao_pagamento = trim($_POST['observacao_pagamento'] ?? '');
        
        if (empty($solicitacoes_ids)) {
            throw new Exception('Selecione pelo menos uma solicitação');
        }
        
        if (in_array($acao, ['suspender', 'recusar']) && empty($motivo)) {
            throw new Exception('Motivo é obrigatório para esta ação');
        }
        
        if ($acao === 'pagar' && empty($data_pagamento)) {
            throw new Exception('Data de pagamento é obrigatória');
        }
        
        $sucesso_count = 0;
        $erro_count = 0;
        
        foreach ($solicitacoes_ids as $solicitacao_id) {
            try {
                // Atualizar status
                $stmt = $pdo->prepare("
                    UPDATE lc_solicitacoes_pagamento 
                    SET status = ?, 
                        status_atualizado_por = ?, 
                        status_atualizado_em = NOW(),
                        motivo_suspensao = CASE WHEN ? = 'suspenso' THEN ? ELSE motivo_suspensao END,
                        motivo_recusa = CASE WHEN ? = 'recusado' THEN ? ELSE motivo_recusa END,
                        data_pagamento = CASE WHEN ? = 'pago' THEN ? ELSE data_pagamento END,
                        observacao_pagamento = CASE WHEN ? = 'pago' THEN ? ELSE observacao_pagamento END,
                        modificado_em = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    $acao === 'aprovar' ? 'aprovado' : $acao,
                    $_SESSION['usuario_id'] ?? null,
                    $acao, $motivo,
                    $acao, $motivo,
                    $acao, $data_pagamento,
                    $acao, $observacao_pagamento,
                    $solicitacao_id
                ]);
                
                // Criar evento na timeline
                $mensagem = "Status alterado para " . ucfirst($acao);
                if ($motivo) {
                    $mensagem .= ": " . $motivo;
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO lc_timeline_pagamentos (solicitacao_id, autor_id, tipo_evento, mensagem)
                    VALUES (?, ?, 'status_change', ?)
                ");
                $stmt->execute([$solicitacao_id, $_SESSION['usuario_id'] ?? null, $mensagem]);
                
                $sucesso_count++;
                
            } catch (Exception $e) {
                $erro_count++;
                error_log("Erro ao processar solicitação {$solicitacao_id}: " . $e->getMessage());
            }
        }
        
        if ($sucesso_count > 0) {
            $sucesso = "Ação executada com sucesso em {$sucesso_count} solicitação(ões)";
        }
        
        if ($erro_count > 0) {
            $erro = "Erro ao processar {$erro_count} solicitação(ões)";
        }
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Filtros
$status_filtro = $_GET['status'] ?? [];
$periodo_inicio = $_GET['periodo_inicio'] ?? '';
$periodo_fim = $_GET['periodo_fim'] ?? '';
$tipo_filtro = $_GET['tipo'] ?? '';
$busca = $_GET['busca'] ?? '';

// Construir query
$where_conditions = [];
$params = [];

// Filtro por status (múltiplos)
if (!empty($status_filtro) && is_array($status_filtro)) {
    $placeholders = str_repeat('?,', count($status_filtro) - 1) . '?';
    $where_conditions[] = "s.status IN ({$placeholders})";
    $params = array_merge($params, $status_filtro);
}

// Filtro por tipo
if ($tipo_filtro) {
    $where_conditions[] = "s.beneficiario_tipo = ?";
    $params[] = $tipo_filtro;
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
    LIMIT 100
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $solicitacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $erro = "Erro ao carregar solicitações: " . $e->getMessage();
    $solicitacoes = [];
}

// Estatísticas por status
$stats = [
    'aguardando' => 0,
    'aprovado' => 0,
    'suspenso' => 0,
    'recusado' => 0,
    'pago' => 0,
    'total' => 0,
    'valor_total' => 0
];

foreach ($solicitacoes as $solicitacao) {
    $stats[$solicitacao['status']]++;
    $stats['total']++;
    $stats['valor_total'] += $solicitacao['valor'];
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
    <title>Painel Financeiro - Sistema Smile</title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border-left: 4px solid;
        }
        
        .stat-card.aguardando { border-left-color: #f59e0b; }
        .stat-card.aprovado { border-left-color: #3b82f6; }
        .stat-card.suspenso { border-left-color: #dc2626; }
        .stat-card.recusado { border-left-color: #dc2626; }
        .stat-card.pago { border-left-color: #10b981; }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 16px;
            color: #64748b;
            margin-bottom: 10px;
        }
        
        .stat-value {
            font-size: 18px;
            font-weight: 600;
            color: #1e40af;
        }
        
        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .status-checkboxes {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .mass-actions {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .mass-actions.hidden {
            display: none;
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
        
        .action-btn.approve {
            background: #10b981;
            color: white;
        }
        
        .action-btn.suspend {
            background: #f59e0b;
            color: white;
        }
        
        .action-btn.reject {
            background: #dc2626;
            color: white;
        }
        
        .action-btn.pay {
            background: #8b5cf6;
            color: white;
        }
    </style>
</head>
<body>
    <div class="smile-container">
        <div class="smile-card">
            <div class="smile-card-header">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h1>💰 Painel Financeiro</h1>
                        <p>Gerencie todas as solicitações de pagamento do sistema</p>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <a href="fornecedores.php" class="smile-btn smile-btn-outline" style="display: flex; align-items: center; gap: 5px;">
                            <span>🏢</span> Fornecedores
                        </a>
                        <a href="freelancers.php" class="smile-btn smile-btn-outline" style="display: flex; align-items: center; gap: 5px;">
                            <span>👨‍💼</span> Freelancers
                        </a>
                        <a href="lc_index.php" class="smile-btn smile-btn-outline" style="display: flex; align-items: center; gap: 5px;">
                            <span>🏠</span> Voltar
                        </a>
                    </div>
                </div>
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
                    <div class="stat-card aguardando">
                        <div class="stat-number"><?= $stats['aguardando'] ?></div>
                        <div class="stat-label">Aguardando Análise</div>
                    </div>
                    <div class="stat-card aprovado">
                        <div class="stat-number"><?= $stats['aprovado'] ?></div>
                        <div class="stat-label">Aprovados</div>
                    </div>
                    <div class="stat-card suspenso">
                        <div class="stat-number"><?= $stats['suspenso'] ?></div>
                        <div class="stat-label">Suspensos</div>
                    </div>
                    <div class="stat-card recusado">
                        <div class="stat-number"><?= $stats['recusado'] ?></div>
                        <div class="stat-label">Recusados</div>
                    </div>
                    <div class="stat-card pago">
                        <div class="stat-number"><?= $stats['pago'] ?></div>
                        <div class="stat-label">Pagos</div>
                        <div class="stat-value">R$ <?= number_format($stats['valor_total'], 2, ',', '.') ?></div>
                    </div>
                </div>
                
                <!-- Filtros -->
                <div class="filters-section">
                    <h3>🔍 Filtros</h3>
                    
                    <form method="GET" action="">
                        <div class="filters-grid">
                            <div class="smile-form-group">
                                <label>Status</label>
                                <div class="status-checkboxes">
                                    <div class="checkbox-group">
                                        <input type="checkbox" name="status[]" value="aguardando" id="status_aguardando" 
                                               <?= in_array('aguardando', $status_filtro) ? 'checked' : '' ?>>
                                        <label for="status_aguardando">Aguardando</label>
                                    </div>
                                    <div class="checkbox-group">
                                        <input type="checkbox" name="status[]" value="aprovado" id="status_aprovado" 
                                               <?= in_array('aprovado', $status_filtro) ? 'checked' : '' ?>>
                                        <label for="status_aprovado">Aprovado</label>
                                    </div>
                                    <div class="checkbox-group">
                                        <input type="checkbox" name="status[]" value="suspenso" id="status_suspenso" 
                                               <?= in_array('suspenso', $status_filtro) ? 'checked' : '' ?>>
                                        <label for="status_suspenso">Suspenso</label>
                                    </div>
                                    <div class="checkbox-group">
                                        <input type="checkbox" name="status[]" value="recusado" id="status_recusado" 
                                               <?= in_array('recusado', $status_filtro) ? 'checked' : '' ?>>
                                        <label for="status_recusado">Recusado</label>
                                    </div>
                                    <div class="checkbox-group">
                                        <input type="checkbox" name="status[]" value="pago" id="status_pago" 
                                               <?= in_array('pago', $status_filtro) ? 'checked' : '' ?>>
                                        <label for="status_pago">Pago</label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="smile-form-group">
                                <label for="tipo">Tipo</label>
                                <select name="tipo" id="tipo" class="smile-form-control">
                                    <option value="">Todos</option>
                                    <option value="freelancer" <?= $tipo_filtro === 'freelancer' ? 'selected' : '' ?>>Freelancer</option>
                                    <option value="fornecedor" <?= $tipo_filtro === 'fornecedor' ? 'selected' : '' ?>>Fornecedor</option>
                                </select>
                            </div>
                            
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
                    </form>
                </div>
                
                <!-- Ações em Massa -->
                <div class="mass-actions" id="mass-actions" style="display: none;">
                    <h4>Ações em Massa</h4>
                    <form method="POST" action="" id="mass-form">
                        <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                            <button type="submit" name="acao_massa" value="aprovar" class="smile-btn smile-btn-success">
                                ✅ Aprovar Selecionados
                            </button>
                            <button type="submit" name="acao_massa" value="suspender" class="smile-btn smile-btn-warning">
                                ⚠️ Suspender Selecionados
                            </button>
                            <button type="submit" name="acao_massa" value="recusar" class="smile-btn smile-btn-danger">
                                ❌ Recusar Selecionados
                            </button>
                            <button type="submit" name="acao_massa" value="pagar" class="smile-btn" style="background: #8b5cf6; color: white;">
                                💰 Marcar como Pago
                            </button>
                        </div>
                        
                        <div class="smile-form-group" style="margin-top: 15px;">
                            <label for="motivo">Motivo (para Suspender/Recusar)</label>
                            <textarea name="motivo" id="motivo" rows="2" class="smile-form-control" 
                                      placeholder="Digite o motivo da suspensão ou recusa..."></textarea>
                        </div>
                        
                        <div class="smile-form-group">
                            <label for="data_pagamento">Data do Pagamento (para Marcar como Pago)</label>
                            <input type="date" name="data_pagamento" id="data_pagamento" class="smile-form-control">
                        </div>
                        
                        <div class="smile-form-group">
                            <label for="observacao_pagamento">Observação do Pagamento</label>
                            <textarea name="observacao_pagamento" id="observacao_pagamento" rows="2" class="smile-form-control" 
                                      placeholder="Comprovante, referência, etc..."></textarea>
                        </div>
                        
                        <input type="hidden" name="solicitacoes_ids" id="solicitacoes_ids">
                    </form>
                </div>
                
                <!-- Tabela de Solicitações -->
                <div style="background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <table class="smile-table">
                        <thead>
                            <tr>
                                <th style="width: 40px;">
                                    <input type="checkbox" id="select-all" onchange="toggleAll()">
                                </th>
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
                                    <td colspan="9" style="text-align: center; padding: 40px; color: #64748b;">
                                        Nenhuma solicitação encontrada
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($solicitacoes as $solicitacao): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="solicitacao-checkbox" 
                                                   value="<?= $solicitacao['id'] ?>" onchange="updateMassActions()">
                                        </td>
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
                                            <?php if ($solicitacao['origem'] === 'fornecedor_link'): ?>
                                                <br><span class="smile-badge smile-badge-info" style="font-size: 10px;">Fornecedor via link</span>
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
                                                
                                                <?php if ($solicitacao['status'] === 'aguardando'): ?>
                                                    <a href="pagamentos_ver.php?id=<?= $solicitacao['id'] ?>&acao=aprovar" 
                                                       class="action-btn approve">✅ Aprovar</a>
                                                    <a href="pagamentos_ver.php?id=<?= $solicitacao['id'] ?>&acao=suspender" 
                                                       class="action-btn suspend">⚠️ Suspender</a>
                                                    <a href="pagamentos_ver.php?id=<?= $solicitacao['id'] ?>&acao=recusar" 
                                                       class="action-btn reject">❌ Recusar</a>
                                                <?php endif; ?>
                                                
                                                <?php if ($solicitacao['status'] === 'aprovado'): ?>
                                                    <a href="pagamentos_ver.php?id=<?= $solicitacao['id'] ?>&acao=pagar" 
                                                       class="action-btn pay">💰 Pagar</a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleAll() {
            const selectAll = document.getElementById('select-all');
            const checkboxes = document.querySelectorAll('.solicitacao-checkbox');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            
            updateMassActions();
        }
        
        function updateMassActions() {
            const checkboxes = document.querySelectorAll('.solicitacao-checkbox:checked');
            const massActions = document.getElementById('mass-actions');
            const solicitacoesIds = document.getElementById('solicitacoes_ids');
            
            if (checkboxes.length > 0) {
                massActions.style.display = 'block';
                solicitacoesIds.value = Array.from(checkboxes).map(cb => cb.value).join(',');
            } else {
                massActions.style.display = 'none';
            }
        }
        
        // Confirmar ações em massa
        document.getElementById('mass-form').addEventListener('submit', function(e) {
            const acao = e.submitter.value;
            const count = document.querySelectorAll('.solicitacao-checkbox:checked').length;
            
            if (count === 0) {
                e.preventDefault();
                alert('Selecione pelo menos uma solicitação');
                return;
            }
            
            let mensagem = '';
            switch (acao) {
                case 'aprovar':
                    mensagem = `Aprovar ${count} solicitação(ões)?`;
                    break;
                case 'suspender':
                    mensagem = `Suspender ${count} solicitação(ões)?`;
                    break;
                case 'recusar':
                    mensagem = `Recusar ${count} solicitação(ões)?`;
                    break;
                case 'pagar':
                    mensagem = `Marcar como pago ${count} solicitação(ões)?`;
                    break;
            }
            
            if (!confirm(mensagem)) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>
