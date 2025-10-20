-- check_enum_aquisicao.sql
-- Verificar e criar o enum insumo_aquisicao se necessário

-- Verificar se o enum existe
SELECT EXISTS (
    SELECT 1 FROM pg_type 
    WHERE typname = 'insumo_aquisicao'
) as enum_exists;

-- Se não existir, criar o enum
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'insumo_aquisicao') THEN
        CREATE TYPE insumo_aquisicao AS ENUM ('mercado', 'preparo', 'fixo');
        RAISE NOTICE 'Enum insumo_aquisicao criado com sucesso.';
    ELSE
        RAISE NOTICE 'Enum insumo_aquisicao já existe.';
    END IF;
END $$;

-- Verificar valores do enum
SELECT unnest(enum_range(NULL::insumo_aquisicao)) as valores_enum;
