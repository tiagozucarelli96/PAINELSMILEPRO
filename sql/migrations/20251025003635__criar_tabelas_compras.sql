-- Migração: Tabelas do módulo Compras
-- Data: 2025-10-25 00:36:35


        CREATE TABLE IF NOT EXISTS lc_categorias (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            descricao TEXT,
            ordem INTEGER DEFAULT 0,
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS lc_unidades (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(50) NOT NULL,
            simbolo VARCHAR(10) NOT NULL,
            tipo VARCHAR(20) DEFAULT 'volume',
            fator_base DECIMAL(10,4) DEFAULT 1.0,
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS lc_fichas (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(200) NOT NULL,
            descricao TEXT,
            consumo_pessoa DECIMAL(10,4) DEFAULT 1.0,
            rendimento_base_pessoas INTEGER DEFAULT 1,
            nome_exibicao VARCHAR(200),
            ativo BOOLEAN DEFAULT TRUE,
            criado_por INTEGER,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS lc_itens (
            id SERIAL PRIMARY KEY,
            ficha_id INTEGER REFERENCES lc_fichas(id),
            insumo_id INTEGER,
            quantidade DECIMAL(10,4) NOT NULL,
            unidade VARCHAR(20),
            tipo VARCHAR(20) DEFAULT 'preparo',
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS lc_ficha_componentes (
            id SERIAL PRIMARY KEY,
            ficha_id INTEGER REFERENCES lc_fichas(id),
            insumo_id INTEGER,
            quantidade DECIMAL(10,4) NOT NULL,
            unidade VARCHAR(20),
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS lc_itens_fixos (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(200) NOT NULL,
            categoria VARCHAR(100),
            unidade VARCHAR(20),
            quantidade_padrao DECIMAL(10,4) DEFAULT 1.0,
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS lc_arredondamentos (
            id SERIAL PRIMARY KEY,
            insumo_id INTEGER,
            regra VARCHAR(50) NOT NULL,
            valor DECIMAL(10,4),
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS lc_rascunhos (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(200) NOT NULL,
            tipo VARCHAR(20) DEFAULT 'compras',
            payload JSONB,
            criado_por INTEGER,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS lc_encomendas_itens (
            id SERIAL PRIMARY KEY,
            lista_id INTEGER,
            fornecedor_id INTEGER,
            evento_id INTEGER,
            insumo_id INTEGER,
            quantidade DECIMAL(10,4) NOT NULL,
            unidade VARCHAR(20),
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS lc_encomendas_overrides (
            id SERIAL PRIMARY KEY,
            lista_id INTEGER,
            fornecedor_id INTEGER,
            evento_id INTEGER,
            insumo_id INTEGER,
            quantidade_override DECIMAL(10,4),
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS lc_geracoes (
            id SERIAL PRIMARY KEY,
            grupo_token VARCHAR(100) UNIQUE NOT NULL,
            criado_por INTEGER,
            criado_por_nome VARCHAR(200),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS lc_lista_eventos (
            id SERIAL PRIMARY KEY,
            grupo_id INTEGER,
            espaco VARCHAR(200),
            convidados INTEGER,
            horario TIME,
            evento_texto VARCHAR(500),
            data_evento DATE,
            dia_semana VARCHAR(20),
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS lc_compras_consolidadas (
            id SERIAL PRIMARY KEY,
            grupo_id INTEGER,
            insumo_id INTEGER,
            nome_insumo VARCHAR(200),
            unidade VARCHAR(20),
            qtd_bruta DECIMAL(10,4),
            qtd_final DECIMAL(10,4),
            foi_arredondado BOOLEAN DEFAULT FALSE,
            origem_json JSONB,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

-- Índices para performance
CREATE INDEX IF NOT EXISTS idx_lc_fichas_nome ON lc_fichas(nome);
CREATE INDEX IF NOT EXISTS idx_lc_itens_ficha_id ON lc_itens(ficha_id);
CREATE INDEX IF NOT EXISTS idx_lc_itens_insumo_id ON lc_itens(insumo_id);
CREATE INDEX IF NOT EXISTS idx_lc_compras_consolidadas_grupo_id ON lc_compras_consolidadas(grupo_id);
CREATE INDEX IF NOT EXISTS idx_lc_lista_eventos_grupo_id ON lc_lista_eventos(grupo_id);
