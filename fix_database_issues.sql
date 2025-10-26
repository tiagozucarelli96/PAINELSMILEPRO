-- fix_database_issues.sql
-- Correções de estruturas faltantes no Railway PostgreSQL

-- Adicionar coluna updated_at se não existir
DO $$ 
BEGIN 
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'lc_categorias' AND column_name = 'updated_at'
    ) THEN
        ALTER TABLE lc_categorias ADD COLUMN updated_at TIMESTAMP DEFAULT NOW();
    END IF;
END $$;

-- Criar tabela solicitacoes_pagfor se não existir
CREATE TABLE IF NOT EXISTS solicitacoes_pagfor (
    id BIGSERIAL PRIMARY KEY,
    criado_por BIGINT NOT NULL,
    status VARCHAR(50) DEFAULT 'aguardando',
    valor DECIMAL(10,2) NOT NULL,
    descricao TEXT,
    chave_pix VARCHAR(255),
    tipo_chave_pix VARCHAR(50),
    ispb VARCHAR(20),
    banco VARCHAR(80),
    agencia VARCHAR(20),
    conta VARCHAR(30),
    tipo_conta VARCHAR(5),
    criado_em TIMESTAMP DEFAULT NOW(),
    modificado_em TIMESTAMP DEFAULT NOW()
);

-- Adicionar coluna status_atualizado_por se não existir
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'pagamentos_solicitacoes' 
        AND column_name = 'status_atualizado_por'
    ) THEN
        ALTER TABLE pagamentos_solicitacoes ADD COLUMN status_atualizado_por BIGINT;
    END IF;
END $$;

-- Adicionar índices para performance
CREATE INDEX IF NOT EXISTS idx_lc_categorias_updated ON lc_categorias(updated_at);
CREATE INDEX IF NOT EXISTS idx_solicitacoes_pagfor_status ON solicitacoes_pagfor(status);
CREATE INDEX IF NOT EXISTS idx_solicitacoes_pagfor_criado_em ON solicitacoes_pagfor(criado_em);

-- Mensagem de sucesso
DO $$ 
BEGIN 
    RAISE NOTICE 'Estruturas do banco corrigidas com sucesso!';
END $$;
