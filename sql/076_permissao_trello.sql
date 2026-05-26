-- 076_permissao_trello.sql
-- Separa a permissão do módulo Trello da permissão do novo módulo Demandas.

ALTER TABLE usuarios
ADD COLUMN IF NOT EXISTS perm_trello BOOLEAN DEFAULT FALSE;

UPDATE usuarios
SET perm_trello = TRUE
WHERE COALESCE(LOWER(perm_trello::TEXT), 'false') NOT IN ('1', 't', 'true', 'on', 'yes')
  AND COALESCE(LOWER(perm_demandas::TEXT), 'false') IN ('1', 't', 'true', 'on', 'yes');

UPDATE usuarios
SET perm_trello = TRUE
WHERE COALESCE(LOWER(perm_superadmin::TEXT), 'false') IN ('1', 't', 'true', 'on', 'yes');
