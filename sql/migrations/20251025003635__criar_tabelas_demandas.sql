-- Migração: Tabelas de Demandas
-- Data: 2025-10-25 00:36:35


        CREATE TABLE IF NOT EXISTS demandas_quadros (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(200) NOT NULL,
            descricao TEXT,
            cor VARCHAR(7) DEFAULT '#3B82F6',
            ativo BOOLEAN DEFAULT TRUE,
            criado_por INTEGER,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS demandas_participantes (
            id SERIAL PRIMARY KEY,
            quadro_id INTEGER REFERENCES demandas_quadros(id),
            usuario_id INTEGER,
            permissao VARCHAR(20) DEFAULT 'leitura',
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS demandas_cartoes (
            id SERIAL PRIMARY KEY,
            quadro_id INTEGER REFERENCES demandas_quadros(id),
            titulo VARCHAR(200) NOT NULL,
            descricao TEXT,
            responsavel_id INTEGER,
            data_vencimento DATE,
            prioridade VARCHAR(20) DEFAULT 'media',
            status VARCHAR(20) DEFAULT 'pendente',
            recorrente BOOLEAN DEFAULT FALSE,
            recorrencia_config JSONB,
            criado_por INTEGER,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS demandas_preferencias_notificacao (
            id SERIAL PRIMARY KEY,
            usuario_id INTEGER,
            tipo_notificacao VARCHAR(20) NOT NULL,
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

-- Índices
CREATE INDEX IF NOT EXISTS idx_demandas_quadros_criado_por ON demandas_quadros(criado_por);
CREATE INDEX IF NOT EXISTS idx_demandas_participantes_quadro_id ON demandas_participantes(quadro_id);
CREATE INDEX IF NOT EXISTS idx_demandas_participantes_usuario_id ON demandas_participantes(usuario_id);
CREATE INDEX IF NOT EXISTS idx_demandas_cartoes_quadro_id ON demandas_cartoes(quadro_id);
CREATE INDEX IF NOT EXISTS idx_demandas_cartoes_responsavel_id ON demandas_cartoes(responsavel_id);
