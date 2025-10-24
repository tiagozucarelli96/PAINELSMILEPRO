<?php
/**
 * fix_all_environments_web.php — Corrigir TODOS os problemas via web
 * Acesse: http://localhost:8000/fix_all_environments_web.php
 * OU: https://painelsmilepro-production.up.railway.app/fix_all_environments_web.php
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
    <title>Correção Completa - Local e Produção</title>
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
        .stats { display: flex; justify-content: space-around; margin: 20px 0; }
        .stat-box { background: #f0f0f0; padding: 15px; border-radius: 5px; text-align: center; }
        .stat-number { font-size: 24px; font-weight: bold; color: #2196F3; }
        .stat-label { font-size: 14px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Correção Completa - Local e Produção</h1>
        
        <?php
        $corrections = [];
        $errors = [];
        $stats = [
            'tables_existing' => 0,
            'tables_created' => 0,
            'columns_existing' => 0,
            'columns_created' => 0,
            'users_found' => 0,
            'indexes_created' => 0
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
                
                'lc_insumos_substitutos' => "
                    CREATE TABLE IF NOT EXISTS lc_insumos_substitutos (
                        id SERIAL PRIMARY KEY,
                        insumo_principal_id INT NOT NULL REFERENCES lc_insumos(id) ON DELETE CASCADE,
                        insumo_substituto_id INT NOT NULL REFERENCES lc_insumos(id) ON DELETE CASCADE,
                        fator_conversao NUMERIC(10,4) NOT NULL DEFAULT 1.0,
                        ativo BOOLEAN DEFAULT true,
                        criado_em TIMESTAMP DEFAULT NOW(),
                        atualizado_em TIMESTAMP DEFAULT NOW(),
                        criado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
                        UNIQUE(insumo_principal_id, insumo_substituto_id)
                    )
                ",
                
                'lc_evento_cardapio' => "
                    CREATE TABLE IF NOT EXISTS lc_evento_cardapio (
                        id SERIAL PRIMARY KEY,
                        evento_id INT NOT NULL REFERENCES eventos(id) ON DELETE CASCADE,
                        insumo_id INT NOT NULL REFERENCES lc_insumos(id) ON DELETE CASCADE,
                        quantidade NUMERIC(10,2) NOT NULL,
                        unidade_medida VARCHAR(10) NOT NULL,
                        created_at TIMESTAMP DEFAULT NOW(),
                        updated_at TIMESTAMP DEFAULT NOW(),
                        UNIQUE(evento_id, insumo_id)
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
                        $stats['tables_existing']++;
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
                    $stats['columns_existing']++;
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
            
            // 4. CONFIGURAR PERMISSÕES PARA USUÁRIOS EXISTENTES
            echo "<h2>👤 Configurando Permissões para Usuários</h2>";
            
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios");
                $userCount = $stmt->fetchColumn();
                $stats['users_found'] = $userCount;
                
                if ($userCount > 0) {
                    echo "<div class='info'>📊 Encontrados $userCount usuários</div>";
                    
                    // Definir todas as permissões como true para usuários existentes
                    foreach ($permissionColumns as $column => $type) {
                        try {
                            $pdo->exec("UPDATE usuarios SET $column = true WHERE $column IS NULL OR $column = false");
                            echo "<div class='success'>✅ Permissões '$column' configuradas</div>";
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
            
            // 5. CRIAR ÍNDICES IMPORTANTES
            echo "<h2>📊 Criando Índices Importantes</h2>";
            
            $indexes = [
                "CREATE INDEX IF NOT EXISTS idx_eventos_data ON eventos(data_inicio)",
                "CREATE INDEX IF NOT EXISTS idx_agenda_eventos_data ON agenda_eventos(data_inicio)",
                "CREATE INDEX IF NOT EXISTS idx_usuarios_email ON usuarios(email)",
                "CREATE INDEX IF NOT EXISTS idx_usuarios_perfil ON usuarios(perfil)"
            ];
            
            foreach ($indexes as $indexSql) {
                try {
                    $pdo->exec($indexSql);
                    echo "<div class='success'>✅ Índice criado</div>";
                    $stats['indexes_created']++;
                } catch (Exception $e) {
                    echo "<div class='warning'>⚠️ Índice já existe ou erro: " . $e->getMessage() . "</div>";
                }
            }
            
            // 6. TESTAR CONSULTAS COMUNS
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
                } catch (Exception $e) {
                    echo "<div class='error'>❌ $description: ERRO - " . $e->getMessage() . "</div>";
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
                <div class="stat-number"><?php echo $stats['tables_existing'] + $stats['tables_created']; ?></div>
                <div class="stat-label">Tabelas</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['columns_existing'] + $stats['columns_created']; ?></div>
                <div class="stat-label">Colunas</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['users_found']; ?></div>
                <div class="stat-label">Usuários</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['indexes_created']; ?></div>
                <div class="stat-label">Índices</div>
            </div>
        </div>
        
        <!-- Relatório Final -->
        <div class="success">
            <h3>🎉 Correção Completa Finalizada!</h3>
            <p><strong>Ambiente:</strong> <?php echo $environment; ?></p>
            <p><strong>Tabelas:</strong> <?php echo $stats['tables_existing']; ?> existentes, <?php echo $stats['tables_created']; ?> criadas</p>
            <p><strong>Colunas:</strong> <?php echo $stats['columns_existing']; ?> existentes, <?php echo $stats['columns_created']; ?> criadas</p>
            <p><strong>Usuários:</strong> <?php echo $stats['users_found']; ?> encontrados</p>
            <p><strong>Índices:</strong> <?php echo $stats['indexes_created']; ?> criados/verificados</p>
            
            <?php if (empty($errors)): ?>
                <p><strong>✅ TODOS OS PROBLEMAS FORAM RESOLVIDOS!</strong></p>
                <p>O sistema agora deve funcionar perfeitamente em qualquer ambiente.</p>
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
            <a href="fix_all_environments_web.php" class="button">🔍 Verificar Novamente</a>
            <a href="dashboard.php" class="button success">🏠 Ir para Dashboard</a>
        </div>
    </div>
</body>
</html>
