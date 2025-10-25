-- CORRECAO_TODOS_PROBLEMAS.sql
-- Correção de TODOS os problemas identificados

-- 1. Adicionar colunas faltantes na tabela usuarios
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_agenda_meus BOOLEAN DEFAULT FALSE;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_agenda_relatorios BOOLEAN DEFAULT FALSE;

-- 2. Atualizar permissões para usuários ADM
UPDATE usuarios 
SET perm_agenda_meus = TRUE, 
    perm_agenda_relatorios = TRUE 
WHERE perfil = 'ADM';

-- 3. Criar tabela fornecedores se não existir
CREATE TABLE IF NOT EXISTS fornecedores (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    telefone VARCHAR(20),
    endereco TEXT,
    cnpj VARCHAR(18),
    status VARCHAR(20) DEFAULT 'ativo',
    observacoes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. Criar tabela lc_categorias se não existir
CREATE TABLE IF NOT EXISTS lc_categorias (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    descricao TEXT,
    ativo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 5. Criar tabela lc_unidades se não existir
CREATE TABLE IF NOT EXISTS lc_unidades (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    sigla VARCHAR(10) NOT NULL,
    ativo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 6. Criar tabela lc_fichas se não existir
CREATE TABLE IF NOT EXISTS lc_fichas (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    descricao TEXT,
    ativo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 7. Criar tabela comercial_campos_padrao se não existir
CREATE TABLE IF NOT EXISTS comercial_campos_padrao (
    id SERIAL PRIMARY KEY,
    campos_json JSONB DEFAULT '[]',
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 8. Inserir dados padrão se as tabelas estiverem vazias
INSERT INTO lc_categorias (nome, descricao, ativo) 
SELECT 'Carnes', 'Carnes e derivados', TRUE
WHERE NOT EXISTS (SELECT 1 FROM lc_categorias WHERE nome = 'Carnes');

INSERT INTO lc_categorias (nome, descricao, ativo) 
SELECT 'Vegetais', 'Vegetais e legumes', TRUE
WHERE NOT EXISTS (SELECT 1 FROM lc_categorias WHERE nome = 'Vegetais');

INSERT INTO lc_categorias (nome, descricao, ativo) 
SELECT 'Laticínios', 'Leite e derivados', TRUE
WHERE NOT EXISTS (SELECT 1 FROM lc_categorias WHERE nome = 'Laticínios');

INSERT INTO lc_categorias (nome, descricao, ativo) 
SELECT 'Grãos', 'Arroz, feijão e outros grãos', TRUE
WHERE NOT EXISTS (SELECT 1 FROM lc_categorias WHERE nome = 'Grãos');

INSERT INTO lc_unidades (nome, sigla, ativo) 
SELECT 'Quilograma', 'kg', TRUE
WHERE NOT EXISTS (SELECT 1 FROM lc_unidades WHERE sigla = 'kg');

INSERT INTO lc_unidades (nome, sigla, ativo) 
SELECT 'Litro', 'L', TRUE
WHERE NOT EXISTS (SELECT 1 FROM lc_unidades WHERE sigla = 'L');

INSERT INTO lc_unidades (nome, sigla, ativo) 
SELECT 'Unidade', 'un', TRUE
WHERE NOT EXISTS (SELECT 1 FROM lc_unidades WHERE sigla = 'un');

INSERT INTO lc_fichas (nome, descricao, ativo) 
SELECT 'Ficha Padrão', 'Ficha técnica padrão do sistema', TRUE
WHERE NOT EXISTS (SELECT 1 FROM lc_fichas WHERE nome = 'Ficha Padrão');

INSERT INTO fornecedores (nome, email, telefone, status) 
SELECT 'Fornecedor Padrão', 'contato@fornecedor.com', '(11) 99999-9999', 'ativo'
WHERE NOT EXISTS (SELECT 1 FROM fornecedores WHERE nome = 'Fornecedor Padrão');

INSERT INTO comercial_campos_padrao (campos_json, ativo) 
SELECT '[]', TRUE
WHERE NOT EXISTS (SELECT 1 FROM comercial_campos_padrao);

-- 9. Criar índices para performance
CREATE INDEX IF NOT EXISTS idx_fornecedores_nome ON fornecedores(nome);
CREATE INDEX IF NOT EXISTS idx_fornecedores_status ON fornecedores(status);
CREATE INDEX IF NOT EXISTS idx_lc_categorias_nome ON lc_categorias(nome);
CREATE INDEX IF NOT EXISTS idx_lc_categorias_ativo ON lc_categorias(ativo);
CREATE INDEX IF NOT EXISTS idx_lc_unidades_sigla ON lc_unidades(sigla);
CREATE INDEX IF NOT EXISTS idx_lc_unidades_ativo ON lc_unidades(ativo);
CREATE INDEX IF NOT EXISTS idx_lc_fichas_nome ON lc_fichas(nome);
CREATE INDEX IF NOT EXISTS idx_lc_fichas_ativo ON lc_fichas(ativo);
CREATE INDEX IF NOT EXISTS idx_comercial_campos_ativo ON comercial_campos_padrao(ativo);

-- 10. Verificar se tudo foi criado corretamente
SELECT 'Tabelas criadas/verificadas:' as status;
SELECT 'usuarios' as tabela, COUNT(*) as registros FROM usuarios
UNION ALL
SELECT 'fornecedores', COUNT(*) FROM fornecedores
UNION ALL
SELECT 'lc_categorias', COUNT(*) FROM lc_categorias
UNION ALL
SELECT 'lc_unidades', COUNT(*) FROM lc_unidades
UNION ALL
SELECT 'lc_fichas', COUNT(*) FROM lc_fichas
UNION ALL
SELECT 'comercial_campos_padrao', COUNT(*) FROM comercial_campos_padrao;

-- 11. Verificar colunas de permissões
SELECT 'Colunas de permissões verificadas:' as status;
SELECT 
    perm_agenda_ver,
    perm_agenda_meus,
    perm_agenda_relatorios
FROM usuarios 
WHERE id = 1;
