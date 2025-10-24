<?php
/**
 * fix_db_simple.php — Corrigir banco de forma simples
 * Execute: php fix_db_simple.php
 */

echo "🔧 Corrigindo Problemas de Banco de Dados\n";
echo "==========================================\n\n";

// Configurações de banco (ajuste conforme necessário)
$host = 'localhost';
$port = '5432';
$dbname = 'painel_smile'; // ou o nome do seu banco
$user = 'postgres'; // ou seu usuário
$password = ''; // sua senha

try {
    // Tentar conectar com diferentes configurações
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "✅ Conexão com banco estabelecida\n\n";
    
    // 1. Verificar e corrigir coluna perm_agenda_ver
    echo "🔍 Verificando coluna perm_agenda_ver...\n";
    
    try {
        $stmt = $pdo->query("SELECT perm_agenda_ver FROM usuarios LIMIT 1");
        echo "✅ Coluna perm_agenda_ver já existe\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'does not exist') !== false) {
            echo "⚠️ Coluna perm_agenda_ver não existe, criando...\n";
            
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN perm_agenda_ver BOOLEAN DEFAULT false");
            echo "✅ Coluna perm_agenda_ver criada com sucesso\n";
        } else {
            echo "❌ Erro: " . $e->getMessage() . "\n";
        }
    }
    
    // 2. Verificar e corrigir outras colunas de permissão
    echo "\n🔍 Verificando outras colunas de permissão...\n";
    
    $permissionColumns = [
        'perm_agenda_editar' => 'BOOLEAN DEFAULT false',
        'perm_agenda_criar' => 'BOOLEAN DEFAULT false',
        'perm_agenda_excluir' => 'BOOLEAN DEFAULT false',
        'perm_demandas_ver' => 'BOOLEAN DEFAULT false',
        'perm_demandas_editar' => 'BOOLEAN DEFAULT false',
        'perm_demandas_criar' => 'BOOLEAN DEFAULT false',
        'perm_demandas_excluir' => 'BOOLEAN DEFAULT false',
        'perm_demandas_ver_produtividade' => 'BOOLEAN DEFAULT false',
        'perm_comercial_ver' => 'BOOLEAN DEFAULT false',
        'perm_comercial_deg_editar' => 'BOOLEAN DEFAULT false',
        'perm_comercial_deg_inscritos' => 'BOOLEAN DEFAULT false',
        'perm_comercial_conversao' => 'BOOLEAN DEFAULT false'
    ];
    
    foreach ($permissionColumns as $column => $type) {
        try {
            $stmt = $pdo->query("SELECT $column FROM usuarios LIMIT 1");
            echo "✅ Coluna $column já existe\n";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'does not exist') !== false) {
                echo "⚠️ Coluna $column não existe, criando...\n";
                
                try {
                    $pdo->exec("ALTER TABLE usuarios ADD COLUMN $column $type");
                    echo "✅ Coluna $column criada com sucesso\n";
                } catch (Exception $e2) {
                    echo "❌ Erro ao criar $column: " . $e2->getMessage() . "\n";
                }
            }
        }
    }
    
    // 3. Verificar e corrigir colunas essenciais
    echo "\n🔍 Verificando colunas essenciais...\n";
    
    $essentialColumns = [
        'email' => 'VARCHAR(255)',
        'perfil' => 'VARCHAR(50) DEFAULT \'CONSULTA\''
    ];
    
    foreach ($essentialColumns as $column => $type) {
        try {
            $stmt = $pdo->query("SELECT $column FROM usuarios LIMIT 1");
            echo "✅ Coluna $column já existe\n";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'does not exist') !== false) {
                echo "⚠️ Coluna $column não existe, criando...\n";
                
                try {
                    $pdo->exec("ALTER TABLE usuarios ADD COLUMN $column $type");
                    echo "✅ Coluna $column criada com sucesso\n";
                } catch (Exception $e2) {
                    echo "❌ Erro ao criar $column: " . $e2->getMessage() . "\n";
                }
            }
        }
    }
    
    // 4. Verificar e corrigir tabelas essenciais
    echo "\n🔍 Verificando tabelas essenciais...\n";
    
    $essentialTables = [
        'demandas_logs' => "
            CREATE TABLE IF NOT EXISTS demandas_logs (
                id SERIAL PRIMARY KEY,
                usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
                acao VARCHAR(255) NOT NULL,
                detalhes TEXT,
                created_at TIMESTAMP DEFAULT NOW()
            )
        ",
        'demandas_configuracoes' => "
            CREATE TABLE IF NOT EXISTS demandas_configuracoes (
                id SERIAL PRIMARY KEY,
                chave VARCHAR(255) UNIQUE NOT NULL,
                valor TEXT,
                created_at TIMESTAMP DEFAULT NOW()
            )
        "
    ];
    
    foreach ($essentialTables as $table => $sql) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM $table LIMIT 1");
            echo "✅ Tabela $table já existe\n";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'does not exist') !== false) {
                echo "⚠️ Tabela $table não existe, criando...\n";
                
                try {
                    $pdo->exec($sql);
                    echo "✅ Tabela $table criada com sucesso\n";
                } catch (Exception $e2) {
                    echo "❌ Erro ao criar $table: " . $e2->getMessage() . "\n";
                }
            }
        }
    }
    
    echo "\n🎉 Correções concluídas com sucesso!\n";
    echo "Agora você pode tentar acessar o dashboard novamente.\n";
    
} catch (Exception $e) {
    echo "❌ Erro fatal: " . $e->getMessage() . "\n";
    echo "\n💡 Dicas para resolver:\n";
    echo "1. Verifique se o PostgreSQL está rodando\n";
    echo "2. Verifique as credenciais de banco no arquivo\n";
    echo "3. Verifique se o banco de dados existe\n";
    echo "4. Tente executar: sudo service postgresql start\n";
}
?>
