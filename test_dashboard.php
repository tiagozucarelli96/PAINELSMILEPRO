<?php
/**
 * test_dashboard.php — Testar se o dashboard está funcionando
 * Execute: php test_dashboard.php
 */

echo "🧪 Testando Dashboard\n";
echo "====================\n\n";

// Testar conexão com banco
echo "🔍 Testando conexão com banco...\n";

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
    
    echo "✅ Conexão com banco OK\n";
    
    // Testar se a coluna perm_agenda_ver existe
    echo "\n🔍 Testando coluna perm_agenda_ver...\n";
    
    $stmt = $pdo->query("SELECT perm_agenda_ver FROM usuarios LIMIT 1");
    echo "✅ Coluna perm_agenda_ver existe e funciona\n";
    
    // Testar se o servidor web está rodando
    echo "\n🔍 Testando servidor web...\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/healthz.txt');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        echo "✅ Servidor web está rodando (HTTP $httpCode)\n";
        echo "✅ Resposta: $response\n";
    } else {
        echo "❌ Servidor web não está respondendo (HTTP $httpCode)\n";
    }
    
    echo "\n🎉 Todos os testes passaram!\n";
    echo "\n📋 Próximos passos:\n";
    echo "1. Acesse: http://localhost:8000/dashboard.php\n";
    echo "2. Se ainda houver erros, execute: php fix_db_correct.php\n";
    echo "3. Para parar o servidor: pkill -f 'php -S localhost:8000'\n";
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    echo "\n💡 Execute: php fix_db_correct.php\n";
}
?>
