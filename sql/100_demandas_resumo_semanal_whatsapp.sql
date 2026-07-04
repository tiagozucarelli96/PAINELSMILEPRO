-- 100_demandas_resumo_semanal_whatsapp.sql
-- Persistencia da regra "Enviar para o Jordao?" e controle anti-duplicidade
-- do resumo automatico semanal de demandas internas por WhatsApp.

ALTER TABLE demandas_internas
ADD COLUMN IF NOT EXISTS enviar_jordao BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE IF NOT EXISTS demandas_internas_resumo_semanal_envios (
    id SERIAL PRIMARY KEY,
    semana_inicio DATE NOT NULL,
    destinatario_chave VARCHAR(80),
    usuario_id INTEGER REFERENCES usuarios(id) ON DELETE CASCADE,
    total_demandas INTEGER NOT NULL DEFAULT 0,
    enviado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE demandas_internas_resumo_semanal_envios
ADD COLUMN IF NOT EXISTS destinatario_chave VARCHAR(80);

ALTER TABLE demandas_internas_resumo_semanal_envios
ALTER COLUMN usuario_id DROP NOT NULL;

UPDATE demandas_internas_resumo_semanal_envios
SET destinatario_chave = 'usuario:' || usuario_id::text
WHERE destinatario_chave IS NULL
  AND usuario_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_demandas_resumo_semana_destinatario
ON demandas_internas_resumo_semanal_envios (semana_inicio, destinatario_chave);
