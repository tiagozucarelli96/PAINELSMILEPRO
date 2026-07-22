-- 105_agenda_formato_online.sql
-- Permite agendar visitas/reunioes no formato online sem criar regras de unidade fisica.

ALTER TABLE agenda_espacos
ADD COLUMN IF NOT EXISTS descricao TEXT;

ALTER TABLE agenda_espacos
ADD COLUMN IF NOT EXISTS cor VARCHAR(7);

INSERT INTO agenda_espacos (nome, slug, descricao, cor, ativo)
VALUES ('Online', 'online', 'Reunião online com cliente', '#64748b', TRUE)
ON CONFLICT (slug) DO UPDATE SET
    nome = EXCLUDED.nome,
    descricao = EXCLUDED.descricao,
    cor = COALESCE(NULLIF(agenda_espacos.cor, ''), EXCLUDED.cor),
    ativo = TRUE;
