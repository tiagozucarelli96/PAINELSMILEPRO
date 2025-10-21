-- 011_sistema_pagamentos.sql
-- Sistema completo de pagamentos e fornecedores

-- 1. Tabela de Freelancers (diretório interno)
CREATE TABLE IF NOT EXISTS lc_freelancers (
    id SERIAL PRIMARY KEY,
    nome_completo VARCHAR(200) NOT NULL,
    cpf VARCHAR(14) NOT NULL UNIQUE,
    pix_tipo VARCHAR(20) NOT NULL CHECK (pix_tipo IN ('cpf', 'cnpj', 'email', 'celular', 'aleatoria')),
    pix_chave VARCHAR(100) NOT NULL,
    ativo BOOLEAN NOT NULL DEFAULT true,
    observacao TEXT,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    criado_por INT,
    modificado_em TIMESTAMP,
    modificado_por INT
);

-- 2. Tabela de Solicitações de Pagamento
CREATE TABLE IF NOT EXISTS lc_solicitacoes_pagamento (
    id SERIAL PRIMARY KEY,
    criador_id INT,
    beneficiario_tipo VARCHAR(20) NOT NULL CHECK (beneficiario_tipo IN ('freelancer', 'fornecedor')),
    freelancer_id INT REFERENCES lc_freelancers(id) ON DELETE SET NULL,
    fornecedor_id INT REFERENCES fornecedores(id) ON DELETE SET NULL,
    valor NUMERIC(14,2) NOT NULL CHECK (valor > 0),
    data_desejada DATE,
    observacoes TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'aguardando' CHECK (status IN ('aguardando', 'aprovado', 'suspenso', 'recusado', 'pago')),
    status_atualizado_por INT,
    status_atualizado_em TIMESTAMP,
    motivo_suspensao TEXT,
    motivo_recusa TEXT,
    pix_tipo VARCHAR(20),
    pix_chave VARCHAR(100),
    data_pagamento DATE,
    observacao_pagamento TEXT,
    origem VARCHAR(50) DEFAULT 'interno' CHECK (origem IN ('interno', 'fornecedor_link')),
    ip_origem INET,
    user_agent TEXT,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    modificado_em TIMESTAMP
);

-- 3. Tabela de Timeline (histórico de eventos)
CREATE TABLE IF NOT EXISTS lc_timeline_pagamentos (
    id SERIAL PRIMARY KEY,
    solicitacao_id INT NOT NULL REFERENCES lc_solicitacoes_pagamento(id) ON DELETE CASCADE,
    autor_id INT,
    tipo_evento VARCHAR(20) NOT NULL CHECK (tipo_evento IN ('comentario', 'status_change', 'criacao')),
    mensagem TEXT,
    status_de VARCHAR(20),
    status_para VARCHAR(20),
    criado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- 4. Adicionar campos PIX à tabela fornecedores (se não existirem)
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'fornecedores' AND column_name = 'pix_tipo') THEN
        ALTER TABLE fornecedores ADD COLUMN pix_tipo VARCHAR(20) CHECK (pix_tipo IN ('cpf', 'cnpj', 'email', 'celular', 'aleatoria'));
        RAISE NOTICE 'Coluna pix_tipo adicionada à tabela fornecedores.';
    END IF;
END $$;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'fornecedores' AND column_name = 'pix_chave') THEN
        ALTER TABLE fornecedores ADD COLUMN pix_chave VARCHAR(100);
        RAISE NOTICE 'Coluna pix_chave adicionada à tabela fornecedores.';
    END IF;
END $$;

-- 5. Adicionar token público à tabela fornecedores
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'fornecedores' AND column_name = 'token_publico') THEN
        ALTER TABLE fornecedores ADD COLUMN token_publico VARCHAR(64) UNIQUE;
        RAISE NOTICE 'Coluna token_publico adicionada à tabela fornecedores.';
    END IF;
END $$;

-- 6. Adicionar campos de categoria e observação à tabela fornecedores
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'fornecedores' AND column_name = 'categoria') THEN
        ALTER TABLE fornecedores ADD COLUMN categoria VARCHAR(100);
        RAISE NOTICE 'Coluna categoria adicionada à tabela fornecedores.';
    END IF;
END $$;

-- 7. Índices para performance
CREATE INDEX IF NOT EXISTS idx_freelancers_cpf ON lc_freelancers(cpf);
CREATE INDEX IF NOT EXISTS idx_freelancers_ativo ON lc_freelancers(ativo);
CREATE INDEX IF NOT EXISTS idx_solicitacoes_criador ON lc_solicitacoes_pagamento(criador_id);
CREATE INDEX IF NOT EXISTS idx_solicitacoes_status ON lc_solicitacoes_pagamento(status);
CREATE INDEX IF NOT EXISTS idx_solicitacoes_beneficiario ON lc_solicitacoes_pagamento(beneficiario_tipo, freelancer_id, fornecedor_id);
CREATE INDEX IF NOT EXISTS idx_solicitacoes_origem ON lc_solicitacoes_pagamento(origem);
CREATE INDEX IF NOT EXISTS idx_timeline_solicitacao ON lc_timeline_pagamentos(solicitacao_id);
CREATE INDEX IF NOT EXISTS idx_fornecedores_token ON fornecedores(token_publico);
CREATE INDEX IF NOT EXISTS idx_fornecedores_pix ON fornecedores(pix_tipo, pix_chave);

-- 8. Função para gerar token público único
CREATE OR REPLACE FUNCTION lc_gerar_token_publico() RETURNS VARCHAR(64) AS $$
DECLARE
    token VARCHAR(64);
    tentativas INTEGER := 0;
BEGIN
    LOOP
        -- Gerar token de 64 caracteres (hex)
        token := encode(gen_random_bytes(32), 'hex');
        
        -- Verificar se já existe
        IF NOT EXISTS (SELECT 1 FROM fornecedores WHERE token_publico = token) THEN
            RETURN token;
        END IF;
        
        tentativas := tentativas + 1;
        IF tentativas > 10 THEN
            RAISE EXCEPTION 'Não foi possível gerar token único após 10 tentativas';
        END IF;
    END LOOP;
END;
$$ LANGUAGE plpgsql;

-- 9. Função para buscar freelancers ativos
CREATE OR REPLACE FUNCTION lc_buscar_freelancers_ativos() RETURNS TABLE (
    id INT,
    nome_completo VARCHAR(200),
    cpf VARCHAR(14),
    pix_tipo VARCHAR(20),
    pix_chave VARCHAR(100)
) AS $$
BEGIN
    RETURN QUERY
    SELECT f.id, f.nome_completo, f.cpf, f.pix_tipo, f.pix_chave
    FROM lc_freelancers f
    WHERE f.ativo = true
    ORDER BY f.nome_completo;
END;
$$ LANGUAGE plpgsql;

-- 10. Função para criar solicitação de pagamento
CREATE OR REPLACE FUNCTION lc_criar_solicitacao_pagamento(
    p_criador_id INT,
    p_beneficiario_tipo VARCHAR(20),
    p_freelancer_id INT DEFAULT NULL,
    p_fornecedor_id INT DEFAULT NULL,
    p_valor NUMERIC(14,2),
    p_data_desejada DATE DEFAULT NULL,
    p_observacoes TEXT DEFAULT NULL,
    p_pix_tipo VARCHAR(20) DEFAULT NULL,
    p_pix_chave VARCHAR(100) DEFAULT NULL,
    p_origem VARCHAR(50) DEFAULT 'interno',
    p_ip_origem INET DEFAULT NULL,
    p_user_agent TEXT DEFAULT NULL
) RETURNS INT AS $$
DECLARE
    solicitacao_id INT;
BEGIN
    INSERT INTO lc_solicitacoes_pagamento (
        criador_id, beneficiario_tipo, freelancer_id, fornecedor_id,
        valor, data_desejada, observacoes, pix_tipo, pix_chave,
        origem, ip_origem, user_agent
    ) VALUES (
        p_criador_id, p_beneficiario_tipo, p_freelancer_id, p_fornecedor_id,
        p_valor, p_data_desejada, p_observacoes, p_pix_tipo, p_pix_chave,
        p_origem, p_ip_origem, p_user_agent
    ) RETURNING id INTO solicitacao_id;
    
    -- Criar evento inicial na timeline
    INSERT INTO lc_timeline_pagamentos (solicitacao_id, autor_id, tipo_evento, mensagem)
    VALUES (solicitacao_id, p_criador_id, 'criacao', 'Solicitação criada');
    
    RETURN solicitacao_id;
END;
$$ LANGUAGE plpgsql;

-- 11. Função para alterar status da solicitação
CREATE OR REPLACE FUNCTION lc_alterar_status_solicitacao(
    p_solicitacao_id INT,
    p_novo_status VARCHAR(20),
    p_autor_id INT,
    p_motivo TEXT DEFAULT NULL,
    p_data_pagamento DATE DEFAULT NULL,
    p_observacao_pagamento TEXT DEFAULT NULL
) RETURNS BOOLEAN AS $$
DECLARE
    status_atual VARCHAR(20);
    mensagem_timeline TEXT;
BEGIN
    -- Buscar status atual
    SELECT status INTO status_atual FROM lc_solicitacoes_pagamento WHERE id = p_solicitacao_id;
    
    IF status_atual IS NULL THEN
        RETURN FALSE;
    END IF;
    
    -- Atualizar solicitação
    UPDATE lc_solicitacoes_pagamento 
    SET status = p_novo_status,
        status_atualizado_por = p_autor_id,
        status_atualizado_em = NOW(),
        motivo_suspensao = CASE WHEN p_novo_status = 'suspenso' THEN p_motivo ELSE motivo_suspensao END,
        motivo_recusa = CASE WHEN p_novo_status = 'recusado' THEN p_motivo ELSE motivo_recusa END,
        data_pagamento = CASE WHEN p_novo_status = 'pago' THEN p_data_pagamento ELSE data_pagamento END,
        observacao_pagamento = CASE WHEN p_novo_status = 'pago' THEN p_observacao_pagamento ELSE observacao_pagamento END,
        modificado_em = NOW()
    WHERE id = p_solicitacao_id;
    
    -- Criar evento na timeline
    mensagem_timeline := 'Status alterado de ' || status_atual || ' para ' || p_novo_status;
    IF p_motivo IS NOT NULL THEN
        mensagem_timeline := mensagem_timeline || ': ' || p_motivo;
    END IF;
    
    INSERT INTO lc_timeline_pagamentos (solicitacao_id, autor_id, tipo_evento, mensagem, status_de, status_para)
    VALUES (p_solicitacao_id, p_autor_id, 'status_change', mensagem_timeline, status_atual, p_novo_status);
    
    RETURN TRUE;
END;
$$ LANGUAGE plpgsql;

-- 12. Função para adicionar comentário na timeline
CREATE OR REPLACE FUNCTION lc_adicionar_comentario_timeline(
    p_solicitacao_id INT,
    p_autor_id INT,
    p_mensagem TEXT
) RETURNS INT AS $$
DECLARE
    comentario_id INT;
BEGIN
    INSERT INTO lc_timeline_pagamentos (solicitacao_id, autor_id, tipo_evento, mensagem)
    VALUES (p_solicitacao_id, p_autor_id, 'comentario', p_mensagem)
    RETURNING id INTO comentario_id;
    
    RETURN comentario_id;
END;
$$ LANGUAGE plpgsql;

-- 13. View para estatísticas de pagamentos
CREATE OR REPLACE VIEW v_estatisticas_pagamentos AS
SELECT 
    COUNT(*) as total_solicitacoes,
    COUNT(*) FILTER (WHERE status = 'aguardando') as aguardando,
    COUNT(*) FILTER (WHERE status = 'aprovado') as aprovado,
    COUNT(*) FILTER (WHERE status = 'suspenso') as suspenso,
    COUNT(*) FILTER (WHERE status = 'recusado') as recusado,
    COUNT(*) FILTER (WHERE status = 'pago') as pago,
    COALESCE(SUM(valor) FILTER (WHERE status = 'pago'), 0) as valor_total_pago,
    COALESCE(SUM(valor) FILTER (WHERE status = 'aguardando'), 0) as valor_aguardando,
    COALESCE(SUM(valor) FILTER (WHERE status = 'aprovado'), 0) as valor_aprovado
FROM lc_solicitacoes_pagamento;

-- 14. View para solicitações com detalhes
CREATE OR REPLACE VIEW v_solicitacoes_detalhadas AS
SELECT 
    s.id,
    s.criador_id,
    s.beneficiario_tipo,
    s.valor,
    s.data_desejada,
    s.observacoes,
    s.status,
    s.status_atualizado_em,
    s.origem,
    s.criado_em,
    s.pix_tipo,
    s.pix_chave,
    s.data_pagamento,
    s.observacao_pagamento,
    -- Dados do freelancer
    f.nome_completo as freelancer_nome,
    f.cpf as freelancer_cpf,
    -- Dados do fornecedor
    fo.nome as fornecedor_nome,
    fo.cnpj as fornecedor_cnpj,
    -- Dados do criador
    u.nome as criador_nome,
    -- Dados do atualizador
    u2.nome as atualizador_nome
FROM lc_solicitacoes_pagamento s
LEFT JOIN lc_freelancers f ON f.id = s.freelancer_id
LEFT JOIN fornecedores fo ON fo.id = s.fornecedor_id
LEFT JOIN usuarios u ON u.id = s.criador_id
LEFT JOIN usuarios u2 ON u2.id = s.status_atualizado_por;

-- 15. Comentários para documentação
COMMENT ON TABLE lc_freelancers IS 'Diretório de freelancers para pagamentos';
COMMENT ON TABLE lc_solicitacoes_pagamento IS 'Solicitações de pagamento do sistema';
COMMENT ON TABLE lc_timeline_pagamentos IS 'Timeline/histórico de eventos das solicitações';
COMMENT ON FUNCTION lc_gerar_token_publico() IS 'Gera token público único para fornecedores';
COMMENT ON FUNCTION lc_criar_solicitacao_pagamento(INT, VARCHAR(20), INT, INT, NUMERIC(14,2), DATE, TEXT, VARCHAR(20), VARCHAR(100), VARCHAR(50), INET, TEXT) IS 'Cria nova solicitação de pagamento';
COMMENT ON FUNCTION lc_alterar_status_solicitacao(INT, VARCHAR(20), INT, TEXT, DATE, TEXT) IS 'Altera status da solicitação e registra na timeline';

-- 16. Verificar se todas as tabelas foram criadas
DO $$
DECLARE
    tabela_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO tabela_count 
    FROM information_schema.tables 
    WHERE table_name IN ('lc_freelancers', 'lc_solicitacoes_pagamento', 'lc_timeline_pagamentos');
    
    RAISE NOTICE 'Tabelas de pagamentos criadas: %', tabela_count;
END $$;
