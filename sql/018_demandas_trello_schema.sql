-- 018_demandas_trello_schema.sql
-- Sistema de Demandas estilo Trello - Schema completo
-- PRESERVA tabelas antigas para migração gradual

-- 1. Quadros (Boards)
CREATE TABLE IF NOT EXISTS demandas_boards (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descricao TEXT,
    criado_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMPTZ DEFAULT NOW(),
    cor VARCHAR(7) DEFAULT '#3b82f6', -- Cor do quadro
    ativo BOOLEAN DEFAULT TRUE
);

-- 1.1 Visibilidade por quadro (opcional)
-- Se um quadro não tiver registros aqui, permanece visível para todos os usuários.
CREATE TABLE IF NOT EXISTS demandas_boards_usuarios (
    id SERIAL PRIMARY KEY,
    board_id INT NOT NULL REFERENCES demandas_boards(id) ON DELETE CASCADE,
    usuario_id INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    criado_em TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(board_id, usuario_id)
);

-- 2. Listas (Colunas do quadro)
CREATE TABLE IF NOT EXISTS demandas_listas (
    id SERIAL PRIMARY KEY,
    board_id INT REFERENCES demandas_boards(id) ON DELETE CASCADE,
    nome VARCHAR(100) NOT NULL,
    posicao INT DEFAULT 0,
    criada_em TIMESTAMPTZ DEFAULT NOW()
);

-- 3. Cards (Tarefas)
CREATE TABLE IF NOT EXISTS demandas_cards (
    id SERIAL PRIMARY KEY,
    lista_id INT REFERENCES demandas_listas(id) ON DELETE CASCADE,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT,
    prazo DATE,
    status VARCHAR(20) DEFAULT 'pendente' CHECK (status IN ('pendente', 'em_andamento', 'concluido', 'cancelado')),
    criador_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMPTZ DEFAULT NOW(),
    atualizado_em TIMESTAMPTZ DEFAULT NOW(),
    posicao INT DEFAULT 0, -- Posição na lista (para drag & drop)
    cor VARCHAR(7), -- Cor do card (para destaque)
    prioridade VARCHAR(20) DEFAULT 'media' CHECK (prioridade IN ('baixa', 'media', 'alta', 'urgente')),
    categoria VARCHAR(100)
);

-- 4. Usuários atribuídos aos cards
CREATE TABLE IF NOT EXISTS demandas_cards_usuarios (
    id SERIAL PRIMARY KEY,
    card_id INT REFERENCES demandas_cards(id) ON DELETE CASCADE,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    atribuido_em TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(card_id, usuario_id)
);

-- 5. Comentários nos cards
CREATE TABLE IF NOT EXISTS demandas_comentarios (
    id SERIAL PRIMARY KEY,
    card_id INT REFERENCES demandas_cards(id) ON DELETE CASCADE,
    autor_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    mensagem TEXT NOT NULL,
    criado_em TIMESTAMPTZ DEFAULT NOW(),
    editado_em TIMESTAMPTZ,
    editado BOOLEAN DEFAULT FALSE
);

-- 6. Anexos nos cards
CREATE TABLE IF NOT EXISTS demandas_arquivos (
    id SERIAL PRIMARY KEY,
    card_id INT REFERENCES demandas_cards(id) ON DELETE CASCADE,
    nome_original VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100),
    tamanho_bytes INT,
    chave_storage VARCHAR(255), -- Chave no Magalu Cloud
    upload_por INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMPTZ DEFAULT NOW()
);

-- 7. Notificações
CREATE TABLE IF NOT EXISTS demandas_notificacoes (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    tipo VARCHAR(50) NOT NULL, -- 'mencao', 'tarefa_atribuida', 'card_movido', 'comentario', etc.
    referencia_id INT, -- ID do card relacionado
    mensagem TEXT NOT NULL,
    lida BOOLEAN DEFAULT FALSE,
    criada_em TIMESTAMPTZ DEFAULT NOW()
);

-- 8. Demandas Fixas (Rotinas automáticas)
CREATE TABLE IF NOT EXISTS demandas_fixas (
    id SERIAL PRIMARY KEY,
    board_id INT REFERENCES demandas_boards(id) ON DELETE CASCADE,
    lista_id INT REFERENCES demandas_listas(id) ON DELETE CASCADE,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT,
    periodicidade VARCHAR(20) NOT NULL CHECK (periodicidade IN ('diaria', 'semanal', 'mensal')),
    dia_semana INT CHECK (dia_semana >= 0 AND dia_semana <= 6), -- 0=domingo, 6=sábado
    dia_mes INT CHECK (dia_mes >= 1 AND dia_mes <= 31), -- Para mensal
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMPTZ DEFAULT NOW()
);

-- 9. Log de demandas fixas geradas
CREATE TABLE IF NOT EXISTS demandas_fixas_log (
    id SERIAL PRIMARY KEY,
    demanda_fixa_id INT REFERENCES demandas_fixas(id) ON DELETE CASCADE,
    card_id INT REFERENCES demandas_cards(id) ON DELETE SET NULL,
    gerado_em TIMESTAMPTZ DEFAULT NOW(),
    dia_gerado DATE DEFAULT CURRENT_DATE -- Para controle de duplicatas
);

-- Constraint única para evitar duplicatas no mesmo dia
CREATE UNIQUE INDEX IF NOT EXISTS idx_fixas_log_unique 
ON demandas_fixas_log(demanda_fixa_id, dia_gerado);

-- ÍNDICES PARA PERFORMANCE
CREATE INDEX IF NOT EXISTS idx_boards_criado_por ON demandas_boards(criado_por);
CREATE INDEX IF NOT EXISTS idx_boards_usuarios_board ON demandas_boards_usuarios(board_id);
CREATE INDEX IF NOT EXISTS idx_boards_usuarios_usuario ON demandas_boards_usuarios(usuario_id);
CREATE INDEX IF NOT EXISTS idx_listas_board ON demandas_listas(board_id);
CREATE INDEX IF NOT EXISTS idx_listas_posicao ON demandas_listas(board_id, posicao);
CREATE INDEX IF NOT EXISTS idx_cards_lista ON demandas_cards(lista_id);
CREATE INDEX IF NOT EXISTS idx_cards_posicao ON demandas_cards(lista_id, posicao);
CREATE INDEX IF NOT EXISTS idx_cards_status ON demandas_cards(status);
CREATE INDEX IF NOT EXISTS idx_cards_prazo ON demandas_cards(prazo) WHERE prazo IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_cards_usuarios_card ON demandas_cards_usuarios(card_id);
CREATE INDEX IF NOT EXISTS idx_cards_usuarios_user ON demandas_cards_usuarios(usuario_id);
CREATE INDEX IF NOT EXISTS idx_comentarios_card ON demandas_comentarios(card_id);
CREATE INDEX IF NOT EXISTS idx_arquivos_card ON demandas_arquivos(card_id);
CREATE INDEX IF NOT EXISTS idx_notificacoes_user ON demandas_notificacoes(usuario_id, lida);
CREATE INDEX IF NOT EXISTS idx_fixas_board ON demandas_fixas(board_id);

-- COMENTÁRIOS PARA DOCUMENTAÇÃO
COMMENT ON TABLE demandas_boards IS 'Quadros estilo Trello - cada quadro contém múltiplas listas';
COMMENT ON TABLE demandas_boards_usuarios IS 'Usuários que podem visualizar um quadro específico; sem registros = quadro visível para todos';
COMMENT ON TABLE demandas_listas IS 'Colunas dentro de um quadro - ex: "To Do", "Em Progresso", "Feito"';
COMMENT ON TABLE demandas_cards IS 'Cards (tarefas) dentro das listas - podem ser movidos entre listas';
COMMENT ON TABLE demandas_cards_usuarios IS 'Relacionamento muitos-para-muitos: usuários atribuídos a cards';
COMMENT ON TABLE demandas_comentarios IS 'Comentários nos cards com suporte a @menções';
COMMENT ON TABLE demandas_arquivos IS 'Anexos dos cards armazenados no Magalu Cloud';
COMMENT ON TABLE demandas_notificacoes IS 'Notificações de menções, atribuições e movimentações';
COMMENT ON TABLE demandas_fixas IS 'Templates de demandas que são geradas automaticamente via cron';
COMMENT ON TABLE demandas_fixas_log IS 'Log de quando cada demanda fixa foi gerada para evitar duplicatas';
