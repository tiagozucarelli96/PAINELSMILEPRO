-- 041_modulo_vendas.sql
-- Sistema completo de Vendas - Pré-contratos e Kanban de Acompanhamento

-- 1. Tabela de Pré-contratos
CREATE TABLE IF NOT EXISTS vendas_pre_contratos (
    id SERIAL PRIMARY KEY,
    tipo_evento VARCHAR(50) NOT NULL CHECK (tipo_evento IN ('casamento', 'infantil', 'pj')),
    status VARCHAR(50) NOT NULL DEFAULT 'aguardando_conferencia' 
        CHECK (status IN ('aguardando_conferencia', 'pronto_aprovacao', 'aprovado_criado_me', 'cancelado_nao_fechou')),
    
    -- Dados do cliente (formulário público)
    nome_completo VARCHAR(255) NOT NULL,
    cpf VARCHAR(14),
    telefone VARCHAR(20),
    email VARCHAR(255),
    data_evento DATE NOT NULL,
    unidade VARCHAR(50) NOT NULL CHECK (unidade IN ('Lisbon', 'Diverkids', 'Garden', 'Cristal')),
    horario_inicio TIME NOT NULL,
    horario_termino TIME NOT NULL,
    observacoes TEXT,
    
    -- Dados comerciais (preenchidos internamente)
    pacote_contratado TEXT,
    valor_negociado NUMERIC(12,2) DEFAULT 0,
    desconto NUMERIC(12,2) DEFAULT 0,
    valor_total NUMERIC(12,2) DEFAULT 0, -- Calculado: valor_negociado + adicionais - desconto
    
    -- Integração ME
    me_client_id INT,
    me_event_id INT,
    me_payload JSONB, -- Payload completo enviado para ME
    me_criado_em TIMESTAMP,
    
    -- Controle
    criado_por_ip VARCHAR(45), -- IP do formulário público
    criado_em TIMESTAMP DEFAULT NOW(),
    atualizado_em TIMESTAMP DEFAULT NOW(),
    atualizado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    aprovado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    aprovado_em TIMESTAMP,
    
    -- Override de conflito
    override_conflito BOOLEAN DEFAULT FALSE,
    override_motivo TEXT,
    override_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    override_em TIMESTAMP
);

-- 2. Tabela de Adicionais (itens extras do pré-contrato)
CREATE TABLE IF NOT EXISTS vendas_adicionais (
    id SERIAL PRIMARY KEY,
    pre_contrato_id INT NOT NULL REFERENCES vendas_pre_contratos(id) ON DELETE CASCADE,
    item VARCHAR(255) NOT NULL,
    valor NUMERIC(12,2) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP DEFAULT NOW()
);

-- 3. Tabela de Anexos (orçamentos/propostas)
CREATE TABLE IF NOT EXISTS vendas_anexos (
    id SERIAL PRIMARY KEY,
    pre_contrato_id INT NOT NULL REFERENCES vendas_pre_contratos(id) ON DELETE CASCADE,
    nome_original VARCHAR(255) NOT NULL,
    nome_arquivo VARCHAR(255) NOT NULL,
    chave_storage VARCHAR(500), -- Chave no Magalu
    url VARCHAR(500),
    mime_type VARCHAR(100),
    tamanho_bytes BIGINT,
    upload_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMP DEFAULT NOW()
);

-- 4. Tabela de Quadros Kanban
CREATE TABLE IF NOT EXISTS vendas_kanban_boards (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL DEFAULT 'Acompanhamento de Contratos',
    descricao TEXT,
    criado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMP DEFAULT NOW(),
    ativo BOOLEAN DEFAULT TRUE
);

-- 5. Tabela de Colunas do Kanban
CREATE TABLE IF NOT EXISTS vendas_kanban_colunas (
    id SERIAL PRIMARY KEY,
    board_id INT NOT NULL REFERENCES vendas_kanban_boards(id) ON DELETE CASCADE,
    nome VARCHAR(255) NOT NULL,
    posicao INT NOT NULL DEFAULT 0,
    cor VARCHAR(7) DEFAULT '#6b7280',
    criado_em TIMESTAMP DEFAULT NOW()
);

-- 6. Tabela de Cards do Kanban
CREATE TABLE IF NOT EXISTS vendas_kanban_cards (
    id SERIAL PRIMARY KEY,
    board_id INT NOT NULL REFERENCES vendas_kanban_boards(id) ON DELETE CASCADE,
    coluna_id INT NOT NULL REFERENCES vendas_kanban_colunas(id) ON DELETE CASCADE,
    pre_contrato_id INT REFERENCES vendas_pre_contratos(id) ON DELETE SET NULL,
    
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT,
    cliente_nome VARCHAR(255),
    data_evento DATE,
    unidade VARCHAR(50),
    valor_total NUMERIC(12,2),
    status VARCHAR(50),
    
    posicao INT NOT NULL DEFAULT 0,
    criado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMP DEFAULT NOW(),
    atualizado_em TIMESTAMP DEFAULT NOW()
);

-- 7. Tabela de Histórico de Movimentação (logs do kanban)
CREATE TABLE IF NOT EXISTS vendas_kanban_historico (
    id SERIAL PRIMARY KEY,
    card_id INT NOT NULL REFERENCES vendas_kanban_cards(id) ON DELETE CASCADE,
    coluna_anterior_id INT REFERENCES vendas_kanban_colunas(id) ON DELETE SET NULL,
    coluna_nova_id INT NOT NULL REFERENCES vendas_kanban_colunas(id) ON DELETE CASCADE,
    movido_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    movido_em TIMESTAMP DEFAULT NOW(),
    observacao TEXT
);

-- 8. Tabela de Logs do Sistema
CREATE TABLE IF NOT EXISTS vendas_logs (
    id SERIAL PRIMARY KEY,
    pre_contrato_id INT REFERENCES vendas_pre_contratos(id) ON DELETE SET NULL,
    acao VARCHAR(100) NOT NULL, -- 'criado', 'aprovado', 'cliente_criado_me', 'evento_criado_me', 'conflito_detectado', 'override', etc
    usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    detalhes JSONB, -- Dados adicionais da ação
    criado_em TIMESTAMP DEFAULT NOW()
);

-- Índices para performance
CREATE INDEX IF NOT EXISTS idx_vendas_pre_contratos_status ON vendas_pre_contratos(status);
CREATE INDEX IF NOT EXISTS idx_vendas_pre_contratos_tipo ON vendas_pre_contratos(tipo_evento);
CREATE INDEX IF NOT EXISTS idx_vendas_pre_contratos_data_evento ON vendas_pre_contratos(data_evento);
CREATE INDEX IF NOT EXISTS idx_vendas_pre_contratos_cpf ON vendas_pre_contratos(cpf) WHERE cpf IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_vendas_pre_contratos_email ON vendas_pre_contratos(email) WHERE email IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_vendas_pre_contratos_me_client ON vendas_pre_contratos(me_client_id) WHERE me_client_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_vendas_pre_contratos_me_event ON vendas_pre_contratos(me_event_id) WHERE me_event_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_vendas_adicionais_pre_contrato ON vendas_adicionais(pre_contrato_id);
CREATE INDEX IF NOT EXISTS idx_vendas_anexos_pre_contrato ON vendas_anexos(pre_contrato_id);
CREATE INDEX IF NOT EXISTS idx_vendas_kanban_cards_pre_contrato ON vendas_kanban_cards(pre_contrato_id);
CREATE INDEX IF NOT EXISTS idx_vendas_kanban_cards_coluna ON vendas_kanban_cards(coluna_id);
CREATE INDEX IF NOT EXISTS idx_vendas_logs_pre_contrato ON vendas_logs(pre_contrato_id);

-- Criar quadro padrão "Acompanhamento de Contratos" com colunas padrão
DO $$
DECLARE
    board_id_var INT;
    coluna_id_var INT;
BEGIN
    -- Criar quadro padrão
    INSERT INTO vendas_kanban_boards (nome, descricao, ativo)
    VALUES ('Acompanhamento de Contratos', 'Kanban para acompanhamento de contratos de vendas', TRUE)
    ON CONFLICT DO NOTHING
    RETURNING id INTO board_id_var;
    
    -- Se não retornou ID, buscar o existente
    IF board_id_var IS NULL THEN
        SELECT id INTO board_id_var FROM vendas_kanban_boards WHERE nome = 'Acompanhamento de Contratos' LIMIT 1;
    END IF;
    
    -- Criar colunas padrão se não existirem
    IF board_id_var IS NOT NULL THEN
        -- Coluna: Criado na ME
        INSERT INTO vendas_kanban_colunas (board_id, nome, posicao, cor)
        VALUES (board_id_var, 'Criado na ME', 0, '#3b82f6')
        ON CONFLICT DO NOTHING;
        
        -- Coluna: Contrato feito
        INSERT INTO vendas_kanban_colunas (board_id, nome, posicao, cor)
        VALUES (board_id_var, 'Contrato feito', 1, '#10b981')
        ON CONFLICT DO NOTHING;
        
        -- Coluna: Contrato enviado
        INSERT INTO vendas_kanban_colunas (board_id, nome, posicao, cor)
        VALUES (board_id_var, 'Contrato enviado', 2, '#f59e0b')
        ON CONFLICT DO NOTHING;
        
        -- Coluna: Falta assinatura
        INSERT INTO vendas_kanban_colunas (board_id, nome, posicao, cor)
        VALUES (board_id_var, 'Falta assinatura', 3, '#ef4444')
        ON CONFLICT DO NOTHING;
        
        -- Coluna: Falta pagamento
        INSERT INTO vendas_kanban_colunas (board_id, nome, posicao, cor)
        VALUES (board_id_var, 'Falta pagamento', 4, '#f97316')
        ON CONFLICT DO NOTHING;
        
        -- Coluna: Assinado + Pago (OK)
        INSERT INTO vendas_kanban_colunas (board_id, nome, posicao, cor)
        VALUES (board_id_var, 'Assinado + Pago (OK)', 5, '#22c55e')
        ON CONFLICT DO NOTHING;
        
        -- Coluna: Checklist lançado (ME)
        INSERT INTO vendas_kanban_colunas (board_id, nome, posicao, cor)
        VALUES (board_id_var, 'Checklist lançado (ME)', 6, '#8b5cf6')
        ON CONFLICT DO NOTHING;
        
        -- Coluna: Cancelado / Não fechou
        INSERT INTO vendas_kanban_colunas (board_id, nome, posicao, cor)
        VALUES (board_id_var, 'Cancelado / Não fechou', 7, '#6b7280')
        ON CONFLICT DO NOTHING;
    END IF;
END $$;
