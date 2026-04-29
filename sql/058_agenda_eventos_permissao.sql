ALTER TABLE usuarios
ADD COLUMN IF NOT EXISTS perm_agenda_eventos BOOLEAN DEFAULT FALSE;

COMMENT ON COLUMN usuarios.perm_agenda_eventos IS 'Permissão para acessar o módulo Agenda de eventos';

CREATE INDEX IF NOT EXISTS idx_usuarios_perm_agenda_eventos
    ON usuarios (perm_agenda_eventos);
