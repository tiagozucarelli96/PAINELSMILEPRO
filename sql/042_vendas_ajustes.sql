-- 042_vendas_ajustes.sql
-- Ajustes finais do módulo de Vendas conforme especificações

-- 1. Adicionar campo origem na tabela vendas_pre_contratos
ALTER TABLE vendas_pre_contratos 
ADD COLUMN IF NOT EXISTS origem VARCHAR(50) DEFAULT 'publico' 
CHECK (origem IN ('publico', 'presencial'));

-- 2. Adicionar campos adicionais para Casamento conforme especificação
ALTER TABLE vendas_pre_contratos 
ADD COLUMN IF NOT EXISTS rg VARCHAR(20),
ADD COLUMN IF NOT EXISTS cep VARCHAR(10),
ADD COLUMN IF NOT EXISTS endereco_completo TEXT,
ADD COLUMN IF NOT EXISTS numero VARCHAR(20),
ADD COLUMN IF NOT EXISTS complemento VARCHAR(100),
ADD COLUMN IF NOT EXISTS bairro VARCHAR(100),
ADD COLUMN IF NOT EXISTS cidade VARCHAR(100),
ADD COLUMN IF NOT EXISTS estado VARCHAR(2),
ADD COLUMN IF NOT EXISTS pais VARCHAR(50) DEFAULT 'Brasil',
ADD COLUMN IF NOT EXISTS instagram VARCHAR(255),
ADD COLUMN IF NOT EXISTS nome_noivos VARCHAR(255), -- Para casamento: vira nomeevento na ME
ADD COLUMN IF NOT EXISTS num_convidados INT,
ADD COLUMN IF NOT EXISTS como_conheceu VARCHAR(50),
ADD COLUMN IF NOT EXISTS como_conheceu_outro TEXT,
ADD COLUMN IF NOT EXISTS forma_pagamento TEXT, -- Interno, não vai para ME
ADD COLUMN IF NOT EXISTS observacoes_internas TEXT, -- Interno
ADD COLUMN IF NOT EXISTS responsavel_comercial_id INT REFERENCES usuarios(id) ON DELETE SET NULL;

-- 3. Ajustar campo unidade para aceitar apenas locais mapeados
-- (A validação será feita via código, não via CHECK constraint)
-- Remover CHECK constraint antigo se existir
ALTER TABLE vendas_pre_contratos 
DROP CONSTRAINT IF EXISTS vendas_pre_contratos_unidade_check;

-- 4. Adicionar índice para origem
CREATE INDEX IF NOT EXISTS idx_vendas_pre_contratos_origem ON vendas_pre_contratos(origem);

-- 5. Garantir que a coluna "Criado na ME" sempre exista no Kanban
-- (Será feito via código, mas adicionamos uma constraint para garantir integridade)
-- Não é possível fazer via SQL, será feito via código
