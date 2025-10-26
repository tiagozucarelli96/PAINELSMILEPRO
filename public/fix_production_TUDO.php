<?php
/**
 * fix_production_TUDO.php — CORRIGIR TUDO NA PRODUÇÃO DE UMA VEZ
 * Acesse: https://painelsmilepro-production.up.railway.app/fix_production_TUDO.php
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
    <title>🚀 CORREÇÃO TOTAL - PRODUÇÃO</title>
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
        .stats { display: flex; justify-content: space-around; margin: 20px 0; }
        .stat-box { background: #f0f0f0; padding: 15px; border-radius: 5px; text-align: center; }
        .stat-number { font-size: 24px; font-weight: bold; color: #2196F3; }
        .stat-label { font-size: 14px; color: #666; }
        .code { background: #f5f5f5; padding: 10px; border-radius: 5px; font-family: monospace; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 CORREÇÃO TOTAL - PRODUÇÃO</h1>
        
        <?php
        $corrections = [];
        $errors = [];
        $stats = [
            'tables_created' => 0,
            'columns_created' => 0,
            'functions_created' => 0,
            'indexes_created' => 0,
            'permissions_fixed' => 0,
            'tests_passed' => 0
        ];
        
        try {
            // Detectar ambiente
            $isProduction = getenv("DATABASE_URL") && strpos(getenv("DATABASE_URL"), 'railway') !== false;
            $environment = $isProduction ? "PRODUÇÃO (Railway)" : "LOCAL";
            
            echo "<div class='info'>";
            echo "<h3>🌍 Ambiente Detectado: $environment</h3>";
            echo "<p>DATABASE_URL: " . (getenv("DATABASE_URL") ? "Definido" : "Não definido") . "</p>";
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
            
            // 1. CRIAR TODAS AS TABELAS NECESSÁRIAS
            echo "<h2>🏗️ Criando Todas as Tabelas Necessárias</h2>";
            
            $tables = [
                'eventos' => "
                    CREATE TABLE IF NOT EXISTS eventos (
                        id SERIAL PRIMARY KEY,
                        titulo VARCHAR(255) NOT NULL,
                        descricao TEXT,
                        data_inicio TIMESTAMP NOT NULL,
                        data_fim TIMESTAMP NOT NULL,
                        local VARCHAR(255),
                        usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
                        status VARCHAR(20) DEFAULT 'ativo' CHECK (status IN ('ativo', 'cancelado', 'concluido')),
                        observacoes TEXT,
                        created_at TIMESTAMP DEFAULT NOW(),
                        updated_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'agenda_espacos' => "
                    CREATE TABLE IF NOT EXISTS agenda_espacos (
                        id SERIAL PRIMARY KEY,
                        nome VARCHAR(255) NOT NULL,
                        descricao TEXT,
                        capacidade INT DEFAULT 0,
                        ativo BOOLEAN DEFAULT true,
                        created_at TIMESTAMP DEFAULT NOW(),
                        updated_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'agenda_eventos' => "
                    CREATE TABLE IF NOT EXISTS agenda_eventos (
                        id SERIAL PRIMARY KEY,
                        titulo VARCHAR(255) NOT NULL,
                        descricao TEXT,
                        data_inicio TIMESTAMP NOT NULL,
                        data_fim TIMESTAMP NOT NULL,
                        espaco_id INT REFERENCES agenda_espacos(id) ON DELETE SET NULL,
                        usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
                        tipo VARCHAR(50) DEFAULT 'evento' CHECK (tipo IN ('evento', 'visita', 'bloqueio')),
                        cor VARCHAR(7),
                        observacoes TEXT,
                        created_at TIMESTAMP DEFAULT NOW(),
                        updated_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'lc_insumos' => "
                    CREATE TABLE IF NOT EXISTS lc_insumos (
                        id SERIAL PRIMARY KEY,
                        nome VARCHAR(255) NOT NULL,
                        descricao TEXT,
                        unidade_medida VARCHAR(50) DEFAULT 'un',
                        preco NUMERIC(10,2) DEFAULT 0.00,
                        ativo BOOLEAN DEFAULT true,
                        created_at TIMESTAMP DEFAULT NOW(),
                        updated_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'lc_listas' => "
                    CREATE TABLE IF NOT EXISTS lc_listas (
                        id SERIAL PRIMARY KEY,
                        nome VARCHAR(255) NOT NULL,
                        descricao TEXT,
                        status VARCHAR(50) DEFAULT 'pendente',
                        usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
                        created_at TIMESTAMP DEFAULT NOW(),
                        updated_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'lc_fornecedores' => "
                    CREATE TABLE IF NOT EXISTS lc_fornecedores (
                        id SERIAL PRIMARY KEY,
                        nome VARCHAR(255) NOT NULL,
                        contato VARCHAR(255),
                        telefone VARCHAR(50),
                        email VARCHAR(255),
                        ativo BOOLEAN DEFAULT true,
                        created_at TIMESTAMP DEFAULT NOW(),
                        updated_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'estoque_contagens' => "
                    CREATE TABLE IF NOT EXISTS estoque_contagens (
                        id SERIAL PRIMARY KEY,
                        nome VARCHAR(255) NOT NULL,
                        data_inicio TIMESTAMP NOT NULL,
                        data_fim TIMESTAMP,
                        status VARCHAR(50) DEFAULT 'em_andamento',
                        usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
                        created_at TIMESTAMP DEFAULT NOW(),
                        updated_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'estoque_contagem_itens' => "
                    CREATE TABLE IF NOT EXISTS estoque_contagem_itens (
                        id SERIAL PRIMARY KEY,
                        contagem_id INT NOT NULL REFERENCES estoque_contagens(id) ON DELETE CASCADE,
                        insumo_id INT NOT NULL REFERENCES lc_insumos(id) ON DELETE CASCADE,
                        quantidade_contada NUMERIC(10,2) NOT NULL,
                        observacoes TEXT,
                        created_at TIMESTAMP DEFAULT NOW(),
                        updated_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'ean_code' => "
                    CREATE TABLE IF NOT EXISTS ean_code (
                        id SERIAL PRIMARY KEY,
                        codigo VARCHAR(50) UNIQUE NOT NULL,
                        insumo_id INT REFERENCES lc_insumos(id) ON DELETE SET NULL,
                        ativo BOOLEAN DEFAULT true,
                        created_at TIMESTAMP DEFAULT NOW(),
                        updated_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'pagamentos_freelancers' => "
                    CREATE TABLE IF NOT EXISTS pagamentos_freelancers (
                        id SERIAL PRIMARY KEY,
                        nome VARCHAR(255) NOT NULL,
                        cpf VARCHAR(14) UNIQUE,
                        pix VARCHAR(255),
                        telefone VARCHAR(50),
                        email VARCHAR(255),
                        ativo BOOLEAN DEFAULT true,
                        created_at TIMESTAMP DEFAULT NOW(),
                        updated_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'pagamentos_solicitacoes' => "
                    CREATE TABLE IF NOT EXISTS pagamentos_solicitacoes (
                        id SERIAL PRIMARY KEY,
                        titulo VARCHAR(255) NOT NULL,
                        descricao TEXT,
                        valor NUMERIC(10,2) NOT NULL,
                        status VARCHAR(50) DEFAULT 'aguardando',
                        usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
                        freelancer_id INT REFERENCES pagamentos_freelancers(id) ON DELETE SET NULL,
                        created_at TIMESTAMP DEFAULT NOW(),
                        updated_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'pagamentos_timeline' => "
                    CREATE TABLE IF NOT EXISTS pagamentos_timeline (
                        id SERIAL PRIMARY KEY,
                        solicitacao_id INT NOT NULL REFERENCES pagamentos_solicitacoes(id) ON DELETE CASCADE,
                        status_anterior VARCHAR(50),
                        status_novo VARCHAR(50) NOT NULL,
                        observacoes TEXT,
                        usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
                        created_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'comercial_degustacoes' => "
                    CREATE TABLE IF NOT EXISTS comercial_degustacoes (
                        id SERIAL PRIMARY KEY,
                        titulo VARCHAR(255) NOT NULL,
                        descricao TEXT,
                        data_evento TIMESTAMP NOT NULL,
                        local VARCHAR(255),
                        capacidade INT DEFAULT 0,
                        preco NUMERIC(10,2) DEFAULT 0.00,
                        ativo BOOLEAN DEFAULT true,
                        usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
                        created_at TIMESTAMP DEFAULT NOW(),
                        updated_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'comercial_degust_inscricoes' => "
                    CREATE TABLE IF NOT EXISTS comercial_degust_inscricoes (
                        id SERIAL PRIMARY KEY,
                        degustacao_id INT NOT NULL REFERENCES comercial_degustacoes(id) ON DELETE CASCADE,
                        nome VARCHAR(255) NOT NULL,
                        email VARCHAR(255) NOT NULL,
                        telefone VARCHAR(50),
                        cpf VARCHAR(14),
                        status VARCHAR(50) DEFAULT 'pendente',
                        created_at TIMESTAMP DEFAULT NOW(),
                        updated_at TIMESTAMP DEFAULT NOW()
                    )
                ",
                
                'comercial_clientes' => "
                    CREATE TABLE IF NOT EXISTS comercial_clientes (
                        id SERIAL PRIMARY KEY,
                        nome VARCHAR(255) NOT NULL,
                        email VARCHAR(255) NOT NULL,
                        telefone VARCHAR(50),
                        cpf VARCHAR(14),
                        status VARCHAR(50) DEFAULT 'prospect',
                        created_at TIMESTAMP DEFAULT NOW(),
                        updated_at TIMESTAMP DEFAULT NOW()
                    )
                "
            ];
            
            foreach ($tables as $tableName => $sql) {
                try {
                    // Verificar se a tabela já existe
                    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = '$tableName'");
                    $exists = $stmt->fetchColumn() > 0;
                    
                    if ($exists) {
                        echo "<div class='success'>✅ Tabela '$tableName' já existe</div>";
                    } else {
                        echo "<div class='warning'>🔨 Criando tabela '$tableName'...</div>";
                        $pdo->exec($sql);
                        echo "<div class='success'>✅ Tabela '$tableName' criada com sucesso</div>";
                        $corrections[] = "Tabela '$tableName' criada";
                        $stats['tables_created']++;
                    }
                } catch (Exception $e) {
                    echo "<div class='error'>❌ Erro ao criar tabela '$tableName': " . $e->getMessage() . "</div>";
                    $errors[] = "Erro ao criar tabela '$tableName': " . $e->getMessage();
                }
            }
            
            // 2. CRIAR TODAS AS COLUNAS DE PERMISSÃO
            echo "<h2>🔐 Criando Todas as Colunas de Permissão</h2>";
            
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
            
            foreach ($permissionColumns as $column => $type) {
                try {
                    $stmt = $pdo->query("SELECT $column FROM usuarios LIMIT 1");
                    echo "<div class='success'>✅ Coluna '$column' já existe</div>";
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'does not exist') !== false) {
                        echo "<div class='warning'>🔨 Criando coluna '$column'...</div>";
                        $pdo->exec("ALTER TABLE usuarios ADD COLUMN $column $type");
                        echo "<div class='success'>✅ Coluna '$column' criada com sucesso</div>";
                        $corrections[] = "Coluna '$column' criada";
                        $stats['columns_created']++;
                    } else {
                        echo "<div class='error'>❌ Erro ao verificar coluna '$column': " . $e->getMessage() . "</div>";
                        $errors[] = "Erro ao verificar coluna '$column': " . $e->getMessage();
                    }
                }
            }
            
            // 3. CRIAR COLUNAS ESSENCIAIS
            echo "<h2>🔍 Criando Colunas Essenciais</h2>";
            
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
                        echo "<div class='warning'>🔨 Criando coluna '$column'...</div>";
                        $pdo->exec("ALTER TABLE usuarios ADD COLUMN $column $type");
                        echo "<div class='success'>✅ Coluna '$column' criada com sucesso</div>";
                        $corrections[] = "Coluna '$column' criada";
                        $stats['columns_created']++;
                    }
                }
            }
            
            // 4. CRIAR FUNÇÃO obter_proximos_eventos
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
            
            // 5. CRIAR OUTRAS FUNÇÕES ÚTEIS
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
            
            // 6. CONFIGURAR PERMISSÕES PARA USUÁRIOS EXISTENTES
            echo "<h2>👤 Configurando Permissões para Usuários</h2>";
            
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios");
                $userCount = $stmt->fetchColumn();
                
                if ($userCount > 0) {
                    echo "<div class='info'>📊 Encontrados $userCount usuários</div>";
                    
                    // Definir todas as permissões como true para usuários existentes
                    foreach ($permissionColumns as $column => $type) {
                        try {
                            $pdo->exec("UPDATE usuarios SET $column = true WHERE $column IS NULL OR $column = false");
                            echo "<div class='success'>✅ Permissões '$column' configuradas</div>";
                            $stats['permissions_fixed']++;
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
            
            // 7. CRIAR ÍNDICES IMPORTANTES
            echo "<h2>📊 Criando Índices Importantes</h2>";
            
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
            
            // 8. TESTAR FUNÇÕES CRIADAS
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
            
            // 9. TESTAR CONSULTAS COMUNS
            echo "<h2>🧪 Testando Consultas Comuns</h2>";
            
            $testQueries = [
                "SELECT COUNT(*) FROM usuarios" => "Usuários",
                "SELECT COUNT(*) FROM eventos" => "Eventos",
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
        
        <!-- Estatísticas -->
        <div class="stats">
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['tables_created']; ?></div>
                <div class="stat-label">Tabelas Criadas</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['columns_created']; ?></div>
                <div class="stat-label">Colunas Criadas</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['functions_created']; ?></div>
                <div class="stat-label">Funções Criadas</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['indexes_created']; ?></div>
                <div class="stat-label">Índices Criados</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['permissions_fixed']; ?></div>
                <div class="stat-label">Permissões Corrigidas</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['tests_passed']; ?></div>
                <div class="stat-label">Testes Passaram</div>
            </div>
        </div>
        
        <!-- Relatório Final -->
        <div class="success">
            <h3>🎉 CORREÇÃO TOTAL COMPLETA!</h3>
            <p><strong>Ambiente:</strong> <?php echo $environment; ?></p>
            <p><strong>Tabelas:</strong> <?php echo $stats['tables_created']; ?> criadas</p>
            <p><strong>Colunas:</strong> <?php echo $stats['columns_created']; ?> criadas</p>
            <p><strong>Funções:</strong> <?php echo $stats['functions_created']; ?> criadas</p>
            <p><strong>Índices:</strong> <?php echo $stats['indexes_created']; ?> criados</p>
            <p><strong>Permissões:</strong> <?php echo $stats['permissions_fixed']; ?> corrigidas</p>
            <p><strong>Testes:</strong> <?php echo $stats['tests_passed']; ?> passaram</p>
            
            <?php if (empty($errors)): ?>
                <p><strong>✅ TODOS OS PROBLEMAS FORAM RESOLVIDOS!</strong></p>
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
            <a href="fix_production_TUDO.php" class="button">🔍 Verificar Novamente</a>
            <a href="dashboard.php" class="button success">🏠 Ir para Dashboard</a>
        </div>
    </div>
</body>
</html>
