-- =====================================================
-- CORREÇÃO DE TIPOS DE DADOS - FUNÇÃO obter_proximos_eventos
-- Script para corrigir tipos de dados
-- =====================================================

-- 1. RECRIAR FUNÇÃO COM TIPOS CORRETOS
-- =====================================================

-- Função obter_proximos_eventos (TIPOS CORRETOS)
CREATE OR REPLACE FUNCTION obter_proximos_eventos(
    p_usuario_id INTEGER,
    p_horas INTEGER DEFAULT 24
)
RETURNS TABLE (
    id BIGINT,
    titulo VARCHAR(255),
    descricao TEXT,
    data_inicio TIMESTAMP,
    data_fim TIMESTAMP,
    local VARCHAR(255),
    status VARCHAR(20),
    observacoes TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
)
LANGUAGE plpgsql
AS $$
BEGIN
    RETURN QUERY
    SELECT 
        e.id,
        e.titulo,
        COALESCE(e.descricao, '') as descricao,
        e.data_inicio,
        e.data_fim,
        COALESCE(e.local, '') as local,
        COALESCE(e.status, 'ativo') as status,
        COALESCE(e.observacoes, '') as observacoes,
        e.created_at,
        e.updated_at
    FROM eventos e
    WHERE 
        e.data_inicio >= NOW()
        AND e.data_inicio <= NOW() + INTERVAL '1 hour' * p_horas
        AND (e.status = 'ativo' OR e.status IS NULL OR e.status = '')
    ORDER BY e.data_inicio ASC;
END;
$$;

-- 2. RECRIAR OUTRAS FUNÇÕES COM TIPOS CORRETOS
-- =====================================================

-- Função obter_eventos_hoje (TIPOS CORRETOS)
CREATE OR REPLACE FUNCTION obter_eventos_hoje(p_usuario_id INTEGER)
RETURNS TABLE (
    id BIGINT,
    titulo VARCHAR(255),
    data_inicio TIMESTAMP,
    data_fim TIMESTAMP,
    local VARCHAR(255)
)
LANGUAGE plpgsql
AS $$
BEGIN
    RETURN QUERY
    SELECT 
        e.id,
        e.titulo,
        e.data_inicio,
        e.data_fim,
        COALESCE(e.local, '') as local
    FROM eventos e
    WHERE 
        DATE(e.data_inicio) = CURRENT_DATE
        AND (e.status = 'ativo' OR e.status IS NULL OR e.status = '')
    ORDER BY e.data_inicio ASC;
END;
$$;

-- Função obter_eventos_semana (TIPOS CORRETOS)
CREATE OR REPLACE FUNCTION obter_eventos_semana(p_usuario_id INTEGER)
RETURNS TABLE (
    id BIGINT,
    titulo VARCHAR(255),
    data_inicio TIMESTAMP,
    data_fim TIMESTAMP,
    local VARCHAR(255)
)
LANGUAGE plpgsql
AS $$
BEGIN
    RETURN QUERY
    SELECT 
        e.id,
        e.titulo,
        e.data_inicio,
        e.data_fim,
        COALESCE(e.local, '') as local
    FROM eventos e
    WHERE 
        e.data_inicio >= CURRENT_DATE
        AND e.data_inicio <= CURRENT_DATE + INTERVAL '7 days'
        AND (e.status = 'ativo' OR e.status IS NULL OR e.status = '')
    ORDER BY e.data_inicio ASC;
END;
$$;

-- 3. TESTAR FUNÇÕES
-- =====================================================

-- Testar função obter_proximos_eventos
SELECT 'Testando função obter_proximos_eventos...' as status;
SELECT * FROM obter_proximos_eventos(1, 24) LIMIT 3;

-- Testar função obter_eventos_hoje
SELECT 'Testando função obter_eventos_hoje...' as status;
SELECT * FROM obter_eventos_hoje(1) LIMIT 3;

-- Testar função obter_eventos_semana
SELECT 'Testando função obter_eventos_semana...' as status;
SELECT * FROM obter_eventos_semana(1) LIMIT 3;

-- =====================================================
-- CORREÇÃO DE TIPOS FINALIZADA!
-- =====================================================

SELECT '🎉 CORREÇÃO DE TIPOS FINALIZADA!' as status;
SELECT 'Todas as funções agora usam BIGINT para o campo id.' as resultado;
