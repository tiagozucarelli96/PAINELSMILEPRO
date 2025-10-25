-- Migração: Tabelas de RH
-- Data: 2025-10-25 00:36:35


        CREATE TABLE IF NOT EXISTS rh_holerites (
            id SERIAL PRIMARY KEY,
            usuario_id INTEGER,
            mes_competencia VARCHAR(7) NOT NULL,
            valor_liquido DECIMAL(10,2),
            observacao TEXT,
            criado_por INTEGER,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS rh_anexos (
            id SERIAL PRIMARY KEY,
            usuario_id INTEGER,
            holerite_id INTEGER,
            nome_arquivo VARCHAR(255) NOT NULL,
            caminho_arquivo VARCHAR(500) NOT NULL,
            tamanho_bytes BIGINT,
            tipo_mime VARCHAR(100),
            autor_id INTEGER,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

-- Índices
CREATE INDEX IF NOT EXISTS idx_rh_holerites_usuario_id ON rh_holerites(usuario_id);
CREATE INDEX IF NOT EXISTS idx_rh_holerites_mes_competencia ON rh_holerites(mes_competencia);
CREATE INDEX IF NOT EXISTS idx_rh_anexos_usuario_id ON rh_anexos(usuario_id);
CREATE INDEX IF NOT EXISTS idx_rh_anexos_holerite_id ON rh_anexos(holerite_id);
