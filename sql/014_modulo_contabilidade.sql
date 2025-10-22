-- 014_modulo_contabilidade.sql
-- Módulo Contabilidade - Portal para escritório + central interna

-- 1. Tabela de documentos contábeis
CREATE TABLE IF NOT EXISTS contab_documentos (
    id SERIAL PRIMARY KEY,
    tipo VARCHAR(50) NOT NULL, -- 'imposto', 'guia', 'honorario', 'parcelamento'
    descricao VARCHAR(255) NOT NULL,
    competencia VARCHAR(7) NOT NULL, -- YYYY-MM
    origem VARCHAR(20) NOT NULL DEFAULT 'interno' CHECK (origem IN ('portal_contab', 'interno')),
    fornecedor_sugerido VARCHAR(255),
    criado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- 2. Tabela de parcelas dos documentos
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

-- 3. Tabela de anexos contábeis
CREATE TABLE IF NOT EXISTS contab_anexos (
    id SERIAL PRIMARY KEY,
    documento_id INT REFERENCES contab_documentos(id) ON DELETE CASCADE,
    parcela_id INT REFERENCES contab_parcelas(id) ON DELETE CASCADE,
    tipo_anexo VARCHAR(50) NOT NULL, -- 'boleto', 'guia', 'comprovante'
    nome_original VARCHAR(255) NOT NULL,
    nome_arquivo VARCHAR(255) NOT NULL,
    caminho_arquivo VARCHAR(500) NOT NULL,
    tipo_mime VARCHAR(100) NOT NULL,
    tamanho_bytes BIGINT NOT NULL,
    autor_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- 4. Tabela de tokens do portal contábil
CREATE TABLE IF NOT EXISTS contab_tokens (
    id SERIAL PRIMARY KEY,
    token VARCHAR(64) UNIQUE NOT NULL,
    descricao VARCHAR(255) NOT NULL,
    ativo BOOLEAN DEFAULT TRUE,
    criado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    ultimo_uso TIMESTAMP
);

-- 5. Tabela de rate limiting do portal
CREATE TABLE IF NOT EXISTS contab_rate_limit (
    id SERIAL PRIMARY KEY,
    ip_origem INET NOT NULL,
    token_contab VARCHAR(64),
    uploads_na_hora INT NOT NULL DEFAULT 1,
    janela_inicio TIMESTAMP NOT NULL DEFAULT NOW(),
    criado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- 6. Índices para performance
CREATE INDEX IF NOT EXISTS idx_contab_documentos_competencia ON contab_documentos(competencia);
CREATE INDEX IF NOT EXISTS idx_contab_documentos_origem ON contab_documentos(origem);
CREATE INDEX IF NOT EXISTS idx_contab_parcelas_documento ON contab_parcelas(documento_id);
CREATE INDEX IF NOT EXISTS idx_contab_parcelas_vencimento ON contab_parcelas(vencimento);
CREATE INDEX IF NOT EXISTS idx_contab_parcelas_status ON contab_parcelas(status);
CREATE INDEX IF NOT EXISTS idx_contab_anexos_documento ON contab_anexos(documento_id);
CREATE INDEX IF NOT EXISTS idx_contab_anexos_parcela ON contab_anexos(parcela_id);
CREATE INDEX IF NOT EXISTS idx_contab_tokens_token ON contab_tokens(token);
CREATE INDEX IF NOT EXISTS idx_contab_rate_limit_ip ON contab_rate_limit(ip_origem);

-- 7. Função para obter estatísticas do dashboard
CREATE OR REPLACE FUNCTION contab_estatisticas_dashboard()
RETURNS TABLE (
    boletos_pendentes BIGINT,
    valor_pendente NUMERIC(12,2),
    vencem_hoje BIGINT,
    vencem_48h BIGINT,
    vencem_7d BIGINT,
    total_mes NUMERIC(12,2)
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        (SELECT COUNT(*) FROM contab_parcelas WHERE status = 'pendente') as boletos_pendentes,
        (SELECT COALESCE(SUM(valor), 0) FROM contab_parcelas WHERE status = 'pendente') as valor_pendente,
        (SELECT COUNT(*) FROM contab_parcelas WHERE status = 'pendente' AND vencimento = CURRENT_DATE) as vencem_hoje,
        (SELECT COUNT(*) FROM contab_parcelas WHERE status = 'pendente' AND vencimento BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '2 days') as vencem_48h,
        (SELECT COUNT(*) FROM contab_parcelas WHERE status = 'pendente' AND vencimento BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '7 days') as vencem_7d,
        (SELECT COALESCE(SUM(valor), 0) FROM contab_parcelas WHERE status = 'pago' AND DATE_TRUNC('month', pago_em) = DATE_TRUNC('month', NOW())) as total_mes;
END;
$$ LANGUAGE plpgsql;

-- 8. Função para verificar rate limit
CREATE OR REPLACE FUNCTION contab_verificar_rate_limit(
    p_ip_origem INET,
    p_token_contab VARCHAR(64) DEFAULT NULL
) RETURNS BOOLEAN AS $$
DECLARE
    uploads_count INTEGER;
BEGIN
    -- Limpar registros antigos (mais de 1 hora)
    DELETE FROM contab_rate_limit 
    WHERE janela_inicio < NOW() - INTERVAL '1 hour';
    
    -- Contar uploads na última hora
    SELECT COUNT(*) INTO uploads_count
    FROM contab_rate_limit 
    WHERE ip_origem = p_ip_origem 
    AND janela_inicio > NOW() - INTERVAL '1 hour';
    
    -- Limite: 10 uploads por hora por IP
    IF uploads_count >= 10 THEN
        RETURN FALSE;
    END IF;
    
    -- Registrar este upload
    INSERT INTO contab_rate_limit (ip_origem, token_contab)
    VALUES (p_ip_origem, p_token_contab);
    
    RETURN TRUE;
END;
$$ LANGUAGE plpgsql;

-- 9. Função para gerar token único
CREATE OR REPLACE FUNCTION contab_gerar_token()
RETURNS VARCHAR(64) AS $$
BEGIN
    RETURN REPLACE(gen_random_uuid()::text, '-', '');
END;
$$ LANGUAGE plpgsql;

-- 10. Triggers para atualizar updated_at
CREATE OR REPLACE FUNCTION contab_update_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.atualizado_em = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_contab_documentos_updated_at ON contab_documentos;
CREATE TRIGGER trg_contab_documentos_updated_at
BEFORE UPDATE ON contab_documentos
FOR EACH ROW
EXECUTE FUNCTION contab_update_updated_at();

DROP TRIGGER IF EXISTS trg_contab_parcelas_updated_at ON contab_parcelas;
CREATE TRIGGER trg_contab_parcelas_updated_at
BEFORE UPDATE ON contab_parcelas
FOR EACH ROW
EXECUTE FUNCTION contab_update_updated_at();

-- 11. Inserir token padrão para contabilidade
INSERT INTO contab_tokens (token, descricao, ativo)
SELECT contab_gerar_token(), 'Token padrão para contabilidade', TRUE
WHERE NOT EXISTS (SELECT 1 FROM contab_tokens WHERE ativo = TRUE);

-- 12. Comentários para documentação
COMMENT ON TABLE contab_documentos IS 'Documentos contábeis (impostos, guias, etc.)';
COMMENT ON TABLE contab_parcelas IS 'Parcelas dos documentos contábeis';
COMMENT ON TABLE contab_anexos IS 'Anexos dos documentos contábeis';
COMMENT ON TABLE contab_tokens IS 'Tokens de acesso ao portal contábil';
COMMENT ON FUNCTION contab_estatisticas_dashboard() IS 'Estatísticas do dashboard contábil';
COMMENT ON FUNCTION contab_verificar_rate_limit(INET, VARCHAR(64)) IS 'Verificar rate limit do portal contábil';

-- 13. Verificar se as tabelas foram criadas
DO $$
DECLARE
    tabela_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO tabela_count 
    FROM information_schema.tables 
    WHERE table_name IN ('contab_documentos', 'contab_parcelas', 'contab_anexos', 'contab_tokens');
    
    RAISE NOTICE 'Tabelas do módulo Contabilidade criadas: %', tabela_count;
END $$;
