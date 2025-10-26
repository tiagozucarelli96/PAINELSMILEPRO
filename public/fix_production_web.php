<?php
/**
 * fix_production_web.php — Corrigir permissões na produção via web
 * Acesse: https://painelsmilepro-production.up.railway.app/fix_production_web.php
 */

// Verificar se está sendo executado via web
if (!isset($_SERVER['HTTP_HOST'])) {
    die("Este script deve ser executado via navegador web.");
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Correção de Permissões - Produção</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
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
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Correção de Permissões - Produção</h1>
        
        <?php
        $corrections = [];
        $errors = [];
        
        try {
            // Incluir conexão
            require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
            
            if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo']) {
                throw new Exception("Conexão com banco não estabelecida");
            }
            
            $pdo = $GLOBALS['pdo'];
            echo "<div class='success'>✅ Conexão com banco estabelecida</div>";
            
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
            
            echo "<h2>🔍 Verificando colunas de permissão</h2>";
            
            $createdCount = 0;
            $existingCount = 0;
            
            foreach ($permissionColumns as $column => $type) {
                try {
                    // Tentar fazer uma consulta na coluna
                    $stmt = $pdo->query("SELECT $column FROM usuarios LIMIT 1");
                    echo "<div class='success'>✅ Coluna '$column' já existe</div>";
                    $existingCount++;
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'does not exist') !== false) {
                        echo "<div class='warning'>⚠️ Coluna '$column' não existe, criando...</div>";
                        
                        try {
                            $pdo->exec("ALTER TABLE usuarios ADD COLUMN $column $type");
                            echo "<div class='success'>✅ Coluna '$column' criada com sucesso</div>";
                            $corrections[] = "Coluna '$column' criada";
                            $createdCount++;
                        } catch (Exception $e2) {
                            echo "<div class='error'>❌ Erro ao criar coluna '$column': " . $e2->getMessage() . "</div>";
                            $errors[] = "Erro ao criar coluna '$column': " . $e2->getMessage();
                        }
                    } else {
                        echo "<div class='error'>❌ Erro ao verificar coluna '$column': " . $e->getMessage() . "</div>";
                        $errors[] = "Erro ao verificar coluna '$column': " . $e->getMessage();
                    }
                }
            }
            
            // Verificar e criar colunas essenciais
            echo "<h2>🔍 Verificando colunas essenciais</h2>";
            
            $essentialColumns = [
                'email' => 'VARCHAR(255)',
                'perfil' => 'VARCHAR(50) DEFAULT \'CONSULTA\''
            ];
            
            foreach ($essentialColumns as $column => $type) {
                try {
                    $stmt = $pdo->query("SELECT $column FROM usuarios LIMIT 1");
                    echo "<div class='success'>✅ Coluna '$column' já existe</div>";
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'does not exist') !== false) {
                        echo "<div class='warning'>⚠️ Coluna '$column' não existe, criando...</div>";
                        
                        try {
                            $pdo->exec("ALTER TABLE usuarios ADD COLUMN $column $type");
                            echo "<div class='success'>✅ Coluna '$column' criada com sucesso</div>";
                            $corrections[] = "Coluna '$column' criada";
                            $createdCount++;
                        } catch (Exception $e2) {
                            echo "<div class='error'>❌ Erro ao criar coluna '$column': " . $e2->getMessage() . "</div>";
                            $errors[] = "Erro ao criar coluna '$column': " . $e2->getMessage();
                        }
                    }
                }
            }
            
            // Definir permissões para usuários existentes
            echo "<h2>🔍 Configurando permissões para usuários existentes</h2>";
            
            try {
                // Verificar se há usuários
                $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios");
                $userCount = $stmt->fetchColumn();
                
                if ($userCount > 0) {
                    echo "<div class='info'>📊 Encontrados $userCount usuários</div>";
                    
                    // Definir todas as permissões como true para usuários existentes
                    foreach ($permissionColumns as $column => $type) {
                        try {
                            $pdo->exec("UPDATE usuarios SET $column = true WHERE $column IS NULL");
                            echo "<div class='success'>✅ Permissões '$column' configuradas para usuários existentes</div>";
                        } catch (Exception $e) {
                            echo "<div class='warning'>⚠️ Erro ao configurar '$column': " . $e->getMessage() . "</div>";
                        }
                    }
                } else {
                    echo "<div class='warning'>⚠️ Nenhum usuário encontrado</div>";
                }
            } catch (Exception $e) {
                echo "<div class='error'>❌ Erro ao configurar permissões: " . $e->getMessage() . "</div>";
                $errors[] = "Erro ao configurar permissões: " . $e->getMessage();
            }
            
            // Relatório final
            echo "<h2>📊 Resumo das Correções</h2>";
            
            if (!empty($corrections)) {
                echo "<div class='success'>";
                echo "<h3>✅ Correções Aplicadas (" . count($corrections) . ")</h3>";
                echo "<ul>";
                foreach ($corrections as $correction) {
                    echo "<li>$correction</li>";
                }
                echo "</ul>";
                echo "</div>";
            }
            
            if (!empty($errors)) {
                echo "<div class='error'>";
                echo "<h3>❌ Erros Encontrados (" . count($errors) . ")</h3>";
                echo "<ul>";
                foreach ($errors as $error) {
                    echo "<li>$error</li>";
                }
                echo "</ul>";
                echo "</div>";
            }
            
            echo "<div class='info'>";
            echo "<h3>📊 Estatísticas</h3>";
            echo "<p>✅ Colunas existentes: $existingCount</p>";
            echo "<p>🔨 Colunas criadas: $createdCount</p>";
            echo "<p>📋 Total de colunas verificadas: " . ($existingCount + $createdCount) . "</p>";
            echo "</div>";
            
            if (empty($errors)) {
                echo "<div class='success'>";
                echo "<h3>🎉 Correções aplicadas com sucesso!</h3>";
                echo "<p>Agora o sistema deve funcionar sem erros de colunas faltantes.</p>";
                echo "</div>";
            } else {
                echo "<div class='warning'>";
                echo "<h3>⚠️ Alguns erros ainda persistem</h3>";
                echo "<p>Verifique os erros listados acima.</p>";
                echo "</div>";
            }
            
        } catch (Exception $e) {
            echo "<div class='error'>";
            echo "<h3>❌ Erro Fatal</h3>";
            echo "<p>" . $e->getMessage() . "</p>";
            echo "<p>Verifique se o banco de dados está configurado corretamente.</p>";
            echo "</div>";
        }
        ?>
        
        <div style="text-align: center; margin: 20px 0;">
            <a href="fix_production_web.php" class="button">🔍 Verificar Novamente</a>
            <a href="dashboard.php" class="button success">🏠 Ir para Dashboard</a>
        </div>
    </div>
</body>
</html>
