-- 075_demandas_internas.sql
-- Novo módulo Demandas: solicitações internas por conversa, responsável, prazo e evento opcional.

CREATE TABLE IF NOT EXISTS demandas_internas (
    id SERIAL PRIMARY KEY,
    titulo VARCHAR(180) NOT NULL,
    descricao TEXT,
    criador_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    responsavel_tipo VARCHAR(20) NOT NULL DEFAULT 'usuario' CHECK (responsavel_tipo IN ('usuario', 'setor')),
    responsavel_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    responsavel_setor VARCHAR(120),
    evento_tipo VARCHAR(40),
    evento_id INTEGER,
    evento_data DATE,
    evento_local VARCHAR(180),
    evento_nome VARCHAR(220),
    evento_whatsapp VARCHAR(40),
    status VARCHAR(20) NOT NULL DEFAULT 'aberta' CHECK (status IN ('aberta', 'em_andamento', 'aguardando', 'resolvida', 'encerrada', 'cancelada')),
    prioridade VARCHAR(20) NOT NULL DEFAULT 'normal' CHECK (prioridade IN ('baixa', 'normal', 'alta', 'urgente')),
    prazo DATE NOT NULL,
    encerrada_em TIMESTAMPTZ,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS demandas_internas_mensagens (
    id SERIAL PRIMARY KEY,
    demanda_id INTEGER NOT NULL REFERENCES demandas_internas(id) ON DELETE CASCADE,
    autor_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    mensagem TEXT NOT NULL,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS demandas_internas_citacoes (
    id SERIAL PRIMARY KEY,
    demanda_id INTEGER NOT NULL REFERENCES demandas_internas(id) ON DELETE CASCADE,
    usuario_id INTEGER REFERENCES usuarios(id) ON DELETE CASCADE,
    setor VARCHAR(120),
    mensagem_id INTEGER REFERENCES demandas_internas_mensagens(id) ON DELETE SET NULL,
    citado_por INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS demandas_internas_anexos (
    id SERIAL PRIMARY KEY,
    demanda_id INTEGER NOT NULL REFERENCES demandas_internas(id) ON DELETE CASCADE,
    upload_por INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    nome_original TEXT NOT NULL,
    mime_type VARCHAR(120),
    tamanho_bytes BIGINT,
    chave_storage TEXT,
    url TEXT,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS demandas_internas_historico (
    id SERIAL PRIMARY KEY,
    demanda_id INTEGER NOT NULL REFERENCES demandas_internas(id) ON DELETE CASCADE,
    usuario_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    acao VARCHAR(60) NOT NULL,
    resumo TEXT NOT NULL,
    dados JSONB,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_demandas_internas_prazo ON demandas_internas(prazo);
CREATE INDEX IF NOT EXISTS idx_demandas_internas_responsavel ON demandas_internas(responsavel_tipo, responsavel_id, responsavel_setor);
CREATE INDEX IF NOT EXISTS idx_demandas_internas_criador ON demandas_internas(criador_id);
CREATE INDEX IF NOT EXISTS idx_demandas_internas_status ON demandas_internas(status);
CREATE INDEX IF NOT EXISTS idx_demandas_internas_citacoes_usuario ON demandas_internas_citacoes(usuario_id);
CREATE INDEX IF NOT EXISTS idx_demandas_internas_mensagens_demanda ON demandas_internas_mensagens(demanda_id, criado_em);
CREATE INDEX IF NOT EXISTS idx_demandas_internas_historico_demanda ON demandas_internas_historico(demanda_id, criado_em);
