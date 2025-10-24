-- CORRECAO_PROBLEMAS_REAIS.sql
-- Correção dos problemas reais identificados no banco

-- 1. ADICIONAR COLUNAS DE PERMISSÕES FALTANTES
-- =============================================

-- Adicionar perm_agenda_meus se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_agenda_meus') THEN
        ALTER TABLE usuarios ADD COLUMN perm_agenda_meus BOOLEAN DEFAULT false;
        RAISE NOTICE 'Coluna perm_agenda_meus adicionada.';
    ELSE
        RAISE NOTICE 'Coluna perm_agenda_meus já existe.';
    END IF;
END $$;

-- Adicionar perm_agenda_relatorios se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_agenda_relatorios') THEN
        ALTER TABLE usuarios ADD COLUMN perm_agenda_relatorios BOOLEAN DEFAULT false;
        RAISE NOTICE 'Coluna perm_agenda_relatorios adicionada.';
    ELSE
        RAISE NOTICE 'Coluna perm_agenda_relatorios já existe.';
    END IF;
END $$;

-- 2. CRIAR TABELA FORNECEDORES
-- ============================

CREATE TABLE IF NOT EXISTS fornecedores (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    telefone VARCHAR(20),
    cnpj VARCHAR(18),
    endereco TEXT,
    ativo BOOLEAN DEFAULT true,
    criado_em TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- 3. CRIAR TABELA LC_CATEGORIAS
-- =============================

CREATE TABLE IF NOT EXISTS lc_categorias (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descricao TEXT,
    ativo BOOLEAN DEFAULT true,
    criado_em TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- 4. CRIAR TABELA LC_UNIDADES
-- ===========================

CREATE TABLE IF NOT EXISTS lc_unidades (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(50) NOT NULL,
    sigla VARCHAR(10) NOT NULL,
    tipo VARCHAR(20) DEFAULT 'volume',
    ativo BOOLEAN DEFAULT true,
    criado_em TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- 5. CRIAR TABELA LC_FICHAS
-- =========================

CREATE TABLE IF NOT EXISTS lc_fichas (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    descricao TEXT,
    rendimento INTEGER DEFAULT 1,
    ativo BOOLEAN DEFAULT true,
    criado_em TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- 6. CRIAR TABELA COMERCIAL_CAMPOS_PADRAO
-- =======================================

CREATE TABLE IF NOT EXISTS comercial_campos_padrao (
    id SERIAL PRIMARY KEY,
    campos_json TEXT DEFAULT '[]',
    ativo BOOLEAN DEFAULT true,
    criado_em TIMESTAMP DEFAULT NOW(),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- 7. CRIAR TABELA DEMANDAS_QUADROS
-- ===============================

CREATE TABLE IF NOT EXISTS demandas_quadros (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    descricao TEXT,
    cor VARCHAR(7) DEFAULT '#3b82f6',
    ativo BOOLEAN DEFAULT true,
    criado_por INTEGER REFERENCES usuarios(id),
    criado_em TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- 8. CRIAR TABELA DEMANDAS_CARTOES
-- ================================

CREATE TABLE IF NOT EXISTS demandas_cartoes (
    id SERIAL PRIMARY KEY,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT,
    status VARCHAR(50) DEFAULT 'pendente',
    prioridade VARCHAR(20) DEFAULT 'media',
    data_vencimento TIMESTAMP,
    quadro_id INTEGER REFERENCES demandas_quadros(id),
    criado_por INTEGER REFERENCES usuarios(id),
    responsavel_id INTEGER REFERENCES usuarios(id),
    criado_em TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- 9. CONFIGURAR PERMISSÕES PARA USUÁRIO ADMIN
-- ============================================

UPDATE usuarios SET 
    perm_agenda_meus = true,
    perm_agenda_relatorios = true,
    perm_agenda_ver = true,
    perm_agenda_editar = true,
    perm_agenda_criar = true,
    perm_agenda_excluir = true,
    perm_demandas_ver = true,
    perm_demandas_editar = true,
    perm_demandas_criar = true,
    perm_demandas_excluir = true,
    perm_demandas_ver_produtividade = true,
    perm_comercial_ver = true,
    perm_comercial_deg_editar = true,
    perm_comercial_deg_inscritos = true,
    perm_comercial_conversao = true
WHERE perfil = 'ADM' OR id = 1;

-- 10. INSERIR DADOS BÁSICOS
-- =========================

-- Inserir categorias básicas
INSERT INTO lc_categorias (nome, descricao) VALUES 
('Carnes', 'Carnes e proteínas'),
('Vegetais', 'Vegetais e legumes'),
('Temperos', 'Temperos e condimentos'),
('Bebidas', 'Bebidas e líquidos')
ON CONFLICT DO NOTHING;

-- Inserir unidades básicas
INSERT INTO lc_unidades (nome, sigla, tipo) VALUES 
('Quilograma', 'kg', 'peso'),
('Gramas', 'g', 'peso'),
('Litros', 'L', 'volume'),
('Mililitros', 'ml', 'volume'),
('Unidade', 'un', 'quantidade'),
('Pacote', 'pct', 'quantidade')
ON CONFLICT DO NOTHING;

-- Inserir registro padrão para comercial_campos_padrao
INSERT INTO comercial_campos_padrao (campos_json, ativo) VALUES 
('[]', true)
ON CONFLICT DO NOTHING;

-- 11. CRIAR ÍNDICES PARA PERFORMANCE
-- ==================================

CREATE INDEX IF NOT EXISTS idx_fornecedores_ativo ON fornecedores(ativo);
CREATE INDEX IF NOT EXISTS idx_lc_categorias_ativo ON lc_categorias(ativo);
CREATE INDEX IF NOT EXISTS idx_lc_unidades_ativo ON lc_unidades(ativo);
CREATE INDEX IF NOT EXISTS idx_lc_fichas_ativo ON lc_fichas(ativo);
CREATE INDEX IF NOT EXISTS idx_demandas_quadros_ativo ON demandas_quadros(ativo);
CREATE INDEX IF NOT EXISTS idx_demandas_cartoes_quadro ON demandas_cartoes(quadro_id);
CREATE INDEX IF NOT EXISTS idx_demandas_cartoes_responsavel ON demandas_cartoes(responsavel_id);

-- 12. VERIFICAÇÃO FINAL
-- ====================

-- Verificar se todas as tabelas foram criadas
SELECT 'Tabelas criadas:' AS status;
SELECT table_name FROM information_schema.tables 
WHERE table_schema = 'smilee12_painel_smile' 
AND table_name IN ('fornecedores', 'lc_categorias', 'lc_unidades', 'lc_fichas', 'comercial_campos_padrao', 'demandas_quadros', 'demandas_cartoes')
ORDER BY table_name;

-- Verificar colunas de permissões
SELECT 'Colunas de permissões:' AS status;
SELECT column_name FROM information_schema.columns 
WHERE table_name = 'usuarios' 
AND column_name IN ('perm_agenda_meus', 'perm_agenda_relatorios')
ORDER BY column_name;

-- Testar consultas básicas
SELECT 'Teste de consultas:' AS status;
SELECT COUNT(*) as fornecedores_count FROM fornecedores;
SELECT COUNT(*) as categorias_count FROM lc_categorias;
SELECT COUNT(*) as unidades_count FROM lc_unidades;
SELECT COUNT(*) as fichas_count FROM lc_fichas;
SELECT COUNT(*) as campos_padrao_count FROM comercial_campos_padrao;
SELECT COUNT(*) as quadros_count FROM demandas_quadros;
SELECT COUNT(*) as cartoes_count FROM demandas_cartoes;
