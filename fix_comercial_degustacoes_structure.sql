-- fix_comercial_degustacoes_structure.sql
-- Corrigir estrutura da tabela comercial_degustacoes

-- Verificar se a coluna titulo existe e se é NOT NULL
DO $$
BEGIN
    -- Se a coluna titulo não existe, criar
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'comercial_degustacoes' 
                   AND column_name = 'titulo') THEN
        ALTER TABLE comercial_degustacoes ADD COLUMN titulo VARCHAR(255);
    END IF;
    
    -- Se a coluna titulo existe mas é NOT NULL, alterar para permitir NULL temporariamente
    IF EXISTS (SELECT 1 FROM information_schema.columns 
               WHERE table_name = 'comercial_degustacoes' 
               AND column_name = 'titulo' 
               AND is_nullable = 'NO') THEN
        ALTER TABLE comercial_degustacoes ALTER COLUMN titulo DROP NOT NULL;
    END IF;
    
    -- Atualizar registros com titulo NULL para ter um valor padrão
    UPDATE comercial_degustacoes 
    SET titulo = COALESCE(titulo, 'Degustação ' || id::text)
    WHERE titulo IS NULL;
    
    -- Agora tornar a coluna NOT NULL novamente
    ALTER TABLE comercial_degustacoes ALTER COLUMN titulo SET NOT NULL;
    
    RAISE NOTICE 'Estrutura da tabela comercial_degustacoes corrigida com sucesso!';
END $$;

-- Verificar outras colunas que podem estar causando problemas
DO $$
BEGIN
    -- Verificar se outras colunas obrigatórias existem
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'comercial_degustacoes' 
                   AND column_name = 'descricao') THEN
        ALTER TABLE comercial_degustacoes ADD COLUMN descricao TEXT;
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'comercial_degustacoes' 
                   AND column_name = 'local') THEN
        ALTER TABLE comercial_degustacoes ADD COLUMN local VARCHAR(255);
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'comercial_degustacoes' 
                   AND column_name = 'data') THEN
        ALTER TABLE comercial_degustacoes ADD COLUMN data DATE;
    END IF;
    
    RAISE NOTICE 'Colunas adicionais verificadas e criadas se necessário!';
END $$;

