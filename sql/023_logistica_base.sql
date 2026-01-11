-- Base do módulo Logística: unidades internas, mapeamento de locais ME e eventos espelho

-- Unidades internas
CREATE TABLE IF NOT EXISTS logistica_unidades (
    id SERIAL PRIMARY KEY,
    codigo VARCHAR(50) UNIQUE NOT NULL,
    nome VARCHAR(100) NOT NULL,
    ativo BOOLEAN DEFAULT TRUE
);

INSERT INTO logistica_unidades (codigo, nome)
VALUES
    ('GardenCentral', 'GardenCentral'),
    ('Lisbon1', 'Lisbon1'),
    ('DiverKids', 'DiverKids')
ON CONFLICT (codigo) DO NOTHING;

-- Mapeamento de locais da ME Eventos
CREATE TABLE IF NOT EXISTS logistica_me_locais (
    id SERIAL PRIMARY KEY,
    me_local_id INTEGER,
    me_local_nome TEXT NOT NULL,
    space_visivel VARCHAR(20),
    unidade_interna_id INTEGER REFERENCES logistica_unidades(id),
    status_mapeamento VARCHAR(20) DEFAULT 'PENDENTE',
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_logistica_me_locais_nome ON logistica_me_locais (LOWER(me_local_nome));

-- Eventos espelho
CREATE TABLE IF NOT EXISTS logistica_eventos_espelho (
    id SERIAL PRIMARY KEY,
    me_event_id INTEGER NOT NULL,
    data_evento DATE NOT NULL,
    hora_inicio TIME,
    convidados INTEGER,
    idlocalevento INTEGER,
    localevento TEXT NOT NULL,
    unidade_interna_id INTEGER REFERENCES logistica_unidades(id),
    space_visivel VARCHAR(20),
    status_mapeamento VARCHAR(20) DEFAULT 'PENDENTE',
    arquivado BOOLEAN DEFAULT FALSE,
    synced_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_logistica_eventos_me_event_id ON logistica_eventos_espelho (me_event_id);
