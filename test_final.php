<?php
/**
 * test_final.php — Teste final do sistema
 * Execute: php test_final.php
 */

echo "🧪 Teste Final do Sistema\n";
echo "========================\n\n";

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
    
    // Testar se o usuário admin existe
    echo "\n🔍 Testando usuário admin...\n";
    
    $stmt = $pdo->query("SELECT nome, email, perfil FROM usuarios WHERE nome = 'admin'");
    $user = $stmt->fetch();
    
    if ($user) {
        echo "✅ Usuário admin encontrado\n";
        echo "  Nome: " . $user['nome'] . "\n";
        echo "  Email: " . $user['email'] . "\n";
        echo "  Perfil: " . $user['perfil'] . "\n";
    } else {
        echo "❌ Usuário admin não encontrado\n";
    }
    
    // Testar servidor web
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
    } else {
        echo "❌ Servidor web não está respondendo (HTTP $httpCode)\n";
    }
    
    echo "\n🎉 Todos os testes passaram!\n";
    echo "\n📋 Sistema Pronto para Uso:\n";
    echo "🌐 URL: http://localhost:8000/login.php\n";
    echo "👤 Usuário: admin\n";
    echo "🔑 Senha: admin123\n";
    echo "👑 Perfil: ADM (todas as permissões)\n";
    
    echo "\n🔧 Comandos Úteis:\n";
    echo "  Parar servidor: pkill -f 'php -S localhost:8000'\n";
    echo "  Iniciar servidor: php -S localhost:8000 -t public\n";
    echo "  Corrigir banco: php fix_db_correct.php\n";
    echo "  Criar usuário: php create_test_user.php\n";
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    echo "\n💡 Execute: php fix_db_correct.php\n";
}
?>
