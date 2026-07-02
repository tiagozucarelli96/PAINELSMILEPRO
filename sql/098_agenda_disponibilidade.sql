-- 098_agenda_disponibilidade.sql
-- Regras de disponibilidade e bloqueio por responsável da Agenda.

CREATE TABLE IF NOT EXISTS agenda_disponibilidade (
    id SERIAL PRIMARY KEY,
    usuario_id INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    tipo VARCHAR(20) NOT NULL DEFAULT 'disponivel',
    recorrencia VARCHAR(20) NOT NULL DEFAULT 'semanal',
    dia_semana SMALLINT,
    data_especifica DATE,
    hora_inicio TIME NOT NULL,
    hora_fim TIME NOT NULL,
    valido_de DATE NOT NULL,
    valido_ate DATE,
    observacao TEXT,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    criado_por_usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT agenda_disponibilidade_tipo_chk CHECK (tipo IN ('disponivel', 'bloqueio')),
    CONSTRAINT agenda_disponibilidade_recorrencia_chk CHECK (recorrencia IN ('semanal', 'data')),
    CONSTRAINT agenda_disponibilidade_dia_chk CHECK (dia_semana IS NULL OR dia_semana BETWEEN 0 AND 6),
    CONSTRAINT agenda_disponibilidade_periodo_chk CHECK (hora_fim > hora_inicio),
    CONSTRAINT agenda_disponibilidade_validade_chk CHECK (valido_ate IS NULL OR valido_ate >= valido_de)
);

CREATE INDEX IF NOT EXISTS idx_agenda_disp_usuario
    ON agenda_disponibilidade(usuario_id, ativo);

CREATE INDEX IF NOT EXISTS idx_agenda_disp_periodo
    ON agenda_disponibilidade(usuario_id, valido_de, valido_ate);
