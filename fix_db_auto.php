<?php
/**
 * fix_db_auto.php — Corrigir banco com detecção automática
 * Execute: php fix_db_auto.php
 */

echo "🔧 Corrigindo Problemas de Banco de Dados\n";
echo "==========================================\n\n";

// Função para tentar conectar com diferentes configurações
function tryConnect($host, $port, $dbname, $user, $password) {
    try {
        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (Exception $e) {
        return null;
    }
}

// Tentar diferentes configurações de banco
$configs = [
    ['localhost', '5432', 'painel_smile', 'postgres', ''],
    ['localhost', '5432', 'painel_smile', 'postgres', 'postgres'],
    ['localhost', '5432', 'painel_smile', 'postgres', 'password'],
    ['localhost', '5432', 'smilee12_painel_smile', 'postgres', ''],
    ['localhost', '5432', 'smilee12_painel_smile', 'postgres', 'postgres'],
    ['127.0.0.1', '5432', 'painel_smile', 'postgres', ''],
    ['127.0.0.1', '5432', 'painel_smile', 'postgres', 'postgres'],
];

$pdo = null;
$connected = false;

echo "🔍 Tentando conectar com o banco de dados...\n";

foreach ($configs as $i => $config) {
    list($host, $port, $dbname, $user, $password) = $config;
    
    echo "Tentativa " . ($i + 1) . ": $host:$port/$dbname (usuário: $user)\n";
    
    $pdo = tryConnect($host, $port, $dbname, $user, $password);
    
    if ($pdo) {
        echo "✅ Conexão estabelecida com sucesso!\n\n";
        $connected = true;
        break;
    } else {
        echo "❌ Falha na conexão\n";
    }
}

if (!$connected) {
    echo "\n❌ Não foi possível conectar com o banco de dados.\n";
    echo "\n💡 Verifique:\n";
    echo "1. Se o PostgreSQL está rodando: sudo service postgresql start\n";
    echo "2. Se o banco de dados existe\n";
    echo "3. Se as credenciais estão corretas\n";
    echo "4. Se a porta 5432 está aberta\n";
    exit(1);
}

try {
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
}
?>
