<?php
// comercial_pagamento.php — Página de pagamento PIX
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/asaas_helper.php';

$payment_id = $_GET['payment_id'] ?? '';
$inscricao_id = (int)($_GET['inscricao_id'] ?? 0);

if (!$payment_id || !$inscricao_id) {
    die('Parâmetros inválidos');
}

// Buscar dados da inscrição
$stmt = $pdo->prepare("
    SELECT i.*, d.nome as degustacao_nome, d.data as degustacao_data, d.local as degustacao_local
    FROM comercial_inscricoes i
    LEFT JOIN comercial_degustacoes d ON d.id = i.event_id
    WHERE i.id = :inscricao_id
");
$stmt->execute([':inscricao_id' => $inscricao_id]);
$inscricao = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$inscricao) {
    die('Inscrição não encontrada');
}

// Buscar dados do pagamento no ASAAS
$asaasHelper = new AsaasHelper();
$payment_data = null;
$qr_code = null;
$pix_copy_paste = null;

try {
    $payment_data = $asaasHelper->getPaymentStatus($payment_id);
    
    if ($payment_data && $payment_data['status'] === 'PENDING') {
        $qr_data = $asaasHelper->getPixQrCode($payment_id);
        $qr_code = $qr_data['encodedImage'] ?? null;
        $pix_copy_paste = $qr_data['payload'] ?? null;
    }
} catch (Exception $e) {
    $error_message = "Erro ao buscar dados do pagamento: " . $e->getMessage();
}

// Verificar se pagamento foi confirmado
if ($payment_data && $payment_data['status'] === 'CONFIRMED') {
    // Atualizar status da inscrição
    $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET pagamento_status = 'pago' WHERE id = :id");
    $stmt->execute([':id' => $inscricao_id]);
    
    // Redirecionar para página de sucesso
    header("Location: comercial_sucesso.php?inscricao_id={$inscricao_id}");
    exit;
}


?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamento - GRUPO Smile EVENTOS</title>
    <link rel="stylesheet" href="estilo.css">
    <style>
        .pagamento-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 40px;
            padding: 30px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border-radius: 12px;
        }
        
        .header h1 {
            font-size: 28px;
            margin: 0 0 10px 0;
        }
        
        .header p {
            font-size: 16px;
            opacity: 0.9;
            margin: 0;
        }
        
        .event-info {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .event-title {
            font-size: 20px;
            font-weight: 600;
            color: #1e3a8a;
            margin: 0 0 15px 0;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            padding: 5px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .info-label {
            font-weight: 600;
            color: #6b7280;
        }
        
        .info-value {
            color: #374151;
        }
        
        .payment-section {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 30px;
            text-align: center;
        }
        
        .payment-title {
            font-size: 24px;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0 0 20px 0;
        }
        
        .payment-value {
            font-size: 32px;
            font-weight: 700;
            color: #10b981;
            margin: 0 0 30px 0;
        }
        
        .qr-code-container {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .qr-code {
            max-width: 200px;
            margin: 0 auto 20px auto;
        }
        
        .pix-instructions {
            background: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .pix-instructions h3 {
            color: #0c4a6e;
            margin: 0 0 15px 0;
        }
        
        .pix-steps {
            text-align: left;
            color: #0c4a6e;
        }
        
        .pix-steps li {
            margin-bottom: 8px;
        }
        
        .copy-paste-section {
            margin: 20px 0;
        }
        
        .copy-paste-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 10px;
        }
        
        .copy-paste-input {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-family: monospace;
            font-size: 12px;
            background: #f8fafc;
        }
        
        .copy-btn {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
        }
        
        .copy-btn:hover {
            background: #2563eb;
        }
        
        .status-check {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            text-align: center;
        }
        
        .status-check p {
            margin: 0;
            color: #92400e;
        }
        
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3b82f6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
    </style>
</head>
<body>
    <div class="pagamento-container">
        <!-- Header -->
        <div class="header">
            <h1>💳 Pagamento PIX</h1>
            <p>Finalize sua inscrição na degustação</p>
        </div>
        
        <!-- Informações do Evento -->
        <div class="event-info">
            <h2 class="event-title"><?= h($inscricao['degustacao_nome']) ?></h2>
            <div class="info-row">
                <span class="info-label">📅 Data:</span>
                <span class="info-value"><?= date('d/m/Y', strtotime($inscricao['degustacao_data'])) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">📍 Local:</span>
                <span class="info-value"><?= h($inscricao['degustacao_local']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">👤 Participante:</span>
                <span class="info-value"><?= h($inscricao['nome']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">🎉 Tipo de Festa:</span>
                <span class="info-value"><?= ucfirst($inscricao['tipo_festa']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">👥 Pessoas:</span>
                <span class="info-value"><?= $inscricao['qtd_pessoas'] ?> pessoas</span>
            </div>
        </div>
        
        <!-- Mensagens de Erro -->
        <?php if (isset($error_message)): ?>
            <div class="alert alert-error">
                ❌ <?= h($error_message) ?>
            </div>
        <?php endif; ?>
        
        <!-- Seção de Pagamento -->
        <div class="payment-section">
            <h2 class="payment-title">💰 Valor a Pagar</h2>
            <div class="payment-value">R$ <?= number_format($inscricao['valor_pago'], 2, ',', '.') ?></div>
            
            <?php if ($qr_code): ?>
                <!-- QR Code PIX -->
                <div class="qr-code-container">
                    <h3 style="margin: 0 0 15px 0; color: #374151;">📱 Escaneie o QR Code</h3>
                    <img src="data:image/png;base64,<?= $qr_code ?>" alt="QR Code PIX" class="qr-code">
                </div>
                
                <!-- Instruções PIX -->
                <div class="pix-instructions">
                    <h3>📋 Como pagar com PIX:</h3>
                    <ol class="pix-steps">
                        <li>Abra o app do seu banco</li>
                        <li>Escaneie o QR Code acima</li>
                        <li>Confirme os dados e finalize o pagamento</li>
                        <li>Você receberá a confirmação por e-mail</li>
                    </ol>
                </div>
                
                <!-- Copy & Paste PIX -->
                <?php if ($pix_copy_paste): ?>
                <div class="copy-paste-section">
                    <label class="copy-paste-label">📋 Ou copie o código PIX:</label>
                    <textarea class="copy-paste-input" readonly onclick="this.select()"><?= h($pix_copy_paste) ?></textarea>
                    <button class="copy-btn" onclick="copyPixCode()">📋 Copiar Código PIX</button>
                </div>
                <?php endif; ?>
                
            <?php else: ?>
                <div class="status-check">
                    <p>⏳ Aguardando geração do QR Code...</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Verificação de Status -->
        <div class="status-check">
            <p><span class="loading"></span>Verificando status do pagamento...</p>
        </div>
    </div>
    
    <script>
        function copyPixCode() {
            const textarea = document.querySelector('.copy-paste-input');
            textarea.select();
            document.execCommand('copy');
            
            const btn = document.querySelector('.copy-btn');
            const originalText = btn.textContent;
            btn.textContent = '✅ Copiado!';
            btn.style.background = '#10b981';
            
            setTimeout(() => {
                btn.textContent = originalText;
                btn.style.background = '#3b82f6';
            }, 2000);
        }
        
        // Verificar status do pagamento a cada 10 segundos
        function checkPaymentStatus() {
            fetch(`comercial_check_payment.php?payment_id=<?= $payment_id ?>&inscricao_id=<?= $inscricao_id ?>`)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'CONFIRMED' || data.status === 'RECEIVED') {
                        window.location.href = `comercial_sucesso.php?inscricao_id=<?= $inscricao_id ?>`;
                    }
                })
                .catch(error => {
                    console.log('Erro ao verificar status:', error);
                });
        }
        
        // Verificar status a cada 10 segundos
        setInterval(checkPaymentStatus, 10000);
        
        // Verificar imediatamente
        checkPaymentStatus();
    </script>
    
    <!-- Custom Modals CSS -->
    <link rel="stylesheet" href="assets/css/custom_modals.css">
    <!-- Custom Modals JS -->
    <script src="assets/js/custom_modals.js"></script>
</body>
</html>
