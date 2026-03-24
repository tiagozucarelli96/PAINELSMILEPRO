ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS perm_vendas_administracao BOOLEAN DEFAULT FALSE;

CREATE INDEX IF NOT EXISTS idx_usuarios_perm_vendas_administracao
    ON usuarios (perm_vendas_administracao);

UPDATE usuarios
SET perm_vendas_administracao = TRUE
WHERE COALESCE(perm_administrativo, FALSE) = TRUE;
