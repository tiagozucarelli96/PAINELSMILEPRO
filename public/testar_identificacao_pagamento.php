<?php
/**
 * Página de teste para simular identificação de pagamento
 * Permite testar sem fazer pagamento real
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

// Verificar autenticação
if (empty($_SESSION['logado']) || ($_SESSION['logado'] ?? 0) != 1) {
    header('Location: index.php?page=login');
    exit;
}

$mensagem = '';
$tipo_mensagem = '';

// Processar ação de simular pagamento
if ($_POST && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'simular_pagamento' && isset($_POST['inscricao_id'])) {
        $inscricao_id = (int)$_POST['inscricao_id'];
        
        try {
            // Verificar se inscrição existe
            $stmt = $pdo->prepare("SELECT id, nome, email, pagamento_status FROM comercial_inscricoes WHERE id = :id");
            $stmt->execute([':id' => $inscricao_id]);
            $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$inscricao) {
                throw new Exception("Inscrição não encontrada");
            }
            
            // Atualizar status para "pago"
            $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET pagamento_status = 'pago' WHERE id = :id");
            $stmt->execute([':id' => $inscricao_id]);
            
            $mensagem = "✅ Pagamento simulado com sucesso para a inscrição ID: $inscricao_id ({$inscricao['nome']})";
            $tipo_mensagem = 'success';
            
        } catch (Exception $e) {
            $mensagem = "❌ Erro: " . $e->getMessage();
            $tipo_mensagem = 'error';
        }
    }
    
    if ($action === 'reverter_pagamento' && isset($_POST['inscricao_id'])) {
        $inscricao_id = (int)$_POST['inscricao_id'];
        
        try {
            $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET pagamento_status = 'aguardando' WHERE id = :id");
            $stmt->execute([':id' => $inscricao_id]);
            
            $mensagem = "🔄 Status revertido para 'aguardando' na inscrição ID: $inscricao_id";
            $tipo_mensagem = 'success';
            
        } catch (Exception $e) {
            $mensagem = "❌ Erro: " . $e->getMessage();
            $tipo_mensagem = 'error';
        }
    }
}

// Buscar todas as inscrições
$stmt = $pdo->query("
    SELECT 
        i.id,
        i.nome,
        i.email,
        i.pagamento_status,
        i.asaas_qr_code_id,
        i.asaas_payment_id,
        COALESCE(i.valor_total, i.valor_pago, 0) as valor,
        i.criado_em,
        d.nome as degustacao_nome,
        d.id as degustacao_id,
        d.token_publico
    FROM comercial_inscricoes i
    LEFT JOIN comercial_degustacoes d ON i.degustacao_id = d.id
    ORDER BY i.id DESC
    LIMIT 20
");
$inscricoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Testar Identificação de Pagamento</title>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1e3a8a;
            margin-top: 0;
        }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            background: #f8fafc;
            font-weight: 600;
            color: #374151;
        }
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }
        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: #3b82f6;
            color: white;
        }
        .btn-primary:hover {
            background: #2563eb;
        }
        .btn-success {
            background: #10b981;
            color: white;
        }
        .btn-success:hover {
            background: #059669;
        }
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        .btn-secondary:hover {
            background: #4b5563;
        }
        .info-box {
            background: #f0f9ff;
            border-left: 4px solid #3b82f6;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Testar Identificação de Pagamento</h1>
        
        <div class="info-box">
            <strong>💡 Como usar:</strong>
            <ol style="margin: 10px 0; padding-left: 20px;">
                <li>Encontre uma inscrição que ainda não está paga</li>
                <li>Clique em "Simular Pagamento"</li>
                <li>A inscrição será marcada como "pago" no banco</li>
                <li>Acesse a página pública da degustação (link fornecido) para ver a confirmação</li>
                <li>Use "Reverter" para voltar o status para "aguardando" e testar novamente</li>
            </ol>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-<?= $tipo_mensagem ?>">
                <?= htmlspecialchars($mensagem) ?>
            </div>
        <?php endif; ?>
        
        <h2>📋 Inscrições Recentes</h2>
        
        <?php if (empty($inscricoes)): ?>
            <p>Nenhuma inscrição encontrada.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Email</th>
                        <th>Degustação</th>
                        <th>Status</th>
                        <th>Valor</th>
                        <th>QR Code ID</th>
                        <th>Ações</th>
                        <th>Link Teste</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inscricoes as $insc): ?>
                        <tr>
                            <td><strong><?= $insc['id'] ?></strong></td>
                            <td><?= htmlspecialchars($insc['nome']) ?></td>
                            <td><?= htmlspecialchars($insc['email']) ?></td>
                            <td>
                                <?php if ($insc['degustacao_nome']): ?>
                                    <?= htmlspecialchars($insc['degustacao_nome']) ?>
                                <?php else: ?>
                                    <span style="color: #9ca3af;">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $status_class = $insc['pagamento_status'] === 'pago' ? 'badge-success' : 'badge-warning';
                                $status_text = match($insc['pagamento_status']) {
                                    'pago' => 'Pago',
                                    'aguardando' => 'Aguardando',
                                    'expirado' => 'Expirado',
                                    'cancelado' => 'Cancelado',
                                    default => 'N/A'
                                };
                                ?>
                                <span class="badge <?= $status_class ?>">
                                    <?= $status_text ?>
                                </span>
                            </td>
                            <td>
                                R$ <?= number_format($insc['valor'], 2, ',', '.') ?>
                            </td>
                            <td>
                                <?php if ($insc['asaas_qr_code_id']): ?>
                                    <small style="color: #6b7280; font-family: monospace;">
                                        <?= htmlspecialchars(substr($insc['asaas_qr_code_id'], 0, 20)) ?>...
                                    </small>
                                <?php else: ?>
                                    <span style="color: #9ca3af;">Sem QR Code</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($insc['pagamento_status'] !== 'pago'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="simular_pagamento">
                                        <input type="hidden" name="inscricao_id" value="<?= $insc['id'] ?>">
                                        <button type="submit" class="btn btn-success" onclick="return confirm('Simular pagamento para esta inscrição?')">
                                            ✅ Simular Pagamento
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="reverter_pagamento">
                                        <input type="hidden" name="inscricao_id" value="<?= $insc['id'] ?>">
                                        <button type="submit" class="btn btn-secondary" onclick="return confirm('Reverter status para aguardando?')">
                                            🔄 Reverter
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($insc['token_publico']): ?>
                                    <a href="index.php?page=comercial_degust_public&t=<?= urlencode($insc['token_publico']) ?>&qr_code=1&inscricao_id=<?= $insc['id'] ?>" 
                                       target="_blank" 
                                       class="btn btn-primary">
                                        🔗 Ver Página
                                    </a>
                                <?php else: ?>
                                    <span style="color: #9ca3af;">Sem token</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
            <a href="index.php?page=dashboard" class="btn btn-secondary">← Voltar ao Dashboard</a>
        </div>
    </div>
</body>
</html>
