-- ============================================
-- MIGRAÇÃO 050: Módulo de Eventos
-- Reunião Final, Portais DJ/Decoração, Galeria
-- ============================================

-- Adicionar permissões de Eventos na tabela usuarios (se não existirem)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_schema = current_schema()
        AND table_name = 'usuarios' 
        AND column_name = 'perm_eventos'
    ) THEN
        ALTER TABLE usuarios ADD COLUMN perm_eventos BOOLEAN DEFAULT FALSE;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
        AND table_name = 'usuarios'
        AND column_name = 'perm_eventos_realizar'
    ) THEN
        ALTER TABLE usuarios ADD COLUMN perm_eventos_realizar BOOLEAN DEFAULT FALSE;
        -- Compatibilidade: mantém comportamento anterior ao separar a permissão.
        UPDATE usuarios SET perm_eventos_realizar = COALESCE(perm_eventos, FALSE);
    END IF;
END $$;

-- ============================================
-- 1) REUNIÃO POR EVENTO (registro principal)
-- ============================================
CREATE TABLE IF NOT EXISTS eventos_reunioes (
    id BIGSERIAL PRIMARY KEY,
    
    -- Vínculo com ME Eventos
    me_event_id BIGINT NOT NULL,
    me_event_snapshot JSONB NOT NULL DEFAULT '{}',
    
    -- Status da reunião
    status VARCHAR(20) NOT NULL DEFAULT 'rascunho' 
        CHECK (status IN ('rascunho', 'concluida')),
    
    -- Fornecedores vinculados (preenchidos na tela interna)
    fornecedor_dj_id BIGINT NULL,
    fornecedor_decoracao_id BIGINT NULL,
    
    -- Auditoria
    created_by BIGINT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_by BIGINT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Índice único: apenas uma reunião por evento ME
CREATE UNIQUE INDEX IF NOT EXISTS idx_eventos_reunioes_me_event 
ON eventos_reunioes(me_event_id);

-- Índice para busca por status
CREATE INDEX IF NOT EXISTS idx_eventos_reunioes_status 
ON eventos_reunioes(status);

-- ============================================
-- 2) CONTEÚDO POR SEÇÃO
-- ============================================
CREATE TABLE IF NOT EXISTS eventos_reunioes_secoes (
    id BIGSERIAL PRIMARY KEY,
    
    meeting_id BIGINT NOT NULL REFERENCES eventos_reunioes(id) ON DELETE CASCADE,
    
    -- Tipo de seção
    section VARCHAR(30) NOT NULL 
        CHECK (section IN ('decoracao', 'observacoes_gerais', 'dj_protocolo')),
    
    -- Conteúdo
    content_html TEXT DEFAULT '',
    content_text TEXT DEFAULT '', -- Versão limpa para busca
    
    -- Travamento (DJ após envio do cliente)
    is_locked BOOLEAN NOT NULL DEFAULT FALSE,
    locked_at TIMESTAMP NULL,
    locked_by BIGINT NULL,
    
    -- Auditoria
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_by BIGINT NULL,
    
    -- Apenas uma seção de cada tipo por reunião
    UNIQUE(meeting_id, section)
);

CREATE INDEX IF NOT EXISTS idx_eventos_secoes_meeting 
ON eventos_reunioes_secoes(meeting_id);

-- ============================================
-- 3) VERSÕES (histórico estilo Canva/Word)
-- ============================================
CREATE TABLE IF NOT EXISTS eventos_reunioes_versoes (
    id BIGSERIAL PRIMARY KEY,
    
    meeting_id BIGINT NOT NULL REFERENCES eventos_reunioes(id) ON DELETE CASCADE,
    section VARCHAR(30) NOT NULL 
        CHECK (section IN ('decoracao', 'observacoes_gerais', 'dj_protocolo')),
    
    -- Número da versão (incremental por meeting+section)
    version_number INT NOT NULL DEFAULT 1,
    
    -- Conteúdo desta versão
    content_html TEXT NOT NULL,
    
    -- Autor da versão
    created_by_user_id BIGINT NULL, -- NULL se for cliente
    created_by_type VARCHAR(20) NOT NULL DEFAULT 'interno' 
        CHECK (created_by_type IN ('interno', 'cliente', 'fornecedor')),
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    
    -- Nota/descrição
    note TEXT DEFAULT '',
    
    -- Se esta é a versão ativa
    is_active BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE INDEX IF NOT EXISTS idx_eventos_versoes_meeting_section 
ON eventos_reunioes_versoes(meeting_id, section);

CREATE INDEX IF NOT EXISTS idx_eventos_versoes_active 
ON eventos_reunioes_versoes(meeting_id, section) 
WHERE is_active = TRUE;

-- ============================================
-- 4) ANEXOS (Magalu Cloud)
-- ============================================
CREATE TABLE IF NOT EXISTS eventos_reunioes_anexos (
    id BIGSERIAL PRIMARY KEY,
    
    meeting_id BIGINT NOT NULL REFERENCES eventos_reunioes(id) ON DELETE CASCADE,
    section VARCHAR(30) NOT NULL 
        CHECK (section IN ('decoracao', 'observacoes_gerais', 'dj_protocolo', 'galeria')),
    
    -- Tipo de arquivo
    file_kind VARCHAR(20) NOT NULL DEFAULT 'outros'
        CHECK (file_kind IN ('imagem', 'audio', 'video', 'pdf', 'outros')),
    
    -- Metadados do arquivo
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    
    -- Armazenamento Magalu
    storage_key VARCHAR(500) NOT NULL, -- Chave no Magalu Cloud
    public_url TEXT, -- URL pública (se disponível)
    
    -- Quem fez upload
    uploaded_by_user_id BIGINT NULL, -- NULL se cliente/fornecedor
    uploaded_by_type VARCHAR(20) NOT NULL DEFAULT 'interno'
        CHECK (uploaded_by_type IN ('interno', 'cliente', 'fornecedor')),
    uploaded_at TIMESTAMP NOT NULL DEFAULT NOW(),
    
    -- Expiração para arquivos pesados
    expires_at TIMESTAMP NULL,
    
    -- Soft delete
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT NULL
);

CREATE INDEX IF NOT EXISTS idx_eventos_anexos_meeting 
ON eventos_reunioes_anexos(meeting_id);

CREATE INDEX IF NOT EXISTS idx_eventos_anexos_section 
ON eventos_reunioes_anexos(meeting_id, section);

CREATE INDEX IF NOT EXISTS idx_eventos_anexos_expires 
ON eventos_reunioes_anexos(expires_at) 
WHERE expires_at IS NOT NULL AND deleted_at IS NULL;

-- ============================================
-- 5) LINKS PÚBLICOS (cliente/compartilhamento)
-- ============================================
CREATE TABLE IF NOT EXISTS eventos_links_publicos (
    id BIGSERIAL PRIMARY KEY,
    
    meeting_id BIGINT NOT NULL REFERENCES eventos_reunioes(id) ON DELETE CASCADE,
    
    -- Token único e seguro
    token VARCHAR(128) NOT NULL UNIQUE,
    
    -- Tipo de link
    link_type VARCHAR(30) NOT NULL 
        CHECK (link_type IN ('cliente_dj', 'link_publico_visualizacao')),
    
    -- Seções permitidas (JSON array)
    allowed_sections JSONB NOT NULL DEFAULT '[]',
    
    -- Expiração opcional
    expires_at TIMESTAMP NULL,
    
    -- Status
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    
    -- Logs de acesso
    created_by BIGINT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    last_access_at TIMESTAMP NULL,
    access_count INT NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_eventos_links_token 
ON eventos_links_publicos(token);

CREATE INDEX IF NOT EXISTS idx_eventos_links_meeting 
ON eventos_links_publicos(meeting_id);

CREATE INDEX IF NOT EXISTS idx_eventos_links_type 
ON eventos_links_publicos(meeting_id, link_type);

-- ============================================
-- 6) FORNECEDORES (DJ/Decoração) - Cadastro fixo
-- ============================================
CREATE TABLE IF NOT EXISTS eventos_fornecedores (
    id BIGSERIAL PRIMARY KEY,
    
    -- Tipo de fornecedor
    tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('dj', 'decoracao')),
    
    -- Dados do fornecedor
    nome VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    telefone VARCHAR(20),
    
    -- Acesso ao portal
    login VARCHAR(100) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    
    -- Status
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    
    -- Auditoria
    created_by BIGINT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_eventos_fornecedores_tipo 
ON eventos_fornecedores(tipo) WHERE ativo = TRUE;

CREATE INDEX IF NOT EXISTS idx_eventos_fornecedores_login 
ON eventos_fornecedores(login);

-- ============================================
-- 7) VÍNCULOS FORNECEDOR ↔ REUNIÃO
-- ============================================
CREATE TABLE IF NOT EXISTS eventos_fornecedores_vinculos (
    id BIGSERIAL PRIMARY KEY,
    
    supplier_id BIGINT NOT NULL REFERENCES eventos_fornecedores(id) ON DELETE CASCADE,
    meeting_id BIGINT NOT NULL REFERENCES eventos_reunioes(id) ON DELETE CASCADE,
    
    -- Seções que o fornecedor pode ver (JSON array)
    allowed_sections JSONB NOT NULL DEFAULT '[]',
    
    -- Pode baixar anexos?
    can_download_attachments BOOLEAN NOT NULL DEFAULT TRUE,
    
    -- Auditoria
    created_by BIGINT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    
    -- Único vínculo por fornecedor+reunião
    UNIQUE(supplier_id, meeting_id)
);

CREATE INDEX IF NOT EXISTS idx_eventos_vinculos_supplier 
ON eventos_fornecedores_vinculos(supplier_id);

CREATE INDEX IF NOT EXISTS idx_eventos_vinculos_meeting 
ON eventos_fornecedores_vinculos(meeting_id);

-- ============================================
-- 8) SESSÕES DE FORNECEDORES (para login portal)
-- ============================================
CREATE TABLE IF NOT EXISTS eventos_fornecedores_sessoes (
    id BIGSERIAL PRIMARY KEY,
    
    fornecedor_id BIGINT NOT NULL REFERENCES eventos_fornecedores(id) ON DELETE CASCADE,
    
    -- Token de sessão
    token VARCHAR(128) NOT NULL UNIQUE,
    
    -- Dados da sessão
    ip_address VARCHAR(45),
    user_agent TEXT,
    
    -- Timestamps
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    expira_em TIMESTAMP NOT NULL,
    ativo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE INDEX IF NOT EXISTS idx_eventos_fornecedores_sessoes_token 
ON eventos_fornecedores_sessoes(token);

-- ============================================
-- 9) GALERIA DE IMAGENS (categorias)
-- ============================================
CREATE TABLE IF NOT EXISTS eventos_galeria (
    id BIGSERIAL PRIMARY KEY,
    
    -- Categoria
    categoria VARCHAR(30) NOT NULL 
        CHECK (categoria IN ('infantil', 'casamento', '15_anos', 'geral')),
    
    -- Nome/descrição
    nome VARCHAR(255) NOT NULL,
    descricao TEXT,
    tags TEXT, -- Tags separadas por vírgula
    
    -- Arquivo no Magalu
    storage_key VARCHAR(500) NOT NULL,
    public_url TEXT,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    
    -- Transformações visuais (rotação via CSS)
    transform_css VARCHAR(100) DEFAULT '',
    
    -- Auditoria
    uploaded_by BIGINT NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT NOW(),
    
    -- Soft delete
    deleted_at TIMESTAMP NULL
);

CREATE INDEX IF NOT EXISTS idx_eventos_galeria_categoria 
ON eventos_galeria(categoria) WHERE deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_eventos_galeria_nome 
ON eventos_galeria(nome) WHERE deleted_at IS NULL;

-- ============================================
-- 10) CACHE DE EVENTOS ME (para evitar rate limit)
-- ============================================
CREATE TABLE IF NOT EXISTS eventos_me_cache (
    id BIGSERIAL PRIMARY KEY,
    
    -- Chave do cache (ex: "events_list_2025-01")
    cache_key VARCHAR(255) NOT NULL UNIQUE,
    
    -- Dados cacheados
    data JSONB NOT NULL,
    
    -- Timestamps
    cached_at TIMESTAMP NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_eventos_me_cache_key 
ON eventos_me_cache(cache_key);

CREATE INDEX IF NOT EXISTS idx_eventos_me_cache_expires 
ON eventos_me_cache(expires_at);

-- ============================================
-- COMENTÁRIOS FINAIS
-- ============================================
COMMENT ON TABLE eventos_reunioes IS 'Reuniões finais por evento ME';
COMMENT ON TABLE eventos_reunioes_secoes IS 'Conteúdo por seção (decoração, observações, DJ)';
COMMENT ON TABLE eventos_reunioes_versoes IS 'Histórico de versões do conteúdo';
COMMENT ON TABLE eventos_reunioes_anexos IS 'Anexos no Magalu Cloud';
COMMENT ON TABLE eventos_links_publicos IS 'Links públicos para clientes';
COMMENT ON TABLE eventos_fornecedores IS 'Cadastro de fornecedores DJ/Decoração';
COMMENT ON TABLE eventos_fornecedores_vinculos IS 'Vínculo fornecedor com reunião';
COMMENT ON TABLE eventos_galeria IS 'Galeria de imagens por categoria';
COMMENT ON TABLE eventos_me_cache IS 'Cache de dados da API ME Eventos';
