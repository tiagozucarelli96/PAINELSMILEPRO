-- ============================================
-- ESTRUTURA DO BANCO DE DADOS - MÓDULO CONTABILIDADE
-- ============================================

-- Tabela de configuração de acesso da contabilidade
CREATE TABLE IF NOT EXISTS contabilidade_acesso (
    id BIGSERIAL PRIMARY KEY,
    link_publico VARCHAR(255) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ativo' CHECK (status IN ('ativo', 'inativo')),
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Tabela de sessões da contabilidade (acesso externo)
CREATE TABLE IF NOT EXISTS contabilidade_sessoes (
    id BIGSERIAL PRIMARY KEY,
    token VARCHAR(255) NOT NULL UNIQUE,
    acesso_id BIGINT NOT NULL REFERENCES contabilidade_acesso(id) ON DELETE CASCADE,
    ip_address VARCHAR(45),
    user_agent TEXT,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    expira_em TIMESTAMP NOT NULL,
    ativo BOOLEAN NOT NULL DEFAULT TRUE
);

-- Tabela de parcelamentos
CREATE TABLE IF NOT EXISTS contabilidade_parcelamentos (
    id BIGSERIAL PRIMARY KEY,
    descricao VARCHAR(255) NOT NULL,
    total_parcelas INTEGER NOT NULL,
    parcela_atual INTEGER NOT NULL DEFAULT 1,
    status VARCHAR(20) NOT NULL DEFAULT 'ativo' CHECK (status IN ('ativo', 'encerrado')),
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Tabela de guias para pagamento
CREATE TABLE IF NOT EXISTS contabilidade_guias (
    id BIGSERIAL PRIMARY KEY,
    arquivo_url TEXT,
    arquivo_nome VARCHAR(255),
    chave_storage VARCHAR(500),
    data_vencimento DATE,
    descricao TEXT NOT NULL,
    e_parcela BOOLEAN NOT NULL DEFAULT FALSE,
    parcelamento_id BIGINT REFERENCES contabilidade_parcelamentos(id) ON DELETE SET NULL,
    numero_parcela INTEGER,
    status VARCHAR(20) NOT NULL DEFAULT 'aberto' CHECK (status IN ('aberto', 'pago', 'vencido', 'cancelado')),
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Tabela de holerites
CREATE TABLE IF NOT EXISTS contabilidade_holerites (
    id BIGSERIAL PRIMARY KEY,
    arquivo_url TEXT NOT NULL,
    arquivo_nome VARCHAR(255) NOT NULL,
    chave_storage VARCHAR(500),
    mes_competencia VARCHAR(7) NOT NULL, -- Formato: MM/AAAA
    e_ajuste BOOLEAN NOT NULL DEFAULT FALSE,
    observacao TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'aberto' CHECK (status IN ('aberto', 'processado', 'cancelado')),
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Tabela de honorários
CREATE TABLE IF NOT EXISTS contabilidade_honorarios (
    id BIGSERIAL PRIMARY KEY,
    arquivo_url TEXT NOT NULL,
    arquivo_nome VARCHAR(255) NOT NULL,
    chave_storage VARCHAR(500),
    data_vencimento DATE,
    descricao TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'aberto' CHECK (status IN ('aberto', 'pago', 'vencido', 'cancelado')),
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Tabela de conversas (chat contábil)
CREATE TABLE IF NOT EXISTS contabilidade_conversas (
    id BIGSERIAL PRIMARY KEY,
    assunto VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'aberto' CHECK (status IN ('aberto', 'em_andamento', 'concluido')),
    criado_por VARCHAR(50) NOT NULL DEFAULT 'contabilidade', -- 'admin' ou 'contabilidade'
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Tabela de mensagens das conversas
CREATE TABLE IF NOT EXISTS contabilidade_conversas_mensagens (
    id BIGSERIAL PRIMARY KEY,
    conversa_id BIGINT NOT NULL REFERENCES contabilidade_conversas(id) ON DELETE CASCADE,
    autor VARCHAR(50) NOT NULL, -- 'admin' ou 'contabilidade'
    mensagem TEXT,
    anexo_url TEXT,
    anexo_nome VARCHAR(255),
    chave_storage VARCHAR(500),
    criado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Tabela de documentos de colaboradores
CREATE TABLE IF NOT EXISTS contabilidade_colaboradores_documentos (
    id BIGSERIAL PRIMARY KEY,
    colaborador_id BIGINT NOT NULL, -- Referência à tabela usuarios
    tipo_documento VARCHAR(50) NOT NULL, -- 'contrato', 'ajuste', 'advertencia', 'outro'
    arquivo_url TEXT NOT NULL,
    arquivo_nome VARCHAR(255) NOT NULL,
    chave_storage VARCHAR(500),
    descricao TEXT,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Índices para melhor performance
CREATE INDEX IF NOT EXISTS idx_contabilidade_acesso_status ON contabilidade_acesso(status);
CREATE INDEX IF NOT EXISTS idx_contabilidade_sessoes_token ON contabilidade_sessoes(token);
CREATE INDEX IF NOT EXISTS idx_contabilidade_sessoes_ativo ON contabilidade_sessoes(ativo);
CREATE INDEX IF NOT EXISTS idx_contabilidade_parcelamentos_status ON contabilidade_parcelamentos(status);
CREATE INDEX IF NOT EXISTS idx_contabilidade_guias_status ON contabilidade_guias(status);
CREATE INDEX IF NOT EXISTS idx_contabilidade_guias_parcelamento ON contabilidade_guias(parcelamento_id);
CREATE INDEX IF NOT EXISTS idx_contabilidade_guias_chave_storage ON contabilidade_guias(chave_storage);
CREATE INDEX IF NOT EXISTS idx_contabilidade_holerites_status ON contabilidade_holerites(status);
CREATE INDEX IF NOT EXISTS idx_contabilidade_holerites_chave_storage ON contabilidade_holerites(chave_storage);
CREATE INDEX IF NOT EXISTS idx_contabilidade_honorarios_status ON contabilidade_honorarios(status);
CREATE INDEX IF NOT EXISTS idx_contabilidade_honorarios_chave_storage ON contabilidade_honorarios(chave_storage);
CREATE INDEX IF NOT EXISTS idx_contabilidade_conversas_status ON contabilidade_conversas(status);
CREATE INDEX IF NOT EXISTS idx_contabilidade_conversas_mensagens_conversa ON contabilidade_conversas_mensagens(conversa_id);
CREATE INDEX IF NOT EXISTS idx_contabilidade_colaboradores_docs_colab ON contabilidade_colaboradores_documentos(colaborador_id);

-- Comentários para documentação
COMMENT ON TABLE contabilidade_acesso IS 'Configuração de acesso externo da contabilidade';
COMMENT ON TABLE contabilidade_sessoes IS 'Sessões ativas do acesso externo da contabilidade';
COMMENT ON TABLE contabilidade_parcelamentos IS 'Parcelamentos inteligentes para guias';
COMMENT ON TABLE contabilidade_guias IS 'Guias para pagamento (com suporte a parcelamento)';
COMMENT ON TABLE contabilidade_holerites IS 'Holerites dos colaboradores';
COMMENT ON TABLE contabilidade_honorarios IS 'Honorários contábeis';
COMMENT ON TABLE contabilidade_conversas IS 'Conversas/chat entre admin e contabilidade';
COMMENT ON TABLE contabilidade_conversas_mensagens IS 'Mensagens das conversas';
COMMENT ON TABLE contabilidade_colaboradores_documentos IS 'Documentos anexados aos colaboradores';
