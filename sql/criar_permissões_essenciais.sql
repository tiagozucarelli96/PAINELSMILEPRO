-- Script para criar apenas as permissões essenciais do sistema
-- Criar apenas as permissões que são realmente necessárias

-- Permissões principais (módulos)
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_agenda BOOLEAN DEFAULT FALSE;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_comercial BOOLEAN DEFAULT FALSE;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_eventos BOOLEAN DEFAULT FALSE;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_eventos_realizar BOOLEAN DEFAULT FALSE;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_logistico BOOLEAN DEFAULT FALSE;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_configuracoes BOOLEAN DEFAULT FALSE;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_cadastros BOOLEAN DEFAULT FALSE;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_financeiro BOOLEAN DEFAULT FALSE;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_administrativo BOOLEAN DEFAULT FALSE;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_rh BOOLEAN DEFAULT FALSE;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_banco_smile BOOLEAN DEFAULT FALSE;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_usuarios BOOLEAN DEFAULT FALSE;

-- Criar índices para melhor performance
CREATE INDEX IF NOT EXISTS idx_usuarios_perm_agenda ON usuarios(perm_agenda);
CREATE INDEX IF NOT EXISTS idx_usuarios_perm_comercial ON usuarios(perm_comercial);
CREATE INDEX IF NOT EXISTS idx_usuarios_perm_eventos ON usuarios(perm_eventos);
CREATE INDEX IF NOT EXISTS idx_usuarios_perm_eventos_realizar ON usuarios(perm_eventos_realizar);
CREATE INDEX IF NOT EXISTS idx_usuarios_perm_logistico ON usuarios(perm_logistico);
CREATE INDEX IF NOT EXISTS idx_usuarios_perm_configuracoes ON usuarios(perm_configuracoes);

-- Verificar colunas criadas
SELECT column_name, data_type, column_default 
FROM information_schema.columns 
WHERE table_schema = 'public' 
AND table_name = 'usuarios' 
AND column_name LIKE 'perm_%'
ORDER BY column_name;
