-- 059_vendas_itens_adicionais.sql
-- Campo interno separado para itens adicionais do pré-contrato

ALTER TABLE vendas_pre_contratos
ADD COLUMN IF NOT EXISTS itens_adicionais TEXT;
