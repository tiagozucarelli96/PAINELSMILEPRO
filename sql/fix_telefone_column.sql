-- Verificar se coluna telefone existe, caso contrário criar como celular
DO $$
BEGIN
    -- Se não existe telefone, mas existe celular, renomear
    IF EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name = 'celular'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name = 'telefone'
    ) THEN
        ALTER TABLE comercial_inscricoes RENAME COLUMN celular TO telefone;
    END IF;
    
    -- Se não existe nenhum dos dois, criar telefone
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name IN ('celular', 'telefone')
    ) THEN
        ALTER TABLE comercial_inscricoes ADD COLUMN telefone VARCHAR(20);
    END IF;
END $$;

