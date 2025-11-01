<?php
// test_asaas_debug.php - Teste super detalhado para debug de chave Asaas
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
    <title>Debug Detalhado - Asaas API</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .box { background: white; padding: 20px; margin: 10px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .success { color: #10b981; }
        .error { color: #dc2626; }
        .warning { color: #f59e0b; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
        pre { background: #1e293b; color: #e2e8f0; padding: 15px; border-radius: 6px; overflow-x: auto; }
        .btn { background: #3b82f6; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; }
        .btn:hover { background: #2563eb; }
    </style>
</head>
<body>
    <div class="box">
        <h1>🔍 Debug Detalhado - Asaas API</h1>
        <p>Este teste faz uma requisição REAL à API Asaas e mostra TODOS os detalhes.</p>
    </div>

    <?php
    require_once __DIR__ . '/asaas_helper.php';

    // Carregar chave
    $env_key = $_ENV['ASAAS_API_KEY'] ?? getenv('ASAAS_API_KEY') ?: null;
    require_once __DIR__ . '/config_env.php';
    $const_key = defined('ASAAS_API_KEY') ? ASAAS_API_KEY : null;
    $chave_usada = $env_key ?? $const_key;

    echo '<div class="box">';
    echo '<h2>1. Informações da Chave</h2>';
    echo '<p><strong>Chave do ENV:</strong> ' . ($env_key ? '✅ Encontrada' : '❌ Não encontrada') . '</p>';
    echo '<p><strong>Chave da Constante:</strong> ' . ($const_key ? '✅ Encontrada' : '❌ Não encontrada') . '</p>';
    echo '<p><strong>Chave que será usada:</strong> ' . ($env_key ? 'ENV' : 'Constante') . '</p>';
    if ($chave_usada) {
        echo '<p><strong>Primeiros 50 caracteres:</strong> <code>' . htmlspecialchars(substr($chave_usada, 0, 50)) . '...</code></p>';
        echo '<p><strong>Últimos 30 caracteres:</strong> <code>...' . htmlspecialchars(substr($chave_usada, -30)) . '</code></p>';
        echo '<p><strong>Tamanho total:</strong> ' . strlen($chave_usada) . ' caracteres</p>';
        echo '<p><strong>Começa com $:</strong> ' . (strpos($chave_usada, '$') === 0 ? '✅ SIM' : '❌ NÃO') . '</p>';
        
        // Mostrar chave COMPLETA em um campo oculto para copiar (devido à segurança, só em debug)
        echo '<p><strong>Chave completa (APENAS para comparação manual):</strong></p>';
        echo '<textarea readonly style="width: 100%; height: 60px; font-family: monospace; font-size: 10px;" onclick="this.select()">' . htmlspecialchars($chave_usada) . '</textarea>';
    }
    echo '</div>';

    if (isset($_POST['test'])) {
        echo '<div class="box">';
        echo '<h2>2. Teste Real com API Asaas</h2>';
        
        try {
            $helper = new AsaasHelper();
            
            // Fazer requisição de teste - buscar informações da conta
            // Endpoint simples que não precisa de dados
            $endpoint = 'https://api.asaas.com/v3/myAccount';
            
            echo '<p>Tentando acessar: <code>' . htmlspecialchars($endpoint) . '</code></p>';
            
            // Usar cURL diretamente para ter controle total
            $ch = curl_init();
            
            // Preparar chave
            $api_key = $chave_usada;
            if (strpos($api_key, '$') !== 0) {
                if (strpos($api_key, 'aact_prod_') === 0 || strpos($api_key, 'aact_hmlg_') === 0) {
                    $api_key = '$' . $api_key;
                }
            }
            
            $headers = [
                'Content-Type: application/json',
                'User-Agent: PainelSmilePRO/1.0',
                'access_token: ' . $api_key
            ];
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_VERBOSE => true
            ]);
            
            $verbose = fopen('php://temp', 'w+');
            curl_setopt($ch, CURLOPT_STDERR, $verbose);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            rewind($verbose);
            $verbose_log = stream_get_contents($verbose);
            fclose($verbose);
            
            curl_close($ch);
            
            echo '<h3>Detalhes da Requisição:</h3>';
            echo '<p><strong>URL:</strong> <code>' . htmlspecialchars($endpoint) . '</code></p>';
            echo '<p><strong>Headers enviados:</strong></p>';
            echo '<pre>' . htmlspecialchars(implode("\n", $headers)) . '</pre>';
            
            echo '<p><strong>Chave usada no header (primeiros 40):</strong> <code>' . htmlspecialchars(substr($api_key, 0, 40)) . '...</code></p>';
            
            echo '<h3>Resposta:</h3>';
            echo '<p><strong>HTTP Code:</strong> ' . $http_code . '</p>';
            
            if ($error) {
                echo '<p class="error"><strong>Erro cURL:</strong> ' . htmlspecialchars($error) . '</p>';
            }
            
            if ($verbose_log) {
                echo '<h3>Log Verbose (cURL):</h3>';
                echo '<pre style="font-size: 11px;">' . htmlspecialchars($verbose_log) . '</pre>';
            }
            
            echo '<p><strong>Resposta completa:</strong></p>';
            echo '<pre>' . htmlspecialchars($response) . '</pre>';
            
            $decoded = json_decode($response, true);
            if ($decoded) {
                echo '<p><strong>Resposta decodificada (JSON):</strong></p>';
                echo '<pre>' . htmlspecialchars(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
                
                if ($http_code === 200 && isset($decoded['name'])) {
                    echo '<p class="success"><strong>✅ SUCESSO!</strong> Autenticação funcionou!</p>';
                    echo '<p>Nome da conta: ' . htmlspecialchars($decoded['name']) . '</p>';
                } elseif (isset($decoded['errors'])) {
                    echo '<p class="error"><strong>❌ ERRO:</strong></p>';
                    foreach ($decoded['errors'] as $err) {
                        echo '<p>Code: <code>' . htmlspecialchars($err['code'] ?? 'N/A') . '</code></p>';
                        echo '<p>Description: ' . htmlspecialchars($err['description'] ?? 'N/A') . '</p>';
                    }
                }
            }
            
        } catch (Exception $e) {
            echo '<p class="error"><strong>❌ Exceção:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        
        echo '</div>';
    } else {
        echo '<div class="box">';
        echo '<form method="POST">';
        echo '<p>Este teste fará uma requisição REAL à API Asaas para verificar se a autenticação está funcionando.</p>';
        echo '<button type="submit" name="test" class="btn">🔍 Executar Teste Detalhado</button>';
        echo '</form>';
        echo '</div>';
    }
    ?>

    <div class="box">
        <h2>3. Instruções</h2>
        <ol>
            <li>Clique em "Executar Teste Detalhado"</li>
            <li>Verifique o <strong>HTTP Code</strong>:
                <ul>
                    <li><strong>200:</strong> ✅ Chave está válida!</li>
                    <li><strong>401:</strong> ❌ Chave inválida - verifique no painel Asaas</li>
                </ul>
            </li>
            <li>Se der 401, compare a chave mostrada acima com a chave no painel Asaas</li>
            <li>Copie a chave EXATA do painel Asaas (incluindo o $) e atualize no Railway</li>
        </ol>
    </div>
</body>
</html>

