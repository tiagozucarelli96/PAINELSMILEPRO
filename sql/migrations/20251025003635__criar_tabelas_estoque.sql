-- Migração: Tabelas de Estoque e Logs
-- Data: 2025-10-25 00:36:35


        CREATE TABLE IF NOT EXISTS lc_movimentos_estoque (
            id SERIAL PRIMARY KEY,
            insumo_id INTEGER,
            tipo_movimento VARCHAR(20) NOT NULL,
            quantidade DECIMAL(10,4) NOT NULL,
            unidade VARCHAR(20),
            data_movimento TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            observacoes TEXT,
            criado_por INTEGER,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS portao_logs (
            id SERIAL PRIMARY KEY,
            usuario_id INTEGER,
            acao VARCHAR(50) NOT NULL,
            ip VARCHAR(45),
            user_agent TEXT,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS clickup_tokens (
            id SERIAL PRIMARY KEY,
            token VARCHAR(500) NOT NULL,
            team_id VARCHAR(100),
            ativo BOOLEAN DEFAULT TRUE,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

-- Índices
CREATE INDEX IF NOT EXISTS idx_lc_movimentos_insumo_id ON lc_movimentos_estoque(insumo_id);
CREATE INDEX IF NOT EXISTS idx_lc_movimentos_data_movimento ON lc_movimentos_estoque(data_movimento);
CREATE INDEX IF NOT EXISTS idx_portao_logs_usuario_id ON portao_logs(usuario_id);
CREATE INDEX IF NOT EXISTS idx_portao_logs_criado_em ON portao_logs(criado_em);
