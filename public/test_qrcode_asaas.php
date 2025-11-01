<?php
// test_qrcode_asaas.php — Teste rápido de QR Code estático Asaas
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Verificar autenticação - usar mesmo padrão que outras páginas de teste
if (empty($_SESSION['logado']) || ($_SESSION['logado'] ?? 0) != 1) {
    header('Location: index.php?page=login');
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/asaas_helper.php';

$qr_code_image = null;
$qr_code_id = null;
$error = null;
$success = null;

if (isset($_POST['gerar_qrcode'])) {
    try {
        require_once __DIR__ . '/config_env.php';
        $pix_address_key = $_ENV['ASAAS_PIX_ADDRESS_KEY'] ?? ASAAS_PIX_ADDRESS_KEY ?? '';
        
        if (empty($pix_address_key)) {
            throw new Exception("Chave PIX não configurada. Configure ASAAS_PIX_ADDRESS_KEY no Railway.");
        }
        
        $valor = (float)($_POST['valor'] ?? 1.00);
        
        // Criar QR Code estático
        $expiration_date = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        $qr_code_data = [
            'addressKey' => $pix_address_key,
            'description' => 'Teste QR Code - R$ ' . number_format($valor, 2, ',', '.'),
            'value' => $valor,
            'expirationDate' => $expiration_date,
            'format' => 'IMAGE',
            'allowsMultiplePayments' => true
        ];
        
        $asaasHelper = new AsaasHelper();
        $qr_code_response = $asaasHelper->createStaticQrCode($qr_code_data);
        
        if ($qr_code_response && isset($qr_code_response['id'])) {
            $qr_code_id = $qr_code_response['id'];
            $qr_code_image = $qr_code_response['encodedImage'] 
                ?? $qr_code_response['payload'] 
                ?? $qr_code_response['image']
                ?? '';
            
            $success = "QR Code gerado com sucesso! ID: $qr_code_id";
        } else {
            throw new Exception("Resposta inválida do Asaas");
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste QR Code Asaas</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .box {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        h1 {
            color: #1e40af;
            margin-bottom: 10px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        input[type="number"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 16px;
        }
        button {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            font-weight: 600;
        }
        button:hover {
            background: #2563eb;
        }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #10b981;
        }
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #ef4444;
        }
        .qr-code-container {
            text-align: center;
            padding: 30px;
            background: #f9fafb;
            border-radius: 12px;
            border: 2px solid #3b82f6;
        }
        .qr-code-container img {
            max-width: 300px;
            width: 100%;
            height: auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
        }
        .info {
            margin-top: 20px;
            padding: 15px;
            background: #eff6ff;
            border-left: 4px solid #3b82f6;
            border-radius: 4px;
        }
        code {
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>
    <div class="box">
        <h1>🧪 Teste QR Code Asaas</h1>
        <p>Gere um QR Code estático para testar pagamento PIX</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <strong>❌ Erro:</strong> <?= h($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <strong>✅ Sucesso:</strong> <?= h($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($qr_code_image): ?>
        <div class="box">
            <div class="qr-code-container">
                <h2 style="color: #1e40af; margin-bottom: 20px;">💰 Pague com PIX</h2>
                <p style="color: #6b7280; margin-bottom: 15px;">
                    Escaneie o QR Code abaixo com o app do seu banco
                </p>
                
                <div style="margin: 20px 0;">
                    <img src="data:image/png;base64,<?= $qr_code_image ?>" 
                         alt="QR Code PIX" 
                         style="max-width: 300px; width: 100%; height: auto;">
                </div>
                
                <div style="margin-top: 20px; padding: 15px; background: #f0f9ff; border-radius: 8px;">
                    <p style="margin: 0; font-size: 24px; font-weight: 700; color: #1e40af;">
                        R$ <?= number_format($_POST['valor'] ?? 1.00, 2, ',', '.') ?>
                    </p>
                </div>
                
                <div class="info" style="margin-top: 20px; text-align: left;">
                    <p style="margin: 5px 0;"><strong>QR Code ID:</strong> <code><?= h($qr_code_id) ?></code></p>
                    <p style="margin: 5px 0;"><strong>Valor:</strong> R$ <?= number_format($_POST['valor'] ?? 1.00, 2, ',', '.') ?></p>
                    <p style="margin: 5px 0;"><strong>Expira em:</strong> <?= date('d/m/Y H:i:s', strtotime('+1 hour')) ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="box">
        <form method="POST">
            <div class="form-group">
                <label for="valor">Valor (R$)</label>
                <input type="number" 
                       id="valor" 
                       name="valor" 
                       value="1.00" 
                       step="0.01" 
                       min="0.01" 
                       required>
            </div>
            
            <button type="submit" name="gerar_qrcode">
                🎯 Gerar QR Code de Teste
            </button>
        </form>
        
        <div class="info" style="margin-top: 20px;">
            <p><strong>ℹ️ Instruções:</strong></p>
            <ol style="margin: 10px 0; padding-left: 20px;">
                <li>Digite o valor desejado (padrão: R$ 1,00)</li>
                <li>Clique em "Gerar QR Code de Teste"</li>
                <li>Escaneie o QR Code com seu app de banco</li>
                <li>Realize o pagamento</li>
                <li>Verifique os logs do webhook para confirmar o recebimento</li>
            </ol>
        </div>
    </div>

    <div class="box">
        <h2>📋 Verificar Webhook</h2>
        <p>Acesse os logs para ver se o webhook foi recebido:</p>
        <p>
            <a href="index.php?page=asaas_webhook_logs" style="color: #3b82f6; text-decoration: none;">
                📊 Ver Logs do Webhook →
            </a>
        </p>
        <p>
            <a href="index.php?page=verificar_webhook_qrcode" style="color: #3b82f6; text-decoration: none;">
                🔍 Verificar Status por QR Code ID →
            </a>
        </p>
    </div>
</body>
</html>

