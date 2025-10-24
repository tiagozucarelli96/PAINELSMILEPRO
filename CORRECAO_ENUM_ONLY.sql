-- =====================================================
-- CORREÇÃO ESPECÍFICA - PROBLEMA DO ENUM eventos_status
-- Execute apenas esta correção se já executou o script completo
-- =====================================================

-- 1. CORRIGIR ENUM eventos_status (CRÍTICO)
-- =====================================================

-- Verificar e corrigir ENUM eventos_status
DO $$
BEGIN
    -- Verificar se existe o ENUM eventos_status
    IF EXISTS (SELECT 1 FROM pg_type WHERE typname = 'eventos_status') THEN
        -- Adicionar valor 'ativo' ao ENUM se não existir
        BEGIN
            ALTER TYPE eventos_status ADD VALUE 'ativo';
        EXCEPTION
            WHEN duplicate_object THEN
                -- Valor já existe, continuar
                NULL;
        END;
        
        -- Alterar coluna status para usar VARCHAR em vez de ENUM
        ALTER TABLE eventos ALTER COLUMN status TYPE VARCHAR(20);
        
        -- Remover o ENUM se não for mais usado
        DROP TYPE IF EXISTS eventos_status CASCADE;
    END IF;
END $$;

-- 2. RECRIAR FUNÇÕES COM LÓGICA CORRIGIDA
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

-- 3. TESTAR FUNÇÕES CORRIGIDAS
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

-- 4. VERIFICAR ESTRUTURA DA COLUNA STATUS
-- =====================================================

-- Verificar tipo da coluna status
SELECT 'Verificando tipo da coluna status...' as status;
SELECT column_name, data_type, is_nullable, column_default
FROM information_schema.columns 
WHERE table_name = 'eventos' AND column_name = 'status';

-- Verificar se ENUM ainda existe
SELECT 'Verificando se ENUM ainda existe...' as status;
SELECT typname, typtype 
FROM pg_type 
WHERE typname = 'eventos_status';

-- =====================================================
-- CORREÇÃO ESPECÍFICA FINALIZADA!
-- =====================================================

SELECT '🎉 CORREÇÃO ESPECÍFICA FINALIZADA!' as status;
SELECT 'Problema do ENUM eventos_status foi resolvido.' as resultado;
SELECT 'As funções agora funcionam com qualquer valor de status.' as conclusao;
