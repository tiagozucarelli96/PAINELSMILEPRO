<?php
// verificar_pagamento_1real.php — Verificação rápida do pagamento de teste
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (empty($_SESSION['logado']) || ($_SESSION['logado'] ?? 0) != 1) {
    header('Location: index.php?page=login');
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Verificar Pagamento R$ 1,00</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; max-width: 1000px; margin: 0 auto; }
        .box { background: white; padding: 25px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h1 { color: #1e40af; margin-bottom: 10px; }
        h2 { color: #374151; margin-top: 0; font-size: 18px; }
        .success { color: #059669; font-weight: bold; background: #d1fae5; padding: 10px; border-radius: 8px; margin: 10px 0; }
        .error { color: #dc2626; font-weight: bold; background: #fee2e2; padding: 10px; border-radius: 8px; margin: 10px 0; }
        .info { background: #eff6ff; padding: 15px; border-radius: 8px; border-left: 4px solid #3b82f6; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f9fafb; font-weight: 600; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="box">
        <h1>🔍 Verificação de Pagamento R$ 1,00</h1>
        <p>Verificando se o pagamento de teste foi identificado pelo sistema</p>
    </div>

    <?php
    // 1. Verificar se coluna asaas_qr_code_id existe
    try {
        $check_col = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'comercial_inscricoes' AND column_name = 'asaas_qr_code_id'");
        $has_qr_code_col = $check_col->rowCount() > 0;
    } catch (Exception $e) {
        $has_qr_code_col = false;
        echo '<div class="box error">Erro ao verificar coluna: ' . h($e->getMessage()) . '</div>';
    }

    if (!$has_qr_code_col) {
        echo '<div class="box error">❌ Coluna asaas_qr_code_id não existe na tabela comercial_inscricoes</div>';
    } else {
        // 2. Buscar QR Codes gerados nas últimas 2 horas
        $stmt_recent = $pdo->query("
            SELECT 
                i.id,
                i.nome,
                i.email,
                i.pagamento_status,
                i.asaas_qr_code_id,
                i.asaas_payment_id,
                i.valor_total,
                i.criado_em,
                d.nome as degustacao_nome
            FROM comercial_inscricoes i
            LEFT JOIN comercial_degustacoes d ON d.id = i.degustacao_id
            WHERE i.asaas_qr_code_id IS NOT NULL 
                AND i.asaas_qr_code_id != ''
                AND i.criado_em >= NOW() - INTERVAL '2 hours'
            ORDER BY i.criado_em DESC
        ");
        $qr_codes_recentes = $stmt_recent->fetchAll(PDO::FETCH_ASSOC);

        echo '<div class="box">';
        echo '<h2>📋 QR Codes Gerados (últimas 2 horas)</h2>';
        
        if (empty($qr_codes_recentes)) {
            echo '<div class="info">Nenhum QR Code gerado nas últimas 2 horas.</div>';
        } else {
            echo '<table>';
            echo '<thead><tr><th>ID</th><th>Nome</th><th>Valor</th><th>Status</th><th>QR Code ID</th><th>Payment ID</th><th>Criado em</th></tr></thead>';
            echo '<tbody>';
            foreach ($qr_codes_recentes as $insc) {
                $status_class = '';
                switch ($insc['pagamento_status']) {
                    case 'pago':
                        $status_class = 'success';
                        break;
                    case 'aguardando':
                        $status_class = 'info';
                        break;
                    case 'expirado':
                        $status_class = 'error';
                        break;
                }
                echo '<tr>';
                echo '<td>' . $insc['id'] . '</td>';
                echo '<td>' . h($insc['nome'] ?? 'Teste') . '</td>';
                echo '<td>R$ ' . number_format($insc['valor_total'] ?? 0, 2, ',', '.') . '</td>';
                echo '<td><span class="' . $status_class . '">' . h($insc['pagamento_status'] ?? 'N/A') . '</span></td>';
                echo '<td><code>' . substr(h($insc['asaas_qr_code_id'] ?? ''), 0, 25) . '...</code></td>';
                echo '<td><code>' . h($insc['asaas_payment_id'] ?? 'N/A') . '</code></td>';
                echo '<td>' . date('d/m/Y H:i:s', strtotime($insc['criado_em'])) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        // 3. Buscar especificamente pagamentos de R$ 1,00
        $stmt_1real = $pdo->query("
            SELECT 
                i.id,
                i.nome,
                i.email,
                i.pagamento_status,
                i.asaas_qr_code_id,
                i.asaas_payment_id,
                i.valor_total,
                i.criado_em
            FROM comercial_inscricoes i
            WHERE i.valor_total BETWEEN 0.99 AND 1.01
                AND i.asaas_qr_code_id IS NOT NULL
                AND i.asaas_qr_code_id != ''
            ORDER BY i.criado_em DESC
            LIMIT 10
        ");
        $pagamentos_1real = $stmt_1real->fetchAll(PDO::FETCH_ASSOC);

        echo '<div class="box">';
        echo '<h2>💰 Pagamentos de R$ 1,00 (específico)</h2>';
        
        if (empty($pagamentos_1real)) {
            echo '<div class="info">Nenhum pagamento de R$ 1,00 encontrado.</div>';
        } else {
            foreach ($pagamentos_1real as $insc) {
                $is_pago = ($insc['pagamento_status'] === 'pago');
                echo '<div class="box" style="margin-bottom: 15px;">';
                
                if ($is_pago) {
                    echo '<div class="success">✅ PAGAMENTO IDENTIFICADO E CONFIRMADO!</div>';
                } else {
                    echo '<div class="error">⏳ Aguardando confirmação do pagamento</div>';
                }
                
                echo '<p><strong>ID Inscrição:</strong> ' . $insc['id'] . '</p>';
                echo '<p><strong>Nome:</strong> ' . h($insc['nome'] ?? 'Teste') . '</p>';
                echo '<p><strong>E-mail:</strong> ' . h($insc['email'] ?? 'N/A') . '</p>';
                echo '<p><strong>Valor:</strong> R$ ' . number_format($insc['valor_total'], 2, ',', '.') . '</p>';
                echo '<p><strong>Status:</strong> <span class="' . ($is_pago ? 'success' : 'error') . '">' . h($insc['pagamento_status']) . '</span></p>';
                echo '<p><strong>QR Code ID:</strong> <code>' . h($insc['asaas_qr_code_id']) . '</code></p>';
                echo '<p><strong>Payment ID:</strong> <code>' . h($insc['asaas_payment_id'] ?? 'Ainda não recebido') . '</code></p>';
                echo '<p><strong>Criado em:</strong> ' . date('d/m/Y H:i:s', strtotime($insc['criado_em'])) . '</p>';
                echo '</div>';
            }
        }
        echo '</div>';

        // 4. Verificar logs do webhook para pagamentos de R$ 1,00
        $log_file = __DIR__ . '/logs/asaas_webhook.log';
        if (file_exists($log_file)) {
            $all_logs = file($log_file);
            $logs_1real = [];
            foreach ($all_logs as $log) {
                if (stripos($log, '1.00') !== false || stripos($log, '1,00') !== false || 
                    stripos($log, 'PAYMENT_RECEIVED') !== false || stripos($log, 'pixQrCodeId') !== false) {
                    $logs_1real[] = $log;
                }
            }
            
            if (!empty($logs_1real)) {
                echo '<div class="box">';
                echo '<h2>📋 Logs do Webhook (relacionados a R$ 1,00)</h2>';
                echo '<div style="max-height: 400px; overflow-y: auto; background: #1f2937; color: #f9fafb; padding: 15px; border-radius: 8px; font-family: monospace; font-size: 12px;">';
                foreach (array_slice($logs_1real, -10) as $log) {
                    echo '<div style="margin: 5px 0; padding: 8px; background: rgba(255,255,255,0.1); border-radius: 4px;">';
                    echo nl2br(h(trim($log)));
                    echo '</div>';
                }
                echo '</div>';
                echo '</div>';
            }
        }
    }

    // 5. Resumo final
    echo '<div class="box">';
    echo '<h2>📊 Resumo da Verificação</h2>';
    
    if ($has_qr_code_col) {
        $total_qrcode = count($qr_codes_recentes);
        $pagos_recentes = 0;
        foreach ($qr_codes_recentes as $insc) {
            if ($insc['pagamento_status'] === 'pago') {
                $pagos_recentes++;
            }
        }
        
        echo '<div class="info">';
        echo '<p><strong>QR Codes gerados (últimas 2h):</strong> ' . $total_qrcode . '</p>';
        echo '<p><strong>Pagamentos confirmados:</strong> ' . $pagos_recentes . '</p>';
        echo '<p><strong>Pagamentos de R$ 1,00 encontrados:</strong> ' . count($pagamentos_1real) . '</p>';
        
        if (!empty($pagamentos_1real)) {
            $pagos_1real = 0;
            foreach ($pagamentos_1real as $insc) {
                if ($insc['pagamento_status'] === 'pago') {
                    $pagos_1real++;
                }
            }
            echo '<p><strong>Pagamentos de R$ 1,00 confirmados:</strong> ' . $pagos_1real . '</p>';
        }
        echo '</div>';
    } else {
        echo '<div class="error">Coluna asaas_qr_code_id não existe - não é possível verificar.</div>';
    }
    echo '</div>';
    ?>
    
    <div class="box">
        <h2>🔗 Links Úteis</h2>
        <p>
            <a href="index.php?page=analisar_pagamentos_qrcode" style="color: #3b82f6; text-decoration: none; margin-right: 20px;">
                📊 Análise Completa →
            </a>
            <a href="index.php?page=asaas_webhook_logs" style="color: #3b82f6; text-decoration: none; margin-right: 20px;">
                📋 Logs Completos →
            </a>
            <a href="index.php?page=test_qrcode_asaas" style="color: #3b82f6; text-decoration: none;">
                🧪 Gerar Novo Teste →
            </a>
        </p>
    </div>
</body>
</html>

