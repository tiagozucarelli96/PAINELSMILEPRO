-- =====================================================
-- CORREÇÃO ESTRUTURA FALTANTE - BASEADO NA VERIFICAÇÃO
-- Script para corrigir o que está faltando no banco
-- =====================================================

-- 1. CRIAR TABELAS DE AGENDA FALTANTES
-- =====================================================

-- Tabela agenda_espacos
CREATE TABLE IF NOT EXISTS agenda_espacos (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    descricao TEXT,
    capacidade INT DEFAULT 0,
    ativo BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela agenda_eventos
CREATE TABLE IF NOT EXISTS agenda_eventos (
    id SERIAL PRIMARY KEY,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT,
    data_inicio TIMESTAMP NOT NULL,
    data_fim TIMESTAMP NOT NULL,
    espaco_id INT REFERENCES agenda_espacos(id) ON DELETE SET NULL,
    usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    tipo VARCHAR(50) DEFAULT 'evento' CHECK (tipo IN ('evento', 'visita', 'bloqueio')),
    cor VARCHAR(7),
    observacoes TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- 2. VERIFICAR E CORRIGIR TABELA EVENTOS
-- =====================================================

-- Adicionar colunas faltantes na tabela eventos
DO $$
BEGIN
    -- Adicionar descricao se não existir
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'eventos' AND column_name = 'descricao') THEN
        ALTER TABLE eventos ADD COLUMN descricao TEXT;
    END IF;
    
    -- Adicionar data_inicio se não existir
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'eventos' AND column_name = 'data_inicio') THEN
        ALTER TABLE eventos ADD COLUMN data_inicio TIMESTAMP;
    END IF;
    
    -- Adicionar data_fim se não existir
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'eventos' AND column_name = 'data_fim') THEN
        ALTER TABLE eventos ADD COLUMN data_fim TIMESTAMP;
    END IF;
    
    -- Adicionar local se não existir
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'eventos' AND column_name = 'local') THEN
        ALTER TABLE eventos ADD COLUMN local VARCHAR(255);
    END IF;
    
    -- Adicionar status se não existir
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'eventos' AND column_name = 'status') THEN
        ALTER TABLE eventos ADD COLUMN status VARCHAR(20) DEFAULT 'ativo';
    END IF;
    
    -- Adicionar observacoes se não existir
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'eventos' AND column_name = 'observacoes') THEN
        ALTER TABLE eventos ADD COLUMN observacoes TEXT;
    END IF;
    
    -- Adicionar created_at se não existir
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'eventos' AND column_name = 'created_at') THEN
        ALTER TABLE eventos ADD COLUMN created_at TIMESTAMP DEFAULT NOW();
    END IF;
    
    -- Adicionar updated_at se não existir
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'eventos' AND column_name = 'updated_at') THEN
        ALTER TABLE eventos ADD COLUMN updated_at TIMESTAMP DEFAULT NOW();
    END IF;
END $$;

-- 3. ADICIONAR COLUNAS DE PERMISSÃO FALTANTES
-- =====================================================

-- Adicionar todas as colunas de permissão necessárias
DO $$
BEGIN
    -- Permissões de agenda
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_agenda_ver') THEN
        ALTER TABLE usuarios ADD COLUMN perm_agenda_ver BOOLEAN DEFAULT false;
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_agenda_editar') THEN
        ALTER TABLE usuarios ADD COLUMN perm_agenda_editar BOOLEAN DEFAULT false;
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_agenda_criar') THEN
        ALTER TABLE usuarios ADD COLUMN perm_agenda_criar BOOLEAN DEFAULT false;
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_agenda_excluir') THEN
        ALTER TABLE usuarios ADD COLUMN perm_agenda_excluir BOOLEAN DEFAULT false;
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_agenda_relatorios') THEN
        ALTER TABLE usuarios ADD COLUMN perm_agenda_relatorios BOOLEAN DEFAULT false;
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_agenda_meus') THEN
        ALTER TABLE usuarios ADD COLUMN perm_agenda_meus BOOLEAN DEFAULT false;
    END IF;
    
    -- Permissões de demandas
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_demandas_ver') THEN
        ALTER TABLE usuarios ADD COLUMN perm_demandas_ver BOOLEAN DEFAULT false;
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_demandas_editar') THEN
        ALTER TABLE usuarios ADD COLUMN perm_demandas_editar BOOLEAN DEFAULT false;
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_demandas_criar') THEN
        ALTER TABLE usuarios ADD COLUMN perm_demandas_criar BOOLEAN DEFAULT false;
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_demandas_excluir') THEN
        ALTER TABLE usuarios ADD COLUMN perm_demandas_excluir BOOLEAN DEFAULT false;
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_demandas_ver_produtividade') THEN
        ALTER TABLE usuarios ADD COLUMN perm_demandas_ver_produtividade BOOLEAN DEFAULT false;
    END IF;
    
    -- Permissões comerciais
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_comercial_ver') THEN
        ALTER TABLE usuarios ADD COLUMN perm_comercial_ver BOOLEAN DEFAULT false;
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_comercial_deg_editar') THEN
        ALTER TABLE usuarios ADD COLUMN perm_comercial_deg_editar BOOLEAN DEFAULT false;
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_comercial_deg_inscritos') THEN
        ALTER TABLE usuarios ADD COLUMN perm_comercial_deg_inscritos BOOLEAN DEFAULT false;
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_comercial_conversao') THEN
        ALTER TABLE usuarios ADD COLUMN perm_comercial_conversao BOOLEAN DEFAULT false;
    END IF;
END $$;

-- 4. CONFIGURAR PERMISSÕES PARA USUÁRIOS ADM
-- =====================================================

-- Ativar todas as permissões para usuários ADM
UPDATE usuarios SET 
    perm_agenda_ver = true,
    perm_agenda_editar = true,
    perm_agenda_criar = true,
    perm_agenda_excluir = true,
    perm_agenda_relatorios = true,
    perm_agenda_meus = true,
    perm_demandas_ver = true,
    perm_demandas_editar = true,
    perm_demandas_criar = true,
    perm_demandas_excluir = true,
    perm_demandas_ver_produtividade = true,
    perm_comercial_ver = true,
    perm_comercial_deg_editar = true,
    perm_comercial_deg_inscritos = true,
    perm_comercial_conversao = true
WHERE perfil = 'ADM';

-- 5. CRIAR FUNÇÕES POSTGRESQL FALTANTES
-- =====================================================

-- Função obter_proximos_eventos
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

-- Função obter_eventos_hoje
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

-- Função obter_eventos_semana
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

-- 6. CRIAR ÍNDICES PARA PERFORMANCE
-- =====================================================

-- Índices para tabela eventos
CREATE INDEX IF NOT EXISTS idx_eventos_data_inicio ON eventos(data_inicio);
CREATE INDEX IF NOT EXISTS idx_eventos_status ON eventos(status) WHERE status IS NOT NULL;

-- Índices para tabela agenda_eventos
CREATE INDEX IF NOT EXISTS idx_agenda_eventos_data ON agenda_eventos(data_inicio);
CREATE INDEX IF NOT EXISTS idx_agenda_eventos_espaco ON agenda_eventos(espaco_id) WHERE espaco_id IS NOT NULL;

-- Índices para tabela usuarios
CREATE INDEX IF NOT EXISTS idx_usuarios_email ON usuarios(email);
CREATE INDEX IF NOT EXISTS idx_usuarios_perfil ON usuarios(perfil);

-- 7. TESTAR FUNÇÕES CRIADAS
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
-- CORREÇÃO ESTRUTURA FALTANTE FINALIZADA!
-- =====================================================

SELECT '🎉 CORREÇÃO ESTRUTURA FALTANTE FINALIZADA!' as status;
SELECT 'Todas as tabelas, colunas e funções foram criadas/corrigidas.' as resultado;
SELECT 'O sistema agora deve funcionar perfeitamente.' as conclusao;
