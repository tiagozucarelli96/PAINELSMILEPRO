<?php
// Executar schema da contabilidade na ordem correta
require_once __DIR__ . '/conexao.php';

echo "🔧 Criando schema completo da contabilidade...\n\n";

// ETAPA 1: Criar tabelas
$tabelas = [
    "CREATE TABLE IF NOT EXISTS contabilidade_acesso (
        id BIGSERIAL PRIMARY KEY,
        link_publico VARCHAR(255) NOT NULL UNIQUE,
        senha_hash VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'ativo' CHECK (status IN ('ativo', 'inativo')),
        criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
        atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
    )",
    
    "CREATE TABLE IF NOT EXISTS contabilidade_sessoes (
        id BIGSERIAL PRIMARY KEY,
        token VARCHAR(255) NOT NULL UNIQUE,
        acesso_id BIGINT NOT NULL REFERENCES contabilidade_acesso(id) ON DELETE CASCADE,
        ip_address VARCHAR(45),
        user_agent TEXT,
        criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
        expira_em TIMESTAMP NOT NULL,
        ativo BOOLEAN NOT NULL DEFAULT TRUE
    )",
    
    "CREATE TABLE IF NOT EXISTS contabilidade_parcelamentos (
        id BIGSERIAL PRIMARY KEY,
        descricao VARCHAR(255) NOT NULL,
        total_parcelas INTEGER NOT NULL,
        parcela_atual INTEGER NOT NULL DEFAULT 1,
        status VARCHAR(20) NOT NULL DEFAULT 'ativo' CHECK (status IN ('ativo', 'encerrado')),
        criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
        atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
    )",
    
    "CREATE TABLE IF NOT EXISTS contabilidade_guias (
        id BIGSERIAL PRIMARY KEY,
        arquivo_url TEXT,
        arquivo_nome VARCHAR(255),
        chave_storage VARCHAR(500),
        data_vencimento DATE,
        descricao TEXT NOT NULL,
        e_parcela BOOLEAN NOT NULL DEFAULT FALSE,
        parcelamento_id BIGINT REFERENCES contabilidade_parcelamentos(id) ON DELETE SET NULL,
        numero_parcela INTEGER,
        status VARCHAR(20) NOT NULL DEFAULT 'aberto' CHECK (status IN ('aberto', 'pago', 'vencido', 'cancelado')),
        criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
        atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
    )",
    
    "CREATE TABLE IF NOT EXISTS contabilidade_holerites (
        id BIGSERIAL PRIMARY KEY,
        arquivo_url TEXT NOT NULL,
        arquivo_nome VARCHAR(255) NOT NULL,
        chave_storage VARCHAR(500),
        mes_competencia VARCHAR(7) NOT NULL,
        e_ajuste BOOLEAN NOT NULL DEFAULT FALSE,
        observacao TEXT,
        status VARCHAR(20) NOT NULL DEFAULT 'aberto' CHECK (status IN ('aberto', 'processado', 'cancelado')),
        criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
        atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
    )",
    
    "CREATE TABLE IF NOT EXISTS contabilidade_honorarios (
        id BIGSERIAL PRIMARY KEY,
        arquivo_url TEXT NOT NULL,
        arquivo_nome VARCHAR(255) NOT NULL,
        chave_storage VARCHAR(500),
        data_vencimento DATE,
        descricao TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'aberto' CHECK (status IN ('aberto', 'pago', 'vencido', 'cancelado')),
        criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
        atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
    )",
    
    "CREATE TABLE IF NOT EXISTS contabilidade_conversas (
        id BIGSERIAL PRIMARY KEY,
        assunto VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'aberto' CHECK (status IN ('aberto', 'em_andamento', 'concluido')),
        criado_por VARCHAR(50) NOT NULL DEFAULT 'contabilidade',
        criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
        atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
    )",
    
    "CREATE TABLE IF NOT EXISTS contabilidade_conversas_mensagens (
        id BIGSERIAL PRIMARY KEY,
        conversa_id BIGINT NOT NULL REFERENCES contabilidade_conversas(id) ON DELETE CASCADE,
        autor VARCHAR(50) NOT NULL,
        mensagem TEXT,
        anexo_url TEXT,
        anexo_nome VARCHAR(255),
        chave_storage VARCHAR(500),
        criado_em TIMESTAMP NOT NULL DEFAULT NOW()
    )",
    
    "CREATE TABLE IF NOT EXISTS contabilidade_colaboradores_documentos (
        id BIGSERIAL PRIMARY KEY,
        colaborador_id BIGINT NOT NULL,
        tipo_documento VARCHAR(50) NOT NULL,
        arquivo_url TEXT NOT NULL,
        arquivo_nome VARCHAR(255) NOT NULL,
        chave_storage VARCHAR(500),
        descricao TEXT,
        criado_em TIMESTAMP NOT NULL DEFAULT NOW()
    )"
];

echo "📋 ETAPA 1: Criando tabelas...\n";
$sucesso_tabelas = 0;
foreach ($tabelas as $index => $sql) {
    try {
        $pdo->exec($sql);
        $sucesso_tabelas++;
        $tabela_nome = preg_match('/CREATE TABLE.*?(\w+)\s*\(/', $sql, $matches) ? $matches[1] : "Tabela " . ($index + 1);
        echo "✅ $tabela_nome criada\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            $tabela_nome = preg_match('/CREATE TABLE.*?(\w+)\s*\(/', $sql, $matches) ? $matches[1] : "Tabela " . ($index + 1);
            echo "⚠️  $tabela_nome já existe\n";
        } else {
            echo "❌ Erro: " . $e->getMessage() . "\n";
        }
    }
}

// ETAPA 2: Adicionar chave_storage nas tabelas existentes que não têm
echo "\n📋 ETAPA 2: Adicionando coluna chave_storage...\n";
$tabelas_para_chave = [
    'contabilidade_guias',
    'contabilidade_holerites',
    'contabilidade_honorarios',
    'contabilidade_conversas_mensagens',
    'contabilidade_colaboradores_documentos'
];

$sucesso_colunas = 0;
foreach ($tabelas_para_chave as $tabela) {
    try {
        // Verificar se tabela existe
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = '$tabela'");
        if ($stmt->fetchColumn() == 0) {
            echo "⚠️  $tabela não existe, pulando...\n";
            continue;
        }
        
        // Verificar se coluna já existe
        $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = '$tabela' AND column_name = 'chave_storage'");
        if ($stmt->fetchColumn() !== false) {
            echo "✅ $tabela - chave_storage já existe\n";
            continue;
        }
        
        // Adicionar coluna
        $pdo->exec("ALTER TABLE $tabela ADD COLUMN chave_storage VARCHAR(500)");
        echo "✅ $tabela - chave_storage adicionada\n";
        $sucesso_colunas++;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false || 
            strpos($e->getMessage(), 'duplicate') !== false) {
            echo "✅ $tabela - chave_storage já existe\n";
        } else {
            echo "❌ $tabela - Erro: " . $e->getMessage() . "\n";
        }
    }
}

// ETAPA 3: Criar índices
echo "\n📋 ETAPA 3: Criando índices...\n";
$indices = [
    "CREATE INDEX IF NOT EXISTS idx_contabilidade_acesso_status ON contabilidade_acesso(status)",
    "CREATE INDEX IF NOT EXISTS idx_contabilidade_sessoes_token ON contabilidade_sessoes(token)",
    "CREATE INDEX IF NOT EXISTS idx_contabilidade_sessoes_ativo ON contabilidade_sessoes(ativo)",
    "CREATE INDEX IF NOT EXISTS idx_contabilidade_parcelamentos_status ON contabilidade_parcelamentos(status)",
    "CREATE INDEX IF NOT EXISTS idx_contabilidade_guias_status ON contabilidade_guias(status)",
    "CREATE INDEX IF NOT EXISTS idx_contabilidade_guias_parcelamento ON contabilidade_guias(parcelamento_id)",
    "CREATE INDEX IF NOT EXISTS idx_contabilidade_guias_chave_storage ON contabilidade_guias(chave_storage)",
    "CREATE INDEX IF NOT EXISTS idx_contabilidade_holerites_status ON contabilidade_holerites(status)",
    "CREATE INDEX IF NOT EXISTS idx_contabilidade_holerites_chave_storage ON contabilidade_holerites(chave_storage)",
    "CREATE INDEX IF NOT EXISTS idx_contabilidade_honorarios_status ON contabilidade_honorarios(status)",
    "CREATE INDEX IF NOT EXISTS idx_contabilidade_honorarios_chave_storage ON contabilidade_honorarios(chave_storage)",
    "CREATE INDEX IF NOT EXISTS idx_contabilidade_conversas_status ON contabilidade_conversas(status)",
    "CREATE INDEX IF NOT EXISTS idx_contabilidade_conversas_mensagens_conversa ON contabilidade_conversas_mensagens(conversa_id)",
    "CREATE INDEX IF NOT EXISTS idx_contabilidade_colaboradores_docs_colab ON contabilidade_colaboradores_documentos(colaborador_id)"
];

$sucesso_indices = 0;
foreach ($indices as $sql) {
    try {
        $pdo->exec($sql);
        $sucesso_indices++;
        $index_nome = preg_match('/idx_(\w+)/', $sql, $matches) ? $matches[1] : "Índice";
        echo "✅ $index_nome criado\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "⚠️  Índice já existe (ignorado)\n";
        } else {
            echo "❌ Erro: " . $e->getMessage() . "\n";
        }
    }
}

echo "\n📊 Resumo Final:\n";
echo "   ✅ Tabelas: $sucesso_tabelas\n";
echo "   ✅ Colunas chave_storage: $sucesso_colunas\n";
echo "   ✅ Índices: $sucesso_indices\n";
echo "\n✅ Schema da contabilidade criado com sucesso!\n";
