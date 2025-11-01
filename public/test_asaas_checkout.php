<?php
// test_asaas_checkout.php — Teste completo de criação de checkout Asaas
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/asaas_helper.php';

// Verificar autenticação (padrão do sistema)
$uid = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$logadoFlag = $_SESSION['logado'] ?? $_SESSION['logged_in'] ?? $_SESSION['auth'] ?? null;
$estaLogado = filter_var($logadoFlag, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
if ($estaLogado === null) { 
    $estaLogado = in_array((string)$logadoFlag, ['1','true','on','yes'], true); 
}

if (!$uid || !is_numeric($uid) || !$estaLogado) {
    // Redirecionar para login se não estiver logado
    header('Location: index.php?page=login');
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste Checkout Asaas - Diagnóstico</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f8fafc;
        }
        .section {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .section h2 {
            margin-top: 0;
            color: #1e3a8a;
        }
        .success { color: #10b981; font-weight: 600; }
        .error { color: #dc2626; font-weight: 600; }
        .info { color: #3b82f6; }
        .code {
            background: #1e293b;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            overflow-x: auto;
            margin: 10px 0;
        }
        pre { margin: 0; }
        .btn {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            margin-top: 10px;
        }
        .btn:hover { background: #2563eb; }
    </style>
</head>
<body>
    <div class="section">
        <h1>🔍 Diagnóstico Checkout Asaas</h1>
    </div>

    <?php
    // PASSO 1: Verificar carregamento da chave
    echo '<div class="section">';
    echo '<h2>1. Verificação da Chave de API</h2>';
    
    $env_key = $_ENV['ASAAS_API_KEY'] ?? getenv('ASAAS_API_KEY') ?: null;
    require_once __DIR__ . '/config_env.php';
    $const_key = ASAAS_API_KEY;
    
    echo '<p><strong>Chave do ENV ($_ENV):</strong> ' . ($env_key ? ('<span class="success">✓ Encontrada</span> - Primeiros 30: ' . substr($env_key, 0, 30) . '...') : '<span class="error">✗ Não encontrada</span>') . '</p>';
    echo '<p><strong>Chave da Constante:</strong> Primeiros 30: ' . substr($const_key, 0, 30) . '...</p>';
    echo '<p><strong>Tamanho ENV:</strong> ' . ($env_key ? strlen($env_key) . ' caracteres' : 'N/A') . '</p>';
    echo '<p><strong>Tamanho Constante:</strong> ' . strlen($const_key) . ' caracteres</p>';
    echo '<p><strong>Tem $ no início (ENV):</strong> ' . ($env_key && strpos($env_key, '$') === 0 ? 'SIM' : 'NÃO') . '</p>';
    echo '<p><strong>Tem $ no início (Constante):</strong> ' . (strpos($const_key, '$') === 0 ? 'SIM' : 'NÃO') . '</p>';
    
    // Criar instância do helper
    $helper = new AsaasHelper();
    echo '</div>';
    
    // PASSO 2: Testar requisição real
    echo '<div class="section">';
    echo '<h2>2. Teste de Requisição ao Asaas</h2>';
    
    if (isset($_POST['test_checkout'])) {
        try {
            // Dados de teste mínimos conforme documentação
            $test_data = [
                'billingTypes' => ['PIX'],
                'chargeTypes' => ['DETACHED'],
                'callback' => [
                    'cancelUrl' => 'https://painelsmilepro-production.up.railway.app/test_cancelado',
                    'expiredUrl' => 'https://painelsmilepro-production.up.railway.app/test_expirado',
                    'successUrl' => 'https://painelsmilepro-production.up.railway.app/test_sucesso'
                ],
                'items' => [
                    [
                        'name' => 'Teste Checkout',
                        'description' => 'Teste de integração',
                        'quantity' => 1,
                        'value' => 10.00  // Float, não string
                    ]
                ],
                'minutesToExpire' => 60
            ];
            
            echo '<p class="info">Enviando requisição de teste...</p>';
            echo '<div class="code"><pre>' . json_encode($test_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</pre></div>';
            
            $response = $helper->createCheckout($test_data);
            
            echo '<p class="success">✅ Checkout criado com sucesso!</p>';
            echo '<div class="code"><pre>' . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</pre></div>';
            
            if (isset($response['id'])) {
                echo '<p><strong>ID do Checkout:</strong> ' . h($response['id']) . '</p>';
                if (isset($response['checkoutUrl'])) {
                    echo '<p><strong>URL do Checkout:</strong> <a href="' . h($response['checkoutUrl']) . '" target="_blank">' . h($response['checkoutUrl']) . '</a></p>';
                }
            }
            
        } catch (Exception $e) {
            echo '<p class="error">❌ Erro ao criar checkout:</p>';
            $error_msg = $e->getMessage();
            echo '<div class="code">' . h($error_msg) . '</div>';
            
            // Detectar tipo específico de erro baseado nos códigos da API Asaas
            $error_code = null;
            if (preg_match('/\[([^\]]+)\]/', $error_msg, $matches)) {
                $error_code = $matches[1];
            }
            
            // Mensagens específicas baseadas na documentação oficial do Asaas
            if (strpos($error_msg, '401') !== false || strpos($error_msg, 'inválida') !== false) {
                echo '<div class="section" style="background: #fef2f2; border-color: #dc2626;">';
                echo '<h3>🔴 Erro 401 - Autenticação Falhou</h3>';
                
                // Mensagens específicas para cada código de erro
                if ($error_code === 'invalid_environment') {
                    echo '<p><strong>Erro:</strong> Uso de chave em ambiente incorreto</p>';
                    echo '<p>A chave de API informada não pertence a este ambiente.</p>';
                    echo '<p><strong>Como resolver:</strong></p>';
                    echo '<ul>';
                    echo '<li>Use chave de <strong>Produção</strong> (<code>$aact_prod_...</code>) nos endpoints de produção (<code>api.asaas.com</code>)</li>';
                    echo '<li>Use chave de <strong>Sandbox</strong> (<code>$aact_hmlg_...</code>) nos endpoints de sandbox (<code>api-sandbox.asaas.com</code>)</li>';
                    echo '</ul>';
                    
                } elseif ($error_code === 'access_token_not_found') {
                    echo '<p><strong>Erro:</strong> Cabeçalho de autenticação ausente</p>';
                    echo '<p>O cabeçalho de autenticação <code>access_token</code> é obrigatório e não foi encontrado na requisição.</p>';
                    echo '<p><strong>Como resolver:</strong></p>';
                    echo '<ul>';
                    echo '<li>Garanta que o cabeçalho <code>access_token</code> está sendo enviado corretamente em todas as requisições</li>';
                    echo '<li>Verifique se os headers estão no formato correto: <code>access_token: sua_chave_aqui</code></li>';
                    echo '</ul>';
                    
                } elseif ($error_code === 'invalid_access_token_format') {
                    echo '<p><strong>Erro:</strong> Formato da chave incorreto</p>';
                    echo '<p>O valor fornecido não parece ser uma chave de API válida do Asaas.</p>';
                    echo '<p><strong>Como resolver:</strong></p>';
                    echo '<ul>';
                    echo '<li>Verifique se você não copiou espaços extras ou caracteres a mais</li>';
                    echo '<li>Chaves de produção começam com <code>$aact_prod_</code></li>';
                    echo '<li>Chaves de Sandbox começam com <code>$aact_hmlg_</code></li>';
                    echo '</ul>';
                    
                } elseif ($error_code === 'invalid_access_token') {
                    echo '<p><strong>Erro:</strong> Chave de API inválida ou revogada</p>';
                    echo '<p>A chave de API fornecida é inválida.</p>';
                    echo '<p><strong>Como resolver:</strong></p>';
                    echo '<ul>';
                    echo '<li>Confirme se o valor da chave de API está correto</li>';
                    echo '<li>Verifique se ela não foi desabilitada, expirada ou excluída no painel Asaas</li>';
                    echo '<li>Acesse <strong>Asaas > Integrações > Chaves de API</strong> e gere uma nova chave se necessário</li>';
                    echo '</ul>';
                    
                } else {
                    // Erro 401 genérico
                    echo '<p><strong>Possíveis causas:</strong></p>';
                    echo '<ul>';
                    echo '<li>A chave no Railway está incorreta ou expirada</li>';
                    echo '<li>A chave não está sendo carregada do ENV corretamente</li>';
                    echo '<li>A chave está sendo enviada com formato incorreto no header</li>';
                    echo '<li>A chave é de sandbox mas está tentando usar em produção (ou vice-versa)</li>';
                    echo '</ul>';
                    echo '<p><strong>Como resolver:</strong></p>';
                    echo '<ol>';
                    echo '<li>Acesse o painel do Asaas</li>';
                    echo '<li>Vá em <strong>Integrações > Chaves de API</strong></li>';
                    echo '<li>Gere uma NOVA chave de API</li>';
                    echo '<li>Copie a chave COMPLETA (incluindo o $ no início se houver)</li>';
                    echo '<li>Cole no Railway na variável <code>ASAAS_API_KEY</code></li>';
                    echo '<li>Faça um redeploy no Railway</li>';
                    echo '</ol>';
                }
                
                echo '</div>';
            }
        }
    } else {
        echo '<form method="POST">';
        echo '<p>Este teste criará um checkout de teste no Asaas (R$ 10,00).</p>';
        echo '<button type="submit" name="test_checkout" class="btn">🧪 Criar Checkout de Teste</button>';
        echo '</form>';
    }
    
    echo '</div>';
    
    // PASSO 3: Verificar logs recentes
    echo '<div class="section">';
    echo '<h2>3. Logs Recentes do AsaasHelper</h2>';
    echo '<p class="info">Verifique os logs do Railway ou acesse: <a href="index.php?page=asaas_webhook_logs">Logs do Webhook Asaas</a></p>';
    echo '</div>';
    ?>
    
    <div class="section">
        <h2>4. Instruções</h2>
        <ol>
            <li>Verifique se a chave acima está correta</li>
            <li>Clique em "Criar Checkout de Teste" para testar a requisição real</li>
            <li>Se der erro 401, gere uma nova chave no painel do Asaas</li>
            <li>Atualize a variável <code>ASAAS_API_KEY</code> no Railway</li>
            <li>Faça redeploy e teste novamente</li>
        </ol>
    </div>
</body>
</html>

