-- 097_agenda_configuracoes.sql
-- Configurações globais ajustáveis da Agenda.

CREATE TABLE IF NOT EXISTS agenda_configuracoes (
    chave VARCHAR(100) PRIMARY KEY,
    valor JSONB NOT NULL,
    descricao TEXT,
    atualizado_em TIMESTAMP DEFAULT NOW()
);

INSERT INTO agenda_configuracoes (chave, valor, descricao)
VALUES
    ('visit_responsible_logins', '["tay","marilia","tiago zucarelli","ays"]'::jsonb, 'Logins permitidos como responsáveis de nova visita.'),
    ('visit_type_durations', '{"Conhecer espaço":30,"Reunião final":120,"Pagamento":30}'::jsonb, 'Duração em minutos por tipo de visita.'),
    ('transit_min_minutes', '30'::jsonb, 'Intervalo mínimo em minutos entre unidades diferentes.'),
    ('space_transit_groups', '{"garden":"garden_cristal","cristal":"garden_cristal","lisbon":"lisbon","diverkids":"diverkids"}'::jsonb, 'Grupos de deslocamento por unidade/espaço.')
ON CONFLICT (chave) DO NOTHING;
