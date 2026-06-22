ALTER TABLE usuarios
ADD COLUMN IF NOT EXISTS perm_gerencia BOOLEAN DEFAULT FALSE;

COMMENT ON COLUMN usuarios.perm_gerencia IS 'Permissão para acessar o módulo Gerência';

CREATE INDEX IF NOT EXISTS idx_usuarios_perm_gerencia
    ON usuarios (perm_gerencia)
    WHERE perm_gerencia = TRUE;
