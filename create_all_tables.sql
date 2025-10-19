-- Script completo para criar todas as tabelas necessárias para o sistema de Lista de Compras
-- Execute este script no seu banco PostgreSQL

-- ========================================
-- 1. TABELAS BÁSICAS (sem dependências)
-- ========================================

-- Tabela de categorias
CREATE TABLE IF NOT EXISTS lc_categorias (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL UNIQUE,
    ordem INTEGER DEFAULT 0,
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de unidades
CREATE TABLE IF NOT EXISTS lc_unidades (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(50) NOT NULL,
    simbolo VARCHAR(10) NOT NULL UNIQUE,
    tipo VARCHAR(20) DEFAULT 'peso', -- peso, volume, quantidade, comprimento
    fator_base NUMERIC(12,6) DEFAULT 1.0,
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de fornecedores
CREATE TABLE IF NOT EXISTS fornecedores (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    contato VARCHAR(100),
    telefone VARCHAR(20),
    email VARCHAR(100),
    endereco TEXT,
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de usuários (se não existir)
CREATE TABLE IF NOT EXISTS usuarios (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE,
    senha VARCHAR(255),
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ========================================
-- 2. TABELAS DE CONFIGURAÇÃO
-- ========================================

-- Tabela de configurações do sistema
CREATE TABLE IF NOT EXISTS lc_config (
    chave VARCHAR(50) PRIMARY KEY,
    valor TEXT NOT NULL,
    descricao TEXT,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ========================================
-- 3. TABELAS DE DADOS MESTRES
-- ========================================

-- Tabela de fichas (receitas/preparos)
CREATE TABLE IF NOT EXISTS lc_fichas (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    nome_exibicao VARCHAR(100),
    descricao TEXT,
    porcoes INTEGER DEFAULT 1,
    exibir_em_categorias BOOLEAN DEFAULT TRUE,
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de componentes das fichas
CREATE TABLE IF NOT EXISTS lc_ficha_componentes (
    id SERIAL PRIMARY KEY,
    ficha_id INTEGER NOT NULL REFERENCES lc_fichas(id) ON DELETE CASCADE,
    insumo_id INTEGER NOT NULL,
    quantidade NUMERIC(12,6) NOT NULL DEFAULT 0,
    unidade VARCHAR(20),
    observacoes TEXT,
    ordem INTEGER DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de itens (preparos e comprados)
CREATE TABLE IF NOT EXISTS lc_itens (
    id SERIAL PRIMARY KEY,
    categoria_id INTEGER REFERENCES lc_categorias(id),
    tipo VARCHAR(20) NOT NULL DEFAULT 'comprado', -- comprado, preparo
    nome VARCHAR(100) NOT NULL,
    unidade VARCHAR(20),
    fornecedor_id INTEGER REFERENCES fornecedores(id),
    ficha_id INTEGER REFERENCES lc_fichas(id),
    ativo BOOLEAN DEFAULT TRUE,
    ordem INTEGER DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de itens fixos (sempre incluídos)
CREATE TABLE IF NOT EXISTS lc_itens_fixos (
    id SERIAL PRIMARY KEY,
    insumo_id INTEGER NOT NULL,
    qtd NUMERIC(12,6) NOT NULL DEFAULT 0,
    unidade_id INTEGER REFERENCES lc_unidades(id),
    observacao TEXT,
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ========================================
-- 4. TABELAS DE LISTAS E GERAÇÕES
-- ========================================

-- Tabela principal de listas
CREATE TABLE IF NOT EXISTS lc_listas (
    id SERIAL PRIMARY KEY,
    grupo_id INTEGER,
    tipo VARCHAR(20) DEFAULT 'compras', -- compras, encomendas
    tipo_lista VARCHAR(20) DEFAULT 'compras', -- compras, encomendas
    espaco_consolidado VARCHAR(100),
    eventos_resumo TEXT,
    resumo_eventos TEXT,
    espaco_resumo VARCHAR(100),
    criado_por INTEGER REFERENCES usuarios(id),
    criado_por_nome VARCHAR(100),
    data_gerada TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de eventos das listas
CREATE TABLE IF NOT EXISTS lc_listas_eventos (
    id SERIAL PRIMARY KEY,
    lista_id INTEGER REFERENCES lc_listas(id) ON DELETE CASCADE,
    grupo_id INTEGER,
    evento_id INTEGER,
    espaco VARCHAR(100),
    convidados INTEGER DEFAULT 0,
    horario VARCHAR(20),
    evento VARCHAR(200),
    data_evento DATE,
    dia_semana VARCHAR(20),
    resumo TEXT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de rascunhos
CREATE TABLE IF NOT EXISTS lc_rascunhos (
    id SERIAL PRIMARY KEY,
    criado_por INTEGER NOT NULL,
    criado_por_nome VARCHAR(100),
    payload JSONB NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ========================================
-- 5. TABELAS DE COMPRAS E ENCOMENDAS
-- ========================================

-- Tabela de compras consolidadas
CREATE TABLE IF NOT EXISTS lc_compras_consolidadas (
    id SERIAL PRIMARY KEY,
    lista_id INTEGER REFERENCES lc_listas(id) ON DELETE CASCADE,
    grupo_id INTEGER,
    insumo_id INTEGER,
    insumo_nome VARCHAR(100) NOT NULL,
    nome_insumo VARCHAR(100),
    unidade VARCHAR(20),
    unidade_simbolo VARCHAR(20),
    qtd NUMERIC(12,6) NOT NULL DEFAULT 0,
    quantidade NUMERIC(12,6),
    qtd_bruta NUMERIC(12,6),
    qtd_final NUMERIC(12,6),
    custo NUMERIC(12,2) DEFAULT 0,
    foi_arredondado BOOLEAN DEFAULT FALSE,
    origem_json JSONB,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de itens de encomendas
CREATE TABLE IF NOT EXISTS lc_encomendas_itens (
    id SERIAL PRIMARY KEY,
    lista_id INTEGER REFERENCES lc_listas(id) ON DELETE CASCADE,
    grupo_id INTEGER,
    fornecedor_id INTEGER REFERENCES fornecedores(id),
    fornecedor_nome VARCHAR(100),
    evento_id INTEGER,
    evento_label VARCHAR(200),
    item_id INTEGER,
    item_nome VARCHAR(100) NOT NULL,
    nome_item VARCHAR(100),
    insumo_nome VARCHAR(100),
    unidade VARCHAR(20),
    unidade_simbolo VARCHAR(20),
    qtd NUMERIC(12,6) NOT NULL DEFAULT 0,
    quantidade NUMERIC(12,6),
    custo NUMERIC(12,2) DEFAULT 0,
    foi_arredondado BOOLEAN DEFAULT FALSE,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de overrides de encomendas
CREATE TABLE IF NOT EXISTS lc_encomendas_overrides (
    id SERIAL PRIMARY KEY,
    grupo_id INTEGER NOT NULL,
    fornecedor_id INTEGER REFERENCES fornecedores(id),
    modo VARCHAR(20) NOT NULL, -- incluir, excluir
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ========================================
-- 6. ÍNDICES PARA PERFORMANCE
-- ========================================

-- Índices para lc_listas
CREATE INDEX IF NOT EXISTS idx_lc_listas_grupo_id ON lc_listas(grupo_id);
CREATE INDEX IF NOT EXISTS idx_lc_listas_tipo ON lc_listas(tipo);
CREATE INDEX IF NOT EXISTS idx_lc_listas_criado_por ON lc_listas(criado_por);
CREATE INDEX IF NOT EXISTS idx_lc_listas_data_gerada ON lc_listas(data_gerada);

-- Índices para lc_listas_eventos
CREATE INDEX IF NOT EXISTS idx_lc_listas_eventos_lista_id ON lc_listas_eventos(lista_id);
CREATE INDEX IF NOT EXISTS idx_lc_listas_eventos_grupo_id ON lc_listas_eventos(grupo_id);
CREATE INDEX IF NOT EXISTS idx_lc_listas_eventos_data_evento ON lc_listas_eventos(data_evento);

-- Índices para lc_compras_consolidadas
CREATE INDEX IF NOT EXISTS idx_lc_compras_consolidadas_lista_id ON lc_compras_consolidadas(lista_id);
CREATE INDEX IF NOT EXISTS idx_lc_compras_consolidadas_grupo_id ON lc_compras_consolidadas(grupo_id);
CREATE INDEX IF NOT EXISTS idx_lc_compras_consolidadas_insumo_id ON lc_compras_consolidadas(insumo_id);

-- Índices para lc_encomendas_itens
CREATE INDEX IF NOT EXISTS idx_lc_encomendas_itens_lista_id ON lc_encomendas_itens(lista_id);
CREATE INDEX IF NOT EXISTS idx_lc_encomendas_itens_grupo_id ON lc_encomendas_itens(grupo_id);
CREATE INDEX IF NOT EXISTS idx_lc_encomendas_itens_fornecedor_id ON lc_encomendas_itens(fornecedor_id);
CREATE INDEX IF NOT EXISTS idx_lc_encomendas_itens_evento_id ON lc_encomendas_itens(evento_id);

-- Índices para lc_rascunhos
CREATE INDEX IF NOT EXISTS idx_lc_rascunhos_criado_por ON lc_rascunhos(criado_por);
CREATE INDEX IF NOT EXISTS idx_lc_rascunhos_updated_at ON lc_rascunhos(updated_at);

-- ========================================
-- 7. DADOS INICIAIS
-- ========================================

-- Inserir unidades padrão
INSERT INTO lc_unidades (nome, simbolo, tipo, fator_base) VALUES
('Quilograma', 'kg', 'peso', 1.0),
('Grama', 'g', 'peso', 0.001),
('Litro', 'L', 'volume', 1.0),
('Mililitro', 'ml', 'volume', 0.001),
('Unidade', 'un', 'quantidade', 1.0),
('Dúzia', 'dz', 'quantidade', 12.0),
('Pacote', 'pct', 'quantidade', 1.0),
('Caixa', 'cx', 'quantidade', 1.0),
('Metro', 'm', 'comprimento', 1.0),
('Centímetro', 'cm', 'comprimento', 0.01)
ON CONFLICT (simbolo) DO NOTHING;

-- Inserir categorias padrão
INSERT INTO lc_categorias (nome, ordem) VALUES
('Alimentos', 1),
('Bebidas', 2),
('Descartáveis', 3),
('Limpeza', 4),
('Decoração', 5),
('Serviços', 6)
ON CONFLICT (nome) DO NOTHING;

-- Inserir configurações padrão
INSERT INTO lc_config (chave, valor, descricao) VALUES
('precisao_quantidade', '3', 'Casas decimais para quantidades'),
('precisao_valor', '2', 'Casas decimais para valores'),
('arred_custo_convidado', '1', 'Arredondar custo por convidado'),
('arred_embalagem_auto', '1', 'Arredondar embalagens automaticamente'),
('arred_sempre_pra_cima', '1', 'Arredondar sempre para cima'),
('mostrar_custo_previa', '1', 'Mostrar custo por convidado na prévia'),
('mostrar_custo_pdf', '1', 'Mostrar custo por convidado no PDF'),
('pdf_detalhe_custos', 'simples', 'Nível de detalhe dos custos no PDF'),
('incluir_fixos_auto', '1', 'Incluir itens fixos automaticamente'),
('fixos_sem_convidados', '1', 'Incluir fixos mesmo sem convidados'),
('multiplicar_por_eventos', '1', 'Multiplicar insumos por número de eventos'),
('perm_pdf_todos', '1', 'Permitir PDF para todos'),
('perm_excluir_listas', '0', 'Permitir excluir listas'),
('perm_editar_insumos_fichas', '1', 'Permitir editar insumos e fichas'),
('tema', 'azul-claro', 'Tema visual'),
('fonte_tamanho', 'media', 'Tamanho da fonte')
ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor;

-- ========================================
-- 8. COMENTÁRIOS DAS TABELAS
-- ========================================

COMMENT ON TABLE lc_categorias IS 'Categorias para organização dos itens';
COMMENT ON TABLE lc_unidades IS 'Unidades de medida (kg, L, un, etc.)';
COMMENT ON TABLE lc_insumos IS 'Insumos/ingredientes disponíveis';
COMMENT ON TABLE lc_fichas IS 'Receitas/preparos com ingredientes';
COMMENT ON TABLE lc_ficha_componentes IS 'Componentes (ingredientes) de cada ficha';
COMMENT ON TABLE lc_itens IS 'Itens que podem ser selecionados (preparos ou comprados)';
COMMENT ON TABLE lc_itens_fixos IS 'Itens que sempre são incluídos nas listas';
COMMENT ON TABLE lc_listas IS 'Listas de compras/encomendas geradas';
COMMENT ON TABLE lc_listas_eventos IS 'Eventos vinculados a cada lista';
COMMENT ON TABLE lc_compras_consolidadas IS 'Itens de compra consolidados por lista';
COMMENT ON TABLE lc_encomendas_itens IS 'Itens de encomenda por fornecedor e evento';
COMMENT ON TABLE lc_rascunhos IS 'Rascunhos salvos pelos usuários';
COMMENT ON TABLE lc_config IS 'Configurações do sistema';

-- ========================================
-- 9. VERIFICAÇÃO FINAL
-- ========================================

-- Verificar se todas as tabelas foram criadas
DO $$
DECLARE
    table_count INTEGER;
    expected_tables TEXT[] := ARRAY[
        'lc_categorias', 'lc_unidades', 'lc_insumos', 'fornecedores', 'usuarios',
        'lc_config', 'lc_fichas', 'lc_ficha_componentes', 'lc_itens', 'lc_itens_fixos',
        'lc_listas', 'lc_listas_eventos', 'lc_rascunhos',
        'lc_compras_consolidadas', 'lc_encomendas_itens', 'lc_encomendas_overrides'
    ];
    table_name TEXT;
BEGIN
    SELECT COUNT(*) INTO table_count
    FROM information_schema.tables 
    WHERE table_schema = 'public' 
    AND table_name = ANY(expected_tables);
    
    RAISE NOTICE 'Tabelas criadas: % de %', table_count, array_length(expected_tables, 1);
    
    IF table_count = array_length(expected_tables, 1) THEN
        RAISE NOTICE 'SUCESSO: Todas as tabelas foram criadas!';
    ELSE
        RAISE NOTICE 'ATENÇÃO: Algumas tabelas podem não ter sido criadas.';
    END IF;
END $$;
