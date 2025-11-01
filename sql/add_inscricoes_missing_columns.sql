-- Adicionar colunas faltantes na tabela comercial_inscricoes

-- Verificar e adicionar qtd_pessoas
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name = 'qtd_pessoas'
    ) THEN
        ALTER TABLE comercial_inscricoes ADD COLUMN qtd_pessoas INTEGER DEFAULT 1;
    END IF;
END $$;

-- Verificar e adicionar tipo_festa
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name = 'tipo_festa'
    ) THEN
        ALTER TABLE comercial_inscricoes ADD COLUMN tipo_festa VARCHAR(50);
    END IF;
END $$;

-- Verificar e adicionar extras
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name = 'extras'
    ) THEN
        ALTER TABLE comercial_inscricoes ADD COLUMN extras INTEGER DEFAULT 0;
    END IF;
END $$;

-- Verificar e adicionar ip_origem
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name = 'ip_origem'
    ) THEN
        ALTER TABLE comercial_inscricoes ADD COLUMN ip_origem VARCHAR(45);
    END IF;
END $$;

-- Verificar e adicionar user_agent_origem
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'comercial_inscricoes' 
        AND column_name = 'user_agent_origem'
    ) THEN
        ALTER TABLE comercial_inscricoes ADD COLUMN user_agent_origem TEXT;
    END IF;
END $$;

