<?php
// verificar_inscricao_checkout.php - Verificar e corrigir problemas de inscrição com checkout
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Verificar autenticação
$uid = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$logadoFlag = $_SESSION['logado'] ?? $_SESSION['logged_in'] ?? $_SESSION['auth'] ?? null;
$estaLogado = filter_var($logadoFlag, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
if ($estaLogado === null) { 
    $estaLogado = in_array((string)$logadoFlag, ['1','true','on','yes'], true); 
}

if (!$uid || !is_numeric($uid) || !$estaLogado) {
    header('Location: index.php?page=login');
    exit;
}

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/conexao.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Verificar Inscrições - Checkout</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .box { background: white; padding: 20px; margin: 10px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .success { color: #10b981; }
        .error { color: #dc2626; }
        .warning { color: #f59e0b; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f3f4f6; font-weight: 600; }
        .btn { background: #3b82f6; color: white; padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; margin: 5px; }
        .btn:hover { background: #2563eb; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 3px; font-family: monospace; font-size: 12px; }
    </style>
</head>
<body>
    <div class="box">
        <h1>🔍 Verificar Inscrições e Checkout</h1>
    </div>

    <?php
    // 1. Verificar se coluna asaas_checkout_id existe
    echo '<div class="box">';
    echo '<h2>1. Verificação da Estrutura da Tabela</h2>';
    
    try {
        $stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                             WHERE table_name = 'comercial_inscricoes' 
                             AND column_name IN ('asaas_checkout_id', 'asaas_payment_id')");
        $colunas = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $tem_checkout_id = in_array('asaas_checkout_id', $colunas);
        $tem_payment_id = in_array('asaas_payment_id', $colunas);
        
        echo '<p><strong>Coluna asaas_checkout_id:</strong> ';
        if ($tem_checkout_id) {
            echo '<span class="success">✅ Existe</span>';
        } else {
            echo '<span class="error">❌ NÃO existe</span>';
        }
        echo '</p>';
        
        echo '<p><strong>Coluna asaas_payment_id:</strong> ';
        if ($tem_payment_id) {
            echo '<span class="success">✅ Existe</span>';
        } else {
            echo '<span class="error">❌ NÃO existe</span>';
        }
        echo '</p>';
        
        // Se não existir, criar
        if (!$tem_checkout_id) {
            if (isset($_POST['criar_coluna_checkout'])) {
                try {
                    $pdo->exec("ALTER TABLE comercial_inscricoes ADD COLUMN asaas_checkout_id VARCHAR(255)");
                    echo '<p class="success">✅ Coluna asaas_checkout_id criada com sucesso!</p>';
                    $tem_checkout_id = true;
                } catch (PDOException $e) {
                    echo '<p class="error">❌ Erro ao criar coluna: ' . htmlspecialchars($e->getMessage()) . '</p>';
                }
            } else {
                echo '<form method="POST">';
                echo '<p class="warning">⚠️ A coluna asaas_checkout_id não existe. É necessário criá-la.</p>';
                echo '<button type="submit" name="criar_coluna_checkout" class="btn">Criar Coluna asaas_checkout_id</button>';
                echo '</form>';
            }
        }
        
    } catch (PDOException $e) {
        echo '<p class="error">Erro ao verificar estrutura: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    
    echo '</div>';
    
    // 2. Verificar inscrições recentes sem checkout_id
    if ($tem_checkout_id) {
        echo '<div class="box">';
        echo '<h2>2. Inscrições Recentes (últimas 10)</h2>';
        
        try {
            $stmt = $pdo->query("
                SELECT i.id, i.nome, i.email, i.pagamento_status, 
                       i.asaas_checkout_id, i.asaas_payment_id, i.valor_pago,
                       i.criado_em, d.nome as degustacao_nome
                FROM comercial_inscricoes i
                LEFT JOIN comercial_degustacoes d ON d.id = i.degustacao_id
                ORDER BY i.criado_em DESC
                LIMIT 10
            ");
            $inscricoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($inscricoes)) {
                echo '<p>Nenhuma inscrição encontrada.</p>';
            } else {
                echo '<table>';
                echo '<thead><tr>';
                echo '<th>ID</th>';
                echo '<th>Nome</th>';
                echo '<th>Degustação</th>';
                echo '<th>Status Pagamento</th>';
                echo '<th>Checkout ID</th>';
                echo '<th>Payment ID</th>';
                echo '<th>Valor</th>';
                echo '<th>Criado em</th>';
                echo '</tr></thead>';
                echo '<tbody>';
                
                foreach ($inscricoes as $insc) {
                    $sem_checkout = empty($insc['asaas_checkout_id']) && empty($insc['asaas_payment_id']);
                    echo '<tr style="' . ($sem_checkout ? 'background: #fef2f2;' : '') . '">';
                    echo '<td>' . $insc['id'] . '</td>';
                    echo '<td>' . htmlspecialchars($insc['nome']) . '</td>';
                    echo '<td>' . htmlspecialchars($insc['degustacao_nome'] ?? 'N/A') . '</td>';
                    echo '<td><code>' . htmlspecialchars($insc['pagamento_status'] ?? 'N/A') . '</code></td>';
                    echo '<td>';
                    if (!empty($insc['asaas_checkout_id'])) {
                        echo '<code>' . htmlspecialchars(substr($insc['asaas_checkout_id'], 0, 30)) . '...</code>';
                    } else {
                        echo '<span class="error">❌ Sem checkout_id</span>';
                    }
                    echo '</td>';
                    echo '<td>';
                    if (!empty($insc['asaas_payment_id'])) {
                        echo '<code>' . htmlspecialchars(substr($insc['asaas_payment_id'], 0, 30)) . '...</code>';
                    } else {
                        echo '<span>-</span>';
                    }
                    echo '</td>';
                    echo '<td>R$ ' . number_format($insc['valor_pago'] ?? 0, 2, ',', '.') . '</td>';
                    echo '<td>' . date('d/m/Y H:i', strtotime($insc['criado_em'])) . '</td>';
                    echo '</tr>';
                }
                
                echo '</tbody>';
                echo '</table>';
            }
            
        } catch (PDOException $e) {
            echo '<p class="error">Erro ao buscar inscrições: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        
        echo '</div>';
        
        // 3. Testar criação de checkout
        echo '<div class="box">';
        echo '<h2>3. Teste de Criação de Checkout com Inscrição</h2>';
        
        if (isset($_POST['testar_checkout_inscricao'])) {
            try {
                require_once __DIR__ . '/asaas_helper.php';
                $helper = new AsaasHelper();
                
                // Dados de teste simulando uma inscrição
                $test_data = [
                    'billingTypes' => ['PIX'],
                    'chargeTypes' => ['DETACHED'],
                    'callback' => [
                        'cancelUrl' => 'https://painelsmilepro-production.up.railway.app/test_cancelado',
                        'expiredUrl' => 'https://painelsmilepro-production.up.railway.app/test_expirado',
                        'successUrl' => 'https://painelsmilepro-production.up.railway.app/comercial_pagamento.php?checkout_id={checkout}&inscricao_id=999'
                    ],
                    'items' => [
                        [
                            'name' => 'Teste Inscrição Degustação',
                            'description' => 'Teste de criação de checkout para inscrição',
                            'quantity' => 1,
                            'value' => 10.00
                        ]
                    ],
                    'minutesToExpire' => 60,
                    'customerData' => [
                        'name' => 'Teste Inscrição',
                        'email' => 'teste@example.com',
                        'phone' => '11999999999'
                    ],
                    'externalReference' => 'inscricao_teste_999'
                ];
                
                echo '<p>Tentando criar checkout...</p>';
                $response = $helper->createCheckout($test_data);
                
                if ($response && isset($response['id'])) {
                    echo '<p class="success">✅ Checkout criado com sucesso!</p>';
                    echo '<p><strong>ID do Checkout:</strong> <code>' . htmlspecialchars($response['id']) . '</code></p>';
                    echo '<p><strong>URL do Checkout:</strong> <a href="' . htmlspecialchars($response['checkoutUrl'] ?? '') . '" target="_blank">' . htmlspecialchars($response['checkoutUrl'] ?? '') . '</a></p>';
                    
                    // Simular salvamento na inscrição
                    echo '<h3>Para salvar na inscrição real, você precisaria fazer:</h3>';
                    echo '<pre style="background: #f3f4f6; padding: 15px; border-radius: 6px;">';
                    echo "UPDATE comercial_inscricoes \n";
                    echo "SET asaas_checkout_id = '" . htmlspecialchars($response['id']) . "', \n";
                    echo "    pagamento_status = 'aguardando' \n";
                    echo "WHERE id = :inscricao_id;";
                    echo '</pre>';
                    
                } else {
                    echo '<p class="error">❌ Erro ao criar checkout</p>';
                    echo '<pre>' . htmlspecialchars(json_encode($response, JSON_PRETTY_PRINT)) . '</pre>';
                }
                
            } catch (Exception $e) {
                echo '<p class="error">❌ Erro: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
        } else {
            echo '<form method="POST">';
            echo '<p>Este teste criará um checkout de teste para simular o fluxo de inscrição.</p>';
            echo '<button type="submit" name="testar_checkout_inscricao" class="btn">🧪 Testar Criação de Checkout</button>';
            echo '</form>';
        }
        
        echo '</div>';
    }
    ?>

    <div class="box">
        <h2>4. Instruções para Resolver Problemas</h2>
        <ol>
            <li><strong>Verifique se a coluna existe:</strong> Se não existir, clique em "Criar Coluna" acima</li>
            <li><strong>Verifique inscrições sem checkout_id:</strong> Inscrições em vermelho não têm checkout_id salvo</li>
            <li><strong>Verifique o código:</strong> O código em <code>comercial_degust_public.php</code> deve salvar o checkout_id após criar</li>
            <li><strong>Verifique logs:</strong> Veja os logs do Railway para erros ao salvar checkout_id</li>
            <li><strong>Verifique webhook:</strong> O webhook deve buscar por <code>asaas_checkout_id</code> ou <code>asaas_payment_id</code></li>
        </ol>
    </div>
</body>
</html>

