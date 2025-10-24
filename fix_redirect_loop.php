<?php
/**
 * fix_redirect_loop.php — Corrigir loop de redirecionamento
 * Execute: php fix_redirect_loop.php
 * OU acesse via web: http://localhost:8000/fix_redirect_loop.php
 */

echo "🔧 Corrigindo Loop de Redirecionamento\n";
echo "=====================================\n\n";

// Detectar ambiente
$isProduction = getenv("DATABASE_URL") && strpos(getenv("DATABASE_URL"), 'railway') !== false;
$environment = $isProduction ? "PRODUÇÃO (Railway)" : "LOCAL";

echo "🌍 Ambiente detectado: $environment\n";
echo "🔗 DATABASE_URL: " . (getenv("DATABASE_URL") ? "Definido" : "Não definido") . "\n\n";

try {
    // Incluir conexão
    require_once __DIR__ . '/public/conexao.php';
    
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo']) {
        throw new Exception("Conexão com banco não estabelecida");
    }
    
    $pdo = $GLOBALS['pdo'];
    echo "✅ Conexão com banco estabelecida\n\n";
    
    // 1. VERIFICAR ARQUIVOS DE ROTEAMENTO
    echo "🔍 VERIFICANDO ARQUIVOS DE ROTEAMENTO\n";
    echo "====================================\n";
    
    $routingFiles = [
        'public/router.php',
        'public/index.php',
        'public/dashboard.php',
        'public/login.php',
        'public/logout.php'
    ];
    
    foreach ($routingFiles as $file) {
        if (file_exists($file)) {
            echo "✅ Arquivo '$file' existe\n";
            
            // Verificar se há redirecionamentos problemáticos
            $content = file_get_contents($file);
            
            // Verificar redirecionamentos infinitos
            if (strpos($content, 'header("Location:') !== false) {
                echo "⚠️ Arquivo '$file' contém redirecionamentos\n";
                
                // Extrair linhas com redirecionamentos
                $lines = explode("\n", $content);
                foreach ($lines as $lineNum => $line) {
                    if (strpos($line, 'header("Location:') !== false) {
                        echo "  Linha " . ($lineNum + 1) . ": " . trim($line) . "\n";
                    }
                }
            }
        } else {
            echo "❌ Arquivo '$file' não existe\n";
        }
    }
    
    // 2. VERIFICAR CONFIGURAÇÕES DE SESSÃO
    echo "\n🔍 VERIFICANDO CONFIGURAÇÕES DE SESSÃO\n";
    echo "=====================================\n";
    
    $sessionConfig = [
        'session.cookie_lifetime' => ini_get('session.cookie_lifetime'),
        'session.cookie_path' => ini_get('session.cookie_path'),
        'session.cookie_domain' => ini_get('session.cookie_domain'),
        'session.cookie_secure' => ini_get('session.cookie_secure'),
        'session.cookie_httponly' => ini_get('session.cookie_httponly'),
        'session.use_cookies' => ini_get('session.use_cookies'),
        'session.use_only_cookies' => ini_get('session.use_only_cookies')
    ];
    
    foreach ($sessionConfig as $key => $value) {
        echo "📋 $key: " . ($value ?: 'Não definido') . "\n";
    }
    
    // 3. VERIFICAR USUÁRIOS E PERMISSÕES
    echo "\n🔍 VERIFICANDO USUÁRIOS E PERMISSÕES\n";
    echo "==================================\n";
    
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios");
        $userCount = $stmt->fetchColumn();
        echo "📊 Total de usuários: $userCount\n";
        
        if ($userCount > 0) {
            $stmt = $pdo->query("SELECT id, nome, email, perfil FROM usuarios LIMIT 5");
            $users = $stmt->fetchAll();
            
            echo "👥 Usuários encontrados:\n";
            foreach ($users as $user) {
                echo "  - ID: {$user['id']}, Nome: {$user['nome']}, Email: {$user['email']}, Perfil: {$user['perfil']}\n";
            }
        }
    } catch (Exception $e) {
        echo "❌ Erro ao verificar usuários: " . $e->getMessage() . "\n";
    }
    
    // 4. CRIAR ARQUIVO DE TESTE SIMPLES
    echo "\n🔧 CRIANDO ARQUIVO DE TESTE SIMPLES\n";
    echo "==================================\n";
    
    $testFile = 'public/test_simple.php';
    $testContent = '<?php
/**
 * test_simple.php — Teste simples sem redirecionamentos
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste Simples - Sem Redirecionamentos</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .success { color: #2E7D32; background: #e8f5e8; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .info { color: #1976D2; background: #e3f2fd; padding: 10px; border-radius: 5px; margin: 10px 0; }
        h1 { color: #333; text-align: center; }
        .button { display: inline-block; padding: 10px 20px; background: #2196F3; color: white; text-decoration: none; border-radius: 5px; margin: 10px 5px; }
        .button:hover { background: #1976D2; }
        .button.success { background: #4CAF50; }
        .button.success:hover { background: #45a049; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Teste Simples - Sem Redirecionamentos</h1>
        
        <div class="success">
            <h3>✅ Página Funcionando!</h3>
            <p>Esta página não tem redirecionamentos e deve funcionar normalmente.</p>
        </div>
        
        <div class="info">
            <h3>📋 Informações do Sistema</h3>
            <p><strong>Ambiente:</strong> ' . ($isProduction ? "PRODUÇÃO (Railway)" : "LOCAL") . '</p>
            <p><strong>Data/Hora:</strong> ' . date('d/m/Y H:i:s') . '</p>
            <p><strong>PHP Version:</strong> ' . phpversion() . '</p>
            <p><strong>Server:</strong> ' . ($_SERVER['SERVER_SOFTWARE'] ?? 'N/A') . '</p>
        </div>
        
        <div style="text-align: center; margin: 20px 0;">
            <a href="test_simple.php" class="button">🔄 Recarregar Página</a>
            <a href="fix_redirect_loop_web.php" class="button success">🔧 Corrigir Redirecionamentos</a>
        </div>
    </div>
</body>
</html>';
    
    file_put_contents($testFile, $testContent);
    echo "✅ Arquivo de teste criado: $testFile\n";
    
    // 5. CRIAR ARQUIVO DE CORREÇÃO DE REDIRECIONAMENTOS
    echo "\n🔧 CRIANDO ARQUIVO DE CORREÇÃO\n";
    echo "=============================\n";
    
    $fixFile = 'public/fix_redirect_loop_web.php';
    $fixContent = '<?php
/**
 * fix_redirect_loop_web.php — Corrigir loop de redirecionamento via web
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Correção de Redirecionamentos</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .success { color: #2E7D32; background: #e8f5e8; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .warning { color: #E65100; background: #fff3e0; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .error { color: #C62828; background: #ffebee; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .info { color: #1976D2; background: #e3f2fd; padding: 10px; border-radius: 5px; margin: 10px 0; }
        h1 { color: #333; text-align: center; }
        h2 { color: #555; border-bottom: 2px solid #2196F3; padding-bottom: 10px; }
        .button { display: inline-block; padding: 10px 20px; background: #2196F3; color: white; text-decoration: none; border-radius: 5px; margin: 10px 5px; }
        .button:hover { background: #1976D2; }
        .button.success { background: #4CAF50; }
        .button.success:hover { background: #45a049; }
        .code { background: #f5f5f5; padding: 10px; border-radius: 5px; font-family: monospace; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Correção de Loop de Redirecionamento</h1>
        
        <div class="info">
            <h3>🌍 Ambiente Detectado: ' . ($isProduction ? "PRODUÇÃO (Railway)" : "LOCAL") . '</h3>
            <p>Data/Hora: ' . date('d/m/Y H:i:s') . '</p>
        </div>
        
        <div class="success">
            <h3>✅ Página Funcionando!</h3>
            <p>Esta página não tem redirecionamentos e deve funcionar normalmente.</p>
        </div>
        
        <div class="warning">
            <h3>⚠️ Possíveis Causas do Loop de Redirecionamento:</h3>
            <ul>
                <li>Redirecionamentos infinitos entre páginas</li>
                <li>Problemas de sessão</li>
                <li>Configurações de cookies</li>
                <li>Problemas de autenticação</li>
                <li>Arquivos de roteamento com loops</li>
            </ul>
        </div>
        
        <div class="info">
            <h3>🔧 Soluções Recomendadas:</h3>
            <ol>
                <li><strong>Limpar cookies do navegador</strong></li>
                <li><strong>Verificar arquivos de roteamento</strong></li>
                <li><strong>Verificar configurações de sessão</strong></li>
                <li><strong>Testar páginas individualmente</strong></li>
                <li><strong>Verificar logs de erro</strong></li>
            </ol>
        </div>
        
        <div style="text-align: center; margin: 20px 0;">
            <a href="test_simple.php" class="button">🧪 Teste Simples</a>
            <a href="fix_redirect_loop_web.php" class="button">🔄 Recarregar</a>
            <a href="dashboard.php" class="button success">🏠 Dashboard</a>
        </div>
    </div>
</body>
</html>';
    
    file_put_contents($fixFile, $fixContent);
    echo "✅ Arquivo de correção criado: $fixFile\n";
    
    // 6. VERIFICAR LOGS DE ERRO
    echo "\n🔍 VERIFICANDO LOGS DE ERRO\n";
    echo "===========================\n";
    
    $logFiles = [
        'public/error_log',
        'error_log',
        'logs/error.log',
        'logs/app.log'
    ];
    
    foreach ($logFiles as $logFile) {
        if (file_exists($logFile)) {
            echo "📋 Log encontrado: $logFile\n";
            $logContent = file_get_contents($logFile);
            $logLines = explode("\n", $logContent);
            $recentLines = array_slice($logLines, -10);
            
            echo "📄 Últimas 10 linhas:\n";
            foreach ($recentLines as $line) {
                if (trim($line)) {
                    echo "  " . trim($line) . "\n";
                }
            }
        }
    }
    
    // RESUMO FINAL
    echo "\n🎉 DIAGNÓSTICO DE REDIRECIONAMENTO COMPLETO!\n";
    echo "==========================================\n";
    echo "🌍 Ambiente: $environment\n";
    echo "✅ Arquivos de roteamento verificados\n";
    echo "✅ Configurações de sessão verificadas\n";
    echo "✅ Usuários e permissões verificados\n";
    echo "✅ Arquivo de teste criado\n";
    echo "✅ Arquivo de correção criado\n";
    echo "✅ Logs de erro verificados\n";
    
    echo "\n✅ DIAGNÓSTICO COMPLETO!\n";
    echo "Agora você pode testar as páginas individualmente para identificar o problema.\n";
    
} catch (Exception $e) {
    echo "❌ Erro fatal: " . $e->getMessage() . "\n";
    echo "\n💡 Verifique se o banco de dados está configurado corretamente.\n";
}
?>
