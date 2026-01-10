-- Adicionar coluna chave_storage nas tabelas da contabilidade
-- Isso permite gerar URLs pré-assinadas para download seguro

ALTER TABLE contabilidade_guias 
ADD COLUMN IF NOT EXISTS chave_storage VARCHAR(500);

ALTER TABLE contabilidade_holerites 
ADD COLUMN IF NOT EXISTS chave_storage VARCHAR(500);

ALTER TABLE contabilidade_honorarios 
ADD COLUMN IF NOT EXISTS chave_storage VARCHAR(500);

ALTER TABLE contabilidade_conversas_mensagens 
ADD COLUMN IF NOT EXISTS chave_storage VARCHAR(500);

ALTER TABLE contabilidade_colaboradores_documentos 
ADD COLUMN IF NOT EXISTS chave_storage VARCHAR(500);

-- Índices para melhor performance
CREATE INDEX IF NOT EXISTS idx_contabilidade_guias_chave_storage ON contabilidade_guias(chave_storage);
CREATE INDEX IF NOT EXISTS idx_contabilidade_holerites_chave_storage ON contabilidade_holerites(chave_storage);
CREATE INDEX IF NOT EXISTS idx_contabilidade_honorarios_chave_storage ON contabilidade_honorarios(chave_storage);
