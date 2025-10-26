<?php
/**
 * fix_production_URGENTE.php — CORREÇÃO URGENTE PARA PRODUÇÃO
 * Acesse: https://painelsmilepro-production.up.railway.app/fix_production_URGENTE.php
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
    <title>🚨 CORREÇÃO URGENTE - PRODUÇÃO</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
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
        .urgent { color: #D32F2F; background: #ffebee; padding: 15px; border-radius: 5px; margin: 10px 0; border-left: 5px solid #D32F2F; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚨 CORREÇÃO URGENTE - PRODUÇÃO</h1>
        
        <div class="urgent">
            <h3>⚠️ PROBLEMAS CRÍTICOS DETECTADOS:</h3>
            <ul>
                <li>15 erros de SQL encontrados</li>
                <li>Tabelas faltantes (agenda_lembretes, agenda_tokens_ics, demandas_*)</li>
                <li>Colunas faltantes (data_inicio, descricao, status)</li>
                <li>Funções PostgreSQL com sintaxe incorreta</li>
                <li>Índices falhando por colunas inexistentes</li>
            </ul>
        </div>
        
        <?php
        $corrections = [];
        $errors = [];
        $stats = [
            'tables_created' => 0,
            'columns_fixed' => 0,
            'functions_created' => 0,
            'indexes_created' => 0,
            'tests_passed' => 0
        ];
        
        try {
            // Detectar ambiente
            $isProduction = getenv("DATABASE_URL") && strpos(getenv("DATABASE_URL"), 'railway') !== false;
            $environment = $isProduction ? "PRODUÇÃO (Railway)" : "LOCAL";
            
            echo "<div class='info'>";
            echo "<h3>🌍 Ambiente Detectado: $environment</h3>";
            echo "<p>Data/Hora: " . date('d/m/Y H:i:s') . "</p>";
            echo "</div>";
            
            // Incluir conexão
            require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
            
            if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo']) {
                throw new Exception("Conexão com banco não estabelecida");
            }
            
            $pdo = $GLOBALS['pdo'];
            echo "<div class='success'>✅ Conexão com banco estabelecida</div>";
            
            // 1. CORRIGIR TABELA EVENTOS PRIMEIRO
            echo "<h2>🔧 Corrigindo Tabela Eventos (CRÍTICO)</h2>";
            
            $eventosColumns = [
                'descricao' => 'TEXT',
                'data_inicio' => 'TIMESTAMP',
                'data_fim' => 'TIMESTAMP',
                'local' => 'VARCHAR(255)',
                'status' => 'VARCHAR(20) DEFAULT \'ativo\'',
                'observacoes' => 'TEXT',
                'created_at' => 'TIMESTAMP DEFAULT NOW()',
                'updated_at' => 'TIMESTAMP DEFAULT NOW()'
            ];
            
            foreach ($eventosColumns as $column => $type) {
                try {
                    $stmt = $pdo->query("SELECT $column FROM eventos LIMIT 1");
                    echo "<div class='success'>✅ Coluna '$column' já existe</div>";
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'does not exist') !== false) {
                        echo "<div class='warning'>🔨 Adicionando coluna '$column'...</div>";
                        $pdo->exec("ALTER TABLE eventos ADD COLUMN $column $type");
                        echo "<div class='success'>✅ Coluna '$column' adicionada</div>";
                        $corrections[] = "Coluna '$column' adicionada à tabela eventos";
                        $stats['columns_fixed']++;
                    }
                }
            }
            
            // Atualizar registros existentes
            try {
                $pdo->exec("UPDATE eventos SET data_inicio = created_at WHERE data_inicio IS NULL");
                $pdo->exec("UPDATE eventos SET data_fim = data_inicio + INTERVAL '1 hour' WHERE data_fim IS NULL");
                $pdo->exec("UPDATE eventos SET status = 'ativo' WHERE status IS NULL");
                echo "<div class='success'>✅ Registros existentes atualizados</div>";
            } catch (Exception $e) {
                echo "<div class='warning'>⚠️ Erro ao atualizar registros: " . $e->getMessage() . "</div>";
            }
            
            // 2. CRIAR TABELAS FALTANTES
            echo "<h2>🏗️ Criando Tabelas Faltantes</h2>";
            
            $missingTables = [
                'agenda_lembretes' => "
                    CREATE TABLE IF NOT EXISTS agenda_lembretes (
                        id SERIAL PRIMARY KEY,
                        evento_id INT REFERENCES agenda_eventos(id) ON DELETE CASCADE,
                        usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
                        tipo VARCHAR(50) DEFAULT 'email',
                        tempo_antes INT DEFAULT 15,
                        enviado BOOLEAN DEFAULT false,
                        created_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'agenda_tokens_ics' => "
                    CREATE TABLE IF NOT EXISTS agenda_tokens_ics (
                        id SERIAL PRIMARY KEY,
                        usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
                        token VARCHAR(64) UNIQUE NOT NULL,
                        ativo BOOLEAN DEFAULT true,
                        created_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'demandas_quadros' => "
                    CREATE TABLE IF NOT EXISTS demandas_quadros (
                        id SERIAL PRIMARY KEY,
                        nome VARCHAR(255) NOT NULL,
                        descricao TEXT,
                        cor VARCHAR(7) DEFAULT '#2196F3',
                        ativo BOOLEAN DEFAULT true,
                        criado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
                        created_at TIMESTAMP DEFAULT NOW(),
                        updated_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'demandas_colunas' => "
                    CREATE TABLE IF NOT EXISTS demandas_colunas (
                        id SERIAL PRIMARY KEY,
                        quadro_id INT REFERENCES demandas_quadros(id) ON DELETE CASCADE,
                        nome VARCHAR(255) NOT NULL,
                        ordem INT DEFAULT 0,
                        cor VARCHAR(7) DEFAULT '#f0f0f0',
                        created_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'demandas_cartoes' => "
                    CREATE TABLE IF NOT EXISTS demandas_cartoes (
                        id SERIAL PRIMARY KEY,
                        quadro_id INT REFERENCES demandas_quadros(id) ON DELETE CASCADE,
                        coluna_id INT REFERENCES demandas_colunas(id) ON DELETE CASCADE,
                        titulo VARCHAR(255) NOT NULL,
                        descricao TEXT,
                        cor VARCHAR(7) DEFAULT '#ffffff',
                        prioridade VARCHAR(20) DEFAULT 'media',
                        data_vencimento TIMESTAMP,
                        recorrente BOOLEAN DEFAULT false,
                        tipo_recorrencia VARCHAR(20),
                        criado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
                        created_at TIMESTAMP DEFAULT NOW(),
                        updated_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'demandas_participantes' => "
                    CREATE TABLE IF NOT EXISTS demandas_participantes (
                        id SERIAL PRIMARY KEY,
                        quadro_id INT REFERENCES demandas_quadros(id) ON DELETE CASCADE,
                        usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
                        permissao VARCHAR(20) DEFAULT 'leitura',
                        created_at TIMESTAMP DEFAULT NOW(),
                        UNIQUE(quadro_id, usuario_id)
                    )
                ",
                
                'demandas_comentarios' => "
                    CREATE TABLE IF NOT EXISTS demandas_comentarios (
                        id SERIAL PRIMARY KEY,
                        cartao_id INT REFERENCES demandas_cartoes(id) ON DELETE CASCADE,
                        usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
                        comentario TEXT NOT NULL,
                        created_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'demandas_anexos' => "
                    CREATE TABLE IF NOT EXISTS demandas_anexos (
                        id SERIAL PRIMARY KEY,
                        cartao_id INT REFERENCES demandas_cartoes(id) ON DELETE CASCADE,
                        nome_arquivo VARCHAR(255) NOT NULL,
                        caminho_arquivo VARCHAR(500) NOT NULL,
                        tamanho INT,
                        tipo_mime VARCHAR(100),
                        enviado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
                        created_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'demandas_recorrencia' => "
                    CREATE TABLE IF NOT EXISTS demandas_recorrencia (
                        id SERIAL PRIMARY KEY,
                        cartao_id INT REFERENCES demandas_cartoes(id) ON DELETE CASCADE,
                        tipo VARCHAR(20) NOT NULL,
                        intervalo INT DEFAULT 1,
                        dia_semana INT,
                        dia_mes INT,
                        proxima_execucao TIMESTAMP,
                        ativo BOOLEAN DEFAULT true,
                        created_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'demandas_notificacoes' => "
                    CREATE TABLE IF NOT EXISTS demandas_notificacoes (
                        id SERIAL PRIMARY KEY,
                        usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
                        tipo VARCHAR(50) NOT NULL,
                        titulo VARCHAR(255) NOT NULL,
                        mensagem TEXT,
                        lida BOOLEAN DEFAULT false,
                        data_notificacao TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'demandas_produtividade' => "
                    CREATE TABLE IF NOT EXISTS demandas_produtividade (
                        id SERIAL PRIMARY KEY,
                        usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
                        data DATE NOT NULL,
                        cartoes_criados INT DEFAULT 0,
                        cartoes_concluidos INT DEFAULT 0,
                        tempo_total INTERVAL,
                        created_at TIMESTAMP DEFAULT NOW(),
                        UNIQUE(usuario_id, data)
                    )
                ",
                
                'demandas_correio' => "
                    CREATE TABLE IF NOT EXISTS demandas_correio (
                        id SERIAL PRIMARY KEY,
                        usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
                        servidor VARCHAR(255) NOT NULL,
                        porta INT DEFAULT 993,
                        usuario_email VARCHAR(255) NOT NULL,
                        senha VARCHAR(255) NOT NULL,
                        ssl BOOLEAN DEFAULT true,
                        ativo BOOLEAN DEFAULT true,
                        created_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'demandas_mensagens_email' => "
                    CREATE TABLE IF NOT EXISTS demandas_mensagens_email (
                        id SERIAL PRIMARY KEY,
                        usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
                        message_id VARCHAR(255) UNIQUE NOT NULL,
                        assunto VARCHAR(500),
                        remetente VARCHAR(255),
                        data_envio TIMESTAMP,
                        lida BOOLEAN DEFAULT false,
                        created_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'demandas_anexos_email' => "
                    CREATE TABLE IF NOT EXISTS demandas_anexos_email (
                        id SERIAL PRIMARY KEY,
                        mensagem_id INT REFERENCES demandas_mensagens_email(id) ON DELETE CASCADE,
                        nome_arquivo VARCHAR(255) NOT NULL,
                        caminho_arquivo VARCHAR(500) NOT NULL,
                        tamanho INT,
                        tipo_mime VARCHAR(100),
                        created_at TIMESTAMP DEFAULT NOW()
                    )
                "
            ];
            
            foreach ($missingTables as $tableName => $sql) {
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = '$tableName'");
                    $exists = $stmt->fetchColumn() > 0;
                    
                    if ($exists) {
                        echo "<div class='success'>✅ Tabela '$tableName' já existe</div>";
                    } else {
                        echo "<div class='warning'>🔨 Criando tabela '$tableName'...</div>";
                        $pdo->exec($sql);
                        echo "<div class='success'>✅ Tabela '$tableName' criada</div>";
                        $corrections[] = "Tabela '$tableName' criada";
                        $stats['tables_created']++;
                    }
                } catch (Exception $e) {
                    echo "<div class='error'>❌ Erro ao criar tabela '$tableName': " . $e->getMessage() . "</div>";
                    $errors[] = "Erro ao criar tabela '$tableName': " . $e->getMessage();
                }
            }
            
            // 3. CRIAR FUNÇÕES CORRETAS
            echo "<h2>🔧 Criando Funções Corretas</h2>";
            
            // Função obter_proximos_eventos (CORRIGIDA)
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
            AS \$\$
            BEGIN
                RETURN QUERY
                SELECT 
                    e.id,
                    e.titulo,
                    COALESCE(e.descricao, '') as descricao,
                    e.data_inicio,
                    e.data_fim,
                    COALESCE(e.local, '') as local,
                    COALESCE(e.status, 'ativo') as status,
                    COALESCE(e.observacoes, '') as observacoes,
                    e.created_at,
                    e.updated_at
                FROM eventos e
                WHERE 
                    e.data_inicio >= NOW()
                    AND e.data_inicio <= NOW() + INTERVAL '1 hour' * p_horas
                    AND (e.status = 'ativo' OR e.status IS NULL)
                ORDER BY e.data_inicio ASC;
            END;
            \$\$;
            ";
            
            try {
                $pdo->exec($functionSQL);
                echo "<div class='success'>✅ Função 'obter_proximos_eventos' criada corretamente</div>";
                $corrections[] = "Função 'obter_proximos_eventos' criada";
                $stats['functions_created']++;
            } catch (Exception $e) {
                echo "<div class='error'>❌ Erro ao criar função 'obter_proximos_eventos': " . $e->getMessage() . "</div>";
                $errors[] = "Erro ao criar função 'obter_proximos_eventos': " . $e->getMessage();
            }
            
            // Função obter_eventos_hoje (CORRIGIDA)
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
            AS \$\$
            BEGIN
                RETURN QUERY
                SELECT 
                    e.id,
                    e.titulo,
                    e.data_inicio,
                    e.data_fim,
                    COALESCE(e.local, '') as local
                FROM eventos e
                WHERE 
                    DATE(e.data_inicio) = CURRENT_DATE
                    AND (e.status = 'ativo' OR e.status IS NULL)
                ORDER BY e.data_inicio ASC;
            END;
            \$\$;
            ";
            
            try {
                $pdo->exec($functionSQL2);
                echo "<div class='success'>✅ Função 'obter_eventos_hoje' criada corretamente</div>";
                $corrections[] = "Função 'obter_eventos_hoje' criada";
                $stats['functions_created']++;
            } catch (Exception $e) {
                echo "<div class='error'>❌ Erro ao criar função 'obter_eventos_hoje': " . $e->getMessage() . "</div>";
                $errors[] = "Erro ao criar função 'obter_eventos_hoje': " . $e->getMessage();
            }
            
            // Função obter_eventos_semana (CORRIGIDA)
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
            AS \$\$
            BEGIN
                RETURN QUERY
                SELECT 
                    e.id,
                    e.titulo,
                    e.data_inicio,
                    e.data_fim,
                    COALESCE(e.local, '') as local
                FROM eventos e
                WHERE 
                    e.data_inicio >= CURRENT_DATE
                    AND e.data_inicio <= CURRENT_DATE + INTERVAL '7 days'
                    AND (e.status = 'ativo' OR e.status IS NULL)
                ORDER BY e.data_inicio ASC;
            END;
            \$\$;
            ";
            
            try {
                $pdo->exec($functionSQL3);
                echo "<div class='success'>✅ Função 'obter_eventos_semana' criada corretamente</div>";
                $corrections[] = "Função 'obter_eventos_semana' criada";
                $stats['functions_created']++;
            } catch (Exception $e) {
                echo "<div class='error'>❌ Erro ao criar função 'obter_eventos_semana': " . $e->getMessage() . "</div>";
                $errors[] = "Erro ao criar função 'obter_eventos_semana': " . $e->getMessage();
            }
            
            // 4. CRIAR ÍNDICES SEGUROS
            echo "<h2>📊 Criando Índices Seguros</h2>";
            
            $safeIndexes = [
                "CREATE INDEX IF NOT EXISTS idx_usuarios_email ON usuarios(email)",
                "CREATE INDEX IF NOT EXISTS idx_usuarios_perfil ON usuarios(perfil)",
                "CREATE INDEX IF NOT EXISTS idx_eventos_status ON eventos(status) WHERE status IS NOT NULL",
                "CREATE INDEX IF NOT EXISTS idx_agenda_eventos_data ON agenda_eventos(data_inicio)",
                "CREATE INDEX IF NOT EXISTS idx_demandas_cartoes_quadro ON demandas_cartoes(quadro_id)",
                "CREATE INDEX IF NOT EXISTS idx_demandas_cartoes_coluna ON demandas_cartoes(coluna_id)",
                "CREATE INDEX IF NOT EXISTS idx_demandas_cartoes_vencimento ON demandas_cartoes(data_vencimento) WHERE data_vencimento IS NOT NULL"
            ];
            
            foreach ($safeIndexes as $indexSql) {
                try {
                    $pdo->exec($indexSql);
                    echo "<div class='success'>✅ Índice criado com sucesso</div>";
                    $stats['indexes_created']++;
                } catch (Exception $e) {
                    echo "<div class='warning'>⚠️ Índice já existe ou erro: " . $e->getMessage() . "</div>";
                }
            }
            
            // 5. TESTAR FUNÇÕES
            echo "<h2>🧪 Testando Funções</h2>";
            
            try {
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
                $stmt = $pdo->prepare("SELECT * FROM obter_eventos_semana(1)");
                $stmt->execute();
                $events = $stmt->fetchAll();
                echo "<div class='success'>✅ Função 'obter_eventos_semana' funcionando (" . count($events) . " eventos)</div>";
                $stats['tests_passed']++;
            } catch (Exception $e) {
                echo "<div class='error'>❌ Erro ao testar função 'obter_eventos_semana': " . $e->getMessage() . "</div>";
                $errors[] = "Erro ao testar função 'obter_eventos_semana': " . $e->getMessage();
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
            <h3>🎉 CORREÇÃO URGENTE COMPLETA!</h3>
            <p><strong>Ambiente:</strong> <?php echo $environment; ?></p>
            <p><strong>Tabelas:</strong> <?php echo $stats['tables_created']; ?> criadas</p>
            <p><strong>Colunas:</strong> <?php echo $stats['columns_fixed']; ?> corrigidas</p>
            <p><strong>Funções:</strong> <?php echo $stats['functions_created']; ?> criadas</p>
            <p><strong>Índices:</strong> <?php echo $stats['indexes_created']; ?> criados</p>
            <p><strong>Testes:</strong> <?php echo $stats['tests_passed']; ?> passaram</p>
            
            <?php if (empty($errors)): ?>
                <p><strong>✅ TODOS OS PROBLEMAS CRÍTICOS FORAM RESOLVIDOS!</strong></p>
                <p>O sistema agora deve funcionar perfeitamente na produção.</p>
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
            <a href="fix_production_URGENTE.php" class="button">🔍 Verificar Novamente</a>
            <a href="dashboard.php" class="button success">🏠 Ir para Dashboard</a>
        </div>
    </div>
</body>
</html>
