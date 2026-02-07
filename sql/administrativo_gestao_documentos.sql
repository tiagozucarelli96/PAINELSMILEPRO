-- Gestão de Documentos (Administrativo)
-- Execução idempotente

CREATE TABLE IF NOT EXISTS administrativo_documentos_colaboradores (
    id BIGSERIAL PRIMARY KEY,
    usuario_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    tipo_documento VARCHAR(40) NOT NULL DEFAULT 'outro',
    titulo VARCHAR(255) NOT NULL,
    competencia VARCHAR(7),
    descricao TEXT,
    arquivo_url TEXT,
    arquivo_nome VARCHAR(255) NOT NULL,
    chave_storage TEXT,
    exibir_minha_conta BOOLEAN NOT NULL DEFAULT TRUE,
    exigir_assinatura BOOLEAN NOT NULL DEFAULT FALSE,
    status_assinatura VARCHAR(40) NOT NULL DEFAULT 'nao_solicitada',
    clicksign_envelope_id VARCHAR(120),
    clicksign_document_id VARCHAR(120),
    clicksign_signer_id VARCHAR(120),
    clicksign_sign_url TEXT,
    clicksign_ultimo_erro TEXT,
    clicksign_payload JSONB,
    enviado_assinatura_em TIMESTAMPTZ,
    assinado_em TIMESTAMPTZ,
    criado_por_usuario_id BIGINT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_admin_docs_usuario ON administrativo_documentos_colaboradores(usuario_id);
CREATE INDEX IF NOT EXISTS idx_admin_docs_tipo ON administrativo_documentos_colaboradores(tipo_documento);
CREATE INDEX IF NOT EXISTS idx_admin_docs_status_assinatura ON administrativo_documentos_colaboradores(status_assinatura);
CREATE INDEX IF NOT EXISTS idx_admin_docs_criado_em ON administrativo_documentos_colaboradores(criado_em DESC);
