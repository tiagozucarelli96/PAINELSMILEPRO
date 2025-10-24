<?php
/**
 * fix_production_permissions.php — Corrigir permissões na produção
 * Execute: php fix_production_permissions.php
 * OU acesse: https://painelsmilepro-production.up.railway.app/fix_production_permissions.php
 */

echo "🔧 Corrigindo Permissões na Produção\n";
echo "===================================\n\n";

try {
    // Incluir conexão
    require_once __DIR__ . '/public/conexao.php';
    
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo']) {
        throw new Exception("Conexão com banco não estabelecida");
    }
    
    $pdo = $GLOBALS['pdo'];
    echo "✅ Conexão com banco estabelecida\n\n";
    
    // Lista de colunas de permissão que devem existir
    $permissionColumns = [
        'perm_agenda_ver' => 'BOOLEAN DEFAULT false',
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
    
    echo "🔍 Verificando colunas de permissão...\n";
    
    $createdCount = 0;
    $existingCount = 0;
    
    foreach ($permissionColumns as $column => $type) {
        try {
            // Tentar fazer uma consulta na coluna
            $stmt = $pdo->query("SELECT $column FROM usuarios LIMIT 1");
            echo "✅ Coluna '$column' já existe\n";
            $existingCount++;
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'does not exist') !== false) {
                echo "⚠️ Coluna '$column' não existe, criando...\n";
                
                try {
                    $pdo->exec("ALTER TABLE usuarios ADD COLUMN $column $type");
                    echo "✅ Coluna '$column' criada com sucesso\n";
                    $createdCount++;
                } catch (Exception $e2) {
                    echo "❌ Erro ao criar coluna '$column': " . $e2->getMessage() . "\n";
                }
            } else {
                echo "❌ Erro ao verificar coluna '$column': " . $e->getMessage() . "\n";
            }
        }
    }
    
    // Verificar e criar colunas essenciais
    echo "\n🔍 Verificando colunas essenciais...\n";
    
    $essentialColumns = [
        'email' => 'VARCHAR(255)',
        'perfil' => 'VARCHAR(50) DEFAULT \'CONSULTA\''
    ];
    
    foreach ($essentialColumns as $column => $type) {
        try {
            $stmt = $pdo->query("SELECT $column FROM usuarios LIMIT 1");
            echo "✅ Coluna '$column' já existe\n";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'does not exist') !== false) {
                echo "⚠️ Coluna '$column' não existe, criando...\n";
                
                try {
                    $pdo->exec("ALTER TABLE usuarios ADD COLUMN $column $type");
                    echo "✅ Coluna '$column' criada com sucesso\n";
                    $createdCount++;
                } catch (Exception $e2) {
                    echo "❌ Erro ao criar coluna '$column': " . $e2->getMessage() . "\n";
                }
            }
        }
    }
    
    // Definir permissões para usuários existentes
    echo "\n🔍 Configurando permissões para usuários existentes...\n";
    
    try {
        // Verificar se há usuários
        $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios");
        $userCount = $stmt->fetchColumn();
        
        if ($userCount > 0) {
            echo "📊 Encontrados $userCount usuários\n";
            
            // Definir todas as permissões como true para usuários existentes
            foreach ($permissionColumns as $column => $type) {
                try {
                    $pdo->exec("UPDATE usuarios SET $column = true WHERE $column IS NULL");
                    echo "✅ Permissões '$column' configuradas para usuários existentes\n";
                } catch (Exception $e) {
                    echo "⚠️ Erro ao configurar '$column': " . $e->getMessage() . "\n";
                }
            }
        } else {
            echo "⚠️ Nenhum usuário encontrado\n";
        }
    } catch (Exception $e) {
        echo "❌ Erro ao configurar permissões: " . $e->getMessage() . "\n";
    }
    
    echo "\n📊 Resumo das Correções:\n";
    echo "✅ Colunas existentes: $existingCount\n";
    echo "🔨 Colunas criadas: $createdCount\n";
    echo "📋 Total de colunas verificadas: " . ($existingCount + $createdCount) . "\n";
    
    if ($createdCount > 0) {
        echo "\n🎉 Correções aplicadas com sucesso!\n";
        echo "Agora o sistema deve funcionar sem erros de colunas faltantes.\n";
    } else {
        echo "\n✅ Todas as colunas já existiam!\n";
        echo "O sistema já estava configurado corretamente.\n";
    }
    
} catch (Exception $e) {
    echo "❌ Erro fatal: " . $e->getMessage() . "\n";
    echo "\n💡 Verifique se o banco de dados está configurado corretamente.\n";
}
?>
