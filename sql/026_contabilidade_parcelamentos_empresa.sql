-- 026_contabilidade_parcelamentos_empresa.sql
-- Adicionar empresa_id na tabela de parcelamentos para melhor performance e consistência

-- Adicionar coluna empresa_id na tabela de parcelamentos
ALTER TABLE contabilidade_parcelamentos
    ADD COLUMN IF NOT EXISTS empresa_id INTEGER REFERENCES contabilidade_empresas(id) ON DELETE SET NULL;

-- Atualizar parcelamentos existentes com empresa_id da primeira guia
UPDATE contabilidade_parcelamentos p
SET empresa_id = (
    SELECT g.empresa_id 
    FROM contabilidade_guias g 
    WHERE g.parcelamento_id = p.id 
    AND g.numero_parcela = 1 
    LIMIT 1
)
WHERE empresa_id IS NULL;

-- Índice para performance
CREATE INDEX IF NOT EXISTS idx_contabilidade_parcelamentos_empresa ON contabilidade_parcelamentos(empresa_id);
