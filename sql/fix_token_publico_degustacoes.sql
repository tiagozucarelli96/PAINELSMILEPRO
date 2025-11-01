-- fix_token_publico_degustacoes.sql
-- Adiciona coluna token_publico na tabela comercial_degustacoes se não existir

-- Verificar e adicionar coluna token_publico
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 
        FROM information_schema.columns 
        WHERE table_schema = 'public' 
        AND table_name = 'comercial_degustacoes' 
        AND column_name = 'token_publico'
    ) THEN
        ALTER TABLE comercial_degustacoes 
        ADD COLUMN token_publico VARCHAR(64) UNIQUE;
        
        -- Gerar tokens para degustações existentes que não têm token
        UPDATE comercial_degustacoes 
        SET token_publico = REPLACE(gen_random_uuid()::text, '-', '')
        WHERE token_publico IS NULL OR token_publico = '';
        
        RAISE NOTICE 'Coluna token_publico adicionada com sucesso!';
    ELSE
        RAISE NOTICE 'Coluna token_publico já existe.';
    END IF;
END $$;

-- Verificar se a função lc_gerar_token_publico existe, se não, criar
CREATE OR REPLACE FUNCTION lc_gerar_token_publico()
RETURNS VARCHAR(64) AS $$
BEGIN
    RETURN REPLACE(gen_random_uuid()::text, '-', '');
END;
$$ LANGUAGE plpgsql;

-- Verificar se precisa tornar NOT NULL (apenas se a tabela estiver vazia ou todos tiverem token)
DO $$
BEGIN
    -- Se todas as degustações têm token, podemos tornar NOT NULL
    IF NOT EXISTS (
        SELECT 1 FROM comercial_degustacoes WHERE token_publico IS NULL OR token_publico = ''
    ) THEN
        -- Tornar a coluna NOT NULL (com DEFAULT para futuras inserções)
        ALTER TABLE comercial_degustacoes 
        ALTER COLUMN token_publico SET NOT NULL,
        ALTER COLUMN token_publico SET DEFAULT REPLACE(gen_random_uuid()::text, '-', '');
        
        RAISE NOTICE 'Coluna token_publico configurada como NOT NULL com DEFAULT.';
    ELSE
        RAISE NOTICE 'Algumas degustações ainda não têm token. Mantendo coluna como NULL permitido.';
    END IF;
END $$;

