-- Checklist de planejamento dos eventos.
-- Mantido separado do checklist operacional usado na execução do evento.

ALTER TABLE IF EXISTS comercial_eventos_painel
    ADD COLUMN IF NOT EXISTS pacote_evento_id BIGINT NULL
        REFERENCES logistica_pacotes_evento(id) ON DELETE SET NULL;

ALTER TABLE IF EXISTS comercial_eventos_painel
    ADD COLUMN IF NOT EXISTS status VARCHAR(40) NOT NULL DEFAULT 'criado_painel';

CREATE TABLE IF NOT EXISTS eventos_checklist_modelos (
    id BIGSERIAL PRIMARY KEY,
    nome VARCHAR(180) NOT NULL,
    origem VARCHAR(20) NOT NULL CHECK (origem IN ('tipo', 'pacote')),
    tipo_evento_key VARCHAR(80) NULL,
    pacote_evento_id BIGINT NULL REFERENCES logistica_pacotes_evento(id) ON DELETE SET NULL,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    versao INTEGER NOT NULL DEFAULT 1,
    created_by INTEGER NULL REFERENCES usuarios(id) ON DELETE SET NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMP NULL,
    CHECK (
        (origem = 'tipo' AND tipo_evento_key IS NOT NULL AND pacote_evento_id IS NULL)
        OR
        (origem = 'pacote' AND pacote_evento_id IS NOT NULL AND tipo_evento_key IS NULL)
    )
);

CREATE INDEX IF NOT EXISTS idx_eventos_checklist_modelos_aplicacao
    ON eventos_checklist_modelos(origem, tipo_evento_key, pacote_evento_id, ativo)
    WHERE deleted_at IS NULL;

CREATE TABLE IF NOT EXISTS eventos_checklist_modelo_tarefas (
    id BIGSERIAL PRIMARY KEY,
    modelo_id BIGINT NOT NULL REFERENCES eventos_checklist_modelos(id) ON DELETE CASCADE,
    titulo VARCHAR(220) NOT NULL,
    descricao TEXT NOT NULL DEFAULT '',
    ordem INTEGER NOT NULL DEFAULT 0,
    responsabilidade VARCHAR(20) NOT NULL
        CHECK (responsabilidade IN ('usuario', 'setor', 'cliente')),
    responsavel_usuario_id INTEGER NULL REFERENCES usuarios(id) ON DELETE SET NULL,
    responsavel_setor VARCHAR(120) NULL,
    visivel_cliente BOOLEAN NOT NULL DEFAULT FALSE,
    exige_validacao BOOLEAN NOT NULL DEFAULT FALSE,
    regra_vencimento VARCHAR(30) NOT NULL DEFAULT 'sem_data'
        CHECK (regra_vencimento IN (
            'sem_data', 'dia_evento', 'antes_evento', 'depois_evento',
            'depois_cadastro', 'depois_insercao'
        )),
    dias INTEGER NOT NULL DEFAULT 0 CHECK (dias >= 0),
    whatsapp_mensagem TEXT NOT NULL DEFAULT '',
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_eventos_checklist_modelo_tarefas
    ON eventos_checklist_modelo_tarefas(modelo_id, ativo, ordem, id);

CREATE TABLE IF NOT EXISTS eventos_checklist_tarefas (
    id BIGSERIAL PRIMARY KEY,
    evento_id INTEGER NOT NULL REFERENCES logistica_eventos_espelho(id) ON DELETE CASCADE,
    modelo_id BIGINT NULL REFERENCES eventos_checklist_modelos(id) ON DELETE SET NULL,
    modelo_tarefa_id BIGINT NULL REFERENCES eventos_checklist_modelo_tarefas(id) ON DELETE SET NULL,
    modelo_versao INTEGER NOT NULL DEFAULT 1,
    origem_modelo VARCHAR(20) NOT NULL CHECK (origem_modelo IN ('tipo', 'pacote', 'manual')),
    titulo VARCHAR(220) NOT NULL,
    descricao TEXT NOT NULL DEFAULT '',
    ordem INTEGER NOT NULL DEFAULT 0,
    responsabilidade VARCHAR(20) NOT NULL
        CHECK (responsabilidade IN ('usuario', 'setor', 'cliente')),
    responsavel_usuario_id INTEGER NULL REFERENCES usuarios(id) ON DELETE SET NULL,
    responsavel_setor VARCHAR(120) NULL,
    visivel_cliente BOOLEAN NOT NULL DEFAULT FALSE,
    exige_validacao BOOLEAN NOT NULL DEFAULT FALSE,
    regra_vencimento VARCHAR(30) NOT NULL DEFAULT 'sem_data',
    dias INTEGER NOT NULL DEFAULT 0,
    vencimento DATE NULL,
    vencimento_manual BOOLEAN NOT NULL DEFAULT FALSE,
    status VARCHAR(30) NOT NULL DEFAULT 'pendente'
        CHECK (status IN (
            'pendente', 'em_andamento', 'aguardando_validacao',
            'concluida', 'desativada'
        )),
    whatsapp_mensagem TEXT NOT NULL DEFAULT '',
    whatsapp_tentado_em TIMESTAMP NULL,
    whatsapp_status VARCHAR(30) NULL,
    whatsapp_destinatario VARCHAR(40) NULL,
    concluida_em TIMESTAMP NULL,
    concluida_por INTEGER NULL REFERENCES usuarios(id) ON DELETE SET NULL,
    concluida_pelo_cliente BOOLEAN NOT NULL DEFAULT FALSE,
    desativada_em TIMESTAMP NULL,
    desativada_por INTEGER NULL REFERENCES usuarios(id) ON DELETE SET NULL,
    motivo_desativacao TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_eventos_checklist_tarefa_unica
    ON eventos_checklist_tarefas(evento_id, modelo_tarefa_id)
    WHERE modelo_tarefa_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_eventos_checklist_tarefas_evento
    ON eventos_checklist_tarefas(evento_id, vencimento, ordem, id);

CREATE INDEX IF NOT EXISTS idx_eventos_checklist_tarefas_usuario
    ON eventos_checklist_tarefas(responsavel_usuario_id, status, vencimento);

CREATE INDEX IF NOT EXISTS idx_eventos_checklist_tarefas_setor
    ON eventos_checklist_tarefas(LOWER(responsavel_setor), status, vencimento)
    WHERE responsavel_setor IS NOT NULL;

CREATE TABLE IF NOT EXISTS eventos_checklist_historico (
    id BIGSERIAL PRIMARY KEY,
    tarefa_id BIGINT NOT NULL REFERENCES eventos_checklist_tarefas(id) ON DELETE CASCADE,
    evento_id INTEGER NOT NULL REFERENCES logistica_eventos_espelho(id) ON DELETE CASCADE,
    acao VARCHAR(60) NOT NULL,
    detalhes TEXT NOT NULL DEFAULT '',
    dados_antes JSONB NULL,
    dados_depois JSONB NULL,
    ator_usuario_id INTEGER NULL REFERENCES usuarios(id) ON DELETE SET NULL,
    ator_tipo VARCHAR(20) NOT NULL DEFAULT 'interno'
        CHECK (ator_tipo IN ('interno', 'cliente', 'sistema')),
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_eventos_checklist_historico_tarefa
    ON eventos_checklist_historico(tarefa_id, created_at DESC);

CREATE TABLE IF NOT EXISTS eventos_checklist_config (
    id SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    portal_cliente_ativo BOOLEAN NOT NULL DEFAULT FALSE,
    whatsapp_cliente_ativo BOOLEAN NOT NULL DEFAULT FALSE,
    whatsapp_hora TIME NOT NULL DEFAULT '09:00',
    updated_by INTEGER NULL REFERENCES usuarios(id) ON DELETE SET NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

INSERT INTO eventos_checklist_config (id)
VALUES (1)
ON CONFLICT (id) DO NOTHING;
