<?php
// pagamentos_solicitar.php
// Página para solicitar pagamentos (Gerentes)

session_start();
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
if (!in_array($perfil, ['ADM', 'FIN', 'GERENTE'])) {
    header('Location: dashboard.php?erro=permissao_negada');
    exit;
}

$sucesso = $_GET['sucesso'] ?? null;
$erro = $_GET['erro'] ?? null;

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $beneficiario_tipo = $_POST['beneficiario_tipo'] ?? '';
        $valor = floatval($_POST['valor'] ?? 0);
        $data_desejada = $_POST['data_desejada'] ?: null;
        $observacoes = trim($_POST['observacoes'] ?? '');
        
        // Validações básicas
        if (empty($beneficiario_tipo) || !in_array($beneficiario_tipo, ['freelancer', 'fornecedor'])) {
            throw new Exception('Tipo de beneficiário inválido');
        }
        
        if ($valor <= 0) {
            throw new Exception('Valor deve ser maior que zero');
        }
        
        $freelancer_id = null;
        $fornecedor_id = null;
        $pix_tipo = null;
        $pix_chave = null;
        
        if ($beneficiario_tipo === 'freelancer') {
            $freelancer_id = intval($_POST['freelancer_id'] ?? 0);
            if ($freelancer_id <= 0) {
                throw new Exception('Selecione um freelancer');
            }
            
            // Buscar dados do freelancer
            $stmt = $pdo->prepare("SELECT pix_tipo, pix_chave FROM lc_freelancers WHERE id = ? AND ativo = true");
            $stmt->execute([$freelancer_id]);
            $freelancer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$freelancer) {
                throw new Exception('Freelancer não encontrado');
            }
            
            $pix_tipo = $freelancer['pix_tipo'];
            $pix_chave = $freelancer['pix_chave'];
            
        } elseif ($beneficiario_tipo === 'fornecedor') {
            $fornecedor_id = intval($_POST['fornecedor_id'] ?? 0);
            if ($fornecedor_id <= 0) {
                throw new Exception('Selecione um fornecedor');
            }
            
            // Buscar dados do fornecedor
            $stmt = $pdo->prepare("SELECT pix_tipo, pix_chave FROM fornecedores WHERE id = ? AND ativo = true");
            $stmt->execute([$fornecedor_id]);
            $fornecedor = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$fornecedor) {
                throw new Exception('Fornecedor não encontrado');
            }
            
            $pix_tipo = $fornecedor['pix_tipo'];
            $pix_chave = $fornecedor['pix_chave'];
        }
        
        // Criar solicitação
        $stmt = $pdo->prepare("
            INSERT INTO lc_solicitacoes_pagamento 
            (criador_id, beneficiario_tipo, freelancer_id, fornecedor_id, valor, data_desejada, observacoes, pix_tipo, pix_chave)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $_SESSION['usuario_id'] ?? null,
            $beneficiario_tipo,
            $freelancer_id,
            $fornecedor_id,
            $valor,
            $data_desejada,
            $observacoes,
            $pix_tipo,
            $pix_chave
        ]);
        
        $solicitacao_id = $pdo->lastInsertId();
        
        // Criar evento na timeline
        $stmt = $pdo->prepare("
            INSERT INTO lc_timeline_pagamentos (solicitacao_id, autor_id, tipo_evento, mensagem)
            VALUES (?, ?, 'criacao', 'Solicitação criada')
        ");
        $stmt->execute([$solicitacao_id, $_SESSION['usuario_id'] ?? null]);
        
        header('Location: pagamentos_minhas.php?sucesso=solicitacao_criada&id=' . $solicitacao_id);
        exit;
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Buscar freelancers ativos
$freelancers = [];
try {
    $stmt = $pdo->query("SELECT id, nome_completo, cpf, pix_tipo, pix_chave FROM lc_freelancers WHERE ativo = true ORDER BY nome_completo");
    $freelancers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabela pode não existir ainda
}

// Buscar fornecedores ativos
$fornecedores = [];
try {
    $stmt = $pdo->query("SELECT id, nome, cnpj, pix_tipo, pix_chave FROM fornecedores WHERE ativo = true ORDER BY nome");
    $fornecedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabela pode não existir ainda
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitar Pagamento - Sistema Smile</title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .form-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .form-section {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .beneficiario-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
        }
        
        .tab-button {
            flex: 1;
            padding: 15px;
            border: 2px solid #e1e5e9;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .tab-button.active {
            border-color: #1e40af;
            background: #1e40af;
            color: white;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .pix-info {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
            font-size: 14px;
        }
        
        .pix-info strong {
            color: #1e40af;
        }
    </style>
</head>
<body>
    <div class="smile-container">
        <div class="smile-card">
            <div class="smile-card-header">
                <h1>💰 Solicitar Pagamento</h1>
                <p>Crie uma nova solicitação de pagamento para freelancer ou fornecedor</p>
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
                
                <form method="POST" action="">
                    <div class="form-section">
                        <h3>👤 Tipo de Beneficiário</h3>
                        <div class="beneficiario-tabs">
                            <div class="tab-button active" data-tab="freelancer">
                                👨‍💼 Freelancer
                            </div>
                            <div class="tab-button" data-tab="fornecedor">
                                🏢 Fornecedor
                            </div>
                        </div>
                        
                        <input type="hidden" name="beneficiario_tipo" id="beneficiario_tipo" value="freelancer">
                        
                        <!-- Freelancer Tab -->
                        <div class="tab-content active" id="freelancer-tab">
                            <div class="smile-form-group">
                                <label for="freelancer_id">Freelancer *</label>
                                <select name="freelancer_id" id="freelancer_id" class="smile-form-control" required>
                                    <option value="">Selecione um freelancer</option>
                                    <?php foreach ($freelancers as $freelancer): ?>
                                        <option value="<?= $freelancer['id'] ?>" 
                                                data-pix-tipo="<?= htmlspecialchars($freelancer['pix_tipo']) ?>"
                                                data-pix-chave="<?= htmlspecialchars($freelancer['pix_chave']) ?>">
                                            <?= htmlspecialchars($freelancer['nome_completo']) ?> 
                                            (<?= htmlspecialchars($freelancer['cpf']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="pix-info" id="freelancer-pix-info" style="display: none;">
                                    <strong>PIX:</strong> <span id="freelancer-pix-details"></span>
                                </div>
                            </div>
                            
                            <div style="text-align: center; margin: 20px 0;">
                                <a href="freelancer_cadastro.php" class="smile-btn smile-btn-secondary">
                                    ➕ Novo Freelancer
                                </a>
                            </div>
                        </div>
                        
                        <!-- Fornecedor Tab -->
                        <div class="tab-content" id="fornecedor-tab">
                            <div class="smile-form-group">
                                <label for="fornecedor_id">Fornecedor *</label>
                                <select name="fornecedor_id" id="fornecedor_id" class="smile-form-control">
                                    <option value="">Selecione um fornecedor</option>
                                    <?php foreach ($fornecedores as $fornecedor): ?>
                                        <option value="<?= $fornecedor['id'] ?>" 
                                                data-pix-tipo="<?= htmlspecialchars($fornecedor['pix_tipo']) ?>"
                                                data-pix-chave="<?= htmlspecialchars($fornecedor['pix_chave']) ?>">
                                            <?= htmlspecialchars($fornecedor['nome']) ?> 
                                            <?php if ($fornecedor['cnpj']): ?>
                                                (<?= htmlspecialchars($fornecedor['cnpj']) ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="pix-info" id="fornecedor-pix-info" style="display: none;">
                                    <strong>PIX:</strong> <span id="fornecedor-pix-details"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3>💰 Dados do Pagamento</h3>
                        
                        <div class="smile-form-group">
                            <label for="valor">Valor (R$) *</label>
                            <input type="number" name="valor" id="valor" step="0.01" min="0.01" 
                                   class="smile-form-control" required>
                        </div>
                        
                        <div class="smile-form-group">
                            <label for="data_desejada">Data Desejada (opcional)</label>
                            <input type="date" name="data_desejada" id="data_desejada" 
                                   class="smile-form-control">
                        </div>
                        
                        <div class="smile-form-group">
                            <label for="observacoes">Observações</label>
                            <textarea name="observacoes" id="observacoes" rows="4" 
                                      class="smile-form-control" 
                                      placeholder="Descreva o motivo do pagamento, referência, etc."></textarea>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 30px;">
                        <a href="pagamentos_minhas.php" class="smile-btn smile-btn-secondary">
                            Cancelar
                        </a>
                        <button type="submit" class="smile-btn smile-btn-primary">
                            💰 Enviar Solicitação
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Tabs functionality
        document.querySelectorAll('.tab-button').forEach(button => {
            button.addEventListener('click', function() {
                const tab = this.dataset.tab;
                
                // Update active tab button
                document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                // Update active tab content
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                document.getElementById(tab + '-tab').classList.add('active');
                
                // Update hidden input
                document.getElementById('beneficiario_tipo').value = tab;
                
                // Clear selections
                document.getElementById('freelancer_id').value = '';
                document.getElementById('fornecedor_id').value = '';
                document.getElementById('freelancer-pix-info').style.display = 'none';
                document.getElementById('fornecedor-pix-info').style.display = 'none';
            });
        });
        
        // Freelancer selection
        document.getElementById('freelancer_id').addEventListener('change', function() {
            const option = this.options[this.selectedIndex];
            const pixInfo = document.getElementById('freelancer-pix-info');
            const pixDetails = document.getElementById('freelancer-pix-details');
            
            if (option.value && option.dataset.pixTipo && option.dataset.pixChave) {
                pixDetails.textContent = `${option.dataset.pixTipo.toUpperCase()}: ${option.dataset.pixChave}`;
                pixInfo.style.display = 'block';
            } else {
                pixInfo.style.display = 'none';
            }
        });
        
        // Fornecedor selection
        document.getElementById('fornecedor_id').addEventListener('change', function() {
            const option = this.options[this.selectedIndex];
            const pixInfo = document.getElementById('fornecedor-pix-info');
            const pixDetails = document.getElementById('fornecedor-pix-details');
            
            if (option.value && option.dataset.pixTipo && option.dataset.pixChave) {
                pixDetails.textContent = `${option.dataset.pixTipo.toUpperCase()}: ${option.dataset.pixChave}`;
                pixInfo.style.display = 'block';
            } else {
                pixInfo.style.display = 'none';
            }
        });
        
        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const beneficiarioTipo = document.getElementById('beneficiario_tipo').value;
            const freelancerId = document.getElementById('freelancer_id').value;
            const fornecedorId = document.getElementById('fornecedor_id').value;
            
            if (beneficiarioTipo === 'freelancer' && !freelancerId) {
                e.preventDefault();
                alert('Selecione um freelancer');
                return;
            }
            
            if (beneficiarioTipo === 'fornecedor' && !fornecedorId) {
                e.preventDefault();
                alert('Selecione um fornecedor');
                return;
            }
        });
    </script>
</body>
</html>
