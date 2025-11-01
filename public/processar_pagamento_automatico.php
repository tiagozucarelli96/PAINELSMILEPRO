<?php
// processar_pagamento_automatico.php — Processar pagamento automaticamente via webhook
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (empty($_SESSION['logado']) || ($_SESSION['logado'] ?? 0) != 1) {
    header('Location: index.php?page=login');
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/asaas_helper.php';

$message = '';
$error = '';
$payment_data = null;
$inscricao_atualizada = false;

$payment_id = $_GET['payment_id'] ?? $_POST['payment_id'] ?? 'pay_gpil0rokyuggtzul';
$qr_code_id = $_GET['qr_code_id'] ?? $_POST['qr_code_id'] ?? '';

if (isset($_POST['processar'])) {
    $payment_id = trim($_POST['payment_id']);
    $qr_code_id = trim($_POST['qr_code_id'] ?? '');
    
    if (empty($payment_id)) {
        $error = "Payment ID é obrigatório";
    } else {
        try {
            // 1. Buscar dados do pagamento na API Asaas
            $asaas = new AsaasHelper();
            $payment_data = $asaas->getPaymentStatus($payment_id);
            
            if (!$payment_data || !isset($payment_data['id'])) {
                throw new Exception('Pagamento não encontrado na API Asaas');
            }
            
            // 2. Se não tiver QR Code ID, tentar pegar do pagamento
            if (empty($qr_code_id) && !empty($payment_data['pixQrCodeId'])) {
                $qr_code_id = $payment_data['pixQrCodeId'];
            }
            
            // 3. Verificar se já existe inscrição para este QR Code ou Payment ID
            $stmt = $pdo->prepare("
                SELECT * FROM comercial_inscricoes 
                WHERE asaas_payment_id = :payment_id 
                   OR (asaas_qr_code_id = :qr_code_id AND :qr_code_id != '')
                LIMIT 1
            ");
            $stmt->execute([
                ':payment_id' => $payment_id,
                ':qr_code_id' => $qr_code_id ?: ''
            ]);
            $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($inscricao) {
                // Atualizar inscrição
                $update_fields = [];
                $update_params = [':id' => $inscricao['id']];
                
                if (empty($inscricao['asaas_payment_id'])) {
                    $update_fields[] = 'asaas_payment_id = :payment_id';
                    $update_params[':payment_id'] = $payment_id;
                }
                
                if (!empty($qr_code_id) && empty($inscricao['asaas_qr_code_id'])) {
                    $update_fields[] = 'asaas_qr_code_id = :qr_code_id';
                    $update_params[':qr_code_id'] = $qr_code_id;
                }
                
                $update_fields[] = "pagamento_status = 'pago'";
                $update_fields[] = "atualizado_em = NOW()";
                
                if (!empty($update_fields)) {
                    $sql = 'UPDATE comercial_inscricoes SET ' . implode(', ', $update_fields) . ' WHERE id = :id';
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($update_params);
                    
                    $inscricao_atualizada = true;
                    $message = "✅ Inscrição ID {$inscricao['id']} atualizada para PAGO!";
                } else {
                    $message = "✅ Inscrição já estava atualizada";
                }
            } else {
                $error = "⚠️ Nenhuma inscrição encontrada para vincular. Payment ID: $payment_id" . 
                         ($qr_code_id ? " | QR Code ID: $qr_code_id" : "");
            }
            
        } catch (Exception $e) {
            $error = "Erro: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Processar Pagamento Automático</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; max-width: 1000px; margin: 0 auto; }
        .box { background: white; padding: 25px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h1 { color: #1e40af; margin-bottom: 10px; }
        .success { color: #059669; background: #d1fae5; padding: 15px; border-radius: 8px; margin: 10px 0; }
        .error { color: #dc2626; background: #fee2e2; padding: 15px; border-radius: 8px; margin: 10px 0; }
        .info { background: #eff6ff; padding: 15px; border-radius: 8px; border-left: 4px solid #3b82f6; margin: 10px 0; }
        label { display: block; font-weight: 600; margin-bottom: 8px; color: #374151; }
        input[type="text"] { width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; box-sizing: border-box; }
        button { background: #1e40af; color: white; padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600; }
        button:hover { background: #1e3a8a; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f9fafb; font-weight: 600; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="box">
        <h1>⚡ Processar Pagamento Automaticamente</h1>
        <p>Busca o pagamento na API Asaas e atualiza a inscrição automaticamente</p>
    </div>

    <?php if ($message): ?>
        <div class="box success"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="box error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="box">
        <h2>🔍 Processar Pagamento</h2>
        <form method="POST">
            <div style="margin-bottom: 15px;">
                <label>Payment ID (do Asaas): *</label>
                <input type="text" name="payment_id" placeholder="pay_xxxxx" value="<?= h($payment_id) ?>" required>
            </div>
            <div style="margin-bottom: 15px;">
                <label>QR Code ID (opcional - será buscado automaticamente se não informado):</label>
                <input type="text" name="qr_code_id" placeholder="GRPSMLEV..." value="<?= h($qr_code_id) ?>">
            </div>
            <button type="submit" name="processar">⚡ Processar e Atualizar Inscrição</button>
        </form>

        <?php if ($payment_data): ?>
            <div style="margin-top: 20px;">
                <h3>📋 Dados do Pagamento (API Asaas):</h3>
                <table>
                    <tr><th>Campo</th><th>Valor</th></tr>
                    <tr><td>Payment ID</td><td><code><?= h($payment_data['id'] ?? 'N/A') ?></code></td></tr>
                    <tr><td>Status</td><td><?= h($payment_data['status'] ?? 'N/A') ?></td></tr>
                    <tr><td>Valor</td><td>R$ <?= number_format($payment_data['value'] ?? 0, 2, ',', '.') ?></td></tr>
                    <tr><td>QR Code ID</td><td><code><?= h($payment_data['pixQrCodeId'] ?? 'N/A') ?></code></td></tr>
                    <tr><td>Data de Criação</td><td><?= h($payment_data['dateCreated'] ?? 'N/A') ?></td></tr>
                    <tr><td>Data de Confirmação</td><td><?= h($payment_data['confirmedDate'] ?? 'N/A') ?></td></tr>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="box">
        <h2>📋 Processar Pagamento de Teste (R$ 1,00)</h2>
        <div class="info">
            <p><strong>Payment ID:</strong> <code>pay_gpil0rokyuggtzul</code></p>
            <p><strong>QR Code ID:</strong> <code>GRPSMLEV00000543616716ASA</code></p>
            <form method="POST" style="margin-top: 15px;">
                <input type="hidden" name="payment_id" value="pay_gpil0rokyuggtzul">
                <input type="hidden" name="qr_code_id" value="GRPSMLEV00000543616716ASA">
                <button type="submit" name="processar">⚡ Processar Este Pagamento</button>
            </form>
        </div>
    </div>

    <div class="box">
        <h2>📋 Inscrições Recentes</h2>
        <?php
        $stmt = $pdo->query("
            SELECT id, nome, email, asaas_payment_id, asaas_qr_code_id, pagamento_status, criado_em
            FROM comercial_inscricoes
            ORDER BY criado_em DESC
            LIMIT 10
        ");
        $inscricoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($inscricoes)) {
            echo '<p>Nenhuma inscrição encontrada.</p>';
        } else {
            echo '<table>';
            echo '<thead><tr><th>ID</th><th>Nome</th><th>Payment ID</th><th>QR Code ID</th><th>Status</th><th>Ação</th></tr></thead>';
            echo '<tbody>';
            foreach ($inscricoes as $insc) {
                $status_class = $insc['pagamento_status'] === 'pago' ? 'success' : '';
                echo '<tr>';
                echo '<td>' . $insc['id'] . '</td>';
                echo '<td>' . h($insc['nome']) . '</td>';
                echo '<td><code>' . h($insc['asaas_payment_id'] ?? 'N/A') . '</code></td>';
                echo '<td><code>' . ($insc['asaas_qr_code_id'] ? substr(h($insc['asaas_qr_code_id']), 0, 25) . '...' : 'N/A') . '</code></td>';
                echo '<td><span class="' . $status_class . '">' . h($insc['pagamento_status']) . '</span></td>';
                echo '<td>';
                if (!empty($insc['asaas_payment_id'])) {
                    echo '<a href="?payment_id=' . urlencode($insc['asaas_payment_id']) . '&qr_code_id=' . urlencode($insc['asaas_qr_code_id'] ?? '') . '">Processar</a>';
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        ?>
    </div>
</body>
</html>

