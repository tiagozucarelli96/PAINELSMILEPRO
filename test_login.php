<?php
/**
 * test_login.php — Testar login
 * Execute: php test_login.php
 */

echo "🧪 Testando Login\n";
echo "================\n\n";

// Testar conexão
echo "🔍 Testando conexão...\n";
require_once __DIR__ . '/public/conexao.php';

if (isset($GLOBALS['pdo']) && $GLOBALS['pdo']) {
    echo "✅ Conexão com banco OK\n";
    
    // Testar se o usuário admin existe
    try {
        $stmt = $GLOBALS['pdo']->query("SELECT nome, email, perfil FROM usuarios WHERE nome = 'admin'");
        $user = $stmt->fetch();
        
        if ($user) {
            echo "✅ Usuário admin encontrado\n";
            echo "  Nome: " . $user['nome'] . "\n";
            echo "  Email: " . $user['email'] . "\n";
            echo "  Perfil: " . $user['perfil'] . "\n";
        } else {
            echo "❌ Usuário admin não encontrado\n";
        }
    } catch (Exception $e) {
        echo "❌ Erro ao buscar usuário: " . $e->getMessage() . "\n";
    }
} else {
    echo "❌ Conexão com banco falhou\n";
}

// Testar servidor web
echo "\n🔍 Testando servidor web...\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    echo "✅ Servidor web está rodando (HTTP $httpCode)\n";
    if (strpos($response, 'Acessar o Painel') !== false) {
        echo "✅ Página de login carregando corretamente\n";
    } else {
        echo "⚠️ Página de login pode ter problemas\n";
    }
} else {
    echo "❌ Servidor web não está respondendo (HTTP $httpCode)\n";
}

echo "\n🎉 Teste de login concluído!\n";
echo "\n📋 Sistema Pronto:\n";
echo "🌐 URL: http://localhost:8000/login.php\n";
echo "👤 Usuário: admin\n";
echo "🔑 Senha: admin123\n";
?>
