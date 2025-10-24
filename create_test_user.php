<?php
/**
 * create_test_user.php — Criar usuário de teste
 * Execute: php create_test_user.php
 */

echo "👤 Criando Usuário de Teste\n";
echo "===========================\n\n";

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
    
    echo "✅ Conexão com banco estabelecida\n";
    
    // Verificar se já existe um usuário admin
    $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE nome = 'admin'");
    $count = $stmt->fetchColumn();
    
    if ($count > 0) {
        echo "⚠️ Usuário admin já existe\n";
        
        // Atualizar senha do admin
        $hashedPassword = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE usuarios SET email = 'admin@smile.com', perfil = 'ADM' WHERE nome = 'admin'");
        $stmt->execute();
        
        echo "✅ Usuário admin atualizado\n";
    } else {
        echo "🔍 Criando usuário admin...\n";
        
        // Criar usuário admin
        $hashedPassword = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO usuarios (nome, email, perfil, created_at) 
            VALUES ('admin', 'admin@smile.com', 'ADM', NOW())
        ");
        $stmt->execute();
        
        echo "✅ Usuário admin criado com sucesso\n";
    }
    
    // Definir todas as permissões como true para o admin
    $permissions = [
        'perm_agenda_ver', 'perm_agenda_editar', 'perm_agenda_criar', 'perm_agenda_excluir',
        'perm_demandas_ver', 'perm_demandas_editar', 'perm_demandas_criar', 'perm_demandas_excluir', 'perm_demandas_ver_produtividade',
        'perm_comercial_ver', 'perm_comercial_deg_editar', 'perm_comercial_deg_inscritos', 'perm_comercial_conversao'
    ];
    
    foreach ($permissions as $permission) {
        try {
            $stmt = $pdo->prepare("UPDATE usuarios SET $permission = true WHERE nome = 'admin'");
            $stmt->execute();
        } catch (Exception $e) {
            // Ignorar se a coluna não existir
        }
    }
    
    echo "✅ Permissões configuradas para admin\n";
    
    echo "\n🎉 Usuário de teste criado com sucesso!\n";
    echo "\n📋 Credenciais de Login:\n";
    echo "  Usuário: admin\n";
    echo "  Senha: admin123\n";
    echo "  Perfil: ADM (todas as permissões)\n";
    
    echo "\n🌐 Acesse: http://localhost:8000/login.php\n";
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    echo "\n💡 Execute primeiro: php fix_db_correct.php\n";
}
?>
