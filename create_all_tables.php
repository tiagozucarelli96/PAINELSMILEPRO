<?php
/**
 * create_all_tables.php — Criar todas as tabelas necessárias
 * Execute: php create_all_tables.php
 */

echo "🏗️ Criando Todas as Tabelas Necessárias\n";
echo "======================================\n\n";

try {
    $host = 'localhost';
    $port = '5432';
    $dbname = 'painel_smile';
    $user = 'tiagozucarelli';
    $password = '';
    
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=disable";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "✅ Conexão com banco estabelecida\n\n";
    
    // Definir todas as tabelas necessárias
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
    
    $createdCount = 0;
    $existingCount = 0;
    
    foreach ($tables as $tableName => $sql) {
        try {
            // Verificar se a tabela já existe
            $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = '$tableName'");
            $exists = $stmt->fetchColumn() > 0;
            
            if ($exists) {
                echo "✅ Tabela '$tableName' já existe\n";
                $existingCount++;
            } else {
                echo "🔨 Criando tabela '$tableName'...\n";
                $pdo->exec($sql);
                echo "✅ Tabela '$tableName' criada com sucesso\n";
                $createdCount++;
            }
        } catch (Exception $e) {
            echo "❌ Erro ao criar tabela '$tableName': " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n📊 Resumo:\n";
    echo "✅ Tabelas existentes: $existingCount\n";
    echo "🔨 Tabelas criadas: $createdCount\n";
    echo "📋 Total de tabelas: " . ($existingCount + $createdCount) . "\n";
    
    // Criar índices importantes
    echo "\n🔍 Criando índices importantes...\n";
    
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_eventos_data ON eventos(data_inicio)",
        "CREATE INDEX IF NOT EXISTS idx_agenda_eventos_data ON agenda_eventos(data_inicio)",
        "CREATE INDEX IF NOT EXISTS idx_usuarios_email ON usuarios(email)",
        "CREATE INDEX IF NOT EXISTS idx_usuarios_perfil ON usuarios(perfil)"
    ];
    
    foreach ($indexes as $indexSql) {
        try {
            $pdo->exec($indexSql);
            echo "✅ Índice criado\n";
        } catch (Exception $e) {
            echo "⚠️ Índice já existe ou erro: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n🎉 Todas as tabelas foram criadas/verificadas com sucesso!\n";
    echo "Agora o sistema deve funcionar sem erros de tabelas faltantes.\n";
    
} catch (Exception $e) {
    echo "❌ Erro fatal: " . $e->getMessage() . "\n";
}
?>
