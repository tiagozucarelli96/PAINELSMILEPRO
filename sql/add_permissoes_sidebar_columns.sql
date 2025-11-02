-- Adicionar colunas de permissões dos módulos da sidebar na tabela usuarios
-- Este script adiciona as novas permissões correspondentes aos itens da sidebar

ALTER TABLE usuarios 
ADD COLUMN IF NOT EXISTS perm_agenda BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_comercial BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_logistico BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_configuracoes BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_cadastros BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_financeiro BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_administrativo BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS perm_rh BOOLEAN DEFAULT FALSE;

-- Adicionar comentários nas colunas para documentação
COMMENT ON COLUMN usuarios.perm_agenda IS 'Permissão para acessar o módulo Agenda';
COMMENT ON COLUMN usuarios.perm_comercial IS 'Permissão para acessar o módulo Comercial';
COMMENT ON COLUMN usuarios.perm_logistico IS 'Permissão para acessar o módulo Logístico';
COMMENT ON COLUMN usuarios.perm_configuracoes IS 'Permissão para acessar o módulo Configurações';
COMMENT ON COLUMN usuarios.perm_cadastros IS 'Permissão para acessar o módulo Cadastros';
COMMENT ON COLUMN usuarios.perm_financeiro IS 'Permissão para acessar o módulo Financeiro';
COMMENT ON COLUMN usuarios.perm_administrativo IS 'Permissão para acessar o módulo Administrativo';
COMMENT ON COLUMN usuarios.perm_rh IS 'Permissão para acessar o módulo RH';

-- Criar índices para melhor performance em consultas de permissões
CREATE INDEX IF NOT EXISTS idx_usuarios_perm_agenda ON usuarios(perm_agenda) WHERE perm_agenda = TRUE;
CREATE INDEX IF NOT EXISTS idx_usuarios_perm_comercial ON usuarios(perm_comercial) WHERE perm_comercial = TRUE;
CREATE INDEX IF NOT EXISTS idx_usuarios_perm_logistico ON usuarios(perm_logistico) WHERE perm_logistico = TRUE;
CREATE INDEX IF NOT EXISTS idx_usuarios_perm_configuracoes ON usuarios(perm_configuracoes) WHERE perm_configuracoes = TRUE;
CREATE INDEX IF NOT EXISTS idx_usuarios_perm_cadastros ON usuarios(perm_cadastros) WHERE perm_cadastros = TRUE;
CREATE INDEX IF NOT EXISTS idx_usuarios_perm_financeiro ON usuarios(perm_financeiro) WHERE perm_financeiro = TRUE;
CREATE INDEX IF NOT EXISTS idx_usuarios_perm_administrativo ON usuarios(perm_administrativo) WHERE perm_administrativo = TRUE;
CREATE INDEX IF NOT EXISTS idx_usuarios_perm_rh ON usuarios(perm_rh) WHERE perm_rh = TRUE;



