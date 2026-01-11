-- Catálogo Logística: tipologias, insumos, receitas e componentes

-- Tipologias de Insumo
CREATE TABLE IF NOT EXISTS logistica_tipologias_insumo (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    ordem INTEGER DEFAULT 0,
    ativo BOOLEAN DEFAULT TRUE,
    visivel_na_lista BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tipologias de Receita
CREATE TABLE IF NOT EXISTS logistica_tipologias_receita (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    ordem INTEGER DEFAULT 0,
    ativo BOOLEAN DEFAULT TRUE,
    visivel_na_lista BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Insumos
CREATE TABLE IF NOT EXISTS logistica_insumos (
    id SERIAL PRIMARY KEY,
    nome_oficial VARCHAR(200) NOT NULL,
    foto_url TEXT,
    unidade_medida VARCHAR(50),
    tipologia_insumo_id INTEGER REFERENCES logistica_tipologias_insumo(id),
    visivel_na_lista BOOLEAN DEFAULT TRUE,
    ativo BOOLEAN DEFAULT TRUE,
    sinonimos TEXT,
    barcode VARCHAR(100),
    fracionavel BOOLEAN DEFAULT TRUE,
    tamanho_embalagem NUMERIC(12,4),
    unidade_embalagem VARCHAR(50),
    custo_padrao NUMERIC(12,4),
    observacoes TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_logistica_insumos_nome ON logistica_insumos (LOWER(nome_oficial));
CREATE INDEX IF NOT EXISTS idx_logistica_insumos_sinonimos ON logistica_insumos (LOWER(sinonimos));

-- Receitas/Fichas
CREATE TABLE IF NOT EXISTS logistica_receitas (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(200) NOT NULL,
    foto_url TEXT,
    tipologia_receita_id INTEGER REFERENCES logistica_tipologias_receita(id),
    ativo BOOLEAN DEFAULT TRUE,
    visivel_na_lista BOOLEAN DEFAULT TRUE,
    rendimento_base_pessoas INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Componentes da receita
CREATE TABLE IF NOT EXISTS logistica_receita_componentes (
    id SERIAL PRIMARY KEY,
    receita_id INTEGER REFERENCES logistica_receitas(id) ON DELETE CASCADE,
    insumo_id INTEGER REFERENCES logistica_insumos(id),
    quantidade_base NUMERIC(12,4) NOT NULL,
    unidade VARCHAR(50),
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_logistica_receita_componentes_receita ON logistica_receita_componentes (receita_id);
