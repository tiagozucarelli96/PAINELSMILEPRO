-- 017_demandas_melhorias.sql
-- Adiciona campos e funcionalidades ao sistema de demandas
-- PRESERVA toda estrutura existente - apenas adiciona campos novos

-- 1. Adicionar campos de prioridade e categoria
ALTER TABLE demandas 
ADD COLUMN IF NOT EXISTS prioridade VARCHAR(20) DEFAULT 'media' 
CHECK (prioridade IN ('baixa', 'media', 'alta', 'urgente'));

ALTER TABLE demandas 
ADD COLUMN IF NOT EXISTS categoria VARCHAR(100);

-- 2. Adicionar campo de progresso
ALTER TABLE demandas 
ADD COLUMN IF NOT EXISTS progresso INTEGER DEFAULT 0 
CHECK (progresso >= 0 AND progresso <= 100);

ALTER TABLE demandas 
ADD COLUMN IF NOT EXISTS etapa VARCHAR(50) DEFAULT 'planejamento'
CHECK (etapa IN ('planejamento', 'execucao', 'revisao', 'concluida'));

-- 3. Adicionar referência externa (integração com módulos)
ALTER TABLE demandas 
ADD COLUMN IF NOT EXISTS referencia_externa VARCHAR(255);

ALTER TABLE demandas 
ADD COLUMN IF NOT EXISTS tipo_referencia VARCHAR(50)
CHECK (tipo_referencia IN (NULL, 'comercial', 'logistico', 'financeiro', 'rh', 'outro'));

-- 4. Adicionar campo de arquivamento (soft delete)
ALTER TABLE demandas 
ADD COLUMN IF NOT EXISTS arquivado BOOLEAN DEFAULT FALSE;

ALTER TABLE demandas 
ADD COLUMN IF NOT EXISTS arquivado_em TIMESTAMPTZ;

ALTER TABLE demandas 
ADD COLUMN IF NOT EXISTS arquivado_por INTEGER REFERENCES usuarios(id) ON DELETE SET NULL;

-- 5. Adicionar índices para performance
CREATE INDEX IF NOT EXISTS idx_demandas_prioridade ON demandas(prioridade) WHERE arquivado = FALSE;
CREATE INDEX IF NOT EXISTS idx_demandas_categoria ON demandas(categoria) WHERE categoria IS NOT NULL AND arquivado = FALSE;
CREATE INDEX IF NOT EXISTS idx_demandas_progresso ON demandas(progresso) WHERE arquivado = FALSE;
CREATE INDEX IF NOT EXISTS idx_demandas_referencia ON demandas(tipo_referencia, referencia_externa) 
WHERE referencia_externa IS NOT NULL AND arquivado = FALSE;
CREATE INDEX IF NOT EXISTS idx_demandas_arquivado ON demandas(arquivado, arquivado_em);

-- 6. Comentários: garantir que tem autor_id (se não existir)
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'demandas_comentarios' AND column_name = 'autor_id'
    ) THEN
        ALTER TABLE demandas_comentarios ADD COLUMN autor_id INTEGER;
        UPDATE demandas_comentarios SET autor_id = 1 WHERE autor_id IS NULL;
        ALTER TABLE demandas_comentarios ALTER COLUMN autor_id SET NOT NULL;
    END IF;
END $$;

-- 7. Anexos: garantir estrutura completa
ALTER TABLE demandas_arquivos 
ADD COLUMN IF NOT EXISTS upload_por INTEGER REFERENCES usuarios(id) ON DELETE SET NULL;

-- Comentários para documentação
COMMENT ON COLUMN demandas.prioridade IS 'Prioridade da demanda: baixa, media, alta, urgente';
COMMENT ON COLUMN demandas.categoria IS 'Categoria livre para classificação da demanda';
COMMENT ON COLUMN demandas.progresso IS 'Progresso de 0 a 100 porcento';
COMMENT ON COLUMN demandas.etapa IS 'Etapa atual: planejamento, execucao, revisao, concluida';
COMMENT ON COLUMN demandas.referencia_externa IS 'ID ou referência a outro módulo do sistema';
COMMENT ON COLUMN demandas.tipo_referencia IS 'Tipo do módulo referenciado';
COMMENT ON COLUMN demandas.arquivado IS 'Indica se demanda foi arquivada (soft delete)';

