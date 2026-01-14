-- Log de execuções do cron de Logística
CREATE TABLE IF NOT EXISTS logistica_cron_execucoes (
    id SERIAL PRIMARY KEY,
    executado_em TIMESTAMP DEFAULT NOW(),
    status VARCHAR(10) NOT NULL,
    resumo_json TEXT,
    duracao_ms INTEGER,
    erro_msg TEXT
);

CREATE INDEX IF NOT EXISTS idx_logistica_cron_execucoes_em ON logistica_cron_execucoes (executado_em DESC);
