<?php
// processar_pagamento_manual.php — Processar pagamento de QR Code manualmente
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

if (isset($_POST['buscar_pagamento'])) {
    $payment_id = trim($_POST['payment_id'] ?? '');
    $qr_code_id = trim($_POST['qr_code_id'] ?? '');
    
    if (empty($payment_id) && empty($qr_code_id)) {
        $error = "Informe o Payment ID ou QR Code ID";
    } else {
        try {
            $asaas = new AsaasHelper();
            
            if ($payment_id) {
                // Buscar pagamento por ID
                $payment_data = $asaas->getPaymentStatus($payment_id);
            } else if ($qr_code_id) {
                // Buscar inscrição pelo QR Code ID
                $stmt = $pdo->prepare("SELECT * FROM comercial_inscricoes WHERE asaas_qr_code_id = :qr_code_id");
                $stmt->execute([':qr_code_id' => $qr_code_id]);
                $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($inscricao && !empty($inscricao['asaas_payment_id'])) {
                    $payment_data = $asaas->getPaymentStatus($inscricao['asaas_payment_id']);
                } else {
                    $error = "QR Code ID não encontrado no banco ou sem Payment ID vinculado";
                }
            }
            
            if ($payment_data && isset($payment_data['id'])) {
                $message = "Pagamento encontrado!";
            }
        } catch (Exception $e) {
            $error = "Erro ao buscar pagamento: " . $e->getMessage();
        }
    }
}

if (isset($_POST['vincular_pagamento'])) {
    $inscricao_id = (int)($_POST['inscricao_id'] ?? 0);
    $payment_id = trim($_POST['payment_id'] ?? '');
    $qr_code_id = trim($_POST['qr_code_id'] ?? '');
    
    if (!$inscricao_id || (empty($payment_id) && empty($qr_code_id))) {
        $error = "Dados incompletos";
    } else {
        try {
            // Atualizar inscrição
            $updates = [];
            $params = [':id' => $inscricao_id];
            
            if ($payment_id) {
                // Verificar se coluna existe
                $check = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'comercial_inscricoes' AND column_name = 'asaas_payment_id'");
                if ($check->rowCount() > 0) {
                    $updates[] = "asaas_payment_id = :payment_id";
                    $params[':payment_id'] = $payment_id;
                }
            }
            
            if ($qr_code_id) {
                $check = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'comercial_inscricoes' AND column_name = 'asaas_qr_code_id'");
                if ($check->rowCount() > 0) {
                    $updates[] = "asaas_qr_code_id = :qr_code_id";
                    $params[':qr_code_id'] = $qr_code_id;
                }
            }
            
            if (!empty($updates)) {
                $updates[] = "pagamento_status = 'pago'";
                $sql = "UPDATE comercial_inscricoes SET " . implode(', ', $updates) . " WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                $message = "Inscrição ID $inscricao_id atualizada com sucesso! Status: PAGO";
            }
        } catch (Exception $e) {
            $error = "Erro ao atualizar: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Processar Pagamento Manualmente</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; max-width: 1000px; margin: 0 auto; }
        .box { background: white; padding: 25px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h1 { color: #1e40af; margin-bottom: 10px; }
        .success { color: #059669; background: #d1fae5; padding: 15px; border-radius: 8px; margin: 10px 0; }
        .error { color: #dc2626; background: #fee2e2; padding: 15px; border-radius: 8px; margin: 10px 0; }
        .info { background: #eff6ff; padding: 15px; border-radius: 8px; border-left: 4px solid #3b82f6; margin: 10px 0; }
        label { display: block; font-weight: 600; margin-bottom: 8px; color: #374151; }
        input[type="text"], input[type="number"] { width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; box-sizing: border-box; }
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
        <h1>🔧 Processar Pagamento Manualmente</h1>
        <p>Use esta ferramenta para vincular um pagamento feito via QR Code a uma inscrição</p>
    </div>

    <?php if ($message): ?>
        <div class="box success"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="box error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="box">
        <h2>1️⃣ Buscar Pagamento</h2>
        <form method="POST">
            <div style="margin-bottom: 15px;">
                <label>Payment ID (do Asaas):</label>
                <input type="text" name="payment_id" placeholder="pay_xxxxx" value="<?= h($_POST['payment_id'] ?? '') ?>">
            </div>
            <div style="margin-bottom: 15px;">
                <label>OU QR Code ID:</label>
                <input type="text" name="qr_code_id" placeholder="GRPSMLEV..." value="<?= h($_POST['qr_code_id'] ?? '') ?>">
            </div>
            <button type="submit" name="buscar_pagamento">🔍 Buscar Pagamento</button>
        </form>

        <?php if ($payment_data): ?>
            <div style="margin-top: 20px;">
                <h3>📋 Dados do Pagamento:</h3>
                <table>
                    <tr><th>Campo</th><th>Valor</th></tr>
                    <tr><td>Payment ID</td><td><code><?= h($payment_data['id'] ?? 'N/A') ?></code></td></tr>
                    <tr><td>Status</td><td><?= h($payment_data['status'] ?? 'N/A') ?></td></tr>
                    <tr><td>Valor</td><td>R$ <?= number_format($payment_data['value'] ?? 0, 2, ',', '.') ?></td></tr>
                    <tr><td>QR Code ID</td><td><code><?= h($payment_data['pixQrCodeId'] ?? 'N/A') ?></code></td></tr>
                    <tr><td>Data de Criação</td><td><?= h($payment_data['dateCreated'] ?? 'N/A') ?></td></tr>
                </table>

                <?php
                // Buscar inscrições com este QR Code ID
                $qr_code_id = $payment_data['pixQrCodeId'] ?? '';
                if ($qr_code_id) {
                    $stmt = $pdo->prepare("SELECT * FROM comercial_inscricoes WHERE asaas_qr_code_id = :qr_code_id");
                    $stmt->execute([':qr_code_id' => $qr_code_id]);
                    $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($inscricao) {
                        echo '<div class="info" style="margin-top: 15px;">';
                        echo '<strong>✅ Inscrição encontrada!</strong><br>';
                        echo 'ID: ' . $inscricao['id'] . '<br>';
                        echo 'Nome: ' . h($inscricao['nome']) . '<br>';
                        echo 'Status: ' . h($inscricao['pagamento_status']) . '<br>';
                        echo '</div>';
                    } else {
                        echo '<div class="error" style="margin-top: 15px;">';
                        echo '<strong>⚠️ Inscrição não encontrada para este QR Code ID</strong><br>';
                        echo 'QR Code ID: <code>' . h($qr_code_id) . '</code><br>';
                        echo 'Você pode vincular manualmente usando o formulário abaixo.';
                        echo '</div>';
                    }
                }
                ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="box">
        <h2>2️⃣ Vincular Pagamento a Inscrição</h2>
        <form method="POST">
            <input type="hidden" name="payment_id" value="<?= h($payment_data['id'] ?? $_POST['payment_id'] ?? '') ?>">
            <input type="hidden" name="qr_code_id" value="<?= h($payment_data['pixQrCodeId'] ?? $_POST['qr_code_id'] ?? '') ?>">
            
            <div style="margin-bottom: 15px;">
                <label>ID da Inscrição:</label>
                <input type="number" name="inscricao_id" required placeholder="50" value="<?= h($_POST['inscricao_id'] ?? '') ?>">
            </div>
            
            <button type="submit" name="vincular_pagamento">🔗 Vincular e Marcar como Pago</button>
        </form>
    </div>

    <div class="box">
        <h2>📋 Inscrições Recentes com QR Code</h2>
        <?php
        $stmt = $pdo->query("
            SELECT id, nome, email, asaas_qr_code_id, asaas_payment_id, pagamento_status, criado_em
            FROM comercial_inscricoes
            WHERE asaas_qr_code_id IS NOT NULL AND asaas_qr_code_id != ''
            ORDER BY criado_em DESC
            LIMIT 10
        ");
        $inscricoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($inscricoes)) {
            echo '<p>Nenhuma inscrição com QR Code encontrada.</p>';
        } else {
            echo '<table>';
            echo '<thead><tr><th>ID</th><th>Nome</th><th>QR Code ID</th><th>Payment ID</th><th>Status</th></tr></thead>';
            echo '<tbody>';
            foreach ($inscricoes as $insc) {
                $status_class = $insc['pagamento_status'] === 'pago' ? 'success' : '';
                echo '<tr>';
                echo '<td>' . $insc['id'] . '</td>';
                echo '<td>' . h($insc['nome']) . '</td>';
                echo '<td><code>' . substr(h($insc['asaas_qr_code_id']), 0, 30) . '...</code></td>';
                echo '<td><code>' . h($insc['asaas_payment_id'] ?? 'Aguardando') . '</code></td>';
                echo '<td><span class="' . $status_class . '">' . h($insc['pagamento_status']) . '</span></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        ?>
    </div>
</body>
</html>

