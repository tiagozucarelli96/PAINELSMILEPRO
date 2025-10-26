<?php
/**
 * fix_eventos_table.php — Corrigir estrutura da tabela eventos
 * Acesse: https://painelsmilepro-production.up.railway.app/fix_eventos_table.php
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
    <title>🔧 Corrigir Tabela Eventos - Produção</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
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
        .code { background: #f5f5f5; padding: 10px; border-radius: 5px; font-family: monospace; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Corrigir Tabela Eventos - Produção</h1>
        
        <?php
        $corrections = [];
        $errors = [];
        $stats = [
            'columns_added' => 0,
            'functions_updated' => 0,
            'tests_passed' => 0
        ];
        
        try {
            // Detectar ambiente
            $isProduction = getenv("DATABASE_URL") && strpos(getenv("DATABASE_URL"), 'railway') !== false;
            $environment = $isProduction ? "PRODUÇÃO (Railway)" : "LOCAL";
            
            echo "<div class='info'>";
            echo "<h3>🌍 Ambiente Detectado: $environment</h3>";
            echo "<p>DATABASE_URL: " . (getenv("DATABASE_URL") ? "Definido" : "Não definido") . "</p>";
            echo "</div>";
            
            // Incluir conexão
            require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
            
            if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo']) {
                throw new Exception("Conexão com banco não estabelecida");
            }
            
            $pdo = $GLOBALS['pdo'];
            echo "<div class='success'>✅ Conexão com banco estabelecida</div>";
            
            // 1. VERIFICAR ESTRUTURA ATUAL DA TABELA EVENTOS
            echo "<h2>🔍 Verificando Estrutura Atual da Tabela Eventos</h2>";
            
            try {
                $stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'eventos' ORDER BY ordinal_position");
                $columns = $stmt->fetchAll();
                
                echo "<div class='info'>";
                echo "<h3>📋 Colunas atuais da tabela eventos:</h3>";
                echo "<ul>";
                foreach ($columns as $column) {
                    echo "<li>" . $column['column_name'] . " (" . $column['data_type'] . ")</li>";
                }
                echo "</ul>";
                echo "</div>";
            } catch (Exception $e) {
                echo "<div class='error'>❌ Erro ao verificar estrutura da tabela eventos: " . $e->getMessage() . "</div>";
                $errors[] = "Erro ao verificar estrutura da tabela eventos: " . $e->getMessage();
            }
            
            // 2. ADICIONAR COLUNAS FALTANTES
            echo "<h2>🔧 Adicionando Colunas Faltantes</h2>";
            
            $missingColumns = [
                'descricao' => 'TEXT',
                'data_inicio' => 'TIMESTAMP',
                'data_fim' => 'TIMESTAMP',
                'local' => 'VARCHAR(255)',
                'status' => 'VARCHAR(20) DEFAULT \'ativo\'',
                'observacoes' => 'TEXT',
                'created_at' => 'TIMESTAMP DEFAULT NOW()',
                'updated_at' => 'TIMESTAMP DEFAULT NOW()'
            ];
            
            foreach ($missingColumns as $column => $type) {
                try {
                    $stmt = $pdo->query("SELECT $column FROM eventos LIMIT 1");
                    echo "<div class='success'>✅ Coluna '$column' já existe</div>";
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'does not exist') !== false) {
                        echo "<div class='warning'>🔨 Adicionando coluna '$column'...</div>";
                        $pdo->exec("ALTER TABLE eventos ADD COLUMN $column $type");
                        echo "<div class='success'>✅ Coluna '$column' adicionada com sucesso</div>";
                        $corrections[] = "Coluna '$column' adicionada";
                        $stats['columns_added']++;
                    } else {
                        echo "<div class='error'>❌ Erro ao verificar coluna '$column': " . $e->getMessage() . "</div>";
                        $errors[] = "Erro ao verificar coluna '$column': " . $e->getMessage();
                    }
                }
            }
            
            // 3. ATUALIZAR REGISTROS EXISTENTES
            echo "<h2>🔄 Atualizando Registros Existentes</h2>";
            
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM eventos");
                $eventCount = $stmt->fetchColumn();
                
                if ($eventCount > 0) {
                    echo "<div class='info'>📊 Encontrados $eventCount eventos</div>";
                    
                    // Atualizar data_inicio se for NULL
                    $pdo->exec("UPDATE eventos SET data_inicio = created_at WHERE data_inicio IS NULL");
                    echo "<div class='success'>✅ Campo data_inicio atualizado para registros existentes</div>";
                    
                    // Atualizar data_fim se for NULL
                    $pdo->exec("UPDATE eventos SET data_fim = data_inicio + INTERVAL '1 hour' WHERE data_fim IS NULL");
                    echo "<div class='success'>✅ Campo data_fim atualizado para registros existentes</div>";
                    
                    // Atualizar status se for NULL
                    $pdo->exec("UPDATE eventos SET status = 'ativo' WHERE status IS NULL");
                    echo "<div class='success'>✅ Campo status atualizado para registros existentes</div>";
                } else {
                    echo "<div class='info'>📊 Nenhum evento encontrado</div>";
                }
            } catch (Exception $e) {
                echo "<div class='error'>❌ Erro ao atualizar registros: " . $e->getMessage() . "</div>";
                $errors[] = "Erro ao atualizar registros: " . $e->getMessage();
            }
            
            // 4. RECRIAR FUNÇÕES COM ESTRUTURA CORRETA
            echo "<h2>🔧 Recriando Funções com Estrutura Correta</h2>";
            
            // Função obter_proximos_eventos
            $functionSQL = "
            CREATE OR REPLACE FUNCTION obter_proximos_eventos(
                p_usuario_id INTEGER,
                p_horas INTEGER DEFAULT 24
            )
            RETURNS TABLE (
                id INTEGER,
                titulo VARCHAR(255),
                descricao TEXT,
                data_inicio TIMESTAMP,
                data_fim TIMESTAMP,
                local VARCHAR(255),
                status VARCHAR(20),
                observacoes TEXT,
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            )
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RETURN QUERY
                SELECT 
                    e.id,
                    e.titulo,
                    e.descricao,
                    e.data_inicio,
                    e.data_fim,
                    e.local,
                    e.status,
                    e.observacoes,
                    e.created_at,
                    e.updated_at
                FROM eventos e
                WHERE 
                    e.data_inicio >= NOW()
                    AND e.data_inicio <= NOW() + INTERVAL '1 hour' * p_horas
                    AND e.status = 'ativo'
                ORDER BY e.data_inicio ASC;
            END;
            $$;
            ";
            
            try {
                $pdo->exec($functionSQL);
                echo "<div class='success'>✅ Função 'obter_proximos_eventos' recriada com sucesso</div>";
                $corrections[] = "Função 'obter_proximos_eventos' recriada";
                $stats['functions_updated']++;
            } catch (Exception $e) {
                echo "<div class='error'>❌ Erro ao recriar função 'obter_proximos_eventos': " . $e->getMessage() . "</div>";
                $errors[] = "Erro ao recriar função 'obter_proximos_eventos': " . $e->getMessage();
            }
            
            // Função obter_eventos_hoje
            $functionSQL2 = "
            CREATE OR REPLACE FUNCTION obter_eventos_hoje(p_usuario_id INTEGER)
            RETURNS TABLE (
                id INTEGER,
                titulo VARCHAR(255),
                data_inicio TIMESTAMP,
                data_fim TIMESTAMP,
                local VARCHAR(255)
            )
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RETURN QUERY
                SELECT 
                    e.id,
                    e.titulo,
                    e.data_inicio,
                    e.data_fim,
                    e.local
                FROM eventos e
                WHERE 
                    DATE(e.data_inicio) = CURRENT_DATE
                    AND e.status = 'ativo'
                ORDER BY e.data_inicio ASC;
            END;
            $$;
            ";
            
            try {
                $pdo->exec($functionSQL2);
                echo "<div class='success'>✅ Função 'obter_eventos_hoje' recriada com sucesso</div>";
                $corrections[] = "Função 'obter_eventos_hoje' recriada";
                $stats['functions_updated']++;
            } catch (Exception $e) {
                echo "<div class='error'>❌ Erro ao recriar função 'obter_eventos_hoje': " . $e->getMessage() . "</div>";
                $errors[] = "Erro ao recriar função 'obter_eventos_hoje': " . $e->getMessage();
            }
            
            // Função obter_eventos_semana
            $functionSQL3 = "
            CREATE OR REPLACE FUNCTION obter_eventos_semana(p_usuario_id INTEGER)
            RETURNS TABLE (
                id INTEGER,
                titulo VARCHAR(255),
                data_inicio TIMESTAMP,
                data_fim TIMESTAMP,
                local VARCHAR(255)
            )
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RETURN QUERY
                SELECT 
                    e.id,
                    e.titulo,
                    e.data_inicio,
                    e.data_fim,
                    e.local
                FROM eventos e
                WHERE 
                    e.data_inicio >= CURRENT_DATE
                    AND e.data_inicio <= CURRENT_DATE + INTERVAL '7 days'
                    AND e.status = 'ativo'
                ORDER BY e.data_inicio ASC;
            END;
            $$;
            ";
            
            try {
                $pdo->exec($functionSQL3);
                echo "<div class='success'>✅ Função 'obter_eventos_semana' recriada com sucesso</div>";
                $corrections[] = "Função 'obter_eventos_semana' recriada";
                $stats['functions_updated']++;
            } catch (Exception $e) {
                echo "<div class='error'>❌ Erro ao recriar função 'obter_eventos_semana': " . $e->getMessage() . "</div>";
                $errors[] = "Erro ao recriar função 'obter_eventos_semana': " . $e->getMessage();
            }
            
            // 5. TESTAR FUNÇÕES RECRIADAS
            echo "<h2>🧪 Testando Funções Recriadas</h2>";
            
            try {
                // Testar função obter_proximos_eventos
                $stmt = $pdo->prepare("SELECT * FROM obter_proximos_eventos(1, 24)");
                $stmt->execute();
                $events = $stmt->fetchAll();
                echo "<div class='success'>✅ Função 'obter_proximos_eventos' funcionando (" . count($events) . " eventos)</div>";
                $stats['tests_passed']++;
            } catch (Exception $e) {
                echo "<div class='error'>❌ Erro ao testar função 'obter_proximos_eventos': " . $e->getMessage() . "</div>";
                $errors[] = "Erro ao testar função 'obter_proximos_eventos': " . $e->getMessage();
            }
            
            try {
                // Testar função obter_eventos_hoje
                $stmt = $pdo->prepare("SELECT * FROM obter_eventos_hoje(1)");
                $stmt->execute();
                $events = $stmt->fetchAll();
                echo "<div class='success'>✅ Função 'obter_eventos_hoje' funcionando (" . count($events) . " eventos)</div>";
                $stats['tests_passed']++;
            } catch (Exception $e) {
                echo "<div class='error'>❌ Erro ao testar função 'obter_eventos_hoje': " . $e->getMessage() . "</div>";
                $errors[] = "Erro ao testar função 'obter_eventos_hoje': " . $e->getMessage();
            }
            
            try {
                // Testar função obter_eventos_semana
                $stmt = $pdo->prepare("SELECT * FROM obter_eventos_semana(1)");
                $stmt->execute();
                $events = $stmt->fetchAll();
                echo "<div class='success'>✅ Função 'obter_eventos_semana' funcionando (" . count($events) . " eventos)</div>";
                $stats['tests_passed']++;
            } catch (Exception $e) {
                echo "<div class='error'>❌ Erro ao testar função 'obter_eventos_semana': " . $e->getMessage() . "</div>";
                $errors[] = "Erro ao testar função 'obter_eventos_semana': " . $e->getMessage();
            }
            
            // 6. VERIFICAR ESTRUTURA FINAL
            echo "<h2>🔍 Verificando Estrutura Final</h2>";
            
            try {
                $stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'eventos' ORDER BY ordinal_position");
                $columns = $stmt->fetchAll();
                
                echo "<div class='success'>";
                echo "<h3>📋 Estrutura final da tabela eventos:</h3>";
                echo "<ul>";
                foreach ($columns as $column) {
                    echo "<li>" . $column['column_name'] . " (" . $column['data_type'] . ")</li>";
                }
                echo "</ul>";
                echo "</div>";
            } catch (Exception $e) {
                echo "<div class='error'>❌ Erro ao verificar estrutura final: " . $e->getMessage() . "</div>";
                $errors[] = "Erro ao verificar estrutura final: " . $e->getMessage();
            }
            
        } catch (Exception $e) {
            echo "<div class='error'>";
            echo "<h3>❌ Erro Fatal</h3>";
            echo "<p>" . $e->getMessage() . "</p>";
            echo "<p>Verifique se o banco de dados está configurado corretamente.</p>";
            echo "</div>";
        }
        ?>
        
        <!-- Relatório Final -->
        <div class="success">
            <h3>🎉 Correção da Tabela Eventos Completa!</h3>
            <p><strong>Ambiente:</strong> <?php echo $environment; ?></p>
            <p><strong>Colunas:</strong> <?php echo $stats['columns_added']; ?> adicionadas</p>
            <p><strong>Funções:</strong> <?php echo $stats['functions_updated']; ?> atualizadas</p>
            <p><strong>Testes:</strong> <?php echo $stats['tests_passed']; ?> passaram</p>
            
            <?php if (empty($errors)): ?>
                <p><strong>✅ TABELA EVENTOS CORRIGIDA!</strong></p>
                <p>As funções agora devem funcionar perfeitamente.</p>
            <?php else: ?>
                <p><strong>⚠️ Alguns erros ainda persistem:</strong></p>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo $error; ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        
        <div style="text-align: center; margin: 20px 0;">
            <a href="fix_eventos_table.php" class="button">🔍 Verificar Novamente</a>
            <a href="dashboard.php" class="button success">🏠 Ir para Dashboard</a>
        </div>
    </div>
</body>
</html>
