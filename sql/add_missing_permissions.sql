-- Adicionar permissões da sidebar que faltam no banco
ALTER TABLE usuarios 
ADD COLUMN IF NOT EXISTS perm_agenda BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_comercial BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_eventos BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_eventos_realizar BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_logistico BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_configuracoes BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_cadastros BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_financeiro BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_administrativo BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_rh BOOLEAN DEFAULT FALSE;

-- Comentários para documentação
COMMENT ON COLUMN usuarios.perm_agenda IS 'Permissão para acessar o módulo Agenda';
COMMENT ON COLUMN usuarios.perm_comercial IS 'Permissão para acessar o módulo Comercial';
COMMENT ON COLUMN usuarios.perm_eventos IS 'Permissão para acessar o módulo Eventos';
COMMENT ON COLUMN usuarios.perm_eventos_realizar IS 'Permissão para acessar a página Realizar evento';
COMMENT ON COLUMN usuarios.perm_logistico IS 'Permissão para acessar o módulo Logístico';
COMMENT ON COLUMN usuarios.perm_configuracoes IS 'Permissão para acessar o módulo Configurações';
COMMENT ON COLUMN usuarios.perm_cadastros IS 'Permissão para acessar o módulo Cadastros';
COMMENT ON COLUMN usuarios.perm_financeiro IS 'Permissão para acessar o módulo Financeiro';
COMMENT ON COLUMN usuarios.perm_administrativo IS 'Permissão para acessar o módulo Administrativo';
COMMENT ON COLUMN usuarios.perm_rh IS 'Permissão para acessar o módulo RH';
