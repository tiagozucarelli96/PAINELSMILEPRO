-- Script para criar todas as tabelas necessárias do sistema Lista de Compras
-- Execute este script no seu banco PostgreSQL

-- 1. Tabela principal de listas
CREATE TABLE IF NOT EXISTS lc_listas (
    id SERIAL PRIMARY KEY,
    tipo_lista VARCHAR(20) NOT NULL DEFAULT 'compras', -- 'compras' ou 'encomendas'
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    criado_por INTEGER,
    resumo_eventos TEXT,
    espaco_resumo VARCHAR(100)
);

-- Adicionar coluna tipo_lista se a tabela já existir sem ela
DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'lc_listas' AND column_name = 'tipo_lista') THEN
        ALTER TABLE lc_listas ADD COLUMN tipo_lista VARCHAR(20) NOT NULL DEFAULT 'compras';
    END IF;
END $$;

-- 2. Tabela de eventos vinculados às listas
CREATE TABLE IF NOT EXISTS lc_listas_eventos (
    id SERIAL PRIMARY KEY,
    lista_id INTEGER NOT NULL REFERENCES lc_listas(id) ON DELETE CASCADE,
    evento_id INTEGER NOT NULL,
    convidados INTEGER DEFAULT 0,
    data_evento DATE,
    resumo TEXT
);

-- 3. Tabela de compras consolidadas (insumos internos + fixos)
CREATE TABLE IF NOT EXISTS lc_compras_consolidadas (
    id SERIAL PRIMARY KEY,
    lista_id INTEGER NOT NULL REFERENCES lc_listas(id) ON DELETE CASCADE,
    insumo_nome VARCHAR(200) NOT NULL,
    unidade_simbolo VARCHAR(20) NOT NULL,
    qtd DECIMAL(15,6) NOT NULL DEFAULT 0,
    custo DECIMAL(15,2) NOT NULL DEFAULT 0
);

-- 4. Tabela de itens de encomendas (fornecedor → evento)
CREATE TABLE IF NOT EXISTS lc_encomendas_itens (
    id SERIAL PRIMARY KEY,
    lista_id INTEGER NOT NULL REFERENCES lc_listas(id) ON DELETE CASCADE,
    fornecedor_id INTEGER,
    evento_id INTEGER NOT NULL,
    item_nome VARCHAR(200) NOT NULL,
    unidade_simbolo VARCHAR(20) NOT NULL,
    qtd DECIMAL(15,6) NOT NULL DEFAULT 0,
    custo DECIMAL(15,2) NOT NULL DEFAULT 0,
    foi_arredondado BOOLEAN DEFAULT FALSE
);

-- 5. Tabela de configurações do sistema
CREATE TABLE IF NOT EXISTS lc_config (
    chave VARCHAR(100) PRIMARY KEY,
    valor TEXT NOT NULL,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 6. Tabela de itens fixos (1x por evento)
CREATE TABLE IF NOT EXISTS lc_itens_fixos (
    id SERIAL PRIMARY KEY,
    insumo_id INTEGER NOT NULL,
    qtd DECIMAL(15,6) NOT NULL DEFAULT 0,
    unidade_id INTEGER NOT NULL,
    observacao TEXT,
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 7. Tabela de categorias (se não existir)
CREATE TABLE IF NOT EXISTS lc_categorias (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL UNIQUE,
    ativa BOOLEAN DEFAULT TRUE,
    ordem INTEGER DEFAULT 0
);

-- 8. Tabela de unidades (se não existir)
CREATE TABLE IF NOT EXISTS lc_unidades (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    simbolo VARCHAR(20) NOT NULL UNIQUE,
    fator_base DECIMAL(15,6) NOT NULL DEFAULT 1,
    ativa BOOLEAN DEFAULT TRUE
);

-- 9. Tabela de insumos (se não existir)
CREATE TABLE IF NOT EXISTS lc_insumos (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(200) NOT NULL,
    unidade_id INTEGER NOT NULL,
    preco DECIMAL(15,2) DEFAULT 0,
    fator_correcao DECIMAL(15,6) DEFAULT 1,
    tipo_padrao VARCHAR(50),
    fornecedor_id INTEGER,
    observacao TEXT,
    ativo BOOLEAN DEFAULT TRUE,
    embalagem_multiplo DECIMAL(15,6),
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 10. Tabela de fornecedores (se não existir)
CREATE TABLE IF NOT EXISTS fornecedores (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(200) NOT NULL,
    contato VARCHAR(200),
    telefone VARCHAR(50),
    email VARCHAR(200),
    endereco TEXT,
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 11. Tabela de usuários (se não existir)
CREATE TABLE IF NOT EXISTS usuarios (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(200) NOT NULL,
    email VARCHAR(200) UNIQUE,
    senha VARCHAR(255),
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Índices para melhor performance
CREATE INDEX IF NOT EXISTS idx_lc_listas_tipo ON lc_listas(tipo_lista);
CREATE INDEX IF NOT EXISTS idx_lc_listas_criado_em ON lc_listas(criado_em);
CREATE INDEX IF NOT EXISTS idx_lc_listas_eventos_lista_id ON lc_listas_eventos(lista_id);
CREATE INDEX IF NOT EXISTS idx_lc_compras_lista_id ON lc_compras_consolidadas(lista_id);
CREATE INDEX IF NOT EXISTS idx_lc_encomendas_lista_id ON lc_encomendas_itens(lista_id);
CREATE INDEX IF NOT EXISTS idx_lc_encomendas_fornecedor ON lc_encomendas_itens(fornecedor_id);
CREATE INDEX IF NOT EXISTS idx_lc_config_chave ON lc_config(chave);
CREATE INDEX IF NOT EXISTS idx_lc_itens_fixos_ativo ON lc_itens_fixos(ativo);

-- Inserir configurações padrão
INSERT INTO lc_config (chave, valor) VALUES 
    ('precisao_quantidade', '3'),
    ('precisao_valor', '2'),
    ('arred_custo_convidado', '1'),
    ('arred_embalagem_auto', '1'),
    ('arred_sempre_pra_cima', '1'),
    ('mostrar_custo_previa', '1'),
    ('mostrar_custo_pdf', '1'),
    ('pdf_detalhe_custos', 'simples'),
    ('incluir_fixos_auto', '1'),
    ('fixos_sem_convidados', '1'),
    ('multiplicar_por_eventos', '1'),
    ('perm_pdf_todos', '1'),
    ('perm_excluir_listas', '0'),
    ('perm_editar_insumos_fichas', '1'),
    ('tema', 'azul-claro'),
    ('fonte_tamanho', 'media')
ON CONFLICT (chave) DO NOTHING;

-- Inserir unidades básicas (se não existirem)
INSERT INTO lc_unidades (nome, simbolo, fator_base) VALUES 
    ('Unidade', 'un', 1),
    ('Quilograma', 'kg', 1),
    ('Grama', 'g', 0.001),
    ('Litro', 'L', 1),
    ('Mililitro', 'ml', 0.001),
    ('Metro', 'm', 1),
    ('Centímetro', 'cm', 0.01),
    ('Pacote', 'pct', 1),
    ('Caixa', 'cx', 1),
    ('Rolo', 'rolo', 1)
ON CONFLICT (simbolo) DO NOTHING;

-- Inserir categorias básicas (se não existirem)
INSERT INTO lc_categorias (nome, ordem) VALUES 
    ('Alimentos', 1),
    ('Bebidas', 2),
    ('Decoração', 3),
    ('Utensílios', 4),
    ('Limpeza', 5),
    ('Outros', 99)
ON CONFLICT (nome) DO NOTHING;

COMMIT;
