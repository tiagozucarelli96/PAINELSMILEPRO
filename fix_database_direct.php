<?php
/**
 * fix_database_direct.php — Corrigir problemas de banco diretamente
 * Execute: php fix_database_direct.php
 */

// Incluir conexão
require_once __DIR__ . '/public/conexao.php';

echo "🔧 Corrigindo Problemas de Banco de Dados\n";
echo "==========================================\n\n";

try {
    $pdo = $GLOBALS['pdo'];
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
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
    
    // 5. Verificar e corrigir índices
    echo "\n🔍 Verificando índices...\n";
    
    $indexes = [
        'idx_usuarios_email' => 'usuarios(email)',
        'idx_usuarios_perfil' => 'usuarios(perfil)'
    ];
    
    foreach ($indexes as $indexName => $tableColumn) {
        try {
            $stmt = $pdo->query("
                SELECT indexname 
                FROM pg_indexes 
                WHERE indexname = '$indexName'
            ");
            
            if ($stmt->fetch()) {
                echo "✅ Índice $indexName já existe\n";
            } else {
                echo "⚠️ Índice $indexName não existe, criando...\n";
                
                try {
                    $pdo->exec("CREATE INDEX IF NOT EXISTS $indexName ON $tableColumn");
                    echo "✅ Índice $indexName criado com sucesso\n";
                } catch (Exception $e) {
                    echo "❌ Erro ao criar índice $indexName: " . $e->getMessage() . "\n";
                }
            }
        } catch (Exception $e) {
            echo "❌ Erro ao verificar índice $indexName: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n🎉 Correções concluídas com sucesso!\n";
    echo "Agora você pode tentar acessar o dashboard novamente.\n";
    
} catch (Exception $e) {
    echo "❌ Erro fatal: " . $e->getMessage() . "\n";
    echo "Verifique se o banco de dados está configurado corretamente.\n";
}
?>
