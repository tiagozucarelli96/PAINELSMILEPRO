<?php
// verificar_webhook_qrcode.php — Script para verificar se inscrição foi atualizada via QR Code
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

// Verificar autenticação
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?page=login');
    exit;
}

$pix_qr_code_id = $_GET['pix_qr_code_id'] ?? 'GRPSMLEV00000543613783ASA'; // QR Code ID do teste
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Verificar Webhook QR Code</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .box { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .success { color: #059669; font-weight: bold; }
        .error { color: #dc2626; font-weight: bold; }
        .info { color: #2563eb; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; }
        pre { background: #1f2937; color: #f9fafb; padding: 15px; border-radius: 8px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="box">
        <h1>🔍 Verificar Webhook QR Code</h1>
        <p>QR Code ID: <code><?= h($pix_qr_code_id) ?></code></p>
    </div>

    <?php
    // Buscar inscrição pelo QR Code ID
    try {
        $check_col = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'comercial_inscricoes' AND column_name = 'asaas_qr_code_id'");
        if ($check_col->rowCount() > 0) {
            $stmt = $pdo->prepare("SELECT * FROM comercial_inscricoes WHERE asaas_qr_code_id = :qr_code_id ORDER BY id DESC LIMIT 1");
            $stmt->execute([':qr_code_id' => $pix_qr_code_id]);
            $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($inscricao) {
                echo '<div class="box">';
                echo '<h2 class="success">✅ Inscrição Encontrada!</h2>';
                echo '<p><strong>ID:</strong> ' . $inscricao['id'] . '</p>';
                echo '<p><strong>Nome:</strong> ' . h($inscricao['nome'] ?? 'N/A') . '</p>';
                echo '<p><strong>E-mail:</strong> ' . h($inscricao['email'] ?? 'N/A') . '</p>';
                echo '<p><strong>Status Pagamento:</strong> <span class="' . ($inscricao['pagamento_status'] === 'pago' ? 'success' : 'error') . '">' . h($inscricao['pagamento_status'] ?? 'N/A') . '</span></p>';
                echo '<p><strong>QR Code ID:</strong> <code>' . h($inscricao['asaas_qr_code_id'] ?? 'N/A') . '</code></p>';
                echo '<p><strong>Payment ID:</strong> <code>' . h($inscricao['asaas_payment_id'] ?? 'N/A') . '</code></p>';
                echo '<p><strong>Valor Total:</strong> R$ ' . number_format($inscricao['valor_total'] ?? 0, 2, ',', '.') . '</p>';
                echo '</div>';
            } else {
                echo '<div class="box">';
                echo '<h2 class="error">❌ Inscrição NÃO Encontrada</h2>';
                echo '<p>Nenhuma inscrição encontrada com QR Code ID: <code>' . h($pix_qr_code_id) . '</code></p>';
                echo '</div>';
            }
        } else {
            echo '<div class="box">';
            echo '<h2 class="error">❌ Coluna não existe</h2>';
            echo '<p>A coluna <code>asaas_qr_code_id</code> não existe na tabela <code>comercial_inscricoes</code>.</p>';
            echo '</div>';
        }
    } catch (Exception $e) {
        echo '<div class="box">';
        echo '<h2 class="error">❌ Erro</h2>';
        echo '<p>' . h($e->getMessage()) . '</p>';
        echo '</div>';
    }
    
    // Verificar logs do webhook
    $log_file = __DIR__ . '/logs/asaas_webhook.log';
    if (file_exists($log_file)) {
        $logs = file($log_file);
        $recent_logs = array_slice($logs, -20); // Últimas 20 linhas
        echo '<div class="box">';
        echo '<h2>📋 Últimos Logs do Webhook</h2>';
        echo '<pre>' . h(implode('', $recent_logs)) . '</pre>';
        echo '</div>';
    } else {
        echo '<div class="box">';
        echo '<h2 class="info">ℹ️ Log não encontrado</h2>';
        echo '<p>O arquivo de log ainda não foi criado: <code>' . $log_file . '</code></p>';
        echo '</div>';
    }
    ?>
</body>
</html>

