-- ============================================
-- SCHEMA: WEB PUSH NOTIFICATIONS
-- ============================================

-- Atualizar tabela de notificações navegador (já existe, vamos expandir)
ALTER TABLE sistema_notificacoes_navegador 
ADD COLUMN IF NOT EXISTS usuario_id BIGINT REFERENCES usuarios(id) ON DELETE CASCADE,
ADD COLUMN IF NOT EXISTS endpoint TEXT NOT NULL,
ADD COLUMN IF NOT EXISTS chave_publica TEXT,
ADD COLUMN IF NOT EXISTS chave_autenticacao TEXT,
ADD COLUMN IF NOT EXISTS consentimento_permitido BOOLEAN NOT NULL DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS data_autorizacao TIMESTAMP,
ADD COLUMN IF NOT EXISTS ativo BOOLEAN NOT NULL DEFAULT TRUE,
ADD COLUMN IF NOT EXISTS criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
ADD COLUMN IF NOT EXISTS atualizado_em TIMESTAMP NOT NULL DEFAULT NOW();

-- Se a tabela não existir, criar completa
CREATE TABLE IF NOT EXISTS sistema_notificacoes_navegador (
    id BIGSERIAL PRIMARY KEY,
    usuario_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    endpoint TEXT NOT NULL,
    chave_publica TEXT,
    chave_autenticacao TEXT,
    consentimento_permitido BOOLEAN NOT NULL DEFAULT FALSE,
    data_autorizacao TIMESTAMP,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE(usuario_id, endpoint)
);

-- Tabela de logs de envio de push (opcional, para debug)
CREATE TABLE IF NOT EXISTS sistema_push_logs (
    id BIGSERIAL PRIMARY KEY,
    usuario_id BIGINT REFERENCES usuarios(id) ON DELETE SET NULL,
    notificacao_id BIGINT REFERENCES sistema_notificacoes_pendentes(id) ON DELETE SET NULL,
    endpoint TEXT,
    sucesso BOOLEAN NOT NULL DEFAULT FALSE,
    erro TEXT,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Índices
CREATE INDEX IF NOT EXISTS idx_sistema_notificacoes_navegador_usuario ON sistema_notificacoes_navegador(usuario_id);
CREATE INDEX IF NOT EXISTS idx_sistema_notificacoes_navegador_ativo ON sistema_notificacoes_navegador(ativo, consentimento_permitido);
CREATE INDEX IF NOT EXISTS idx_sistema_push_logs_usuario ON sistema_push_logs(usuario_id);
CREATE INDEX IF NOT EXISTS idx_sistema_push_logs_criado ON sistema_push_logs(criado_em);

-- Comentários
COMMENT ON TABLE sistema_notificacoes_navegador IS 'Subscriptions de Web Push Notifications dos usuários';
COMMENT ON TABLE sistema_push_logs IS 'Logs de envio de notificações push';

-- Função para verificar se usuário tem consentimento válido
CREATE OR REPLACE FUNCTION usuario_tem_push_consentimento(p_usuario_id BIGINT)
RETURNS BOOLEAN AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 
        FROM sistema_notificacoes_navegador 
        WHERE usuario_id = p_usuario_id 
        AND consentimento_permitido = TRUE 
        AND ativo = TRUE
    );
END;
$$ LANGUAGE plpgsql;
