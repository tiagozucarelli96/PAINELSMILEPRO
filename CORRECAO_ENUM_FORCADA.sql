-- =====================================================
-- CORREÇÃO FORÇADA - PROBLEMA DO ENUM eventos_status
-- Execute este script para forçar a correção do ENUM
-- =====================================================

-- 1. FORÇAR REMOÇÃO DO ENUM eventos_status
-- =====================================================

-- Primeiro, verificar se existe o ENUM
SELECT 'Verificando ENUM eventos_status...' as status;
SELECT typname, typtype 
FROM pg_type 
WHERE typname = 'eventos_status';

-- Forçar remoção do ENUM se existir
DO $$
BEGIN
    -- Verificar se existe o ENUM eventos_status
    IF EXISTS (SELECT 1 FROM pg_type WHERE typname = 'eventos_status') THEN
        -- Primeiro, alterar a coluna para VARCHAR
        ALTER TABLE eventos ALTER COLUMN status TYPE VARCHAR(20);
        
        -- Depois, remover o ENUM
        DROP TYPE eventos_status CASCADE;
        
        RAISE NOTICE 'ENUM eventos_status removido com sucesso';
    ELSE
        RAISE NOTICE 'ENUM eventos_status não existe';
    END IF;
END $$;

-- 2. VERIFICAR ESTRUTURA ATUAL DA COLUNA STATUS
-- =====================================================

-- Verificar tipo da coluna status
SELECT 'Verificando estrutura da coluna status...' as status;
SELECT column_name, data_type, is_nullable, column_default
FROM information_schema.columns 
WHERE table_name = 'eventos' AND column_name = 'status';

-- 3. RECRIAR FUNÇÕES COM LÓGICA CORRIGIDA
-- =====================================================

-- Função obter_proximos_eventos (CORRIGIDA)
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

-- Função obter_eventos_hoje (CORRIGIDA)
CREATE OR REPLACE FUNCTION obter_eventos_hoje(p_usuario_id INTEGER)
RETURNS TABLE (
    id INTEGER,
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

-- Função obter_eventos_semana (CORRIGIDA)
CREATE OR REPLACE FUNCTION obter_eventos_semana(p_usuario_id INTEGER)
RETURNS TABLE (
    id INTEGER,
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

-- 4. TESTAR FUNÇÕES CORRIGIDAS
-- =====================================================

-- Testar função obter_proximos_eventos
SELECT 'Testando função obter_proximos_eventos...' as status;
SELECT * FROM obter_proximos_eventos(1, 24) LIMIT 5;

-- Testar função obter_eventos_hoje
SELECT 'Testando função obter_eventos_hoje...' as status;
SELECT * FROM obter_eventos_hoje(1) LIMIT 5;

-- Testar função obter_eventos_semana
SELECT 'Testando função obter_eventos_semana...' as status;
SELECT * FROM obter_eventos_semana(1) LIMIT 5;

-- 5. VERIFICAR SE ENUM AINDA EXISTE
-- =====================================================

-- Verificar se ENUM ainda existe
SELECT 'Verificando se ENUM ainda existe...' as status;
SELECT typname, typtype 
FROM pg_type 
WHERE typname = 'eventos_status';

-- Verificar tipo da coluna status
SELECT 'Verificando tipo da coluna status...' as status;
SELECT column_name, data_type, is_nullable, column_default
FROM information_schema.columns 
WHERE table_name = 'eventos' AND column_name = 'status';

-- =====================================================
-- CORREÇÃO FORÇADA FINALIZADA!
-- =====================================================

SELECT '🎉 CORREÇÃO FORÇADA FINALIZADA!' as status;
SELECT 'ENUM eventos_status foi removido forçadamente.' as resultado;
SELECT 'As funções agora funcionam com VARCHAR(20) em vez de ENUM.' as conclusao;
