ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS perm_smile_chat BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS perm_smile_chat_admin BOOLEAN DEFAULT FALSE;

COMMENT ON COLUMN usuarios.perm_smile_chat IS 'Permissão para acessar o domínio do Smile Chat';
COMMENT ON COLUMN usuarios.perm_smile_chat_admin IS 'Permissão para administrar configurações do Smile Chat';

CREATE INDEX IF NOT EXISTS idx_usuarios_perm_smile_chat
    ON usuarios (perm_smile_chat);

CREATE INDEX IF NOT EXISTS idx_usuarios_perm_smile_chat_admin
    ON usuarios (perm_smile_chat_admin);
