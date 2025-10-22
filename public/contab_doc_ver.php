<?php
// contab_doc_ver.php
// Detalhes do documento contábil

session_start();
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
if (!in_array($perfil, ['ADM', 'FIN', 'GERENTE', 'CONSULTA'])) {
    header('Location: dashboard.php?erro=permissao_negada');
    exit;
}

$documento_id = $_GET['id'] ?? null;
if (!$documento_id) {
    header('Location: contab_documentos.php?erro=documento_nao_encontrado');
    exit;
}

// Buscar dados do documento
$documento = null;
try {
    $stmt = $pdo->prepare("
        SELECT d.*, u.nome as criado_por_nome
        FROM contab_documentos d
        LEFT JOIN usuarios u ON u.id = d.criado_por
        WHERE d.id = ?
    ");
    $stmt->execute([$documento_id]);
    $documento = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$documento) {
        header('Location: contab_documentos.php?erro=documento_nao_encontrado');
        exit;
    }
} catch (Exception $e) {
    $erro = "Erro ao buscar documento: " . $e->getMessage();
}

// Buscar parcelas
$parcelas = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.*, u.nome as pago_por_nome
        FROM contab_parcelas p
        LEFT JOIN usuarios u ON u.id = p.pago_por
        WHERE p.documento_id = ?
        ORDER BY p.numero_parcela
    ");
    $stmt->execute([$documento_id]);
    $parcelas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignorar erro
}

// Buscar anexos
$anexos = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, u.nome as autor_nome
        FROM contab_anexos a
        LEFT JOIN usuarios u ON u.id = a.autor_id
        WHERE a.documento_id = ?
        ORDER BY a.criado_em DESC
    ");
    $stmt->execute([$documento_id]);
    $anexos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignorar erro
}

// Processar ações
$sucesso = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    try {
        switch ($acao) {
            case 'marcar_pago':
                $parcela_id = $_POST['parcela_id'] ?? null;
                $data_pagamento = $_POST['data_pagamento'] ?? date('Y-m-d');
                $observacao = $_POST['observacao'] ?? '';
                
                if ($parcela_id) {
                    $stmt = $pdo->prepare("
                        UPDATE contab_parcelas 
                        SET status = 'pago', 
                            data_pagamento = ?, 
                            observacao_pagamento = ?, 
                            pago_por = ?, 
                            pago_em = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$data_pagamento, $observacao, $_SESSION['usuario_id'], $parcela_id]);
                    $sucesso = "Parcela marcada como paga com sucesso!";
                }
                break;
                
            case 'suspender':
                $parcela_id = $_POST['parcela_id'] ?? null;
                $motivo = $_POST['motivo'] ?? '';
                
                if ($parcela_id && $motivo) {
                    $stmt = $pdo->prepare("
                        UPDATE contab_parcelas 
                        SET status = 'suspenso', 
                            motivo_suspensao = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$motivo, $parcela_id]);
                    $sucesso = "Parcela suspensa com sucesso!";
                }
                break;
                
            case 'recusar':
                $parcela_id = $_POST['parcela_id'] ?? null;
                $motivo = $_POST['motivo'] ?? '';
                
                if ($parcela_id && $motivo) {
                    $stmt = $pdo->prepare("
                        UPDATE contab_parcelas 
                        SET status = 'recusado', 
                            motivo_suspensao = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$motivo, $parcela_id]);
                    $sucesso = "Parcela recusada com sucesso!";
                }
                break;
        }
        
        // Recarregar dados após ação
        if ($sucesso) {
            header("Location: contab_doc_ver.php?id=$documento_id&sucesso=" . urlencode($sucesso));
            exit;
        }
        
    } catch (Exception $e) {
        $erro = "Erro ao processar ação: " . $e->getMessage();
    }
}

// Tab ativa
$tab_ativa = $_GET['tab'] ?? 'resumo';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documento - <?= htmlspecialchars($documento['descricao']) ?></title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .contab-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .contab-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border-radius: 12px;
        }
        
        .contab-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
        }
        
        .contab-actions {
            display: flex;
            gap: 10px;
        }
        
        .tabs-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .tabs-header {
            display: flex;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .tab-button {
            flex: 1;
            padding: 15px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: #64748b;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }
        
        .tab-button:hover {
            color: #1e40af;
            background: #f8fafc;
        }
        
        .tab-button.active {
            color: #1e40af;
            border-bottom-color: #1e40af;
            background: #f8fafc;
        }
        
        .tab-content {
            padding: 30px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
        }
        
        .info-section {
            background: #f8fafc;
            border-radius: 8px;
            padding: 20px;
        }
        
        .info-title {
            font-size: 16px;
            font-weight: 600;
            color: #1e40af;
            margin: 0 0 15px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 500;
            color: #374151;
        }
        
        .info-value {
            color: #64748b;
            text-align: right;
        }
        
        .parcelas-list {
            display: grid;
            gap: 15px;
        }
        
        .parcela-item {
            background: #f8fafc;
            border-radius: 8px;
            padding: 15px;
            border: 1px solid #e5e7eb;
        }
        
        .parcela-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .parcela-numero {
            font-weight: 600;
            color: #1e40af;
        }
        
        .parcela-valor {
            font-weight: 600;
            color: #059669;
        }
        
        .parcela-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-pendente {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-pago {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-vencido {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .status-suspenso {
            background: #f3f4f6;
            color: #374151;
        }
        
        .status-recusado {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .parcela-meta {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 10px;
        }
        
        .parcela-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .anexos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .anexo-item {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .anexo-item:hover {
            border-color: #1e40af;
            transform: translateY(-2px);
        }
        
        .anexo-icon {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .anexo-nome {
            font-weight: 500;
            color: #374151;
            margin-bottom: 5px;
        }
        
        .anexo-meta {
            font-size: 12px;
            color: #64748b;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #64748b;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 20px;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e40af;
        }
        
        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: #000;
        }
    </style>
</head>
<body>
    <div class="contab-container">
        <!-- Header -->
        <div class="contab-header">
            <h1 class="contab-title">📄 <?= htmlspecialchars($documento['descricao']) ?></h1>
            <div class="contab-actions">
                <a href="contab_documentos.php" class="smile-btn smile-btn-outline">← Voltar</a>
                <?php if (in_array($perfil, ['ADM', 'FIN'])): ?>
                <a href="contab_doc_ver.php?id=<?= $documento['id'] ?>&acao=editar" class="smile-btn smile-btn-primary">✏️ Editar</a>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Mensagens -->
        <?php if ($sucesso): ?>
        <div class="smile-alert smile-alert-success">
            <strong>✅ Sucesso!</strong> <?= htmlspecialchars($sucesso) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($erro): ?>
        <div class="smile-alert smile-alert-danger">
            <strong>❌ Erro!</strong> <?= htmlspecialchars($erro) ?>
        </div>
        <?php endif; ?>
        
        <!-- Tabs -->
        <div class="tabs-container">
            <div class="tabs-header">
                <button class="tab-button <?= $tab_ativa === 'resumo' ? 'active' : '' ?>" 
                        onclick="showTab('resumo')">📋 Resumo</button>
                <button class="tab-button <?= $tab_ativa === 'parcelas' ? 'active' : '' ?>" 
                        onclick="showTab('parcelas')">💰 Parcelas</button>
                <button class="tab-button <?= $tab_ativa === 'anexos' ? 'active' : '' ?>" 
                        onclick="showTab('anexos')">📎 Anexos</button>
            </div>
            
            <!-- Tab Resumo -->
            <div id="tab-resumo" class="tab-content" style="<?= $tab_ativa !== 'resumo' ? 'display: none;' : '' ?>">
                <div class="info-grid">
                    <div class="info-section">
                        <h3 class="info-title">📄 Dados do Documento</h3>
                        <div class="info-item">
                            <span class="info-label">Descrição</span>
                            <span class="info-value"><?= htmlspecialchars($documento['descricao']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Tipo</span>
                            <span class="info-value"><?= ucfirst($documento['tipo']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Competência</span>
                            <span class="info-value"><?= $documento['competencia'] ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Origem</span>
                            <span class="info-value"><?= ucfirst($documento['origem']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Fornecedor Sugerido</span>
                            <span class="info-value"><?= htmlspecialchars($documento['fornecedor_sugerido'] ?? 'Não informado') ?></span>
                        </div>
                    </div>
                    
                    <div class="info-section">
                        <h3 class="info-title">📊 Estatísticas</h3>
                        <div class="info-item">
                            <span class="info-label">Total de Parcelas</span>
                            <span class="info-value"><?= count($parcelas) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Parcelas Pagas</span>
                            <span class="info-value">
                                <?= count(array_filter($parcelas, fn($p) => $p['status'] === 'pago')) ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Parcelas Pendentes</span>
                            <span class="info-value">
                                <?= count(array_filter($parcelas, fn($p) => $p['status'] === 'pendente')) ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Valor Total</span>
                            <span class="info-value">
                                R$ <?= number_format(array_sum(array_column($parcelas, 'valor')), 2, ',', '.') ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Criado em</span>
                            <span class="info-value"><?= date('d/m/Y H:i', strtotime($documento['criado_em'])) ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tab Parcelas -->
            <div id="tab-parcelas" class="tab-content" style="<?= $tab_ativa !== 'parcelas' ? 'display: none;' : '' ?>">
                <?php if (!empty($parcelas)): ?>
                <div class="parcelas-list">
                    <?php foreach ($parcelas as $parcela): ?>
                    <div class="parcela-item">
                        <div class="parcela-header">
                            <div class="parcela-numero">Parcela <?= $parcela['numero_parcela'] ?>/<?= $parcela['total_parcelas'] ?></div>
                            <div class="parcela-valor">R$ <?= number_format($parcela['valor'], 2, ',', '.') ?></div>
                            <span class="parcela-status status-<?= $parcela['status'] ?>">
                                <?= ucfirst($parcela['status']) ?>
                            </span>
                        </div>
                        <div class="parcela-meta">
                            Vencimento: <?= date('d/m/Y', strtotime($parcela['vencimento'])) ?>
                            <?php if ($parcela['linha_digitavel']): ?>
                            • Linha: <?= substr($parcela['linha_digitavel'], 0, 20) ?>...
                            <?php endif; ?>
                            <?php if ($parcela['data_pagamento']): ?>
                            • Pago em: <?= date('d/m/Y', strtotime($parcela['data_pagamento'])) ?>
                            <?php endif; ?>
                            <?php if ($parcela['pago_por_nome']): ?>
                            • Por: <?= htmlspecialchars($parcela['pago_por_nome']) ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($parcela['observacao_pagamento']): ?>
                        <div class="parcela-meta">
                            <strong>Obs:</strong> <?= htmlspecialchars($parcela['observacao_pagamento']) ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($parcela['motivo_suspensao']): ?>
                        <div class="parcela-meta">
                            <strong>Motivo:</strong> <?= htmlspecialchars($parcela['motivo_suspensao']) ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (in_array($perfil, ['ADM', 'FIN']) && $parcela['status'] === 'pendente'): ?>
                        <div class="parcela-actions">
                            <button onclick="abrirModal('pagar', <?= $parcela['id'] ?>)" 
                                    class="smile-btn smile-btn-sm smile-btn-success">
                                ✅ Marcar Pago
                            </button>
                            <button onclick="abrirModal('suspender', <?= $parcela['id'] ?>)" 
                                    class="smile-btn smile-btn-sm smile-btn-warning">
                                ⏸️ Suspender
                            </button>
                            <button onclick="abrirModal('recusar', <?= $parcela['id'] ?>)" 
                                    class="smile-btn smile-btn-sm smile-btn-danger">
                                ❌ Recusar
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">💰</div>
                    <div style="font-size: 18px; font-weight: 600; margin-bottom: 10px;">Nenhuma parcela encontrada</div>
                    <div>Este documento ainda não possui parcelas cadastradas.</div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Tab Anexos -->
            <div id="tab-anexos" class="tab-content" style="<?= $tab_ativa !== 'anexos' ? 'display: none;' : '' ?>">
                <?php if (!empty($anexos)): ?>
                <div class="anexos-grid">
                    <?php foreach ($anexos as $anexo): ?>
                    <div class="anexo-item">
                        <div class="anexo-icon">
                            <?php
                            $ext = strtolower(pathinfo($anexo['nome_original'], PATHINFO_EXTENSION));
                            if (in_array($ext, ['pdf'])) echo '📄';
                            elseif (in_array($ext, ['jpg', 'jpeg', 'png'])) echo '🖼️';
                            else echo '📎';
                            ?>
                        </div>
                        <div class="anexo-nome"><?= htmlspecialchars($anexo['nome_original']) ?></div>
                        <div class="anexo-meta">
                            <?= $anexo['tipo_anexo'] ?> • 
                            <?= number_format($anexo['tamanho_bytes'] / 1024, 1) ?> KB
                        </div>
                        <div style="margin-top: 10px;">
                            <a href="download_anexo.php?tipo=contab&id=<?= $anexo['id'] ?>" 
                               class="smile-btn smile-btn-sm smile-btn-primary">
                                📥 Baixar
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📎</div>
                    <div style="font-size: 18px; font-weight: 600; margin-bottom: 10px;">Nenhum anexo encontrado</div>
                    <div>Este documento ainda não possui anexos.</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal para ações -->
    <div id="modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modal-title">Ação</h3>
                <span class="close" onclick="fecharModal()">&times;</span>
            </div>
            <form method="POST" id="modal-form">
                <input type="hidden" name="acao" id="modal-acao">
                <input type="hidden" name="parcela_id" id="modal-parcela-id">
                
                <div id="modal-content">
                    <!-- Conteúdo será inserido aqui -->
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" onclick="fecharModal()" class="smile-btn smile-btn-outline">Cancelar</button>
                    <button type="submit" class="smile-btn smile-btn-primary">Confirmar</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function showTab(tabName) {
            // Esconder todas as tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.style.display = 'none';
            });
            
            // Remover classe active de todos os botões
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Mostrar tab selecionada
            document.getElementById('tab-' + tabName).style.display = 'block';
            
            // Adicionar classe active ao botão
            event.target.classList.add('active');
            
            // Atualizar URL
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
        }
        
        function abrirModal(acao, parcelaId) {
            const modal = document.getElementById('modal');
            const modalTitle = document.getElementById('modal-title');
            const modalAcao = document.getElementById('modal-acao');
            const modalParcelaId = document.getElementById('modal-parcela-id');
            const modalContent = document.getElementById('modal-content');
            
            modalParcelaId.value = parcelaId;
            modalAcao.value = acao;
            
            switch (acao) {
                case 'pagar':
                    modalTitle.textContent = 'Marcar como Pago';
                    modalContent.innerHTML = `
                        <div class="form-group">
                            <label class="form-label">Data do Pagamento</label>
                            <input type="date" name="data_pagamento" value="${new Date().toISOString().split('T')[0]}" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Observação</label>
                            <textarea name="observacao" class="form-input" rows="3" placeholder="Observações sobre o pagamento..."></textarea>
                        </div>
                    `;
                    break;
                    
                case 'suspender':
                    modalTitle.textContent = 'Suspender Parcela';
                    modalContent.innerHTML = `
                        <div class="form-group">
                            <label class="form-label">Motivo da Suspensão</label>
                            <textarea name="motivo" class="form-input" rows="3" placeholder="Informe o motivo da suspensão..." required></textarea>
                        </div>
                    `;
                    break;
                    
                case 'recusar':
                    modalTitle.textContent = 'Recusar Parcela';
                    modalContent.innerHTML = `
                        <div class="form-group">
                            <label class="form-label">Motivo da Recusa</label>
                            <textarea name="motivo" class="form-input" rows="3" placeholder="Informe o motivo da recusa..." required></textarea>
                        </div>
                    `;
                    break;
            }
            
            modal.style.display = 'block';
        }
        
        function fecharModal() {
            document.getElementById('modal').style.display = 'none';
        }
        
        // Fechar modal ao clicar fora
        window.onclick = function(event) {
            const modal = document.getElementById('modal');
            if (event.target === modal) {
                fecharModal();
            }
        }
    </script>
</body>
</html>
