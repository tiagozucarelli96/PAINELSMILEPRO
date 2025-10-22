<?php
// fornecedor_link.php
// Portal público do fornecedor para solicitar pagamentos

session_start();
require_once __DIR__ . '/conexao.php';

$token = $_GET['t'] ?? '';
$sucesso = $_GET['sucesso'] ?? null;
$erro = $_GET['erro'] ?? null;

// Buscar fornecedor pelo token
$fornecedor = null;
if ($token) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, nome, cnpj, telefone, email, pix_tipo, pix_chave, ativo, token_publico
            FROM fornecedores 
            WHERE token_publico = ? AND ativo = true
        ");
        $stmt->execute([$token]);
        $fornecedor = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$fornecedor) {
            $erro = 'Link inválido ou fornecedor desativado';
        }
    } catch (Exception $e) {
        $erro = 'Erro ao validar link';
    }
} else {
    $erro = 'Token não fornecido';
}

// Processar formulário de solicitação
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $fornecedor) {
    try {
        $valor = floatval($_POST['valor'] ?? 0);
        $data_desejada = $_POST['data_desejada'] ?: null;
        $observacoes = trim($_POST['observacoes'] ?? '');
        
        // Validações
        if ($valor <= 0) {
            throw new Exception('Valor deve ser maior que zero');
        }
        
        if ($valor > 50000) {
            throw new Exception('Valor muito alto. Entre em contato conosco.');
        }
        
        // Rate limiting simples (máximo 5 solicitações por hora por IP)
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM lc_solicitacoes_pagamento 
            WHERE ip_origem = ? AND criado_em > NOW() - INTERVAL '1 hour'
        ");
        $stmt->execute([$ip]);
        $solicitacoes_recentes = $stmt->fetchColumn();
        
        if ($solicitacoes_recentes >= 5) {
            throw new Exception('Limite de solicitações atingido. Tente novamente mais tarde.');
        }
        
        // Criar solicitação
        $stmt = $pdo->prepare("
            INSERT INTO lc_solicitacoes_pagamento 
            (beneficiario_tipo, fornecedor_id, valor, data_desejada, observacoes, 
             pix_tipo, pix_chave, status, origem, ip_origem, user_agent)
            VALUES ('fornecedor', ?, ?, ?, ?, ?, ?, 'aguardando', 'fornecedor_link', ?, ?)
        ");
        
        $stmt->execute([
            $fornecedor['id'],
            $valor,
            $data_desejada,
            $observacoes,
            $fornecedor['pix_tipo'],
            $fornecedor['pix_chave'],
            $ip,
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        $solicitacao_id = $pdo->lastInsertId();
        
        // Criar evento na timeline
        $stmt = $pdo->prepare("
            INSERT INTO lc_timeline_pagamentos (solicitacao_id, tipo_evento, mensagem)
            VALUES (?, 'criacao', 'Solicitação criada via portal do fornecedor')
        ");
        $stmt->execute([$solicitacao_id]);
        
        header('Location: fornecedor_link.php?t=' . urlencode($token) . '&sucesso=solicitacao_enviada&id=' . $solicitacao_id);
        exit;
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Função para mascarar PIX
function maskPix($pix_chave, $pix_tipo) {
    if (!$pix_chave) return '-';
    
    switch ($pix_tipo) {
        case 'cpf':
        case 'cnpj':
            return substr($pix_chave, 0, 3) . '***' . substr($pix_chave, -2);
        case 'email':
            $parts = explode('@', $pix_chave);
            return substr($parts[0], 0, 2) . '***@' . $parts[1];
        case 'celular':
            return substr($pix_chave, 0, 4) . '****' . substr($pix_chave, -2);
        default:
            return substr($pix_chave, 0, 8) . '***' . substr($pix_chave, -4);
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitar Pagamento - <?= htmlspecialchars($fornecedor['nome'] ?? 'Fornecedor') ?></title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .portal-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .portal-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .portal-logo {
            font-size: 48px;
            margin-bottom: 10px;
        }
        
        .portal-title {
            font-size: 28px;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 10px;
        }
        
        .portal-subtitle {
            color: #64748b;
            font-size: 16px;
        }
        
        .form-section {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .fornecedor-info {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #374151;
        }
        
        .info-value {
            color: #64748b;
        }
        
        .success-message {
            background: #d1fae5;
            color: #065f46;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #6ee7b7;
        }
        
        .error-message {
            background: #fee2e2;
            color: #991b1b;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #f87171;
        }
        
        .contact-info {
            background: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .contact-info h4 {
            color: #1e40af;
            margin-bottom: 10px;
        }
        
        .contact-info p {
            color: #64748b;
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <div class="smile-container">
        <div class="portal-container">
            <!-- Header -->
            <div class="portal-header">
                <div class="portal-logo">💰</div>
                <h1 class="portal-title">Solicitar Pagamento</h1>
                <p class="portal-subtitle">Portal do Fornecedor</p>
            </div>
            
            <!-- Mensagens -->
            <?php if ($sucesso): ?>
                <div class="success-message">
                    <h3>✅ Solicitação Enviada com Sucesso!</h3>
                    <p>Sua solicitação foi recebida e está sendo analisada pelo nosso time financeiro.</p>
                    <p><strong>Número da solicitação:</strong> #<?= htmlspecialchars($_GET['id'] ?? 'N/A') ?></p>
                    <p>Você será notificado sobre o status da sua solicitação através do canal combinado.</p>
                </div>
            <?php endif; ?>
            
            <?php if ($erro): ?>
                <div class="error-message">
                    <h3>❌ Erro</h3>
                    <p><?= htmlspecialchars($erro) ?></p>
                </div>
            <?php endif; ?>
            
            <?php if ($fornecedor): ?>
                <!-- Informações do Fornecedor -->
                <div class="fornecedor-info">
                    <h3>🏢 <?= htmlspecialchars($fornecedor['nome']) ?></h3>
                    
                    <div class="info-row">
                        <span class="info-label">CNPJ:</span>
                        <span class="info-value"><?= htmlspecialchars($fornecedor['cnpj'] ?: '-') ?></span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">PIX:</span>
                        <span class="info-value">
                            <strong><?= strtoupper($fornecedor['pix_tipo']) ?>:</strong> 
                            <?= maskPix($fornecedor['pix_chave'], $fornecedor['pix_tipo']) ?>
                        </span>
                    </div>
                    
                    <?php if ($fornecedor['telefone']): ?>
                        <div class="info-row">
                            <span class="info-label">Telefone:</span>
                            <span class="info-value"><?= htmlspecialchars($fornecedor['telefone']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Formulário de Solicitação -->
                <div class="form-section">
                    <h3>📝 Nova Solicitação de Pagamento</h3>
                    
                    <form method="POST" action="">
                        <div class="smile-form-group">
                            <label for="valor">Valor (R$) *</label>
                            <input type="number" name="valor" id="valor" step="0.01" min="0.01" max="50000" 
                                   class="smile-form-control" required
                                   placeholder="0,00">
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
                                      placeholder="Descreva o motivo do pagamento, referência, entrega, etc."></textarea>
                        </div>
                        
                        <div style="display: flex; gap: 15px; justify-content: center; margin-top: 30px;">
                            <button type="submit" class="smile-btn smile-btn-primary" style="padding: 15px 30px; font-size: 16px;">
                                💰 Enviar Solicitação
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Informações de Contato -->
                <div class="contact-info">
                    <h4>📞 Precisa de Ajuda?</h4>
                    <p>Entre em contato com nosso time financeiro:</p>
                    <p><strong>E-mail:</strong> financeiro@smileeventos.com.br</p>
                    <p><strong>Telefone:</strong> (11) 99999-9999</p>
                    <p><strong>Horário:</strong> Segunda a Sexta, 9h às 18h</p>
                </div>
                
            <?php else: ?>
                <!-- Link Inválido -->
                <div class="form-section">
                    <div style="text-align: center; padding: 40px;">
                        <div style="font-size: 48px; margin-bottom: 20px;">🔒</div>
                        <h3>Link Inválido</h3>
                        <p style="color: #64748b; margin-bottom: 20px;">
                            Este link não é válido ou o fornecedor foi desativado.
                        </p>
                        <p style="color: #64748b;">
                            Entre em contato conosco para obter um novo link.
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Definir data mínima como hoje
        document.getElementById('data_desejada').min = new Date().toISOString().split('T')[0];
        
        // Formatação de valor em tempo real
        document.getElementById('valor').addEventListener('input', function(e) {
            let value = e.target.value;
            if (value && parseFloat(value) > 50000) {
                e.target.style.borderColor = '#dc2626';
                e.target.style.backgroundColor = '#fef2f2';
            } else {
                e.target.style.borderColor = '';
                e.target.style.backgroundColor = '';
            }
        });
        
        // Validação do formulário
        document.querySelector('form').addEventListener('submit', function(e) {
            const valor = parseFloat(document.getElementById('valor').value);
            
            if (valor <= 0) {
                e.preventDefault();
                alert('Valor deve ser maior que zero');
                return;
            }
            
            if (valor > 50000) {
                e.preventDefault();
                alert('Valor muito alto. Entre em contato conosco.');
                return;
            }
            
            if (!confirm('Confirma o envio da solicitação de pagamento?')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>
