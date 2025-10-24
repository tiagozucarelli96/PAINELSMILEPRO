-- 016_sistema_demandas.sql
-- Sistema completo de Demandas com todas as funcionalidades

-- Tabela de quadros
CREATE TABLE IF NOT EXISTS demandas_quadros (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    descricao TEXT,
    cor VARCHAR(7) DEFAULT '#3b82f6',
    criado_por INT REFERENCES usuarios(id) ON DELETE CASCADE,
    criado_em TIMESTAMP DEFAULT NOW(),
    atualizado_em TIMESTAMP DEFAULT NOW(),
    ativo BOOLEAN DEFAULT TRUE
);

-- Tabela de colunas dos quadros
CREATE TABLE IF NOT EXISTS demandas_colunas (
    id SERIAL PRIMARY KEY,
    quadro_id INT REFERENCES demandas_quadros(id) ON DELETE CASCADE,
    nome VARCHAR(255) NOT NULL,
    posicao INT NOT NULL DEFAULT 0,
    cor VARCHAR(7) DEFAULT '#6b7280',
    criado_em TIMESTAMP DEFAULT NOW()
);

-- Tabela de cartões
CREATE TABLE IF NOT EXISTS demandas_cartoes (
    id SERIAL PRIMARY KEY,
    quadro_id INT REFERENCES demandas_quadros(id) ON DELETE CASCADE,
    coluna_id INT REFERENCES demandas_colunas(id) ON DELETE CASCADE,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT,
    responsavel_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    vencimento TIMESTAMP,
    prioridade VARCHAR(20) DEFAULT 'media' CHECK (prioridade IN ('baixa', 'media', 'alta', 'urgente')),
    cor VARCHAR(7) DEFAULT '#ffffff',
    posicao INT NOT NULL DEFAULT 0,
    concluido BOOLEAN DEFAULT FALSE,
    concluido_em TIMESTAMP,
    concluido_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_por INT REFERENCES usuarios(id) ON DELETE CASCADE,
    criado_em TIMESTAMP DEFAULT NOW(),
    atualizado_em TIMESTAMP DEFAULT NOW(),
    arquivado BOOLEAN DEFAULT FALSE,
    arquivado_em TIMESTAMP,
    arquivado_por INT REFERENCES usuarios(id) ON DELETE SET NULL
);

-- Tabela de participantes dos quadros
CREATE TABLE IF NOT EXISTS demandas_participantes (
    id SERIAL PRIMARY KEY,
    quadro_id INT REFERENCES demandas_quadros(id) ON DELETE CASCADE,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    permissao VARCHAR(50) DEFAULT 'comentar' CHECK (permissao IN ('criar', 'editar', 'comentar', 'ler')),
    convidado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    convidado_em TIMESTAMP DEFAULT NOW(),
    aceito BOOLEAN DEFAULT FALSE,
    aceito_em TIMESTAMP,
    UNIQUE(quadro_id, usuario_id)
);

-- Tabela de comentários
CREATE TABLE IF NOT EXISTS demandas_comentarios (
    id SERIAL PRIMARY KEY,
    cartao_id INT REFERENCES demandas_cartoes(id) ON DELETE CASCADE,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    comentario TEXT NOT NULL,
    mencionados TEXT, -- JSON com IDs dos usuários mencionados
    criado_em TIMESTAMP DEFAULT NOW(),
    editado BOOLEAN DEFAULT FALSE,
    editado_em TIMESTAMP
);

-- Tabela de anexos
CREATE TABLE IF NOT EXISTS demandas_anexos (
    id SERIAL PRIMARY KEY,
    cartao_id INT REFERENCES demandas_cartoes(id) ON DELETE CASCADE,
    nome_original VARCHAR(255) NOT NULL,
    nome_arquivo VARCHAR(255) NOT NULL,
    caminho_arquivo VARCHAR(500) NOT NULL,
    tipo_mime VARCHAR(100) NOT NULL,
    tamanho_bytes BIGINT NOT NULL,
    upload_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMP DEFAULT NOW()
);

-- Tabela de recorrência
CREATE TABLE IF NOT EXISTS demandas_recorrencia (
    id SERIAL PRIMARY KEY,
    cartao_id INT REFERENCES demandas_cartoes(id) ON DELETE CASCADE,
    tipo VARCHAR(50) NOT NULL CHECK (tipo IN ('diario', 'semanal', 'mensal', 'por_conclusao')),
    intervalo INT DEFAULT 1, -- A cada N dias/semanas/meses
    dias_semana TEXT, -- JSON com dias da semana (0-6)
    dia_mes INT, -- Dia do mês (1-31)
    proxima_geracao TIMESTAMP,
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT NOW()
);

-- Tabela de notificações
CREATE TABLE IF NOT EXISTS demandas_notificacoes (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    tipo VARCHAR(50) NOT NULL CHECK (tipo IN ('novo_cartao', 'comentario', 'vencimento', 'reset_semanal')),
    titulo VARCHAR(255) NOT NULL,
    mensagem TEXT NOT NULL,
    cartao_id INT REFERENCES demandas_cartoes(id) ON DELETE CASCADE,
    lida BOOLEAN DEFAULT FALSE,
    lida_em TIMESTAMP,
    enviada_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criada_em TIMESTAMP DEFAULT NOW()
);

-- Tabela de preferências de notificação
CREATE TABLE IF NOT EXISTS demandas_preferencias_notificacao (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE UNIQUE,
    notificacao_painel BOOLEAN DEFAULT TRUE,
    notificacao_email BOOLEAN DEFAULT TRUE,
    notificacao_whatsapp BOOLEAN DEFAULT FALSE,
    alerta_vencimento INT DEFAULT 24, -- Horas antes do vencimento
    criado_em TIMESTAMP DEFAULT NOW(),
    atualizado_em TIMESTAMP DEFAULT NOW()
);

-- Tabela de logs de atividade
CREATE TABLE IF NOT EXISTS demandas_logs (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    acao VARCHAR(100) NOT NULL,
    entidade VARCHAR(50) NOT NULL, -- 'cartao', 'quadro', 'comentario'
    entidade_id INT NOT NULL,
    dados_anteriores JSONB,
    dados_novos JSONB,
    ip_origem INET,
    user_agent TEXT,
    criado_em TIMESTAMP DEFAULT NOW()
);

-- Tabela de configurações do sistema
CREATE TABLE IF NOT EXISTS demandas_configuracoes (
    id SERIAL PRIMARY KEY,
    chave VARCHAR(100) UNIQUE NOT NULL,
    valor TEXT,
    descricao TEXT,
    tipo VARCHAR(50) DEFAULT 'string' CHECK (tipo IN ('string', 'number', 'boolean', 'json')),
    criado_em TIMESTAMP DEFAULT NOW(),
    atualizado_em TIMESTAMP DEFAULT NOW()
);

-- Tabela de produtividade (cache de KPIs)
CREATE TABLE IF NOT EXISTS demandas_produtividade (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    quadro_id INT REFERENCES demandas_quadros(id) ON DELETE CASCADE,
    periodo_inicio DATE NOT NULL,
    periodo_fim DATE NOT NULL,
    cartoes_criados INT DEFAULT 0,
    cartoes_concluidos INT DEFAULT 0,
    cartoes_no_prazo INT DEFAULT 0,
    tempo_medio_conclusao INTERVAL,
    calculado_em TIMESTAMP DEFAULT NOW(),
    UNIQUE(usuario_id, quadro_id, periodo_inicio, periodo_fim)
);

-- Tabela de correio (IMAP)
CREATE TABLE IF NOT EXISTS demandas_correio (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE UNIQUE,
    imap_host VARCHAR(255) NOT NULL,
    imap_porta INT NOT NULL DEFAULT 993,
    imap_usuario VARCHAR(255) NOT NULL,
    imap_senha_criptografada TEXT NOT NULL,
    imap_ssl BOOLEAN DEFAULT TRUE,
    ultima_sincronizacao TIMESTAMP,
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT NOW(),
    atualizado_em TIMESTAMP DEFAULT NOW()
);

-- Tabela de mensagens de e-mail
CREATE TABLE IF NOT EXISTS demandas_mensagens_email (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    uid VARCHAR(255) NOT NULL, -- UID da mensagem no servidor IMAP
    assunto VARCHAR(500),
    remetente VARCHAR(255),
    data_envio TIMESTAMP,
    data_recebimento TIMESTAMP,
    lida BOOLEAN DEFAULT FALSE,
    tem_anexos BOOLEAN DEFAULT FALSE,
    tamanho_bytes INT,
    corpo_texto TEXT,
    corpo_html TEXT,
    sincronizada_em TIMESTAMP DEFAULT NOW(),
    UNIQUE(usuario_id, uid)
);

-- Tabela de anexos de e-mail
CREATE TABLE IF NOT EXISTS demandas_anexos_email (
    id SERIAL PRIMARY KEY,
    mensagem_id INT REFERENCES demandas_mensagens_email(id) ON DELETE CASCADE,
    nome_original VARCHAR(255) NOT NULL,
    nome_arquivo VARCHAR(255) NOT NULL,
    caminho_arquivo VARCHAR(500) NOT NULL,
    tipo_mime VARCHAR(100) NOT NULL,
    tamanho_bytes BIGINT NOT NULL,
    criado_em TIMESTAMP DEFAULT NOW()
);

-- Inserir configurações padrão
INSERT INTO demandas_configuracoes (chave, valor, descricao, tipo) VALUES
('arquivamento_dias', '10', 'Dias para arquivar cartões não recorrentes após conclusão', 'number'),
('reset_semanal_hora', '06:00', 'Hora para executar reset semanal (formato HH:MM)', 'string'),
('notificacao_vencimento_horas', '24', 'Horas antes do vencimento para enviar notificação', 'number'),
('max_anexos_por_cartao', '5', 'Máximo de anexos por cartão', 'number'),
('max_tamanho_anexo_mb', '10', 'Tamanho máximo de anexo em MB', 'number'),
('tipos_anexo_permitidos', '["pdf", "jpg", "jpeg", "png"]', 'Tipos de arquivo permitidos para anexos', 'json'),
('whatsapp_ativado', 'true', 'Ativar integração com WhatsApp', 'boolean'),
('correio_ativado', 'true', 'Ativar sistema de correio (IMAP)', 'boolean'),
('produtividade_ativado', 'true', 'Ativar sistema de produtividade', 'boolean')
ON CONFLICT (chave) DO NOTHING;

-- Adicionar permissões de demandas na tabela usuarios
ALTER TABLE usuarios 
ADD COLUMN IF NOT EXISTS perm_demandas BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_demandas_criar_quadros BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_demandas_ver_produtividade BOOLEAN DEFAULT FALSE;

-- Função para verificar permissões de demandas
CREATE OR REPLACE FUNCTION lc_can_access_demandas()
RETURNS BOOLEAN AS $$
BEGIN
    RETURN COALESCE(
        (SELECT perm_demandas FROM usuarios WHERE id = COALESCE(current_setting('app.user_id', true)::int, 0)),
        FALSE
    );
END;
$$ LANGUAGE plpgsql;

-- Função para verificar se pode criar quadros
CREATE OR REPLACE FUNCTION lc_can_create_quadros()
RETURNS BOOLEAN AS $$
BEGIN
    RETURN COALESCE(
        (SELECT perm_demandas_criar_quadros FROM usuarios WHERE id = COALESCE(current_setting('app.user_id', true)::int, 0)),
        FALSE
    );
END;
$$ LANGUAGE plpgsql;

-- Função para verificar se pode ver produtividade
CREATE OR REPLACE FUNCTION lc_can_view_produtividade()
RETURNS BOOLEAN AS $$
BEGIN
    RETURN COALESCE(
        (SELECT perm_demandas_ver_produtividade FROM usuarios WHERE id = COALESCE(current_setting('app.user_id', true)::int, 0)),
        FALSE
    );
END;
$$ LANGUAGE plpgsql;

-- Função para gerar próximo cartão recorrente
CREATE OR REPLACE FUNCTION gerar_proximo_cartao_recorrente(cartao_id_param INT)
RETURNS INT AS $$
DECLARE
    cartao_original RECORD;
    novo_cartao_id INT;
    proxima_data TIMESTAMP;
BEGIN
    -- Buscar dados do cartão original
    SELECT dc.*, dr.* INTO cartao_original
    FROM demandas_cartoes dc
    LEFT JOIN demandas_recorrencia dr ON dc.id = dr.cartao_id
    WHERE dc.id = cartao_id_param;
    
    IF NOT FOUND THEN
        RETURN NULL;
    END IF;
    
    -- Calcular próxima data baseada no tipo de recorrência
    CASE cartao_original.tipo
        WHEN 'diario' THEN
            proxima_data := cartao_original.vencimento + (cartao_original.intervalo || ' days')::INTERVAL;
        WHEN 'semanal' THEN
            proxima_data := cartao_original.vencimento + (cartao_original.intervalo || ' weeks')::INTERVAL;
        WHEN 'mensal' THEN
            proxima_data := cartao_original.vencimento + (cartao_original.intervalo || ' months')::INTERVAL;
        WHEN 'por_conclusao' THEN
            proxima_data := NOW() + (cartao_original.intervalo || ' days')::INTERVAL;
        ELSE
            RETURN NULL;
    END CASE;
    
    -- Criar novo cartão
    INSERT INTO demandas_cartoes (
        quadro_id, coluna_id, titulo, descricao, responsavel_id, 
        vencimento, prioridade, cor, criado_por
    ) VALUES (
        cartao_original.quadro_id, cartao_original.coluna_id, cartao_original.titulo, 
        cartao_original.descricao, cartao_original.responsavel_id, 
        proxima_data, cartao_original.prioridade, cartao_original.cor, 
        cartao_original.criado_por
    ) RETURNING id INTO novo_cartao_id;
    
    -- Criar recorrência para o novo cartão
    INSERT INTO demandas_recorrencia (
        cartao_id, tipo, intervalo, dias_semana, dia_mes, proxima_geracao, ativo
    ) VALUES (
        novo_cartao_id, cartao_original.tipo, cartao_original.intervalo, 
        cartao_original.dias_semana, cartao_original.dia_mes, 
        proxima_data + (cartao_original.intervalo || ' days')::INTERVAL, TRUE
    );
    
    RETURN novo_cartao_id;
END;
$$ LANGUAGE plpgsql;

-- Função para reset semanal
CREATE OR REPLACE FUNCTION executar_reset_semanal()
RETURNS VOID AS $$
DECLARE
    cartao RECORD;
BEGIN
    -- Buscar cartões marcados como rotina semanal
    FOR cartao IN 
        SELECT dc.*, dq.nome as quadro_nome
        FROM demandas_cartoes dc
        JOIN demandas_quadros dq ON dc.quadro_id = dq.id
        WHERE dc.concluido = TRUE 
        AND dc.descricao ILIKE '%rotina semanal%'
        AND dc.concluido_em >= NOW() - INTERVAL '7 days'
    LOOP
        -- Gerar próximo cartão
        PERFORM gerar_proximo_cartao_recorrente(cartao.id);
        
        -- Log da ação
        INSERT INTO demandas_logs (acao, entidade, entidade_id, dados_novos)
        VALUES ('reset_semanal', 'cartao', cartao.id, 
                jsonb_build_object('quadro', cartao.quadro_nome, 'titulo', cartao.titulo));
    END LOOP;
END;
$$ LANGUAGE plpgsql;

-- Função para arquivar cartões antigos
CREATE OR REPLACE FUNCTION arquivar_cartoes_antigos()
RETURNS VOID AS $$
DECLARE
    dias_arquivamento INT;
BEGIN
    -- Buscar configuração de dias para arquivamento
    SELECT valor::int INTO dias_arquivamento 
    FROM demandas_configuracoes 
    WHERE chave = 'arquivamento_dias';
    
    -- Arquivar cartões não recorrentes concluídos há mais de X dias
    UPDATE demandas_cartoes 
    SET arquivado = TRUE, arquivado_em = NOW(), arquivado_por = 1
    WHERE concluido = TRUE 
    AND concluido_em <= NOW() - (dias_arquivamento || ' days')::INTERVAL
    AND id NOT IN (SELECT cartao_id FROM demandas_recorrencia WHERE ativo = TRUE)
    AND arquivado = FALSE;
END;
$$ LANGUAGE plpgsql;

-- Trigger para atualizar timestamp de atualização
CREATE OR REPLACE FUNCTION update_demandas_timestamp()
RETURNS TRIGGER AS $$
BEGIN
    NEW.atualizado_em = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Aplicar trigger nas tabelas principais
DROP TRIGGER IF EXISTS trg_update_demandas_quadros ON demandas_quadros;
CREATE TRIGGER trg_update_demandas_quadros
    BEFORE UPDATE ON demandas_quadros
    FOR EACH ROW EXECUTE FUNCTION update_demandas_timestamp();

DROP TRIGGER IF EXISTS trg_update_demandas_cartoes ON demandas_cartoes;
CREATE TRIGGER trg_update_demandas_cartoes
    BEFORE UPDATE ON demandas_cartoes
    FOR EACH ROW EXECUTE FUNCTION update_demandas_timestamp();

-- Índices para performance
CREATE INDEX IF NOT EXISTS idx_demandas_cartoes_vencimento ON demandas_cartoes(vencimento) WHERE vencimento IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_demandas_cartoes_responsavel ON demandas_cartoes(responsavel_id) WHERE responsavel_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_demandas_cartoes_quadro ON demandas_cartoes(quadro_id);
CREATE INDEX IF NOT EXISTS idx_demandas_cartoes_concluido ON demandas_cartoes(concluido);
CREATE INDEX IF NOT EXISTS idx_demandas_notificacoes_usuario ON demandas_notificacoes(usuario_id, lida);
CREATE INDEX IF NOT EXISTS idx_demandas_participantes_quadro ON demandas_participantes(quadro_id);
CREATE INDEX IF NOT EXISTS idx_demandas_logs_entidade ON demandas_logs(entidade, entidade_id);