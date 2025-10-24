<?php
/**
 * test_connection.php — Testar conexão
 * Execute: php test_connection.php
 */

echo "🔍 Testando Conexão\n";
echo "==================\n\n";

// Testar conexão direta
echo "1. Testando conexão direta...\n";
try {
    $host = 'localhost';
    $port = '5432';
    $dbname = 'painel_smile';
    $user = 'tiagozucarelli';
    $password = '';
    
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "✅ Conexão direta OK\n";
    
    // Testar se a tabela usuarios existe
    $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios");
    $count = $stmt->fetchColumn();
    echo "✅ Tabela usuarios existe ($count registros)\n";
    
} catch (Exception $e) {
    echo "❌ Erro na conexão direta: " . $e->getMessage() . "\n";
}

// Testar conexão via config_local.php
echo "\n2. Testando conexão via config_local.php...\n";
try {
    require_once __DIR__ . '/config_local.php';
    $pdo = getLocalDbConnection();
    echo "✅ Conexão via config_local.php OK\n";
    
    // Testar se a tabela usuarios existe
    $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios");
    $count = $stmt->fetchColumn();
    echo "✅ Tabela usuarios existe ($count registros)\n";
    
} catch (Exception $e) {
    echo "❌ Erro na conexão via config_local.php: " . $e->getMessage() . "\n";
}

// Testar conexão via conexao.php
echo "\n3. Testando conexão via conexao.php...\n";
try {
    require_once __DIR__ . '/public/conexao.php';
    
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo']) {
        echo "✅ Conexão via conexao.php OK\n";
        
        // Testar se a tabela usuarios existe
        $stmt = $GLOBALS['pdo']->query("SELECT COUNT(*) FROM usuarios");
        $count = $stmt->fetchColumn();
        echo "✅ Tabela usuarios existe ($count registros)\n";
    } else {
        echo "❌ GLOBALS['pdo'] não está definido\n";
    }
    
} catch (Exception $e) {
    echo "❌ Erro na conexão via conexao.php: " . $e->getMessage() . "\n";
}

echo "\n🎉 Teste de conexão concluído!\n";
?>
