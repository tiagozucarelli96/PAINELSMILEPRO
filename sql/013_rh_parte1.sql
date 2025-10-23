-- 013_rh_parte1.sql
-- PARTE 1: Adicionar campos RH na tabela usuarios

-- Adicionar campos extras na tabela usuarios (sem quebrar login)
ALTER TABLE usuarios 
ADD COLUMN IF NOT EXISTS cpf VARCHAR(14),
ADD COLUMN IF NOT EXISTS cargo VARCHAR(100),
ADD COLUMN IF NOT EXISTS admissao_data DATE,
ADD COLUMN IF NOT EXISTS salario_base NUMERIC(10,2),
ADD COLUMN IF NOT EXISTS pix_tipo VARCHAR(20),
ADD COLUMN IF NOT EXISTS pix_chave VARCHAR(255),
ADD COLUMN IF NOT EXISTS status_empregado VARCHAR(20) DEFAULT 'ativo' CHECK (status_empregado IN ('ativo', 'inativo'));
