-- 025_contabilidade_empresas.sql
-- Adicionar suporte a empresas nas tabelas de contabilidade

-- Criar tabela de empresas (se não existir)
CREATE TABLE IF NOT EXISTS contabilidade_empresas (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    cnpj VARCHAR(18) NOT NULL UNIQUE,
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT NOW(),
    atualizado_em TIMESTAMP DEFAULT NOW()
);

-- Inserir empresas padrão
INSERT INTO contabilidade_empresas (nome, cnpj) VALUES
    ('VITORETTI ZUCARELLI', '22.040.923/0001-01'),
    ('GRP SML', '61.592.228/0001-02'),
    ('LISBON EVENTOS', '45.193.196/0001-16')
ON CONFLICT (cnpj) DO NOTHING;

-- Adicionar coluna empresa_id nas tabelas
ALTER TABLE contabilidade_guias
    ADD COLUMN IF NOT EXISTS empresa_id INTEGER REFERENCES contabilidade_empresas(id) ON DELETE SET NULL;

ALTER TABLE contabilidade_holerites
    ADD COLUMN IF NOT EXISTS empresa_id INTEGER REFERENCES contabilidade_empresas(id) ON DELETE SET NULL;

ALTER TABLE contabilidade_honorarios
    ADD COLUMN IF NOT EXISTS empresa_id INTEGER REFERENCES contabilidade_empresas(id) ON DELETE SET NULL;

-- Índices para performance
CREATE INDEX IF NOT EXISTS idx_contabilidade_guias_empresa ON contabilidade_guias(empresa_id);
CREATE INDEX IF NOT EXISTS idx_contabilidade_holerites_empresa ON contabilidade_holerites(empresa_id);
CREATE INDEX IF NOT EXISTS idx_contabilidade_honorarios_empresa ON contabilidade_honorarios(empresa_id);
