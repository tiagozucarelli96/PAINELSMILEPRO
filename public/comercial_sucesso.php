<?php
// comercial_sucesso.php — Página de confirmação de pagamento
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/comercial_email_helper.php';

$inscricao_id = (int)($_GET['inscricao_id'] ?? 0);

if (!$inscricao_id) {
    die('Parâmetros inválidos');
}

// Buscar dados da inscrição
$stmt = $pdo->prepare("
    SELECT i.*, d.nome as degustacao_nome, d.data as degustacao_data, d.local as degustacao_local,
           d.hora_inicio, d.hora_fim, d.instrutivo_html, d.email_confirmacao_html
    FROM comercial_inscricoes i
    LEFT JOIN comercial_degustacoes d ON d.id = i.event_id
    WHERE i.id = :inscricao_id
");
$stmt->execute([':inscricao_id' => $inscricao_id]);
$inscricao = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$inscricao) {
    die('Inscrição não encontrada');
}

// Enviar e-mail de confirmação se ainda não foi enviado
if ($inscricao['pagamento_status'] === 'pago') {
    try {
        $emailHelper = new ComercialEmailHelper();
        $emailHelper->sendInscricaoConfirmation($inscricao, $inscricao);
    } catch (Exception $e) {
        // Log do erro, mas não interromper a página
        error_log("Erro ao enviar e-mail de confirmação: " . $e->getMessage());
    }
}


?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscrição Confirmada - GRUPO Smile EVENTOS</title>
    <link rel="stylesheet" href="estilo.css">
    <style>
        .sucesso-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 40px;
            padding: 40px 30px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border-radius: 12px;
        }
        
        .header h1 {
            font-size: 32px;
            margin: 0 0 10px 0;
        }
        
        .header p {
            font-size: 18px;
            opacity: 0.9;
            margin: 0;
        }
        
        .success-icon {
            font-size: 64px;
            margin-bottom: 20px;
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
        
        .instructions {
            background: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .instructions h3 {
            color: #0c4a6e;
            margin: 0 0 15px 0;
        }
        
        .instructions-content {
            color: #0c4a6e;
            line-height: 1.6;
        }
        
        .next-steps {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .next-steps h3 {
            color: #92400e;
            margin: 0 0 15px 0;
        }
        
        .next-steps ul {
            color: #92400e;
            margin: 0;
            padding-left: 20px;
        }
        
        .next-steps li {
            margin-bottom: 8px;
        }
        
        .contact-info {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        
        .contact-info h3 {
            color: #374151;
            margin: 0 0 10px 0;
        }
        
        .contact-info p {
            color: #6b7280;
            margin: 0;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            margin: 10px 5px;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a606a;
        }
    </style>
</head>
<body>
    <div class="sucesso-container">
        <!-- Header de Sucesso -->
        <div class="header">
            <div class="success-icon">✅</div>
            <h1>Inscrição Confirmada!</h1>
            <p>Pagamento realizado com sucesso</p>
        </div>
        
        <!-- Informações do Evento -->
        <div class="event-info">
            <h2 class="event-title"><?= h($inscricao['degustacao_nome']) ?></h2>
            <div class="info-row">
                <span class="info-label">📅 Data:</span>
                <span class="info-value"><?= date('d/m/Y', strtotime($inscricao['degustacao_data'])) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">🕐 Horário:</span>
                <span class="info-value"><?= date('H:i', strtotime($inscricao['hora_inicio'])) ?> - <?= date('H:i', strtotime($inscricao['hora_fim'])) ?></span>
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
            <div class="info-row">
                <span class="info-label">💰 Valor Pago:</span>
                <span class="info-value">R$ <?= number_format($inscricao['valor_pago'], 2, ',', '.') ?></span>
            </div>
        </div>
        
        <!-- Instruções do Dia -->
        <?php if ($inscricao['instrutivo_html']): ?>
        <div class="instructions">
            <h3>📋 Instruções do Dia</h3>
            <div class="instructions-content">
                <?= $inscricao['instrutivo_html'] ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Próximos Passos -->
        <div class="next-steps">
            <h3>📝 Próximos Passos</h3>
            <ul>
                <li>Você receberá um e-mail de confirmação em breve</li>
                <li>Guarde este comprovante de inscrição</li>
                <li>Compareça no local e horário indicados</li>
                <li>Em caso de dúvidas, entre em contato conosco</li>
            </ul>
        </div>
        
        <!-- Informações de Contato -->
        <div class="contact-info">
            <h3>📞 Precisa de Ajuda?</h3>
            <p>Entre em contato conosco para qualquer dúvida sobre sua inscrição</p>
        </div>
        
        <!-- Ações -->
        <div style="text-align: center; margin-top: 30px;">
            <a href="comercial_degust_public.php?t=<?= $_GET['t'] ?? '' ?>" class="btn btn-secondary">
                ← Voltar ao Evento
            </a>
            <button onclick="window.print()" class="btn btn-primary">
                🖨️ Imprimir Comprovante
            </button>
        </div>
    </div>
    
    <script>
        // Auto-print se solicitado
        if (window.location.search.includes('print=1')) {
            window.print();
        }
    </script>
</body>
</html>
