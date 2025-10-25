-- Migração: Funções PostgreSQL
-- Data: 2025-10-25 00:36:35


-- Função para buscar fornecedores ativos
CREATE OR REPLACE FUNCTION lc_buscar_fornecedores_ativos()
RETURNS TABLE (
    id INTEGER,
    nome VARCHAR(200),
    cnpj VARCHAR(20),
    telefone VARCHAR(20),
    email VARCHAR(200)
) AS $$
BEGIN
    RETURN QUERY
    SELECT f.id, f.nome, f.cnpj, f.telefone, f.email
    FROM fornecedores f
    WHERE f.ativo = TRUE
    ORDER BY f.nome;
END;
$$ LANGUAGE plpgsql;

-- Função para buscar freelancers ativos
CREATE OR REPLACE FUNCTION lc_buscar_freelancers_ativos()
RETURNS TABLE (
    id INTEGER,
    nome_completo VARCHAR(200),
    cpf VARCHAR(14),
    pix_tipo VARCHAR(20),
    pix_chave VARCHAR(200)
) AS $$
BEGIN
    RETURN QUERY
    SELECT fl.id, fl.nome_completo, fl.cpf, fl.pix_tipo, fl.pix_chave
    FROM lc_freelancers fl
    WHERE fl.ativo = TRUE
    ORDER BY fl.nome_completo;
END;
$$ LANGUAGE plpgsql;

-- Função para gerar token público
CREATE OR REPLACE FUNCTION lc_gerar_token_publico()
RETURNS VARCHAR(100) AS $$
BEGIN
    RETURN 'pub_' || substr(md5(random()::text), 1, 32);
END;
$$ LANGUAGE plpgsql;

-- Função para estatísticas do RH
CREATE OR REPLACE FUNCTION rh_estatisticas_dashboard()
RETURNS TABLE (
    total_colaboradores INTEGER,
    total_holerites INTEGER,
    holerites_este_mes INTEGER,
    valor_total_pago DECIMAL(10,2)
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        (SELECT COUNT(*)::INTEGER FROM usuarios WHERE ativo = TRUE) as total_colaboradores,
        (SELECT COUNT(*)::INTEGER FROM rh_holerites) as total_holerites,
        (SELECT COUNT(*)::INTEGER FROM rh_holerites WHERE mes_competencia = to_char(CURRENT_DATE, 'YYYY-MM')) as holerites_este_mes,
        (SELECT COALESCE(SUM(valor_liquido), 0) FROM rh_holerites) as valor_total_pago;
END;
$$ LANGUAGE plpgsql;

-- Função para estatísticas da Contabilidade
CREATE OR REPLACE FUNCTION contab_estatisticas_dashboard()
RETURNS TABLE (
    total_documentos INTEGER,
    documentos_pendentes INTEGER,
    valor_total_pendente DECIMAL(10,2),
    valor_total_pago DECIMAL(10,2)
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        (SELECT COUNT(*)::INTEGER FROM contab_documentos) as total_documentos,
        (SELECT COUNT(*)::INTEGER FROM contab_documentos WHERE status = 'pendente') as documentos_pendentes,
        (SELECT COALESCE(SUM(valor), 0) FROM contab_documentos WHERE status = 'pendente') as valor_total_pendente,
        (SELECT COALESCE(SUM(valor), 0) FROM contab_documentos WHERE status = 'pago') as valor_total_pago;
END;
$$ LANGUAGE plpgsql;
