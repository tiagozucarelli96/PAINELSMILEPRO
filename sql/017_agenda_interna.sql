-- 017_agenda_interna.sql
-- Sistema de Agenda Interna - Painel Smile PRO

-- Tabela de espaços (seed fixa)
CREATE TABLE IF NOT EXISTS agenda_espacos (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    slug VARCHAR(50) UNIQUE NOT NULL,
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT NOW()
);

-- Inserir espaços padrão
INSERT INTO agenda_espacos (id, nome, slug) VALUES
(1, 'Espaço Garden', 'garden'),
(2, 'Diverkids Buffet', 'diverkids'),
(3, 'Espaço Cristal', 'cristal'),
(4, 'Lisbon Un. 1', 'lisbon')
ON CONFLICT (id) DO NOTHING;

-- Adicionar campos à tabela usuarios
ALTER TABLE usuarios 
ADD COLUMN IF NOT EXISTS cor_agenda VARCHAR(7) DEFAULT '#1E40AF',
ADD COLUMN IF NOT EXISTS agenda_lembrete_padrao_min INT DEFAULT 60,
ADD COLUMN IF NOT EXISTS perm_forcar_conflito BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_gerir_eventos_outros BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_agenda_ver BOOLEAN DEFAULT TRUE,
ADD COLUMN IF NOT EXISTS perm_agenda_meus BOOLEAN DEFAULT TRUE,
ADD COLUMN IF NOT EXISTS perm_agenda_relatorios BOOLEAN DEFAULT FALSE;

-- Tabela de eventos
CREATE TABLE IF NOT EXISTS agenda_eventos (
    id SERIAL PRIMARY KEY,
    tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('visita', 'bloqueio', 'outro')),
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT,
    inicio TIMESTAMP NOT NULL,
    fim TIMESTAMP NOT NULL,
    responsavel_usuario_id INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    criado_por_usuario_id INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    espaco_id INT REFERENCES agenda_espacos(id) ON DELETE SET NULL,
    lembrete_minutos INT DEFAULT 60,
    status VARCHAR(20) DEFAULT 'agendado' CHECK (status IN ('agendado', 'realizado', 'no_show', 'cancelado')),
    compareceu BOOLEAN DEFAULT FALSE,
    fechou_contrato BOOLEAN DEFAULT FALSE,
    fechou_ref VARCHAR(255),
    participantes JSONB DEFAULT '[]',
    cor_evento VARCHAR(7),
    criado_em TIMESTAMP DEFAULT NOW(),
    atualizado_em TIMESTAMP DEFAULT NOW()
);

-- Tabela de lembretes enviados
CREATE TABLE IF NOT EXISTS agenda_lembretes (
    id SERIAL PRIMARY KEY,
    evento_id INT NOT NULL REFERENCES agenda_eventos(id) ON DELETE CASCADE,
    usuario_id INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    tipo_lembrete VARCHAR(20) NOT NULL CHECK (tipo_lembrete IN ('criacao', 'alteracao', 'proximidade')),
    enviado_em TIMESTAMP DEFAULT NOW(),
    metodo VARCHAR(20) DEFAULT 'email' CHECK (metodo IN ('email', 'painel', 'ambos'))
);

-- Tabela de tokens ICS
CREATE TABLE IF NOT EXISTS agenda_tokens_ics (
    id SERIAL PRIMARY KEY,
    usuario_id INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE UNIQUE,
    token VARCHAR(64) NOT NULL UNIQUE,
    ativo BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT NOW(),
    ultimo_acesso TIMESTAMP
);

-- Função para verificar conflitos
CREATE OR REPLACE FUNCTION verificar_conflito_agenda(
    p_responsavel_id INT,
    p_espaco_id INT,
    p_inicio TIMESTAMP,
    p_fim TIMESTAMP,
    p_evento_id INT DEFAULT NULL
)
RETURNS TABLE(
    conflito_responsavel BOOLEAN,
    conflito_espaco BOOLEAN,
    evento_conflito_id INT,
    evento_conflito_titulo VARCHAR
) AS $$
BEGIN
    -- Verificar conflito com responsável
    SELECT 
        TRUE,
        FALSE,
        ae.id,
        ae.titulo
    INTO 
        conflito_responsavel,
        conflito_espaco,
        evento_conflito_id,
        evento_conflito_titulo
    FROM agenda_eventos ae
    WHERE ae.responsavel_usuario_id = p_responsavel_id
    AND ae.status != 'cancelado'
    AND (p_evento_id IS NULL OR ae.id != p_evento_id)
    AND (
        (ae.inicio <= p_inicio AND ae.fim > p_inicio) OR
        (ae.inicio < p_fim AND ae.fim >= p_fim) OR
        (ae.inicio >= p_inicio AND ae.fim <= p_fim)
    )
    LIMIT 1;
    
    -- Se não há conflito com responsável, verificar conflito com espaço
    IF NOT FOUND THEN
        SELECT 
            FALSE,
            TRUE,
            ae.id,
            ae.titulo
        INTO 
            conflito_responsavel,
            conflito_espaco,
            evento_conflito_id,
            evento_conflito_titulo
        FROM agenda_eventos ae
        WHERE ae.espaco_id = p_espaco_id
        AND ae.tipo = 'visita'
        AND ae.status != 'cancelado'
        AND (p_evento_id IS NULL OR ae.id != p_evento_id)
        AND (
            (ae.inicio <= p_inicio AND ae.fim > p_inicio) OR
            (ae.inicio < p_fim AND ae.fim >= p_fim) OR
            (ae.inicio >= p_inicio AND ae.fim <= p_fim)
        )
        LIMIT 1;
    END IF;
    
    -- Se não há conflitos, retornar FALSE
    IF NOT FOUND THEN
        RETURN QUERY SELECT FALSE, FALSE, NULL::INT, NULL::VARCHAR;
    END IF;
END;
$$ LANGUAGE plpgsql;

-- Função para gerar token ICS
CREATE OR REPLACE FUNCTION gerar_token_ics(p_usuario_id INT)
RETURNS VARCHAR(64) AS $$
DECLARE
    novo_token VARCHAR(64);
BEGIN
    -- Gerar token único
    novo_token := encode(gen_random_bytes(32), 'hex');
    
    -- Inserir ou atualizar token
    INSERT INTO agenda_tokens_ics (usuario_id, token)
    VALUES (p_usuario_id, novo_token)
    ON CONFLICT (usuario_id) DO UPDATE SET 
        token = EXCLUDED.token,
        ativo = TRUE,
        ultimo_acesso = NOW();
    
    RETURN novo_token;
END;
$$ LANGUAGE plpgsql;

-- Função para obter próximos eventos
CREATE OR REPLACE FUNCTION obter_proximos_eventos(p_usuario_id INT, p_horas INT DEFAULT 24)
RETURNS TABLE(
    id INT,
    tipo VARCHAR,
    titulo VARCHAR,
    inicio TIMESTAMP,
    fim TIMESTAMP,
    espaco_nome VARCHAR,
    responsavel_nome VARCHAR,
    cor_evento VARCHAR,
    status VARCHAR
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        ae.id,
        ae.tipo,
        ae.titulo,
        ae.inicio,
        ae.fim,
        esp.nome as espaco_nome,
        u.nome as responsavel_nome,
        COALESCE(ae.cor_evento, u.cor_agenda) as cor_evento,
        ae.status
    FROM agenda_eventos ae
    JOIN usuarios u ON ae.responsavel_usuario_id = u.id
    LEFT JOIN agenda_espacos esp ON ae.espaco_id = esp.id
    WHERE ae.responsavel_usuario_id = p_usuario_id
    AND ae.status != 'cancelado'
    AND ae.inicio BETWEEN NOW() AND NOW() + (p_horas || ' hours')::INTERVAL
    ORDER BY ae.inicio ASC;
END;
$$ LANGUAGE plpgsql;

-- Função para calcular conversão
CREATE OR REPLACE FUNCTION calcular_conversao_visitas(
    p_data_inicio DATE,
    p_data_fim DATE,
    p_espaco_id INT DEFAULT NULL,
    p_responsavel_id INT DEFAULT NULL
)
RETURNS TABLE(
    total_visitas INT,
    comparecimentos INT,
    contratos_fechados INT,
    taxa_conversao NUMERIC
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        COUNT(*)::INT as total_visitas,
        SUM(CASE WHEN ae.compareceu THEN 1 ELSE 0 END)::INT as comparecimentos,
        SUM(CASE WHEN ae.fechou_contrato THEN 1 ELSE 0 END)::INT as contratos_fechados,
        CASE 
            WHEN SUM(CASE WHEN ae.compareceu THEN 1 ELSE 0 END) > 0 
            THEN ROUND(
                (SUM(CASE WHEN ae.fechou_contrato THEN 1 ELSE 0 END)::NUMERIC / 
                 SUM(CASE WHEN ae.compareceu THEN 1 ELSE 0 END)::NUMERIC) * 100, 2
            )
            ELSE 0
        END as taxa_conversao
    FROM agenda_eventos ae
    WHERE ae.tipo = 'visita'
    AND DATE(ae.inicio) BETWEEN p_data_inicio AND p_data_fim
    AND (p_espaco_id IS NULL OR ae.espaco_id = p_espaco_id)
    AND (p_responsavel_id IS NULL OR ae.responsavel_usuario_id = p_responsavel_id);
END;
$$ LANGUAGE plpgsql;

-- Trigger para atualizar timestamp
CREATE OR REPLACE FUNCTION update_agenda_timestamp()
RETURNS TRIGGER AS $$
BEGIN
    NEW.atualizado_em = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_update_agenda_eventos ON agenda_eventos;
CREATE TRIGGER trg_update_agenda_eventos
    BEFORE UPDATE ON agenda_eventos
    FOR EACH ROW EXECUTE FUNCTION update_agenda_timestamp();

-- Índices para performance
CREATE INDEX IF NOT EXISTS idx_agenda_eventos_responsavel ON agenda_eventos(responsavel_usuario_id);
CREATE INDEX IF NOT EXISTS idx_agenda_eventos_espaco ON agenda_eventos(espaco_id);
CREATE INDEX IF NOT EXISTS idx_agenda_eventos_inicio ON agenda_eventos(inicio);
CREATE INDEX IF NOT EXISTS idx_agenda_eventos_status ON agenda_eventos(status);
CREATE INDEX IF NOT EXISTS idx_agenda_eventos_tipo ON agenda_eventos(tipo);
CREATE INDEX IF NOT EXISTS idx_agenda_lembretes_evento ON agenda_lembretes(evento_id);
CREATE INDEX IF NOT EXISTS idx_agenda_tokens_usuario ON agenda_tokens_ics(usuario_id);

-- Configurar cores padrão para usuários existentes
UPDATE usuarios SET 
    cor_agenda = CASE 
        WHEN nome ILIKE '%tiago%' THEN '#1E40AF'
        WHEN nome ILIKE '%tay%' THEN '#22C55E'
        WHEN nome ILIKE '%diego%' THEN '#F59E0B'
        WHEN nome ILIKE '%islane%' THEN '#EC4899'
        ELSE '#9CA3AF'
    END
WHERE cor_agenda IS NULL OR cor_agenda = '#1E40AF';

-- Configurar permissões padrão
UPDATE usuarios SET 
    perm_agenda_ver = TRUE,
    perm_agenda_meus = TRUE,
    perm_agenda_relatorios = (perfil = 'ADM' OR perfil = 'GERENTE'),
    perm_gerir_eventos_outros = (perfil = 'ADM'),
    perm_forcar_conflito = (perfil = 'ADM')
WHERE perfil IS NOT NULL;
