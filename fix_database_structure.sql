-- fix_database_structure.sql
-- Correção definitiva da estrutura do banco de dados

-- 1. Criar tabela solicitacoes_pagfor se não existir
CREATE TABLE IF NOT EXISTS solicitacoes_pagfor (
    id BIGSERIAL PRIMARY KEY,
    criado_por BIGINT NOT NULL,
    status VARCHAR(50) DEFAULT 'aguardando',
    valor DECIMAL(10,2) NOT NULL,
    descricao TEXT,
    chave_pix VARCHAR(255),
    tipo_chave VARCHAR(50) DEFAULT 'cpf',
    banco_nome VARCHAR(255),
    banco_codigo VARCHAR(10),
    agencia VARCHAR(20),
    conta VARCHAR(20),
    conta_digito VARCHAR(5),
    criado_em TIMESTAMP DEFAULT NOW(),
    modificado_em TIMESTAMP DEFAULT NOW()
);

-- 2. Adicionar coluna updated_at em lc_rascunhos se não existir
DO $$ 
BEGIN 
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                  WHERE table_name = 'lc_rascunhos' AND column_name = 'updated_at') THEN
        ALTER TABLE lc_rascunhos ADD COLUMN updated_at TIMESTAMP DEFAULT NOW();
    END IF;
END $$;

-- 3. Corrigir estrutura comercial_degustacoes
DO $$ 
BEGIN 
    -- Adicionar colunas faltantes
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                  WHERE table_name = 'comercial_degustacoes' AND column_name = 'titulo') THEN
        ALTER TABLE comercial_degustacoes ADD COLUMN titulo VARCHAR(255);
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                  WHERE table_name = 'comercial_degustacoes' AND column_name = 'descricao') THEN
        ALTER TABLE comercial_degustacoes ADD COLUMN descricao TEXT;
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                  WHERE table_name = 'comercial_degustacoes' AND column_name = 'local') THEN
        ALTER TABLE comercial_degustacoes ADD COLUMN local VARCHAR(255);
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                  WHERE table_name = 'comercial_degustacoes' AND column_name = 'data') THEN
        ALTER TABLE comercial_degustacoes ADD COLUMN data DATE;
    END IF;
    
    -- Atualizar registros com titulo NULL
    UPDATE comercial_degustacoes 
    SET titulo = COALESCE(titulo, 'Degustação ' || id::text)
    WHERE titulo IS NULL;
    
    -- Tornar titulo NOT NULL após atualizar
    ALTER TABLE comercial_degustacoes ALTER COLUMN titulo SET NOT NULL;
    
END $$;

-- 4. Criar tabela comercial_inscricoes se não existir
CREATE TABLE IF NOT EXISTS comercial_inscricoes (
    id BIGSERIAL PRIMARY KEY,
    event_id BIGINT NOT NULL,
    nome VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    telefone VARCHAR(20),
    cpf VARCHAR(14),
    status VARCHAR(50) DEFAULT 'confirmado',
    fechou_contrato BOOLEAN DEFAULT FALSE,
    observacoes TEXT,
    criado_em TIMESTAMP DEFAULT NOW(),
    modificado_em TIMESTAMP DEFAULT NOW()
);

-- 5. Criar índices para performance
CREATE INDEX IF NOT EXISTS idx_solicitacoes_pagfor_criado_por ON solicitacoes_pagfor(criado_por);
CREATE INDEX IF NOT EXISTS idx_solicitacoes_pagfor_status ON solicitacoes_pagfor(status);
CREATE INDEX IF NOT EXISTS idx_comercial_inscricoes_event_id ON comercial_inscricoes(event_id);
CREATE INDEX IF NOT EXISTS idx_comercial_inscricoes_status ON comercial_inscricoes(status);

-- 6. Comentários para documentação
COMMENT ON TABLE solicitacoes_pagfor IS 'Solicitações de pagamento para freelancers e fornecedores';
COMMENT ON TABLE comercial_inscricoes IS 'Inscrições em degustações comerciais';
COMMENT ON COLUMN solicitacoes_pagfor.status IS 'Status: aguardando, aprovado, suspenso, recusado, pago';
COMMENT ON COLUMN comercial_inscricoes.status IS 'Status: confirmado, lista_espera, cancelado';

