-- 027_contabilidade_empresas_documento.sql
-- Renomear campo cnpj para documento para suportar tanto CNPJ quanto CPF

-- Renomear coluna cnpj para documento
ALTER TABLE contabilidade_empresas RENAME COLUMN cnpj TO documento;

-- Renomear constraint de unicidade
ALTER TABLE contabilidade_empresas RENAME CONSTRAINT contabilidade_empresas_cnpj_key TO contabilidade_empresas_documento_key;

-- Inserir empresa Tiago Zucarelli (pessoa física com CPF)
INSERT INTO contabilidade_empresas (nome, documento) VALUES
    ('Tiago Zucarelli', '423.999.978-24')
ON CONFLICT (documento) DO NOTHING;
