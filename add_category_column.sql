-- Adicionar coluna categoria_id na tabela lc_insumos
-- Execute este script no TablePlus

-- Verificar se a coluna já existe
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'lc_insumos' 
        AND column_name = 'categoria_id'
        AND table_schema = 'smilee12_painel_smile'
    ) THEN
        -- Adicionar a coluna categoria_id
        ALTER TABLE smilee12_painel_smile.lc_insumos 
        ADD COLUMN categoria_id INTEGER;
        
        -- Adicionar foreign key constraint
        ALTER TABLE smilee12_painel_smile.lc_insumos 
        ADD CONSTRAINT fk_insumos_categoria 
        FOREIGN KEY (categoria_id) 
        REFERENCES smilee12_painel_smile.lc_categorias(id);
        
        RAISE NOTICE 'Coluna categoria_id adicionada com sucesso!';
    ELSE
        RAISE NOTICE 'Coluna categoria_id já existe.';
    END IF;
END $$;
