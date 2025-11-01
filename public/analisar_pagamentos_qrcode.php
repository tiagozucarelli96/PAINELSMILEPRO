<?php
// analisar_pagamentos_qrcode.php — Análise de pagamentos via QR Code estático
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

// Verificar autenticação
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?page=login');
    exit;
}

// Verificar se coluna asaas_qr_code_id existe
try {
    $check_col = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'comercial_inscricoes' AND column_name = 'asaas_qr_code_id'");
    $has_qr_code_col = $check_col->rowCount() > 0;
} catch (Exception $e) {
    $has_qr_code_col = false;
}

// Buscar inscrições com QR Code
$inscricoes_com_qrcode = [];
if ($has_qr_code_col) {
    try {
        $stmt = $pdo->query("
            SELECT 
                i.id,
                i.nome,
                i.email,
                i.pagamento_status,
                i.asaas_qr_code_id,
                i.asaas_payment_id,
                i.asaas_checkout_id,
                i.valor_total,
                i.criado_em,
                d.nome as degustacao_nome
            FROM comercial_inscricoes i
            LEFT JOIN comercial_degustacoes d ON d.id = i.degustacao_id
            WHERE i.asaas_qr_code_id IS NOT NULL AND i.asaas_qr_code_id != ''
            ORDER BY i.criado_em DESC
            LIMIT 50
        ");
        $inscricoes_com_qrcode = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error_query = $e->getMessage();
    }
}

// Estatísticas
$total_qrcode = count($inscricoes_com_qrcode);
$pagos = 0;
$aguardando = 0;
$expirados = 0;

foreach ($inscricoes_com_qrcode as $insc) {
    switch ($insc['pagamento_status']) {
        case 'pago':
            $pagos++;
            break;
        case 'aguardando':
            $aguardando++;
            break;
        case 'expirado':
            $expirados++;
            break;
    }
}

// Buscar logs do webhook
$log_file = __DIR__ . '/logs/asaas_webhook.log';
$recent_logs = [];
if (file_exists($log_file)) {
    $all_logs = file($log_file);
    $recent_logs = array_slice(array_reverse($all_logs), 0, 20); // Últimas 20 linhas
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Análise de Pagamentos QR Code</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .box {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        h1 {
            color: #1e40af;
            margin-bottom: 10px;
        }
        h2 {
            color: #374151;
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 20px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        .stat-card.pagos {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        .stat-card.aguardando {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }
        .stat-card.expirados {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            margin: 10px 0;
        }
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }
        tr:hover {
            background: #f9fafb;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-badge.pago {
            background: #d1fae5;
            color: #065f46;
        }
        .status-badge.aguardando {
            background: #fef3c7;
            color: #92400e;
        }
        .status-badge.expirado {
            background: #fee2e2;
            color: #991b1b;
        }
        code {
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
        }
        .log-entry {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            padding: 8px;
            margin: 4px 0;
            background: #1f2937;
            color: #f9fafb;
            border-radius: 4px;
            overflow-x: auto;
        }
        .log-entry.success {
            border-left: 4px solid #10b981;
        }
        .log-entry.error {
            border-left: 4px solid #ef4444;
        }
        .log-entry.warning {
            border-left: 4px solid #f59e0b;
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="box">
        <h1>📊 Análise de Pagamentos via QR Code</h1>
        <p>Estatísticas e análise de inscrições pagas via QR Code estático do Asaas</p>
    </div>

    <?php if (!$has_qr_code_col): ?>
        <div class="box">
            <div class="no-data">
                <h2>⚠️ Coluna não encontrada</h2>
                <p>A coluna <code>asaas_qr_code_id</code> não existe na tabela <code>comercial_inscricoes</code>.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="box">
            <h2>📈 Estatísticas</h2>
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-label">Total com QR Code</div>
                    <div class="stat-value"><?= $total_qrcode ?></div>
                </div>
                <div class="stat-card pagos">
                    <div class="stat-label">Pagas</div>
                    <div class="stat-value"><?= $pagos ?></div>
                </div>
                <div class="stat-card aguardando">
                    <div class="stat-label">Aguardando</div>
                    <div class="stat-value"><?= $aguardando ?></div>
                </div>
                <div class="stat-card expirados">
                    <div class="stat-label">Expiradas</div>
                    <div class="stat-value"><?= $expirados ?></div>
                </div>
            </div>
        </div>

        <div class="box">
            <h2>📋 Inscrições com QR Code (últimas 50)</h2>
            <?php if (empty($inscricoes_com_qrcode)): ?>
                <div class="no-data">
                    <p>Nenhuma inscrição encontrada com QR Code.</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>E-mail</th>
                            <th>Degustação</th>
                            <th>Valor</th>
                            <th>Status</th>
                            <th>QR Code ID</th>
                            <th>Payment ID</th>
                            <th>Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inscricoes_com_qrcode as $insc): ?>
                            <tr>
                                <td><?= $insc['id'] ?></td>
                                <td><?= h($insc['nome'] ?? 'N/A') ?></td>
                                <td><?= h($insc['email'] ?? 'N/A') ?></td>
                                <td><?= h($insc['degustacao_nome'] ?? 'N/A') ?></td>
                                <td>R$ <?= number_format($insc['valor_total'] ?? 0, 2, ',', '.') ?></td>
                                <td>
                                    <span class="status-badge <?= $insc['pagamento_status'] ?>">
                                        <?= h($insc['pagamento_status'] ?? 'N/A') ?>
                                    </span>
                                </td>
                                <td><code><?= substr(h($insc['asaas_qr_code_id'] ?? ''), 0, 30) ?>...</code></td>
                                <td><code><?= h($insc['asaas_payment_id'] ?? 'N/A') ?></code></td>
                                <td><?= date('d/m/Y H:i', strtotime($insc['criado_em'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="box">
        <h2>📋 Últimos Logs do Webhook</h2>
        <?php if (empty($recent_logs)): ?>
            <div class="no-data">
                <p>Nenhum log encontrado. O arquivo pode não ter sido criado ainda ou não há eventos recentes.</p>
            </div>
        <?php else: ?>
            <div style="max-height: 500px; overflow-y: auto;">
                <?php foreach ($recent_logs as $log): ?>
                    <?php
                    $log_class = 'log-entry';
                    if (stripos($log, 'sucesso') !== false || stripos($log, '✅') !== false) {
                        $log_class .= ' success';
                    } elseif (stripos($log, 'erro') !== false || stripos($log, '❌') !== false) {
                        $log_class .= ' error';
                    } elseif (stripos($log, '⚠️') !== false) {
                        $log_class .= ' warning';
                    }
                    ?>
                    <div class="<?= $log_class ?>">
                        <?= nl2br(h(trim($log))) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="box">
        <h2>🔗 Links Úteis</h2>
        <p>
            <a href="index.php?page=asaas_webhook_logs" style="color: #3b82f6; text-decoration: none; margin-right: 20px;">
                📊 Ver Logs Completos do Webhook →
            </a>
            <a href="index.php?page=verificar_webhook_qrcode" style="color: #3b82f6; text-decoration: none; margin-right: 20px;">
                🔍 Verificar por QR Code ID →
            </a>
            <a href="index.php?page=test_qrcode_asaas" style="color: #3b82f6; text-decoration: none;">
                🧪 Gerar QR Code de Teste →
            </a>
        </p>
    </div>
</body>
</html>

