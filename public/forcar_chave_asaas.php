<?php
// forcar_chave_asaas.php - Script para forçar atualização da chave Asaas
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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Forçar Nova Chave Asaas</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .box { background: white; padding: 20px; margin: 10px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .success { color: #10b981; font-weight: bold; }
        .error { color: #dc2626; font-weight: bold; }
        .warning { color: #f59e0b; font-weight: bold; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
        textarea { width: 100%; height: 100px; font-family: monospace; font-size: 11px; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; }
        .btn { background: #3b82f6; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; margin: 5px; }
        .btn:hover { background: #2563eb; }
        .btn-danger { background: #dc2626; }
        .btn-danger:hover { background: #b91c1c; }
        pre { background: #1e293b; color: #e2e8f0; padding: 15px; border-radius: 6px; overflow-x: auto; font-size: 11px; }
    </style>
</head>
<body>
    <div class="box">
        <h1>🔧 Forçar Nova Chave Asaas</h1>
        <p>Este script vai diagnosticar e tentar forçar o uso da nova chave.</p>
    </div>

    <?php
    // NOVA CHAVE CORRETA
    $NOVA_CHAVE = '$aact_prod_000MzkwODA2MWY2OGM3MWRlMDU2NWM3MzJlNzZmNGZhZGY6OjA2OTVjYTRhLTgzNTctNDkzNC1hMmQyLTEyOTNmMWFjY2NjYjo6JGFhY2hfMmRlNDE2ZTktMzk2OS00YTYzLTkyYmYtNzg2NzUzNmY5NTVl';
    
    echo '<div class="box">';
    echo '<h2>1. Diagnóstico Atual</h2>';
    
    // Verificar todas as fontes possíveis
    $env_key = $_ENV['ASAAS_API_KEY'] ?? null;
    $getenv_key = getenv('ASAAS_API_KEY') ?: null;
    
    require_once __DIR__ . '/config_env.php';
    $const_key = defined('ASAAS_API_KEY') ? ASAAS_API_KEY : null;
    
    $chave_atual = $env_key ?? $getenv_key ?? $const_key;
    
    echo '<p><strong>$_ENV["ASAAS_API_KEY"]:</strong> ';
    if ($env_key) {
        echo '<span class="success">✅ Encontrada</span> (' . strlen($env_key) . ' chars)';
        echo '<br><code>' . htmlspecialchars(substr($env_key, 0, 50)) . '...</code>';
    } else {
        echo '<span class="error">❌ Não encontrada</span>';
    }
    echo '</p>';
    
    echo '<p><strong>getenv("ASAAS_API_KEY"):</strong> ';
    if ($getenv_key) {
        echo '<span class="success">✅ Encontrada</span> (' . strlen($getenv_key) . ' chars)';
        echo '<br><code>' . htmlspecialchars(substr($getenv_key, 0, 50)) . '...</code>';
    } else {
        echo '<span class="error">❌ Não encontrada</span>';
    }
    echo '</p>';
    
    echo '<p><strong>Constante ASAAS_API_KEY:</strong> ';
    if ($const_key) {
        echo '<span class="success">✅ Encontrada</span> (' . strlen($const_key) . ' chars)';
        echo '<br><code>' . htmlspecialchars(substr($const_key, 0, 50)) . '...</code>';
    } else {
        echo '<span class="error">❌ Não encontrada</span>';
    }
    echo '</p>';
    
    echo '<p><strong>Chave que será usada (prioridade):</strong> ';
    if ($chave_atual) {
        $fonte = $env_key ? '$_ENV' : ($getenv_key ? 'getenv' : 'CONSTANTE');
        echo '<span class="info">' . $fonte . '</span> (' . strlen($chave_atual) . ' chars)';
        echo '<br><code>' . htmlspecialchars(substr($chave_atual, 0, 50)) . '...</code>';
        
        // Comparar com nova chave
        if ($chave_atual === $NOVA_CHAVE) {
            echo '<br><span class="success">✅ A chave atual é a NOVA chave!</span>';
        } else {
            echo '<br><span class="error">❌ A chave atual é DIFERENTE da nova chave!</span>';
            echo '<br><span class="warning">⚠️ Railway ENV pode não ter sido atualizado corretamente.</span>';
        }
    } else {
        echo '<span class="error">❌ Nenhuma chave encontrada!</span>';
    }
    echo '</p>';
    
    echo '</div>';
    
    if (isset($_POST['forcar_chave'])) {
        echo '<div class="box">';
        echo '<h2>2. Tentativa de Forçar Nova Chave</h2>';
        
        // Tentar definir no nível do script (temporário)
        $_ENV['ASAAS_API_KEY'] = $NOVA_CHAVE;
        putenv('ASAAS_API_KEY=' . $NOVA_CHAVE);
        
        echo '<p>✅ Chave definida temporariamente neste script.</p>';
        echo '<p><strong>Nova chave (primeiros 50):</strong> <code>' . htmlspecialchars(substr($NOVA_CHAVE, 0, 50)) . '...</code></p>';
        echo '<p><strong>Tamanho:</strong> ' . strlen($NOVA_CHAVE) . ' caracteres</p>';
        
        // Testar se funcionou
        $helper = new AsaasHelper();
        
        // Usar reflection para ver a chave que está sendo usada
        $reflection = new ReflectionClass($helper);
        $property = $reflection->getProperty('api_key');
        $property->setAccessible(true);
        $chave_helper = $property->getValue($helper);
        
        echo '<h3>Chave no AsaasHelper:</h3>';
        echo '<p><strong>Tamanho:</strong> ' . strlen($chave_helper) . ' caracteres</p>';
        echo '<p><strong>Primeiros 50:</strong> <code>' . htmlspecialchars(substr($chave_helper, 0, 50)) . '...</code></p>';
        
        if ($chave_helper === $NOVA_CHAVE) {
            echo '<p class="success">✅ AsaasHelper está usando a NOVA chave!</p>';
        } else {
            echo '<p class="error">❌ AsaasHelper ainda está usando chave diferente!</p>';
            echo '<p class="warning">O problema pode ser cache do PHP/OPcache.</p>';
        }
        
        echo '</div>';
        
        // Testar requisição real
        echo '<div class="box">';
        echo '<h2>3. Teste Real da API</h2>';
        
        try {
            // Testar com endpoint simples
            $endpoint = 'https://api.asaas.com/v3/myAccount';
            
            $ch = curl_init();
            $headers = [
                'Content-Type: application/json',
                'User-Agent: PainelSmilePRO/1.0',
                'access_token: ' . $NOVA_CHAVE
            ];
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true
            ]);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            echo '<p><strong>HTTP Code:</strong> ' . $http_code . '</p>';
            
            if ($http_code === 200) {
                echo '<p class="success">✅ SUCESSO! A nova chave funciona!</p>';
                $decoded = json_decode($response, true);
                if ($decoded && isset($decoded['name'])) {
                    echo '<p>Conta: ' . htmlspecialchars($decoded['name']) . '</p>';
                }
            } else {
                echo '<p class="error">❌ Erro ' . $http_code . '</p>';
                echo '<pre>' . htmlspecialchars($response) . '</pre>';
            }
            
        } catch (Exception $e) {
            echo '<p class="error">❌ Erro: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        
        echo '</div>';
    }
    
    if (isset($_POST['limpar_opcache'])) {
        echo '<div class="box">';
        echo '<h2>4. Tentativa de Limpar OPcache</h2>';
        
        if (function_exists('opcache_reset')) {
            opcache_reset();
            echo '<p class="success">✅ OPcache resetado!</p>';
        } else {
            echo '<p class="warning">⚠️ OPcache não está habilitado ou não disponível.</p>';
        }
        
        // Também limpar cache de arquivos
        clearstatcache();
        echo '<p>✅ Cache de arquivos limpo.</p>';
        
        echo '</div>';
    }
    ?>

    <div class="box">
        <h2>5. Ações Disponíveis</h2>
        <form method="POST" style="margin: 10px 0;">
            <button type="submit" name="forcar_chave" class="btn">🔧 Forçar Nova Chave (Teste)</button>
            <button type="submit" name="limpar_opcache" class="btn">🧹 Limpar OPcache</button>
        </form>
        
        <div style="background: #fee2e2; border: 1px solid #dc2626; padding: 15px; border-radius: 6px; margin-top: 20px;">
            <p><strong>⚠️ SOLUÇÃO DEFINITIVA:</strong></p>
            <ol>
                <li><strong>Verificar no Railway:</strong>
                    <ul>
                        <li>Acesse Variables no Railway</li>
                        <li>Verifique se <code>ASAAS_API_KEY</code> tem EXATAMENTE 200 caracteres</li>
                        <li>Se não, <strong>delete a variável e crie novamente</strong></li>
                    </ul>
                </li>
                <li><strong>Verificar outras variáveis:</strong>
                    <ul>
                        <li>Procure por variáveis similares: <code>ASAAS_API</code>, <code>ASAS_API_KEY</code>, etc.</li>
                        <li>Pode haver variável com nome diferente sendo usada</li>
                    </ul>
                </li>
                <li><strong>Verificar em outros serviços:</strong>
                    <ul>
                        <li>Se há múltiplos serviços no Railway, verifique qual está rodando</li>
                        <li>Certifique-se de atualizar no serviço correto</li>
                    </ul>
                </li>
                <li><strong>Logs do Railway:</strong>
                    <ul>
                        <li>Verifique os logs do deploy para ver se há erros</li>
                        <li>Confirme que o redeploy realmente aconteceu</li>
                    </ul>
                </li>
            </ol>
        </div>
    </div>
</body>
</html>

