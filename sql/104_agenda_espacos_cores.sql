-- 104_agenda_espacos_cores.sql
-- Cores da agenda por unidade/espaco.

ALTER TABLE agenda_espacos
ADD COLUMN IF NOT EXISTS cor VARCHAR(7);

UPDATE agenda_espacos
SET cor = CASE
    WHEN LOWER(slug) IN ('garden', 'espaco_garden') THEN '#16a34a'
    WHEN LOWER(slug) IN ('diverkids', 'diver_kids') THEN '#f97316'
    WHEN LOWER(slug) IN ('cristal', 'espaco_cristal') THEN '#9333ea'
    WHEN LOWER(slug) IN ('lisbon', 'lisbon_un_1') THEN '#2563eb'
    ELSE '#0891b2'
END
WHERE cor IS NULL OR cor = '' OR LOWER(cor) = '#3b82f6';

ALTER TABLE agenda_espacos
ALTER COLUMN cor SET DEFAULT '#3b82f6';

INSERT INTO agenda_configuracoes (chave, valor, descricao, atualizado_em)
VALUES (
    'space_color_defaults_initialized',
    'true',
    'Controla a inicializacao unica das cores padrao das unidades da agenda.',
    NOW()
)
ON CONFLICT (chave) DO UPDATE SET
    valor = EXCLUDED.valor,
    descricao = EXCLUDED.descricao,
    atualizado_em = NOW();
