-- 044_vendas_tipo_15anos.sql
-- Adiciona o tipo de evento "15anos" na tabela de pré-contratos

-- 1. Remover a constraint antiga de tipo_evento
ALTER TABLE vendas_pre_contratos 
DROP CONSTRAINT IF EXISTS vendas_pre_contratos_tipo_evento_check;

-- 2. Adicionar a nova constraint incluindo "15anos"
ALTER TABLE vendas_pre_contratos 
ADD CONSTRAINT vendas_pre_contratos_tipo_evento_check 
CHECK (tipo_evento IN ('casamento', '15anos', 'infantil', 'pj'));

-- 3. Adicionar índice para o novo tipo (se não existir)
CREATE INDEX IF NOT EXISTS idx_vendas_pre_contratos_tipo_15anos 
ON vendas_pre_contratos(tipo_evento) 
WHERE tipo_evento = '15anos';

-- Comentário: Esta migration adiciona suporte ao formulário público de 15 Anos / Debutante
