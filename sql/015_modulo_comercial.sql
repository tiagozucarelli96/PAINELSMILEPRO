-- 015_modulo_comercial.sql
-- Módulo Comercial - Degustações e Inscrições

-- Tabela de Degustações (eventos)
CREATE TABLE IF NOT EXISTS comercial_degustacoes (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    data DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fim TIME NOT NULL,
    local VARCHAR(255) NOT NULL,
    capacidade INT NOT NULL DEFAULT 50,
    data_limite DATE NOT NULL,
    lista_espera BOOLEAN DEFAULT TRUE,
    
    -- Preços
    preco_casamento NUMERIC(10,2) NOT NULL DEFAULT 150.00,
    incluidos_casamento INT NOT NULL DEFAULT 2,
    preco_15anos NUMERIC(10,2) NOT NULL DEFAULT 180.00,
    incluidos_15anos INT NOT NULL DEFAULT 3,
    preco_extra NUMERIC(10,2) NOT NULL DEFAULT 50.00,
    
    -- Textos configuráveis
    instrutivo_html TEXT,
    email_confirmacao_html TEXT,
    msg_sucesso_html TEXT,
    
    -- Form Builder
    campos_json JSONB,
    usar_como_padrao BOOLEAN DEFAULT FALSE,
    
    -- Status e controle
    status VARCHAR(20) NOT NULL DEFAULT 'rascunho' CHECK (status IN ('rascunho', 'publicado', 'encerrado')),
    token_publico VARCHAR(64) UNIQUE NOT NULL,
    
    -- Timestamps
    criado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Tabela de Inscrições
CREATE TABLE IF NOT EXISTS comercial_inscricoes (
    id SERIAL PRIMARY KEY,
    event_id INT NOT NULL REFERENCES comercial_degustacoes(id) ON DELETE CASCADE,
    status VARCHAR(20) NOT NULL DEFAULT 'confirmado' CHECK (status IN ('confirmado', 'lista_espera', 'cancelado')),
    
    -- Contrato ME Eventos
    fechou_contrato VARCHAR(10) NOT NULL DEFAULT 'indefinido' CHECK (fechou_contrato IN ('sim', 'nao', 'indefinido')),
    me_event_id INT,
    nome_titular_contrato VARCHAR(255),
    
    -- Dados do participante
    nome VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    celular VARCHAR(20),
    dados_json JSONB, -- Respostas do formulário
    
    -- Informações da festa
    qtd_pessoas INT NOT NULL DEFAULT 1,
    tipo_festa VARCHAR(20) CHECK (tipo_festa IN ('casamento', '15anos')),
    extras INT DEFAULT 0,
    
    -- Pagamento ASAAS
    pagamento_status VARCHAR(20) NOT NULL DEFAULT 'nao_aplicavel' CHECK (pagamento_status IN ('nao_aplicavel', 'aguardando', 'pago', 'expirado')),
    asaas_payment_id VARCHAR(100),
    valor_pago NUMERIC(10,2),
    
    -- Controle
    ip_origem INET,
    user_agent_origem TEXT,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Tabela de configurações de e-mail SMTP
CREATE TABLE IF NOT EXISTS comercial_email_config (
    id SERIAL PRIMARY KEY,
    smtp_host VARCHAR(255) NOT NULL,
    smtp_port INT NOT NULL DEFAULT 587,
    smtp_username VARCHAR(255) NOT NULL,
    smtp_password VARCHAR(255) NOT NULL,
    from_name VARCHAR(255) NOT NULL,
    from_email VARCHAR(255) NOT NULL,
    reply_to VARCHAR(255),
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Tabela de campos padrão do Form Builder
CREATE TABLE IF NOT EXISTS comercial_campos_padrao (
    id SERIAL PRIMARY KEY,
    campos_json JSONB NOT NULL,
    ativo BOOLEAN DEFAULT TRUE,
    criado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Adicionar permissões do módulo Comercial
ALTER TABLE usuarios 
ADD COLUMN IF NOT EXISTS perm_comercial_ver BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_comercial_deg_editar BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_comercial_deg_inscritos BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_comercial_conversao BOOLEAN DEFAULT FALSE;

-- Função para gerar token público
CREATE OR REPLACE FUNCTION generate_public_token()
RETURNS VARCHAR(64) AS $$
BEGIN
    RETURN REPLACE(gen_random_uuid()::text, '-', '');
END;
$$ LANGUAGE plpgsql;

-- Trigger para gerar token público automaticamente
CREATE OR REPLACE FUNCTION set_degustacao_public_token()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.token_publico IS NULL OR NEW.token_publico = '' THEN
        NEW.token_publico := generate_public_token();
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_set_degustacao_public_token ON comercial_degustacoes;
CREATE TRIGGER trg_set_degustacao_public_token
BEFORE INSERT ON comercial_degustacoes
FOR EACH ROW
EXECUTE FUNCTION set_degustacao_public_token();

-- Trigger para atualizar 'atualizado_em'
CREATE OR REPLACE FUNCTION update_degustacao_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.atualizado_em = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_update_degustacao_updated_at ON comercial_degustacoes;
CREATE TRIGGER trg_update_degustacao_updated_at
BEFORE UPDATE ON comercial_degustacoes
FOR EACH ROW
EXECUTE FUNCTION update_degustacao_updated_at();

-- Trigger para atualizar 'atualizado_em' nas inscrições
CREATE OR REPLACE FUNCTION update_inscricao_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.atualizado_em = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_update_inscricao_updated_at ON comercial_inscricoes;
CREATE TRIGGER trg_update_inscricao_updated_at
BEFORE UPDATE ON comercial_inscricoes
FOR EACH ROW
EXECUTE FUNCTION update_inscricao_updated_at();

-- Índices para performance
CREATE INDEX IF NOT EXISTS idx_comercial_degustacoes_status ON comercial_degustacoes(status);
CREATE INDEX IF NOT EXISTS idx_comercial_degustacoes_data ON comercial_degustacoes(data);
CREATE INDEX IF NOT EXISTS idx_comercial_inscricoes_event_id ON comercial_inscricoes(event_id);
CREATE INDEX IF NOT EXISTS idx_comercial_inscricoes_status ON comercial_inscricoes(status);
CREATE INDEX IF NOT EXISTS idx_comercial_inscricoes_fechou_contrato ON comercial_inscricoes(fechou_contrato);
CREATE INDEX IF NOT EXISTS idx_comercial_inscricoes_pagamento_status ON comercial_inscricoes(pagamento_status);

-- Inserir configuração de e-mail padrão
INSERT INTO comercial_email_config (smtp_host, smtp_port, smtp_username, smtp_password, from_name, from_email, ativo)
VALUES ('mail.exemplo.com', 587, 'contato@exemplo.com', 'senha123', 'GRUPO Smile EVENTOS', 'contato@exemplo.com', TRUE)
ON CONFLICT DO NOTHING;
