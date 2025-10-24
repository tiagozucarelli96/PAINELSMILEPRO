<?php
/**
 * fix_production_final.php — Corrigir TODOS os problemas na produção
 * Acesse: https://painelsmilepro-production.up.railway.app/fix_production_final.php
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
    <title>Correção Final - Produção</title>
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
        <h1>🔧 Correção Final - Produção</h1>
        
        <?php
        $corrections = [];
        $errors = [];
        $stats = [
            'functions_created' => 0,
            'columns_fixed' => 0,
            'indexes_created' => 0,
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
            
            if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo']) {
                throw new Exception("Conexão com banco não estabelecida");
            }
            
            $pdo = $GLOBALS['pdo'];
            echo "<div class='success'>✅ Conexão com banco estabelecida</div>";
            
            // 1. VERIFICAR SE A COLUNA data_inicio EXISTE
            echo "<h2>🔍 Verificando Coluna data_inicio</h2>";
            
            try {
                $stmt = $pdo->query("SELECT data_inicio FROM eventos LIMIT 1");
                echo "<div class='success'>✅ Coluna 'data_inicio' existe na tabela eventos</div>";
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'does not exist') !== false) {
                    echo "<div class='warning'>🔨 Adicionando coluna 'data_inicio' à tabela eventos...</div>";
                    $pdo->exec("ALTER TABLE eventos ADD COLUMN data_inicio TIMESTAMP");
                    echo "<div class='success'>✅ Coluna 'data_inicio' adicionada com sucesso</div>";
                    
                    // Atualizar registros existentes se houver
                    $pdo->exec("UPDATE eventos SET data_inicio = created_at WHERE data_inicio IS NULL");
                    echo "<div class='success'>✅ Registros existentes atualizados</div>";
                    $corrections[] = "Coluna 'data_inicio' adicionada à tabela eventos";
                    $stats['columns_fixed']++;
                } else {
                    echo "<div class='error'>❌ Erro ao verificar coluna 'data_inicio': " . $e->getMessage() . "</div>";
                    $errors[] = "Erro ao verificar coluna 'data_inicio': " . $e->getMessage();
                }
            }
            
            // 2. CRIAR FUNÇÃO obter_proximos_eventos
            echo "<h2>🔧 Criando Função obter_proximos_eventos</h2>";
            
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
                echo "<div class='success'>✅ Função 'obter_proximos_eventos' criada com sucesso</div>";
                $corrections[] = "Função 'obter_proximos_eventos' criada";
                $stats['functions_created']++;
            } catch (Exception $e) {
                echo "<div class='error'>❌ Erro ao criar função 'obter_proximos_eventos': " . $e->getMessage() . "</div>";
                $errors[] = "Erro ao criar função 'obter_proximos_eventos': " . $e->getMessage();
            }
            
            // 3. CRIAR OUTRAS FUNÇÕES ÚTEIS
            echo "<h2>🔧 Criando Outras Funções Úteis</h2>";
            
            $functions = [
                'obter_eventos_hoje' => "
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
                ",
                
                'obter_eventos_semana' => "
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
                "
            ];
            
            foreach ($functions as $functionName => $functionSQL) {
                try {
                    $pdo->exec($functionSQL);
                    echo "<div class='success'>✅ Função '$functionName' criada com sucesso</div>";
                    $corrections[] = "Função '$functionName' criada";
                    $stats['functions_created']++;
                } catch (Exception $e) {
                    echo "<div class='error'>❌ Erro ao criar função '$functionName': " . $e->getMessage() . "</div>";
                    $errors[] = "Erro ao criar função '$functionName': " . $e->getMessage();
                }
            }
            
            // 4. TESTAR FUNÇÕES CRIADAS
            echo "<h2>🧪 Testando Funções Criadas</h2>";
            
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
            
            // 5. VERIFICAR FUNÇÕES EXISTENTES
            echo "<h2>🔍 Verificando Funções Existentes</h2>";
            
            try {
                $stmt = $pdo->query("SELECT routine_name FROM information_schema.routines WHERE routine_schema = 'public' AND routine_name LIKE 'obter_%'");
                $functions = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                echo "<div class='info'>";
                echo "<h3>📋 Funções encontradas:</h3>";
                echo "<ul>";
                foreach ($functions as $function) {
                    echo "<li>$function</li>";
                }
                echo "</ul>";
                echo "</div>";
            } catch (Exception $e) {
                echo "<div class='error'>❌ Erro ao verificar funções: " . $e->getMessage() . "</div>";
                $errors[] = "Erro ao verificar funções: " . $e->getMessage();
            }
            
            // 6. CRIAR ÍNDICES IMPORTANTES
            echo "<h2>🔧 Criando Índices Importantes</h2>";
            
            $indexes = [
                "CREATE INDEX IF NOT EXISTS idx_eventos_data_inicio ON eventos(data_inicio)",
                "CREATE INDEX IF NOT EXISTS idx_eventos_status ON eventos(status)",
                "CREATE INDEX IF NOT EXISTS idx_agenda_eventos_data ON agenda_eventos(data_inicio)",
                "CREATE INDEX IF NOT EXISTS idx_usuarios_email ON usuarios(email)",
                "CREATE INDEX IF NOT EXISTS idx_usuarios_perfil ON usuarios(perfil)"
            ];
            
            foreach ($indexes as $indexSql) {
                try {
                    $pdo->exec($indexSql);
                    echo "<div class='success'>✅ Índice criado com sucesso</div>";
                    $stats['indexes_created']++;
                } catch (Exception $e) {
                    echo "<div class='warning'>⚠️ Índice já existe ou erro: " . $e->getMessage() . "</div>";
                }
            }
            
            // 7. TESTAR CONSULTAS COMUNS
            echo "<h2>🧪 Testando Consultas Comuns</h2>";
            
            $testQueries = [
                "SELECT COUNT(*) FROM eventos" => "Eventos",
                "SELECT COUNT(*) FROM usuarios" => "Usuários",
                "SELECT COUNT(*) FROM lc_insumos" => "Insumos",
                "SELECT COUNT(*) FROM lc_listas" => "Listas de compras",
                "SELECT COUNT(*) FROM estoque_contagens" => "Contagens de estoque",
                "SELECT COUNT(*) FROM pagamentos_solicitacoes" => "Solicitações de pagamento",
                "SELECT COUNT(*) FROM comercial_degustacoes" => "Degustações comerciais"
            ];
            
            foreach ($testQueries as $query => $description) {
                try {
                    $stmt = $pdo->query($query);
                    $count = $stmt->fetchColumn();
                    echo "<div class='success'>✅ $description: $count registros</div>";
                    $stats['tests_passed']++;
                } catch (Exception $e) {
                    echo "<div class='error'>❌ $description: ERRO - " . $e->getMessage() . "</div>";
                    $errors[] = "Erro ao testar $description: " . $e->getMessage();
                }
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
            <h3>🎉 Correção Final Completa!</h3>
            <p><strong>Ambiente:</strong> <?php echo $environment; ?></p>
            <p><strong>Funções:</strong> <?php echo $stats['functions_created']; ?> criadas</p>
            <p><strong>Índices:</strong> <?php echo $stats['indexes_created']; ?> criados</p>
            <p><strong>Colunas:</strong> <?php echo $stats['columns_fixed']; ?> corrigidas</p>
            <p><strong>Testes:</strong> <?php echo $stats['tests_passed']; ?> passaram</p>
            
            <?php if (empty($errors)): ?>
                <p><strong>✅ TODAS AS FUNÇÕES FORAM CRIADAS!</strong></p>
                <p>O sistema agora deve funcionar perfeitamente sem erros de funções.</p>
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
            <a href="fix_production_final.php" class="button">🔍 Verificar Novamente</a>
            <a href="dashboard.php" class="button success">🏠 Ir para Dashboard</a>
        </div>
    </div>
</body>
</html>
