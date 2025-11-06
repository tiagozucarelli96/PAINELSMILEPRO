-- Script para limpar TODAS as colunas de permissões da tabela usuarios
-- ATENÇÃO: Este script remove TODAS as colunas que começam com 'perm_'

DO $$
DECLARE
    col_name text;
BEGIN
    -- Buscar todas as colunas de permissões
    FOR col_name IN
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = 'public'
        AND table_name = 'usuarios'
        AND column_name LIKE 'perm_%'
        ORDER BY column_name
    LOOP
        -- Remover coluna
        EXECUTE format('ALTER TABLE usuarios DROP COLUMN IF EXISTS %I', col_name);
        RAISE NOTICE 'Coluna removida: %', col_name;
    END LOOP;
    
    RAISE NOTICE 'Limpeza de permissões concluída!';
END $$;

-- Verificar se todas foram removidas
SELECT column_name 
FROM information_schema.columns 
WHERE table_schema = 'public' 
AND table_name = 'usuarios' 
AND column_name LIKE 'perm_%'
ORDER BY column_name;

