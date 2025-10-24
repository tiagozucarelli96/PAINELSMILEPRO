-- =====================================================
-- CORREÇÃO SIMPLES - APENAS O PROBLEMA DO ENUM
-- Script rápido e direto
-- =====================================================

-- 1. REMOVER ENUM eventos_status (SIMPLES)
-- =====================================================

-- Forçar remoção do ENUM
DROP TYPE IF EXISTS eventos_status CASCADE;

-- 2. ALTERAR COLUNA STATUS PARA VARCHAR
-- =====================================================

-- Alterar coluna status para VARCHAR
ALTER TABLE eventos ALTER COLUMN status TYPE VARCHAR(20);

-- 3. RECRIAR APENAS A FUNÇÃO PRINCIPAL
-- =====================================================

-- Função obter_proximos_eventos (SIMPLES)
CREATE OR REPLACE FUNCTION obter_proximos_eventos(
    p_usuario_id INTEGER,
    p_horas INTEGER DEFAULT 24
)
RETURNS TABLE (
    id INTEGER,
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

-- 4. TESTAR FUNÇÃO
-- =====================================================

-- Testar função obter_proximos_eventos
SELECT * FROM obter_proximos_eventos(1, 24) LIMIT 3;

-- =====================================================
-- CORREÇÃO SIMPLES FINALIZADA!
-- =====================================================

SELECT '🎉 CORREÇÃO SIMPLES FINALIZADA!' as status;
