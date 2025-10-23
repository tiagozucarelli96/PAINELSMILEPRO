-- 014_contab_parte1.sql
-- PARTE 1: Criar tabelas básicas da Contabilidade

-- Tabela de documentos contábeis
CREATE TABLE IF NOT EXISTS contab_documentos (
    id SERIAL PRIMARY KEY,
    tipo VARCHAR(50) NOT NULL,
    descricao VARCHAR(255) NOT NULL,
    competencia VARCHAR(7) NOT NULL,
    origem VARCHAR(20) NOT NULL DEFAULT 'interno' CHECK (origem IN ('portal_contab', 'interno')),
    fornecedor_sugerido VARCHAR(255),
    criado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Tabela de parcelas dos documentos
CREATE TABLE IF NOT EXISTS contab_parcelas (
    id SERIAL PRIMARY KEY,
    documento_id INT NOT NULL REFERENCES contab_documentos(id) ON DELETE CASCADE,
    numero_parcela INT NOT NULL,
    total_parcelas INT NOT NULL,
    vencimento DATE NOT NULL,
    valor NUMERIC(10,2) NOT NULL,
    linha_digitavel VARCHAR(100),
    status VARCHAR(20) NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente', 'pago', 'vencido', 'suspenso', 'recusado')),
    motivo_suspensao TEXT,
    data_pagamento DATE,
    observacao_pagamento TEXT,
    pago_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    pago_em TIMESTAMP,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);
