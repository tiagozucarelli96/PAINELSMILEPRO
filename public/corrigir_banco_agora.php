<?php
/**
 * corrigir_banco_agora.php — Corrigir problemas de banco diretamente no navegador
 * Acesse: http://seu-dominio.com/public/corrigir_banco_agora.php
 */

// Verificar se está sendo executado via web
if (!isset($_SERVER['HTTP_HOST'])) {
    die("Este script deve ser executado via navegador web.");
}

// Incluir conexão
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Correção de Banco de Dados</title>
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
        <h1>🔧 Correção de Banco de Dados</h1>
        
        <?php
        $corrections = [];
        $errors = [];
        
        try {
            $pdo = $GLOBALS['pdo'];
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            echo "<div class='success'>✅ Conexão com banco estabelecida</div>";
            
            // 1. Verificar e corrigir coluna perm_agenda_ver
            echo "<h2>🔍 Verificando coluna perm_agenda_ver</h2>";
            
            try {
                $stmt = $pdo->query("SELECT perm_agenda_ver FROM usuarios LIMIT 1");
                echo "<div class='success'>✅ Coluna perm_agenda_ver já existe</div>";
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'does not exist') !== false) {
                    echo "<div class='warning'>⚠️ Coluna perm_agenda_ver não existe, criando...</div>";
                    
                    try {
                        $pdo->exec("ALTER TABLE usuarios ADD COLUMN perm_agenda_ver BOOLEAN DEFAULT false");
                        echo "<div class='success'>✅ Coluna perm_agenda_ver criada com sucesso</div>";
                        $corrections[] = "Coluna perm_agenda_ver criada";
                    } catch (Exception $e2) {
                        echo "<div class='error'>❌ Erro ao criar perm_agenda_ver: " . $e2->getMessage() . "</div>";
                        $errors[] = "Erro ao criar perm_agenda_ver: " . $e2->getMessage();
                    }
                } else {
                    echo "<div class='error'>❌ Erro: " . $e->getMessage() . "</div>";
                    $errors[] = "Erro na verificação: " . $e->getMessage();
                }
            }
            
            // 2. Verificar e corrigir outras colunas de permissão
            echo "<h2>🔍 Verificando outras colunas de permissão</h2>";
            
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
                    echo "<div class='success'>✅ Coluna $column já existe</div>";
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'does not exist') !== false) {
                        echo "<div class='warning'>⚠️ Coluna $column não existe, criando...</div>";
                        
                        try {
                            $pdo->exec("ALTER TABLE usuarios ADD COLUMN $column $type");
                            echo "<div class='success'>✅ Coluna $column criada com sucesso</div>";
                            $corrections[] = "Coluna $column criada";
                        } catch (Exception $e2) {
                            echo "<div class='error'>❌ Erro ao criar $column: " . $e2->getMessage() . "</div>";
                            $errors[] = "Erro ao criar $column: " . $e2->getMessage();
                        }
                    }
                }
            }
            
            // 3. Verificar e corrigir colunas essenciais
            echo "<h2>🔍 Verificando colunas essenciais</h2>";
            
            $essentialColumns = [
                'email' => 'VARCHAR(255)',
                'perfil' => 'VARCHAR(50) DEFAULT \'CONSULTA\''
            ];
            
            foreach ($essentialColumns as $column => $type) {
                try {
                    $stmt = $pdo->query("SELECT $column FROM usuarios LIMIT 1");
                    echo "<div class='success'>✅ Coluna $column já existe</div>";
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'does not exist') !== false) {
                        echo "<div class='warning'>⚠️ Coluna $column não existe, criando...</div>";
                        
                        try {
                            $pdo->exec("ALTER TABLE usuarios ADD COLUMN $column $type");
                            echo "<div class='success'>✅ Coluna $column criada com sucesso</div>";
                            $corrections[] = "Coluna $column criada";
                        } catch (Exception $e2) {
                            echo "<div class='error'>❌ Erro ao criar $column: " . $e2->getMessage() . "</div>";
                            $errors[] = "Erro ao criar $column: " . $e2->getMessage();
                        }
                    }
                }
            }
            
            // 4. Verificar e corrigir tabelas essenciais
            echo "<h2>🔍 Verificando tabelas essenciais</h2>";
            
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
                    echo "<div class='success'>✅ Tabela $table já existe</div>";
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'does not exist') !== false) {
                        echo "<div class='warning'>⚠️ Tabela $table não existe, criando...</div>";
                        
                        try {
                            $pdo->exec($sql);
                            echo "<div class='success'>✅ Tabela $table criada com sucesso</div>";
                            $corrections[] = "Tabela $table criada";
                        } catch (Exception $e2) {
                            echo "<div class='error'>❌ Erro ao criar $table: " . $e2->getMessage() . "</div>";
                            $errors[] = "Erro ao criar $table: " . $e2->getMessage();
                        }
                    }
                }
            }
            
            // 5. Verificar e corrigir índices
            echo "<h2>🔍 Verificando índices</h2>";
            
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
                        echo "<div class='success'>✅ Índice $indexName já existe</div>";
                    } else {
                        echo "<div class='warning'>⚠️ Índice $indexName não existe, criando...</div>";
                        
                        try {
                            $pdo->exec("CREATE INDEX IF NOT EXISTS $indexName ON $tableColumn");
                            echo "<div class='success'>✅ Índice $indexName criado com sucesso</div>";
                            $corrections[] = "Índice $indexName criado";
                        } catch (Exception $e) {
                            echo "<div class='error'>❌ Erro ao criar índice $indexName: " . $e->getMessage() . "</div>";
                            $errors[] = "Erro ao criar índice $indexName: " . $e->getMessage();
                        }
                    }
                } catch (Exception $e) {
                    echo "<div class='error'>❌ Erro ao verificar índice $indexName: " . $e->getMessage() . "</div>";
                    $errors[] = "Erro ao verificar índice $indexName: " . $e->getMessage();
                }
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
            
            if (empty($errors)) {
                echo "<div class='success'>";
                echo "<h3>🎉 Todas as correções foram aplicadas com sucesso!</h3>";
                echo "<p>Agora você pode tentar acessar o dashboard novamente.</p>";
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
            <a href="corrigir_banco_agora.php" class="button">🔍 Verificar Novamente</a>
            <a href="dashboard.php" class="button success">🏠 Ir para Dashboard</a>
        </div>
    </div>
</body>
</html>
