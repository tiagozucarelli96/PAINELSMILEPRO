-- 060_vendas_organizacao_defaults.sql
-- Campos internos do pré-contrato usados para iniciar a organização do evento

ALTER TABLE vendas_pre_contratos
ADD COLUMN IF NOT EXISTS tipo_evento_real VARCHAR(24),
ADD COLUMN IF NOT EXISTS pacote_evento_id BIGINT REFERENCES logistica_pacotes_evento(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_vendas_pre_contratos_tipo_evento_real
ON vendas_pre_contratos(tipo_evento_real);

CREATE INDEX IF NOT EXISTS idx_vendas_pre_contratos_pacote_evento_id
ON vendas_pre_contratos(pacote_evento_id);
