-- =====================================================
-- CORREÇÃO COMPLETA PARA PRODUÇÃO - PAINEL SMILE
-- Execute este arquivo no TablePlus para corrigir todos os problemas
-- =====================================================

-- 0. CORRIGIR PROBLEMA DO ENUM eventos_status (CRÍTICO)
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

-- 1. CORRIGIR TABELA EVENTOS (ADICIONAR COLUNAS FALTANTES)
-- =====================================================

-- Adicionar coluna descricao se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'eventos' AND column_name = 'descricao') THEN
        ALTER TABLE eventos ADD COLUMN descricao TEXT;
    END IF;
END $$;

-- Adicionar coluna data_inicio se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'eventos' AND column_name = 'data_inicio') THEN
        ALTER TABLE eventos ADD COLUMN data_inicio TIMESTAMP;
    END IF;
END $$;

-- Adicionar coluna data_fim se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'eventos' AND column_name = 'data_fim') THEN
        ALTER TABLE eventos ADD COLUMN data_fim TIMESTAMP;
    END IF;
END $$;

-- Adicionar coluna local se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'eventos' AND column_name = 'local') THEN
        ALTER TABLE eventos ADD COLUMN local VARCHAR(255);
    END IF;
END $$;

-- Adicionar coluna status se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'eventos' AND column_name = 'status') THEN
        ALTER TABLE eventos ADD COLUMN status VARCHAR(20) DEFAULT 'ativo';
    END IF;
END $$;

-- Corrigir ENUM eventos_status se existir
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
    END IF;
END $$;

-- Adicionar coluna observacoes se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'eventos' AND column_name = 'observacoes') THEN
        ALTER TABLE eventos ADD COLUMN observacoes TEXT;
    END IF;
END $$;

-- Adicionar coluna created_at se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'eventos' AND column_name = 'created_at') THEN
        ALTER TABLE eventos ADD COLUMN created_at TIMESTAMP DEFAULT NOW();
    END IF;
END $$;

-- Adicionar coluna updated_at se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'eventos' AND column_name = 'updated_at') THEN
        ALTER TABLE eventos ADD COLUMN updated_at TIMESTAMP DEFAULT NOW();
    END IF;
END $$;

-- Atualizar registros existentes
UPDATE eventos SET data_inicio = created_at WHERE data_inicio IS NULL;
UPDATE eventos SET data_fim = data_inicio + INTERVAL '1 hour' WHERE data_fim IS NULL;
UPDATE eventos SET status = 'ativo' WHERE status IS NULL;

-- 2. CRIAR TABELAS FALTANTES
-- =====================================================

-- Tabela agenda_lembretes
CREATE TABLE IF NOT EXISTS agenda_lembretes (
    id SERIAL PRIMARY KEY,
    evento_id INT REFERENCES agenda_eventos(id) ON DELETE CASCADE,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    tipo VARCHAR(50) DEFAULT 'email',
    tempo_antes INT DEFAULT 15,
    enviado BOOLEAN DEFAULT false,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Tabela agenda_tokens_ics
CREATE TABLE IF NOT EXISTS agenda_tokens_ics (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    token VARCHAR(64) UNIQUE NOT NULL,
    ativo BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Tabela demandas_quadros
CREATE TABLE IF NOT EXISTS demandas_quadros (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    descricao TEXT,
    cor VARCHAR(7) DEFAULT '#2196F3',
    ativo BOOLEAN DEFAULT true,
    criado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela demandas_colunas
CREATE TABLE IF NOT EXISTS demandas_colunas (
    id SERIAL PRIMARY KEY,
    quadro_id INT REFERENCES demandas_quadros(id) ON DELETE CASCADE,
    nome VARCHAR(255) NOT NULL,
    ordem INT DEFAULT 0,
    cor VARCHAR(7) DEFAULT '#f0f0f0',
    created_at TIMESTAMP DEFAULT NOW()
);

-- Tabela demandas_cartoes
CREATE TABLE IF NOT EXISTS demandas_cartoes (
    id SERIAL PRIMARY KEY,
    quadro_id INT REFERENCES demandas_quadros(id) ON DELETE CASCADE,
    coluna_id INT REFERENCES demandas_colunas(id) ON DELETE CASCADE,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT,
    cor VARCHAR(7) DEFAULT '#ffffff',
    prioridade VARCHAR(20) DEFAULT 'media',
    data_vencimento TIMESTAMP,
    recorrente BOOLEAN DEFAULT false,
    tipo_recorrencia VARCHAR(20),
    criado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Tabela demandas_participantes
CREATE TABLE IF NOT EXISTS demandas_participantes (
    id SERIAL PRIMARY KEY,
    quadro_id INT REFERENCES demandas_quadros(id) ON DELETE CASCADE,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    permissao VARCHAR(20) DEFAULT 'leitura',
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(quadro_id, usuario_id)
);

-- Tabela demandas_comentarios
CREATE TABLE IF NOT EXISTS demandas_comentarios (
    id SERIAL PRIMARY KEY,
    cartao_id INT REFERENCES demandas_cartoes(id) ON DELETE CASCADE,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    comentario TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Tabela demandas_anexos
CREATE TABLE IF NOT EXISTS demandas_anexos (
    id SERIAL PRIMARY KEY,
    cartao_id INT REFERENCES demandas_cartoes(id) ON DELETE CASCADE,
    nome_arquivo VARCHAR(255) NOT NULL,
    caminho_arquivo VARCHAR(500) NOT NULL,
    tamanho INT,
    tipo_mime VARCHAR(100),
    enviado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Tabela demandas_recorrencia
CREATE TABLE IF NOT EXISTS demandas_recorrencia (
    id SERIAL PRIMARY KEY,
    cartao_id INT REFERENCES demandas_cartoes(id) ON DELETE CASCADE,
    tipo VARCHAR(20) NOT NULL,
    intervalo INT DEFAULT 1,
    dia_semana INT,
    dia_mes INT,
    proxima_execucao TIMESTAMP,
    ativo BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Tabela demandas_notificacoes
CREATE TABLE IF NOT EXISTS demandas_notificacoes (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    tipo VARCHAR(50) NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    mensagem TEXT,
    lida BOOLEAN DEFAULT false,
    data_notificacao TIMESTAMP DEFAULT NOW()
);

-- Tabela demandas_produtividade
CREATE TABLE IF NOT EXISTS demandas_produtividade (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    data DATE NOT NULL,
    cartoes_criados INT DEFAULT 0,
    cartoes_concluidos INT DEFAULT 0,
    tempo_total INTERVAL,
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(usuario_id, data)
);

-- Tabela demandas_correio
CREATE TABLE IF NOT EXISTS demandas_correio (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    servidor VARCHAR(255) NOT NULL,
    porta INT DEFAULT 993,
    usuario_email VARCHAR(255) NOT NULL,
    senha VARCHAR(255) NOT NULL,
    ssl BOOLEAN DEFAULT true,
    ativo BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Tabela demandas_mensagens_email
CREATE TABLE IF NOT EXISTS demandas_mensagens_email (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    message_id VARCHAR(255) UNIQUE NOT NULL,
    assunto VARCHAR(500),
    remetente VARCHAR(255),
    data_envio TIMESTAMP,
    lida BOOLEAN DEFAULT false,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Tabela demandas_anexos_email
CREATE TABLE IF NOT EXISTS demandas_anexos_email (
    id SERIAL PRIMARY KEY,
    mensagem_id INT REFERENCES demandas_mensagens_email(id) ON DELETE CASCADE,
    nome_arquivo VARCHAR(255) NOT NULL,
    caminho_arquivo VARCHAR(500) NOT NULL,
    tamanho INT,
    tipo_mime VARCHAR(100),
    created_at TIMESTAMP DEFAULT NOW()
);

-- 3. CRIAR FUNÇÕES POSTGRESQL CORRETAS
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

-- Função verificar_conflito_agenda
CREATE OR REPLACE FUNCTION verificar_conflito_agenda(
    p_espaco_id INTEGER,
    p_data_inicio TIMESTAMP,
    p_data_fim TIMESTAMP,
    p_evento_id INTEGER DEFAULT NULL
)
RETURNS BOOLEAN
LANGUAGE plpgsql
AS $$
DECLARE
    conflito_count INTEGER;
BEGIN
    SELECT COUNT(*)
    INTO conflito_count
    FROM agenda_eventos ae
    WHERE ae.espaco_id = p_espaco_id
    AND ae.id != COALESCE(p_evento_id, 0)
    AND (
        (p_data_inicio BETWEEN ae.data_inicio AND ae.data_fim)
        OR (p_data_fim BETWEEN ae.data_inicio AND ae.data_fim)
        OR (ae.data_inicio BETWEEN p_data_inicio AND p_data_fim)
        OR (ae.data_fim BETWEEN p_data_inicio AND p_data_fim)
    );
    
    RETURN conflito_count > 0;
END;
$$;

-- Função gerar_token_ics
CREATE OR REPLACE FUNCTION gerar_token_ics(p_usuario_id INTEGER)
RETURNS VARCHAR(64)
LANGUAGE plpgsql
AS $$
DECLARE
    novo_token VARCHAR(64);
BEGIN
    novo_token := encode(gen_random_bytes(32), 'hex');
    
    INSERT INTO agenda_tokens_ics (usuario_id, token)
    VALUES (p_usuario_id, novo_token)
    ON CONFLICT (token) DO NOTHING;
    
    RETURN novo_token;
END;
$$;

-- Função calcular_conversao_visitas
CREATE OR REPLACE FUNCTION calcular_conversao_visitas(
    p_data_inicio DATE,
    p_data_fim DATE
)
RETURNS TABLE (
    total_visitas INTEGER,
    total_conversoes INTEGER,
    taxa_conversao NUMERIC(5,2)
)
LANGUAGE plpgsql
AS $$
BEGIN
    RETURN QUERY
    SELECT 
        COUNT(*)::INTEGER as total_visitas,
        COUNT(CASE WHEN cc.status = 'fechado' THEN 1 END)::INTEGER as total_conversoes,
        CASE 
            WHEN COUNT(*) > 0 THEN 
                (COUNT(CASE WHEN cc.status = 'fechado' THEN 1 END)::NUMERIC / COUNT(*)::NUMERIC) * 100
            ELSE 0
        END as taxa_conversao
    FROM comercial_degust_inscricoes cdi
    LEFT JOIN comercial_clientes cc ON cdi.email = cc.email
    WHERE cdi.created_at::DATE BETWEEN p_data_inicio AND p_data_fim;
END;
$$;

-- 4. CRIAR ÍNDICES PARA PERFORMANCE
-- =====================================================

-- Índices para tabela eventos
CREATE INDEX IF NOT EXISTS idx_eventos_data_inicio ON eventos(data_inicio);
CREATE INDEX IF NOT EXISTS idx_eventos_status ON eventos(status) WHERE status IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_eventos_usuario ON eventos(usuario_id) WHERE usuario_id IS NOT NULL;

-- Índices para tabela agenda_eventos
CREATE INDEX IF NOT EXISTS idx_agenda_eventos_data ON agenda_eventos(data_inicio);
CREATE INDEX IF NOT EXISTS idx_agenda_eventos_espaco ON agenda_eventos(espaco_id) WHERE espaco_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_agenda_eventos_usuario ON agenda_eventos(usuario_id) WHERE usuario_id IS NOT NULL;

-- Índices para tabela usuarios
CREATE INDEX IF NOT EXISTS idx_usuarios_email ON usuarios(email);
CREATE INDEX IF NOT EXISTS idx_usuarios_perfil ON usuarios(perfil);

-- Índices para tabelas de demandas
CREATE INDEX IF NOT EXISTS idx_demandas_cartoes_quadro ON demandas_cartoes(quadro_id);
CREATE INDEX IF NOT EXISTS idx_demandas_cartoes_coluna ON demandas_cartoes(coluna_id);
CREATE INDEX IF NOT EXISTS idx_demandas_cartoes_vencimento ON demandas_cartoes(data_vencimento) WHERE data_vencimento IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_demandas_cartoes_criado_por ON demandas_cartoes(criado_por) WHERE criado_por IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_demandas_participantes_quadro ON demandas_participantes(quadro_id);
CREATE INDEX IF NOT EXISTS idx_demandas_participantes_usuario ON demandas_participantes(usuario_id);
CREATE INDEX IF NOT EXISTS idx_demandas_notificacoes_usuario ON demandas_notificacoes(usuario_id);
CREATE INDEX IF NOT EXISTS idx_demandas_notificacoes_lida ON demandas_notificacoes(lida) WHERE lida = false;

-- 5. ADICIONAR COLUNAS DE PERMISSÃO SE NÃO EXISTIREM
-- =====================================================

-- Verificar e adicionar colunas de permissão
DO $$
DECLARE
    coluna_existe BOOLEAN;
BEGIN
    -- Verificar e adicionar perm_agenda_ver
    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'usuarios' AND column_name = 'perm_agenda_ver'
    ) INTO coluna_existe;
    
    IF NOT coluna_existe THEN
        ALTER TABLE usuarios ADD COLUMN perm_agenda_ver BOOLEAN DEFAULT false;
    END IF;
    
    -- Verificar e adicionar perm_agenda_editar
    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'usuarios' AND column_name = 'perm_agenda_editar'
    ) INTO coluna_existe;
    
    IF NOT coluna_existe THEN
        ALTER TABLE usuarios ADD COLUMN perm_agenda_editar BOOLEAN DEFAULT false;
    END IF;
    
    -- Verificar e adicionar perm_agenda_criar
    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'usuarios' AND column_name = 'perm_agenda_criar'
    ) INTO coluna_existe;
    
    IF NOT coluna_existe THEN
        ALTER TABLE usuarios ADD COLUMN perm_agenda_criar BOOLEAN DEFAULT false;
    END IF;
    
    -- Verificar e adicionar perm_agenda_excluir
    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'usuarios' AND column_name = 'perm_agenda_excluir'
    ) INTO coluna_existe;
    
    IF NOT coluna_existe THEN
        ALTER TABLE usuarios ADD COLUMN perm_agenda_excluir BOOLEAN DEFAULT false;
    END IF;
    
    -- Verificar e adicionar perm_demandas_ver
    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'usuarios' AND column_name = 'perm_demandas_ver'
    ) INTO coluna_existe;
    
    IF NOT coluna_existe THEN
        ALTER TABLE usuarios ADD COLUMN perm_demandas_ver BOOLEAN DEFAULT false;
    END IF;
    
    -- Verificar e adicionar perm_demandas_editar
    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'usuarios' AND column_name = 'perm_demandas_editar'
    ) INTO coluna_existe;
    
    IF NOT coluna_existe THEN
        ALTER TABLE usuarios ADD COLUMN perm_demandas_editar BOOLEAN DEFAULT false;
    END IF;
    
    -- Verificar e adicionar perm_demandas_criar
    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'usuarios' AND column_name = 'perm_demandas_criar'
    ) INTO coluna_existe;
    
    IF NOT coluna_existe THEN
        ALTER TABLE usuarios ADD COLUMN perm_demandas_criar BOOLEAN DEFAULT false;
    END IF;
    
    -- Verificar e adicionar perm_demandas_excluir
    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'usuarios' AND column_name = 'perm_demandas_excluir'
    ) INTO coluna_existe;
    
    IF NOT coluna_existe THEN
        ALTER TABLE usuarios ADD COLUMN perm_demandas_excluir BOOLEAN DEFAULT false;
    END IF;
    
    -- Verificar e adicionar perm_demandas_ver_produtividade
    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'usuarios' AND column_name = 'perm_demandas_ver_produtividade'
    ) INTO coluna_existe;
    
    IF NOT coluna_existe THEN
        ALTER TABLE usuarios ADD COLUMN perm_demandas_ver_produtividade BOOLEAN DEFAULT false;
    END IF;
    
    -- Verificar e adicionar perm_comercial_ver
    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'usuarios' AND column_name = 'perm_comercial_ver'
    ) INTO coluna_existe;
    
    IF NOT coluna_existe THEN
        ALTER TABLE usuarios ADD COLUMN perm_comercial_ver BOOLEAN DEFAULT false;
    END IF;
    
    -- Verificar e adicionar perm_comercial_deg_editar
    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'usuarios' AND column_name = 'perm_comercial_deg_editar'
    ) INTO coluna_existe;
    
    IF NOT coluna_existe THEN
        ALTER TABLE usuarios ADD COLUMN perm_comercial_deg_editar BOOLEAN DEFAULT false;
    END IF;
    
    -- Verificar e adicionar perm_comercial_deg_inscritos
    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'usuarios' AND column_name = 'perm_comercial_deg_inscritos'
    ) INTO coluna_existe;
    
    IF NOT coluna_existe THEN
        ALTER TABLE usuarios ADD COLUMN perm_comercial_deg_inscritos BOOLEAN DEFAULT false;
    END IF;
    
    -- Verificar e adicionar perm_comercial_conversao
    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'usuarios' AND column_name = 'perm_comercial_conversao'
    ) INTO coluna_existe;
    
    IF NOT coluna_existe THEN
        ALTER TABLE usuarios ADD COLUMN perm_comercial_conversao BOOLEAN DEFAULT false;
    END IF;
END $$;

-- 6. CONFIGURAR PERMISSÕES PARA USUÁRIOS EXISTENTES
-- =====================================================

-- Ativar todas as permissões para usuários existentes
UPDATE usuarios SET 
    perm_agenda_ver = true,
    perm_agenda_editar = true,
    perm_agenda_criar = true,
    perm_agenda_excluir = true,
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

-- 7. TESTAR FUNÇÕES CRIADAS
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

-- 8. VERIFICAR ESTRUTURA FINAL
-- =====================================================

-- Verificar colunas da tabela eventos
SELECT 'Verificando estrutura da tabela eventos...' as status;
SELECT column_name, data_type, is_nullable, column_default
FROM information_schema.columns 
WHERE table_name = 'eventos' 
ORDER BY ordinal_position;

-- Verificar tabelas criadas
SELECT 'Verificando tabelas criadas...' as status;
SELECT table_name, 
       (SELECT COUNT(*) FROM information_schema.columns WHERE table_name = t.table_name) as colunas
FROM information_schema.tables t
WHERE table_schema = 'public' 
AND table_name IN (
    'eventos', 'agenda_lembretes', 'agenda_tokens_ics', 
    'demandas_quadros', 'demandas_colunas', 'demandas_cartoes',
    'demandas_participantes', 'demandas_comentarios', 'demandas_anexos',
    'demandas_recorrencia', 'demandas_notificacoes', 'demandas_produtividade',
    'demandas_correio', 'demandas_mensagens_email', 'demandas_anexos_email'
)
ORDER BY table_name;

-- Verificar funções criadas
SELECT 'Verificando funções criadas...' as status;
SELECT routine_name, routine_type
FROM information_schema.routines 
WHERE routine_schema = 'public' 
AND routine_name IN (
    'obter_proximos_eventos', 'obter_eventos_hoje', 'obter_eventos_semana',
    'verificar_conflito_agenda', 'gerar_token_ics', 'calcular_conversao_visitas'
)
ORDER BY routine_name;

-- Verificar índices criados
SELECT 'Verificando índices criados...' as status;
SELECT indexname, tablename, indexdef
FROM pg_indexes 
WHERE schemaname = 'public'
AND indexname LIKE 'idx_%'
ORDER BY tablename, indexname;

-- =====================================================
-- CORREÇÃO COMPLETA FINALIZADA!
-- =====================================================

SELECT '🎉 CORREÇÃO COMPLETA FINALIZADA!' as status;
SELECT 'Todas as tabelas, colunas, funções e índices foram criados/corrigidos.' as resultado;
SELECT 'O sistema agora deve funcionar perfeitamente na produção.' as conclusao;
