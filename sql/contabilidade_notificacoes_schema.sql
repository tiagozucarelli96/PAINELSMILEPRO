-- ============================================
-- SCHEMA: SISTEMA GLOBAL DE NOTIFICAÇÕES + CONFIGURAÇÃO DE E-MAIL
-- ============================================

-- Tabela de configuração global de e-mail (ETAPA 12)
CREATE TABLE IF NOT EXISTS sistema_email_config (
    id BIGSERIAL PRIMARY KEY,
    smtp_host VARCHAR(255) NOT NULL DEFAULT 'mail.smileeventos.com.br',
    smtp_port INTEGER NOT NULL DEFAULT 465,
    smtp_username VARCHAR(255) NOT NULL DEFAULT 'painelsmilenotifica@smileeventos.com.br',
    smtp_password VARCHAR(255) NOT NULL,
    smtp_encryption VARCHAR(10) NOT NULL DEFAULT 'ssl' CHECK (smtp_encryption IN ('ssl', 'tls', 'none')),
    email_remetente VARCHAR(255) NOT NULL DEFAULT 'painelsmilenotifica@smileeventos.com.br',
    email_administrador VARCHAR(255) NOT NULL,
    preferencia_notif_contabilidade BOOLEAN NOT NULL DEFAULT TRUE,
    preferencia_notif_sistema BOOLEAN NOT NULL DEFAULT TRUE,
    preferencia_notif_financeiro BOOLEAN NOT NULL DEFAULT TRUE,
    tempo_inatividade_minutos INTEGER NOT NULL DEFAULT 10,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Tabela de notificações pendentes (ETAPA 13)
CREATE TABLE IF NOT EXISTS sistema_notificacoes_pendentes (
    id BIGSERIAL PRIMARY KEY,
    modulo VARCHAR(50) NOT NULL, -- 'contabilidade', 'sistema', 'financeiro', etc.
    tipo VARCHAR(50) NOT NULL, -- 'novo_cadastro', 'nova_mensagem', 'novo_anexo', 'alteracao_status', etc.
    entidade_tipo VARCHAR(50) NOT NULL, -- 'guia', 'holerite', 'honorario', 'conversa', etc.
    entidade_id BIGINT,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT,
    destinatario_tipo VARCHAR(20) NOT NULL CHECK (destinatario_tipo IN ('admin', 'contabilidade', 'ambos')),
    processado BOOLEAN NOT NULL DEFAULT FALSE,
    enviado_em TIMESTAMP,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Tabela de controle de última atividade global (ETAPA 13)
CREATE TABLE IF NOT EXISTS sistema_ultima_atividade (
    id BIGSERIAL PRIMARY KEY,
    ultima_atividade TIMESTAMP NOT NULL DEFAULT NOW(),
    ultimo_envio TIMESTAMP,
    bloqueado BOOLEAN NOT NULL DEFAULT FALSE
);

-- Inserir registro inicial de controle de atividade
INSERT INTO sistema_ultima_atividade (ultima_atividade, ultimo_envio, bloqueado)
VALUES (NOW(), NULL, FALSE)
ON CONFLICT DO NOTHING;

-- Tabela de preferências de notificação via navegador (ETAPA 16 - preparação)
CREATE TABLE IF NOT EXISTS sistema_notificacoes_navegador (
    id BIGSERIAL PRIMARY KEY,
    usuario_id BIGINT REFERENCES usuarios(id) ON DELETE CASCADE,
    endpoint TEXT NOT NULL,
    chave_publica TEXT,
    chave_autenticacao TEXT,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Atualizar tabelas existentes para padronizar status (ETAPA 11)
-- Adicionar coluna de status padronizado se não existir
DO $$
BEGIN
    -- Guias: já tem status, mas vamos garantir que aceite os valores padrão
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'contabilidade_guias' 
        AND column_name = 'status'
    ) THEN
        ALTER TABLE contabilidade_guias ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'aberto';
    END IF;
    
    -- Atualizar constraint de status para aceitar valores padrão
    ALTER TABLE contabilidade_guias 
    DROP CONSTRAINT IF EXISTS contabilidade_guias_status_check;
    
    ALTER TABLE contabilidade_guias 
    ADD CONSTRAINT contabilidade_guias_status_check 
    CHECK (status IN ('aberto', 'em_andamento', 'concluido', 'pago', 'vencido', 'cancelado'));
    
    -- Holerites: atualizar status
    ALTER TABLE contabilidade_holerites 
    DROP CONSTRAINT IF EXISTS contabilidade_holerites_status_check;
    
    ALTER TABLE contabilidade_holerites 
    ADD CONSTRAINT contabilidade_holerites_status_check 
    CHECK (status IN ('aberto', 'em_andamento', 'concluido', 'processado', 'cancelado'));
    
    -- Honorários: atualizar status
    ALTER TABLE contabilidade_honorarios 
    DROP CONSTRAINT IF EXISTS contabilidade_honorarios_status_check;
    
    ALTER TABLE contabilidade_honorarios 
    ADD CONSTRAINT contabilidade_honorarios_status_check 
    CHECK (status IN ('aberto', 'em_andamento', 'concluido', 'pago', 'vencido', 'cancelado'));
    
    -- Conversas: já tem status correto, apenas garantir
    -- (já está correto no schema original)
END $$;

-- Adicionar coluna de última atividade nas tabelas principais
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'contabilidade_guias' 
        AND column_name = 'ultima_atividade'
    ) THEN
        ALTER TABLE contabilidade_guias ADD COLUMN ultima_atividade TIMESTAMP DEFAULT NOW();
    END IF;
    
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'contabilidade_holerites' 
        AND column_name = 'ultima_atividade'
    ) THEN
        ALTER TABLE contabilidade_holerites ADD COLUMN ultima_atividade TIMESTAMP DEFAULT NOW();
    END IF;
    
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'contabilidade_honorarios' 
        AND column_name = 'ultima_atividade'
    ) THEN
        ALTER TABLE contabilidade_honorarios ADD COLUMN ultima_atividade TIMESTAMP DEFAULT NOW();
    END IF;
    
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'contabilidade_conversas' 
        AND column_name = 'ultima_atividade'
    ) THEN
        ALTER TABLE contabilidade_conversas ADD COLUMN ultima_atividade TIMESTAMP DEFAULT NOW();
    END IF;
END $$;

-- Corrigir nome da tabela de mensagens (se estiver errado)
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.tables 
        WHERE table_name = 'contabilidade_conversas_mensagens'
    ) THEN
        -- Tabela existe com nome antigo, criar alias ou renomear
        -- Por enquanto, vamos manter compatibilidade
        NULL;
    END IF;
    
    -- Criar tabela com nome correto se não existir
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.tables 
        WHERE table_name = 'contabilidade_mensagens'
    ) THEN
        CREATE TABLE contabilidade_mensagens (
            id BIGSERIAL PRIMARY KEY,
            conversa_id BIGINT NOT NULL REFERENCES contabilidade_conversas(id) ON DELETE CASCADE,
            autor VARCHAR(50) NOT NULL,
            mensagem TEXT,
            anexo_url TEXT,
            anexo_nome VARCHAR(255),
            criado_em TIMESTAMP NOT NULL DEFAULT NOW()
        );
        
        CREATE INDEX IF NOT EXISTS idx_contabilidade_mensagens_conversa ON contabilidade_mensagens(conversa_id);
    END IF;
END $$;

-- Corrigir nome da tabela de documentos de colaboradores
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.tables 
        WHERE table_name = 'contabilidade_colaboradores_documentos'
    ) THEN
        -- Tabela existe, criar alias se necessário
        NULL;
    END IF;
    
    -- Criar tabela com nome correto se não existir
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.tables 
        WHERE table_name = 'contabilidade_colaborador_documentos'
    ) THEN
        CREATE TABLE contabilidade_colaborador_documentos (
            id BIGSERIAL PRIMARY KEY,
            colaborador_id BIGINT NOT NULL,
            tipo_documento VARCHAR(50) NOT NULL,
            arquivo_url TEXT NOT NULL,
            arquivo_nome VARCHAR(255) NOT NULL,
            descricao TEXT,
            criado_em TIMESTAMP NOT NULL DEFAULT NOW()
        );
        
        CREATE INDEX IF NOT EXISTS idx_contabilidade_colaborador_docs_colab ON contabilidade_colaborador_documentos(colaborador_id);
    END IF;
END $$;

-- Índices para notificações
CREATE INDEX IF NOT EXISTS idx_sistema_notificacoes_pendentes_processado ON sistema_notificacoes_pendentes(processado);
CREATE INDEX IF NOT EXISTS idx_sistema_notificacoes_pendentes_criado ON sistema_notificacoes_pendentes(criado_em);
CREATE INDEX IF NOT EXISTS idx_sistema_notificacoes_pendentes_modulo ON sistema_notificacoes_pendentes(modulo);

-- Comentários
COMMENT ON TABLE sistema_email_config IS 'Configuração global de e-mail SMTP e preferências do administrador';
COMMENT ON TABLE sistema_notificacoes_pendentes IS 'Notificações pendentes de envio (consolidadas após inatividade)';
COMMENT ON TABLE sistema_ultima_atividade IS 'Controle de última atividade global para envio consolidado de notificações';
COMMENT ON TABLE sistema_notificacoes_navegador IS 'Preferências de notificações via navegador (Web Push)';
