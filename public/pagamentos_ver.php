<?php
// pagamentos_ver.php
// Detalhes da solicitação de pagamento

session_start();
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
if (!in_array($perfil, ['ADM', 'FIN', 'GERENTE', 'CONSULTA'])) {
    header('Location: dashboard.php?erro=permissao_negada');
    exit;
}

$solicitacao_id = intval($_GET['id'] ?? 0);
if (!$solicitacao_id) {
    header('Location: pagamentos_minhas.php?erro=solicitacao_nao_encontrada');
    exit;
}

$sucesso = $_GET['sucesso'] ?? null;
$erro = $_GET['erro'] ?? null;

// Buscar solicitação
$sql = "
    SELECT 
        s.*,
        -- Dados do freelancer
        f.nome_completo as freelancer_nome,
        f.cpf as freelancer_cpf,
        -- Dados do fornecedor
        fo.nome as fornecedor_nome,
        fo.cnpj as fornecedor_cnpj,
        fo.telefone as fornecedor_telefone,
        fo.email as fornecedor_email,
        -- Dados do criador
        u.nome as criador_nome,
        -- Dados do atualizador
        u2.nome as atualizador_nome
    FROM lc_solicitacoes_pagamento s
    LEFT JOIN lc_freelancers f ON f.id = s.freelancer_id
    LEFT JOIN fornecedores fo ON fo.id = s.fornecedor_id
    LEFT JOIN usuarios u ON u.id = s.criador_id
    LEFT JOIN usuarios u2 ON u2.id = s.status_atualizado_por
    WHERE s.id = ?
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$solicitacao_id]);
    $solicitacao = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$solicitacao) {
        header('Location: pagamentos_minhas.php?erro=solicitacao_nao_encontrada');
        exit;
    }
    
    // Verificar se o usuário pode ver esta solicitação
    if (!in_array($perfil, ['ADM', 'FIN']) && $solicitacao['criador_id'] != ($_SESSION['usuario_id'] ?? 0)) {
        header('Location: pagamentos_minhas.php?erro=permissao_negada');
        exit;
    }
    
} catch (Exception $e) {
    $erro = "Erro ao carregar solicitação: " . $e->getMessage();
    $solicitacao = null;
}

// Buscar timeline
$timeline = [];
if ($solicitacao) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                t.*,
                u.nome as autor_nome
            FROM lc_timeline_pagamentos t
            LEFT JOIN usuarios u ON u.id = t.autor_id
            WHERE t.solicitacao_id = ?
            ORDER BY t.criado_em ASC
        ");
        $stmt->execute([$solicitacao_id]);
        $timeline = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Timeline pode não existir ainda
    }
}

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($perfil, ['ADM', 'FIN'])) {
    try {
        $acao = $_POST['acao'] ?? '';
        $motivo = trim($_POST['motivo'] ?? '');
        $data_pagamento = $_POST['data_pagamento'] ?? null;
        $observacao_pagamento = trim($_POST['observacao_pagamento'] ?? '');
        $comentario = trim($_POST['comentario'] ?? '');
        
        if (empty($acao)) {
            throw new Exception('Ação não especificada');
        }
        
        if (in_array($acao, ['suspender', 'recusar']) && empty($motivo)) {
            throw new Exception('Motivo é obrigatório para esta ação');
        }
        
        if ($acao === 'pagar' && empty($data_pagamento)) {
            throw new Exception('Data de pagamento é obrigatória');
        }
        
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
        
        // Adicionar comentário se fornecido
        if ($comentario) {
            $stmt = $pdo->prepare("
                INSERT INTO lc_timeline_pagamentos (solicitacao_id, autor_id, tipo_evento, mensagem)
                VALUES (?, ?, 'comentario', ?)
            ");
            $stmt->execute([$solicitacao_id, $_SESSION['usuario_id'] ?? null, $comentario]);
        }
        
        header('Location: pagamentos_ver.php?id=' . $solicitacao_id . '&sucesso=acao_executada');
        exit;
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
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

// Função para obter ícone do tipo de evento
function getEventIcon($tipo) {
    switch ($tipo) {
        case 'criacao': return '🆕';
        case 'status_change': return '🔄';
        case 'comentario': return '💬';
        default: return '📝';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitação #<?= $solicitacao_id ?> - Sistema Smile</title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .detail-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .detail-section.full-width {
            grid-column: 1 / -1;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            font-weight: 600;
            color: #374151;
        }
        
        .detail-value {
            color: #64748b;
            text-align: right;
        }
        
        .timeline {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .timeline-item {
            display: flex;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .timeline-item:last-child {
            border-bottom: none;
        }
        
        .timeline-icon {
            font-size: 20px;
            margin-top: 2px;
        }
        
        .timeline-content {
            flex: 1;
        }
        
        .timeline-message {
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .timeline-meta {
            font-size: 14px;
            color: #64748b;
        }
        
        .actions-section {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .action-form {
            display: none;
            margin-top: 20px;
            padding: 20px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        
        .action-form.active {
            display: block;
        }
    </style>
</head>
<body>
    <div class="smile-container">
        <div class="smile-card">
            <div class="smile-card-header">
                <h1>💰 Solicitação #<?= $solicitacao_id ?></h1>
                <p>Detalhes da solicitação de pagamento</p>
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
                
                <?php if ($solicitacao): ?>
                    <!-- Detalhes da Solicitação -->
                    <div class="detail-grid">
                        <div class="detail-section">
                            <h3>📋 Informações Básicas</h3>
                            
                            <div class="detail-row">
                                <span class="detail-label">Status</span>
                                <span class="detail-value">
                                    <span class="smile-badge <?= getStatusClass($solicitacao['status']) ?>">
                                        <?= getStatusText($solicitacao['status']) ?>
                                    </span>
                                </span>
                            </div>
                            
                            <div class="detail-row">
                                <span class="detail-label">Valor</span>
                                <span class="detail-value">
                                    <strong>R$ <?= number_format($solicitacao['valor'], 2, ',', '.') ?></strong>
                                </span>
                            </div>
                            
                            <div class="detail-row">
                                <span class="detail-label">Tipo</span>
                                <span class="detail-value">
                                    <span class="smile-badge <?= $solicitacao['beneficiario_tipo'] === 'freelancer' ? 'smile-badge-info' : 'smile-badge-warning' ?>">
                                        <?= $solicitacao['beneficiario_tipo'] === 'freelancer' ? 'Freelancer' : 'Fornecedor' ?>
                                    </span>
                                </span>
                            </div>
                            
                            <div class="detail-row">
                                <span class="detail-label">Criado em</span>
                                <span class="detail-value">
                                    <?= date('d/m/Y H:i', strtotime($solicitacao['criado_em'])) ?>
                                </span>
                            </div>
                            
                            <?php if ($solicitacao['data_desejada']): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Data Desejada</span>
                                    <span class="detail-value">
                                        <?= date('d/m/Y', strtotime($solicitacao['data_desejada'])) ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($solicitacao['data_pagamento']): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Data do Pagamento</span>
                                    <span class="detail-value">
                                        <?= date('d/m/Y', strtotime($solicitacao['data_pagamento'])) ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="detail-section">
                            <h3>👤 Beneficiário</h3>
                            
                            <?php if ($solicitacao['beneficiario_tipo'] === 'freelancer'): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Nome</span>
                                    <span class="detail-value">
                                        <strong><?= htmlspecialchars($solicitacao['freelancer_nome']) ?></strong>
                                    </span>
                                </div>
                                
                                <div class="detail-row">
                                    <span class="detail-label">CPF</span>
                                    <span class="detail-value">
                                        <?= htmlspecialchars($solicitacao['freelancer_cpf']) ?>
                                    </span>
                                </div>
                            <?php else: ?>
                                <div class="detail-row">
                                    <span class="detail-label">Nome</span>
                                    <span class="detail-value">
                                        <strong><?= htmlspecialchars($solicitacao['fornecedor_nome']) ?></strong>
                                    </span>
                                </div>
                                
                                <?php if ($solicitacao['fornecedor_cnpj']): ?>
                                    <div class="detail-row">
                                        <span class="detail-label">CNPJ</span>
                                        <span class="detail-value">
                                            <?= htmlspecialchars($solicitacao['fornecedor_cnpj']) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($solicitacao['fornecedor_telefone']): ?>
                                    <div class="detail-row">
                                        <span class="detail-label">Telefone</span>
                                        <span class="detail-value">
                                            <?= htmlspecialchars($solicitacao['fornecedor_telefone']) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($solicitacao['fornecedor_email']): ?>
                                    <div class="detail-row">
                                        <span class="detail-label">E-mail</span>
                                        <span class="detail-value">
                                            <?= htmlspecialchars($solicitacao['fornecedor_email']) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <div class="detail-row">
                                <span class="detail-label">PIX</span>
                                <span class="detail-value">
                                    <strong><?= strtoupper($solicitacao['pix_tipo']) ?>:</strong> 
                                    <?= htmlspecialchars($solicitacao['pix_chave']) ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php if ($solicitacao['observacoes']): ?>
                            <div class="detail-section full-width">
                                <h3>📝 Observações</h3>
                                <p style="color: #64748b; line-height: 1.6;"><?= nl2br(htmlspecialchars($solicitacao['observacoes'])) ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($solicitacao['motivo_suspensao'] || $solicitacao['motivo_recusa']): ?>
                            <div class="detail-section full-width">
                                <h3>⚠️ Motivo</h3>
                                <p style="color: #dc2626; line-height: 1.6;">
                                    <?= htmlspecialchars($solicitacao['motivo_suspensao'] ?: $solicitacao['motivo_recusa']) ?>
                                </p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($solicitacao['observacao_pagamento']): ?>
                            <div class="detail-section full-width">
                                <h3>💰 Observação do Pagamento</h3>
                                <p style="color: #64748b; line-height: 1.6;"><?= nl2br(htmlspecialchars($solicitacao['observacao_pagamento'])) ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Ações (apenas para ADM/FIN) -->
                    <?php if (in_array($perfil, ['ADM', 'FIN'])): ?>
                        <div class="actions-section">
                            <h3>⚡ Ações</h3>
                            
                            <div class="action-buttons">
                                <?php if ($solicitacao['status'] === 'aguardando'): ?>
                                    <button onclick="showActionForm('aprovar')" class="smile-btn smile-btn-success">
                                        ✅ Aprovar
                                    </button>
                                    <button onclick="showActionForm('suspender')" class="smile-btn smile-btn-warning">
                                        ⚠️ Suspender
                                    </button>
                                    <button onclick="showActionForm('recusar')" class="smile-btn smile-btn-danger">
                                        ❌ Recusar
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($solicitacao['status'] === 'aprovado'): ?>
                                    <button onclick="showActionForm('pagar')" class="smile-btn" style="background: #8b5cf6; color: white;">
                                        💰 Marcar como Pago
                                    </button>
                                <?php endif; ?>
                                
                                <button onclick="showActionForm('comentario')" class="smile-btn smile-btn-secondary">
                                    💬 Adicionar Comentário
                                </button>
                            </div>
                            
                            <!-- Formulários de Ação -->
                            <form method="POST" action="" id="action-form" style="display: none;">
                                <input type="hidden" name="acao" id="action-type">
                                
                                <div id="motivo-section" style="display: none;">
                                    <div class="smile-form-group">
                                        <label for="motivo">Motivo *</label>
                                        <textarea name="motivo" id="motivo" rows="3" class="smile-form-control" 
                                                  placeholder="Digite o motivo da suspensão ou recusa..."></textarea>
                                    </div>
                                </div>
                                
                                <div id="pagamento-section" style="display: none;">
                                    <div class="smile-form-group">
                                        <label for="data_pagamento">Data do Pagamento *</label>
                                        <input type="date" name="data_pagamento" id="data_pagamento" class="smile-form-control">
                                    </div>
                                    <div class="smile-form-group">
                                        <label for="observacao_pagamento">Observação do Pagamento</label>
                                        <textarea name="observacao_pagamento" id="observacao_pagamento" rows="3" class="smile-form-control" 
                                                  placeholder="Comprovante, referência, etc..."></textarea>
                                    </div>
                                </div>
                                
                                <div id="comentario-section" style="display: none;">
                                    <div class="smile-form-group">
                                        <label for="comentario">Comentário</label>
                                        <textarea name="comentario" id="comentario" rows="3" class="smile-form-control" 
                                                  placeholder="Adicione um comentário à timeline..."></textarea>
                                    </div>
                                </div>
                                
                                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                                    <button type="button" onclick="hideActionForm()" class="smile-btn smile-btn-secondary">
                                        Cancelar
                                    </button>
                                    <button type="submit" class="smile-btn smile-btn-primary">
                                        Executar Ação
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Timeline -->
                    <div class="timeline">
                        <h3>📅 Timeline</h3>
                        
                        <?php if (empty($timeline)): ?>
                            <p style="color: #64748b; text-align: center; padding: 20px;">
                                Nenhum evento registrado
                            </p>
                        <?php else: ?>
                            <?php foreach ($timeline as $evento): ?>
                                <div class="timeline-item">
                                    <div class="timeline-icon">
                                        <?= getEventIcon($evento['tipo_evento']) ?>
                                    </div>
                                    <div class="timeline-content">
                                        <div class="timeline-message">
                                            <?= htmlspecialchars($evento['mensagem']) ?>
                                        </div>
                                        <div class="timeline-meta">
                                            <?= date('d/m/Y H:i', strtotime($evento['criado_em'])) ?>
                                            <?php if ($evento['autor_nome']): ?>
                                                • por <?= htmlspecialchars($evento['autor_nome']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                <?php else: ?>
                    <div class="smile-alert smile-alert-danger">
                        ❌ Solicitação não encontrada
                    </div>
                <?php endif; ?>
                
                <!-- Navegação -->
                <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 30px;">
                    <a href="pagamentos_minhas.php" class="smile-btn smile-btn-secondary">
                        ← Voltar para Minhas Solicitações
                    </a>
                    <?php if (in_array($perfil, ['ADM', 'FIN'])): ?>
                        <a href="pagamentos_painel.php" class="smile-btn smile-btn-primary">
                            📊 Painel Financeiro
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showActionForm(acao) {
            const form = document.getElementById('action-form');
            const actionType = document.getElementById('action-type');
            const motivoSection = document.getElementById('motivo-section');
            const pagamentoSection = document.getElementById('pagamento-section');
            const comentarioSection = document.getElementById('comentario-section');
            
            // Esconder todas as seções
            motivoSection.style.display = 'none';
            pagamentoSection.style.display = 'none';
            comentarioSection.style.display = 'none';
            
            // Mostrar seções relevantes
            if (['suspender', 'recusar'].includes(acao)) {
                motivoSection.style.display = 'block';
            }
            
            if (acao === 'pagar') {
                pagamentoSection.style.display = 'block';
            }
            
            if (acao === 'comentario') {
                comentarioSection.style.display = 'block';
            }
            
            actionType.value = acao;
            form.style.display = 'block';
        }
        
        function hideActionForm() {
            document.getElementById('action-form').style.display = 'none';
        }
        
        // Definir data padrão para hoje
        document.getElementById('data_pagamento').value = new Date().toISOString().split('T')[0];
    </script>
</body>
</html>
