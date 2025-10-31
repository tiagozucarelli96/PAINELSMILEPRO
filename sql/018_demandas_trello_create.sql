-- 018_demandas_trello_create.sql
-- Criar tabelas na ordem correta

-- 1. Quadros
CREATE TABLE IF NOT EXISTS demandas_boards (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descricao TEXT,
    criado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMPTZ DEFAULT NOW(),
    cor VARCHAR(7) DEFAULT '#3b82f6',
    ativo BOOLEAN DEFAULT TRUE
);

-- 2. Listas
CREATE TABLE IF NOT EXISTS demandas_listas (
    id SERIAL PRIMARY KEY,
    board_id INT REFERENCES demandas_boards(id) ON DELETE CASCADE,
    nome VARCHAR(100) NOT NULL,
    posicao INT DEFAULT 0,
    criada_em TIMESTAMPTZ DEFAULT NOW()
);

-- 3. Cards
CREATE TABLE IF NOT EXISTS demandas_cards (
    id SERIAL PRIMARY KEY,
    lista_id INT REFERENCES demandas_listas(id) ON DELETE CASCADE,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT,
    prazo DATE,
    status VARCHAR(20) DEFAULT 'pendente',
    criador_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMPTZ DEFAULT NOW(),
    atualizado_em TIMESTAMPTZ DEFAULT NOW(),
    posicao INT DEFAULT 0,
    cor VARCHAR(7),
    prioridade VARCHAR(20) DEFAULT 'media',
    categoria VARCHAR(100)
);

ALTER TABLE demandas_cards ADD CONSTRAINT check_status 
CHECK (status IN ('pendente', 'em_andamento', 'concluido', 'cancelado'));

ALTER TABLE demandas_cards ADD CONSTRAINT check_prioridade 
CHECK (prioridade IN ('baixa', 'media', 'alta', 'urgente'));

-- 4. Usuários dos cards
CREATE TABLE IF NOT EXISTS demandas_cards_usuarios (
    id SERIAL PRIMARY KEY,
    card_id INT REFERENCES demandas_cards(id) ON DELETE CASCADE,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    atribuido_em TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(card_id, usuario_id)
);

-- 5. Comentários
CREATE TABLE IF NOT EXISTS demandas_comentarios_trello (
    id SERIAL PRIMARY KEY,
    card_id INT REFERENCES demandas_cards(id) ON DELETE CASCADE,
    autor_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    mensagem TEXT NOT NULL,
    criado_em TIMESTAMPTZ DEFAULT NOW(),
    editado_em TIMESTAMPTZ,
    editado BOOLEAN DEFAULT FALSE
);

-- 6. Anexos
CREATE TABLE IF NOT EXISTS demandas_arquivos_trello (
    id SERIAL PRIMARY KEY,
    card_id INT REFERENCES demandas_cards(id) ON DELETE CASCADE,
    nome_original VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100),
    tamanho_bytes INT,
    chave_storage VARCHAR(255),
    upload_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMPTZ DEFAULT NOW()
);

-- 7. Notificações
CREATE TABLE IF NOT EXISTS demandas_notificacoes (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    tipo VARCHAR(50) NOT NULL,
    referencia_id INT,
    mensagem TEXT NOT NULL,
    lida BOOLEAN DEFAULT FALSE,
    criada_em TIMESTAMPTZ DEFAULT NOW()
);

-- 8. Demandas Fixas
CREATE TABLE IF NOT EXISTS demandas_fixas (
    id SERIAL PRIMARY KEY,
    board_id INT REFERENCES demandas_boards(id) ON DELETE CASCADE,
    lista_id INT REFERENCES demandas_listas(id) ON DELETE CASCADE,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT,
    periodicidade VARCHAR(20) NOT NULL,
    dia_semana INT,
    dia_mes INT,
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMPTZ DEFAULT NOW()
);

ALTER TABLE demandas_fixas ADD CONSTRAINT check_periodicidade 
CHECK (periodicidade IN ('diaria', 'semanal', 'mensal'));

ALTER TABLE demandas_fixas ADD CONSTRAINT check_dia_semana 
CHECK (dia_semana IS NULL OR (dia_semana >= 0 AND dia_semana <= 6));

ALTER TABLE demandas_fixas ADD CONSTRAINT check_dia_mes 
CHECK (dia_mes IS NULL OR (dia_mes >= 1 AND dia_mes <= 31));

-- 9. Log de fixas
CREATE TABLE IF NOT EXISTS demandas_fixas_log (
    id SERIAL PRIMARY KEY,
    demanda_fixa_id INT REFERENCES demandas_fixas(id) ON DELETE CASCADE,
    card_id INT REFERENCES demandas_cards(id) ON DELETE SET NULL,
    gerado_em TIMESTAMPTZ DEFAULT NOW(),
    dia_gerado DATE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_fixas_log_unique 
ON demandas_fixas_log(demanda_fixa_id, dia_gerado);

-- Índices
CREATE INDEX IF NOT EXISTS idx_boards_criado_por ON demandas_boards(criado_por);
CREATE INDEX IF NOT EXISTS idx_listas_board ON demandas_listas(board_id);
CREATE INDEX IF NOT EXISTS idx_listas_posicao ON demandas_listas(board_id, posicao);
CREATE INDEX IF NOT EXISTS idx_cards_lista ON demandas_cards(lista_id);
CREATE INDEX IF NOT EXISTS idx_cards_posicao ON demandas_cards(lista_id, posicao);
CREATE INDEX IF NOT EXISTS idx_cards_status ON demandas_cards(status);
CREATE INDEX IF NOT EXISTS idx_cards_prazo ON demandas_cards(prazo) WHERE prazo IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_cards_usuarios_card ON demandas_cards_usuarios(card_id);
CREATE INDEX IF NOT EXISTS idx_cards_usuarios_user ON demandas_cards_usuarios(usuario_id);
CREATE INDEX IF NOT EXISTS idx_comentarios_card ON demandas_comentarios_trello(card_id);
CREATE INDEX IF NOT EXISTS idx_arquivos_card ON demandas_arquivos_trello(card_id);
CREATE INDEX IF NOT EXISTS idx_notificacoes_user ON demandas_notificacoes(usuario_id, lida);
CREATE INDEX IF NOT EXISTS idx_fixas_board ON demandas_fixas(board_id);

