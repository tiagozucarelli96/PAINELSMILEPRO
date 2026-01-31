-- 045_cron_execucoes_log.sql
-- Tabela para registrar execuções de cron jobs

CREATE TABLE IF NOT EXISTS sistema_cron_execucoes (
    id SERIAL PRIMARY KEY,
    tipo VARCHAR(100) NOT NULL,                    -- Identificador do cron (ex: 'demandas_fixas', 'google_calendar_daily')
    iniciado_em TIMESTAMP NOT NULL DEFAULT NOW(),  -- Quando iniciou
    finalizado_em TIMESTAMP,                       -- Quando terminou (NULL se ainda executando)
    sucesso BOOLEAN,                               -- TRUE = sucesso, FALSE = erro, NULL = em execução
    duracao_ms INTEGER,                            -- Duração em milissegundos
    resultado JSONB,                               -- Resultado retornado (sucesso ou erro)
    ip_origem VARCHAR(45),                         -- IP que chamou o cron
    user_agent TEXT,                               -- User-agent da requisição
    criado_em TIMESTAMP DEFAULT NOW()
);

-- Índices para consultas rápidas
CREATE INDEX IF NOT EXISTS idx_cron_exec_tipo ON sistema_cron_execucoes(tipo);
CREATE INDEX IF NOT EXISTS idx_cron_exec_iniciado ON sistema_cron_execucoes(iniciado_em DESC);
CREATE INDEX IF NOT EXISTS idx_cron_exec_sucesso ON sistema_cron_execucoes(sucesso);
CREATE INDEX IF NOT EXISTS idx_cron_exec_tipo_iniciado ON sistema_cron_execucoes(tipo, iniciado_em DESC);

-- View para última execução de cada tipo
CREATE OR REPLACE VIEW v_cron_ultima_execucao AS
SELECT DISTINCT ON (tipo)
    tipo,
    id as ultima_execucao_id,
    iniciado_em as ultima_execucao,
    finalizado_em,
    sucesso,
    duracao_ms,
    resultado,
    CASE 
        WHEN sucesso IS NULL THEN 'executando'
        WHEN sucesso = TRUE THEN 'sucesso'
        ELSE 'erro'
    END as status_texto
FROM sistema_cron_execucoes
ORDER BY tipo, iniciado_em DESC;

-- Comentário
COMMENT ON TABLE sistema_cron_execucoes IS 'Registro de todas as execuções de cron jobs do sistema';
