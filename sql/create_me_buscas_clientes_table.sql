-- Criar tabela para salvar buscas/seleções de clientes ME Eventos
-- Esta tabela armazena quando um cliente busca/seleciona um nome na API

CREATE TABLE IF NOT EXISTS comercial_me_buscas_clientes (
    id SERIAL PRIMARY KEY,
    
    -- Dados da busca
    nome_buscado VARCHAR(255) NOT NULL,
    nome_cliente_encontrado VARCHAR(255) NOT NULL,
    quantidade_eventos INT DEFAULT 0,
    
    -- Dados do cliente encontrado na API (antes da validação de CPF)
    cpf_api_encontrado VARCHAR(11), -- CPF retornado pela API (pode ser NULL se API não retornar)
    email_api_encontrado VARCHAR(255),
    telefone_api_encontrado VARCHAR(20),
    me_event_id INT,
    
    -- Dados da validação de CPF
    cpf_digitado VARCHAR(11), -- CPF que o cliente digitou
    cpf_validado BOOLEAN DEFAULT FALSE,
    cpf_bateu BOOLEAN DEFAULT FALSE, -- Se CPF digitado bateu com CPF da API
    
    -- Status
    status VARCHAR(20) DEFAULT 'busca_realizada' CHECK (status IN ('busca_realizada', 'cpf_validado', 'cpf_invalido', 'campos_preenchidos')),
    
    -- Relacionamento com inscrição (se houver)
    inscricao_id INT REFERENCES comercial_inscricoes(id) ON DELETE SET NULL,
    
    -- Controle
    ip_origem VARCHAR(45),
    user_agent TEXT,
    degustacao_token VARCHAR(64), -- Token da degustação onde foi feita a busca
    
    -- Timestamps
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Índices para busca rápida
CREATE INDEX IF NOT EXISTS idx_me_buscas_nome_buscado ON comercial_me_buscas_clientes(nome_buscado);
CREATE INDEX IF NOT EXISTS idx_me_buscas_nome_encontrado ON comercial_me_buscas_clientes(nome_cliente_encontrado);
CREATE INDEX IF NOT EXISTS idx_me_buscas_cpf_validado ON comercial_me_buscas_clientes(cpf_validado);
CREATE INDEX IF NOT EXISTS idx_me_buscas_inscricao_id ON comercial_me_buscas_clientes(inscricao_id);
CREATE INDEX IF NOT EXISTS idx_me_buscas_degustacao_token ON comercial_me_buscas_clientes(degustacao_token);

COMMENT ON TABLE comercial_me_buscas_clientes IS 'Armazena buscas e seleções de clientes na API ME Eventos para rastreamento e validação de segurança';
COMMENT ON COLUMN comercial_me_buscas_clientes.cpf_api_encontrado IS 'CPF encontrado na API ME Eventos (pode ser NULL se API não retornar)';
COMMENT ON COLUMN comercial_me_buscas_clientes.cpf_digitado IS 'CPF digitado pelo cliente para validação';
COMMENT ON COLUMN comercial_me_buscas_clientes.cpf_bateu IS 'Se o CPF digitado bateu exatamente com o CPF da API';

