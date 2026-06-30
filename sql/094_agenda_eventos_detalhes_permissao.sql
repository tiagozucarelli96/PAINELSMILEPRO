-- 094_agenda_eventos_detalhes_permissao.sql
-- Permissão separada para visualizar detalhes completos dos eventos na Agenda Geral.

ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS perm_agenda_eventos_detalhes BOOLEAN DEFAULT FALSE;

UPDATE usuarios
SET perm_agenda_eventos_detalhes = TRUE
WHERE COALESCE(perm_superadmin, FALSE) = TRUE;

COMMENT ON COLUMN usuarios.perm_agenda_eventos_detalhes
    IS 'Permissão para abrir a ficha detalhada dos eventos na Agenda Geral';

CREATE INDEX IF NOT EXISTS idx_usuarios_perm_agenda_eventos_detalhes
    ON usuarios (perm_agenda_eventos_detalhes);
