-- 012_sistema_anexos.sql
-- Sistema completo de anexos para solicitações de pagamento

-- 1. Tabela de anexos
CREATE TABLE IF NOT EXISTS lc_anexos_pagamentos (
    id SERIAL PRIMARY KEY,
    solicitacao_id INT NOT NULL REFERENCES lc_solicitacoes_pagamento(id) ON DELETE CASCADE,
    nome_original VARCHAR(255) NOT NULL,
    nome_arquivo VARCHAR(255) NOT NULL, -- UUID.ext
    caminho_arquivo VARCHAR(500) NOT NULL,
    tipo_mime VARCHAR(100) NOT NULL,
    tamanho_bytes BIGINT NOT NULL,
    eh_comprovante BOOLEAN NOT NULL DEFAULT false, -- true = comprovante do financeiro
    autor_id INT, -- NULL para portal público
    autor_tipo VARCHAR(20) DEFAULT 'interno' CHECK (autor_tipo IN ('interno', 'portal')),
    ip_origem INET,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    modificado_em TIMESTAMP
);

-- 2. Tabela de miniaturas (para imagens)
CREATE TABLE IF NOT EXISTS lc_anexos_miniaturas (
    id SERIAL PRIMARY KEY,
    anexo_id INT NOT NULL REFERENCES lc_anexos_pagamentos(id) ON DELETE CASCADE,
    caminho_miniatura VARCHAR(500) NOT NULL,
    largura INT NOT NULL,
    altura INT NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- 3. Tabela de logs de download
CREATE TABLE IF NOT EXISTS lc_anexos_logs_download (
    id SERIAL PRIMARY KEY,
    anexo_id INT NOT NULL REFERENCES lc_anexos_pagamentos(id) ON DELETE CASCADE,
    usuario_id INT,
    ip_origem INET NOT NULL,
    user_agent TEXT,
    baixado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- 4. Tabela de rate limiting (portal público)
CREATE TABLE IF NOT EXISTS lc_anexos_rate_limit (
    id SERIAL PRIMARY KEY,
    ip_origem INET NOT NULL,
    token_publico VARCHAR(64),
    solicitacao_id INT,
    uploads_na_hora INT NOT NULL DEFAULT 1,
    janela_inicio TIMESTAMP NOT NULL DEFAULT NOW(),
    criado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- 5. Índices para performance
CREATE INDEX IF NOT EXISTS idx_anexos_solicitacao ON lc_anexos_pagamentos(solicitacao_id);
CREATE INDEX IF NOT EXISTS idx_anexos_autor ON lc_anexos_pagamentos(autor_id);
CREATE INDEX IF NOT EXISTS idx_anexos_comprovante ON lc_anexos_pagamentos(eh_comprovante);
CREATE INDEX IF NOT EXISTS idx_anexos_criado_em ON lc_anexos_pagamentos(criado_em);
CREATE INDEX IF NOT EXISTS idx_miniaturas_anexo ON lc_anexos_miniaturas(anexo_id);
CREATE INDEX IF NOT EXISTS idx_logs_download_anexo ON lc_anexos_logs_download(anexo_id);
CREATE INDEX IF NOT EXISTS idx_logs_download_usuario ON lc_anexos_logs_download(usuario_id);
CREATE INDEX IF NOT EXISTS idx_rate_limit_ip ON lc_anexos_rate_limit(ip_origem);
CREATE INDEX IF NOT EXISTS idx_rate_limit_token ON lc_anexos_rate_limit(token_publico);
CREATE INDEX IF NOT EXISTS idx_rate_limit_janela ON lc_anexos_rate_limit(janela_inicio);

-- 6. Função para verificar rate limit
CREATE OR REPLACE FUNCTION lc_verificar_rate_limit_anexos(
    p_ip_origem INET,
    p_token_publico VARCHAR(64) DEFAULT NULL,
    p_solicitacao_id INT DEFAULT NULL
) RETURNS BOOLEAN AS $$
DECLARE
    uploads_count INTEGER;
BEGIN
    -- Limpar registros antigos (mais de 1 hora)
    DELETE FROM lc_anexos_rate_limit 
    WHERE janela_inicio < NOW() - INTERVAL '1 hour';
    
    -- Contar uploads na última hora
    SELECT COUNT(*) INTO uploads_count
    FROM lc_anexos_rate_limit 
    WHERE ip_origem = p_ip_origem 
    AND janela_inicio > NOW() - INTERVAL '1 hour';
    
    -- Limite: 5 uploads por hora por IP
    IF uploads_count >= 5 THEN
        RETURN FALSE;
    END IF;
    
    -- Registrar este upload
    INSERT INTO lc_anexos_rate_limit (ip_origem, token_publico, solicitacao_id)
    VALUES (p_ip_origem, p_token_publico, p_solicitacao_id);
    
    RETURN TRUE;
END;
$$ LANGUAGE plpgsql;

-- 7. Função para registrar download
CREATE OR REPLACE FUNCTION lc_registrar_download_anexo(
    p_anexo_id INT,
    p_usuario_id INT DEFAULT NULL,
    p_ip_origem INET,
    p_user_agent TEXT DEFAULT NULL
) RETURNS INT AS $$
DECLARE
    log_id INT;
BEGIN
    INSERT INTO lc_anexos_logs_download (anexo_id, usuario_id, ip_origem, user_agent)
    VALUES (p_anexo_id, p_usuario_id, p_ip_origem, p_user_agent)
    RETURNING id INTO log_id;
    
    RETURN log_id;
END;
$$ LANGUAGE plpgsql;

-- 8. Função para obter estatísticas de anexos
CREATE OR REPLACE FUNCTION lc_estatisticas_anexos_solicitacao(p_solicitacao_id INT) RETURNS TABLE (
    total_anexos BIGINT,
    total_comprovantes BIGINT,
    tamanho_total BIGINT,
    tipos_arquivos TEXT[]
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        COUNT(*) as total_anexos,
        COUNT(*) FILTER (WHERE eh_comprovante = true) as total_comprovantes,
        COALESCE(SUM(tamanho_bytes), 0) as tamanho_total,
        ARRAY_AGG(DISTINCT tipo_mime) as tipos_arquivos
    FROM lc_anexos_pagamentos 
    WHERE solicitacao_id = p_solicitacao_id;
END;
$$ LANGUAGE plpgsql;

-- 9. Função para validar tipo de arquivo
CREATE OR REPLACE FUNCTION lc_validar_tipo_arquivo(
    p_nome_arquivo VARCHAR(255),
    p_tipo_mime VARCHAR(100)
) RETURNS BOOLEAN AS $$
DECLARE
    extensao VARCHAR(10);
    tipos_permitidos TEXT[] := ARRAY['pdf', 'jpg', 'jpeg', 'png'];
    mimes_permitidos TEXT[] := ARRAY[
        'application/pdf',
        'image/jpeg',
        'image/jpg', 
        'image/png'
    ];
BEGIN
    -- Extrair extensão
    extensao := LOWER(SUBSTRING(p_nome_arquivo FROM '\.([^.]*)$'));
    
    -- Verificar extensão
    IF NOT (extensao = ANY(tipos_permitidos)) THEN
        RETURN FALSE;
    END IF;
    
    -- Verificar MIME type
    IF NOT (p_tipo_mime = ANY(mimes_permitidos)) THEN
        RETURN FALSE;
    END IF;
    
    RETURN TRUE;
END;
$$ LANGUAGE plpgsql;

-- 10. View para anexos com detalhes
CREATE OR REPLACE VIEW v_anexos_detalhados AS
SELECT 
    a.id,
    a.solicitacao_id,
    a.nome_original,
    a.nome_arquivo,
    a.caminho_arquivo,
    a.tipo_mime,
    a.tamanho_bytes,
    a.eh_comprovante,
    a.autor_tipo,
    a.criado_em,
    -- Dados do autor
    u.nome as autor_nome,
    -- Dados da solicitação
    s.beneficiario_tipo,
    s.status,
    -- Miniaturas
    m.caminho_miniatura,
    m.largura,
    m.altura,
    -- Estatísticas
    CASE 
        WHEN a.tamanho_bytes < 1024 THEN a.tamanho_bytes::TEXT || ' B'
        WHEN a.tamanho_bytes < 1048576 THEN ROUND(a.tamanho_bytes/1024.0, 1)::TEXT || ' KB'
        ELSE ROUND(a.tamanho_bytes/1048576.0, 1)::TEXT || ' MB'
    END as tamanho_formatado,
    -- Downloads
    COUNT(d.id) as total_downloads
FROM lc_anexos_pagamentos a
LEFT JOIN usuarios u ON u.id = a.autor_id
LEFT JOIN lc_solicitacoes_pagamento s ON s.id = a.solicitacao_id
LEFT JOIN lc_anexos_miniaturas m ON m.anexo_id = a.id
LEFT JOIN lc_anexos_logs_download d ON d.anexo_id = a.id
GROUP BY a.id, a.solicitacao_id, a.nome_original, a.nome_arquivo, a.caminho_arquivo, 
         a.tipo_mime, a.tamanho_bytes, a.eh_comprovante, a.autor_tipo, a.criado_em,
         u.nome, s.beneficiario_tipo, s.status, m.caminho_miniatura, m.largura, m.altura;

-- 11. Comentários para documentação
COMMENT ON TABLE lc_anexos_pagamentos IS 'Anexos das solicitações de pagamento';
COMMENT ON TABLE lc_anexos_miniaturas IS 'Miniaturas de imagens dos anexos';
COMMENT ON TABLE lc_anexos_logs_download IS 'Logs de download dos anexos';
COMMENT ON TABLE lc_anexos_rate_limit IS 'Controle de rate limiting para uploads';
COMMENT ON FUNCTION lc_verificar_rate_limit_anexos(INET, VARCHAR(64), INT) IS 'Verifica rate limit de uploads';
COMMENT ON FUNCTION lc_registrar_download_anexo(INT, INT, INET, TEXT) IS 'Registra download de anexo';
COMMENT ON FUNCTION lc_estatisticas_anexos_solicitacao(INT) IS 'Estatísticas de anexos de uma solicitação';
COMMENT ON FUNCTION lc_validar_tipo_arquivo(VARCHAR(255), VARCHAR(100)) IS 'Valida tipo de arquivo por extensão e MIME';

-- 12. Verificar se todas as tabelas foram criadas
DO $$
DECLARE
    tabela_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO tabela_count 
    FROM information_schema.tables 
    WHERE table_name IN ('lc_anexos_pagamentos', 'lc_anexos_miniaturas', 'lc_anexos_logs_download', 'lc_anexos_rate_limit');
    
    RAISE NOTICE 'Tabelas de anexos criadas: %', tabela_count;
END $$;
