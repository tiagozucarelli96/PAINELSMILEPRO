-- add_ativo_column.sql
-- Adiciona a coluna ativo na tabela lc_insumos se ela não existir

DO $$
BEGIN
    -- Verifica se a coluna ativo já existe
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'smilee12_painel_smile'
        AND table_name = 'lc_insumos'
        AND column_name = 'ativo'
    ) THEN
        -- Adiciona a coluna ativo
        ALTER TABLE smilee12_painel_smile.lc_insumos
        ADD COLUMN ativo BOOLEAN DEFAULT true;
        
        -- Atualiza todos os registros existentes para ativo = true
        UPDATE smilee12_painel_smile.lc_insumos
        SET ativo = true
        WHERE ativo IS NULL;
        
        RAISE NOTICE 'Coluna ativo adicionada à tabela lc_insumos.';
    ELSE
        RAISE NOTICE 'Coluna ativo já existe na tabela lc_insumos.';
    END IF;
END $$;
