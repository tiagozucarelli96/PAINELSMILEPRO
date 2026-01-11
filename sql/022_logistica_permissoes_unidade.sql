-- Permissões e escopo de unidade para o módulo Logística
ALTER TABLE usuarios
ADD COLUMN IF NOT EXISTS perm_superadmin BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_logistico BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_logistico_divergencias BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_logistico_financeiro BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS unidade_scope VARCHAR(20) DEFAULT 'nenhuma',
ADD COLUMN IF NOT EXISTS unidade_id INTEGER;

-- Comentários para documentação
COMMENT ON COLUMN usuarios.perm_superadmin IS 'Bypass total de permissões';
COMMENT ON COLUMN usuarios.perm_logistico IS 'Permissão para acessar o módulo Logística';
COMMENT ON COLUMN usuarios.perm_logistico_divergencias IS 'Permissão para área de divergências/auditoria da Logística';
COMMENT ON COLUMN usuarios.perm_logistico_financeiro IS 'Permissão para área financeira da Logística';
COMMENT ON COLUMN usuarios.unidade_scope IS 'Escopo de unidade (nenhuma/todas/unidade)';
COMMENT ON COLUMN usuarios.unidade_id IS 'Unidade vinculada quando unidade_scope=unidade';
