-- Script para corrigir a tabela lc_listas
-- Execute este código no TablePlus

-- 1. Verificar se a tabela existe e sua estrutura atual
SELECT column_name, data_type, is_nullable, column_default
FROM information_schema.columns 
WHERE table_name = 'lc_listas' 
ORDER BY ordinal_position;

-- 2. Adicionar colunas que estão faltando
DO $$ 
BEGIN
    -- Adicionar coluna criado_em se não existir
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'lc_listas' AND column_name = 'criado_em') THEN
        ALTER TABLE lc_listas ADD COLUMN criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
    END IF;
    
    -- Adicionar coluna tipo_lista se não existir
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'lc_listas' AND column_name = 'tipo_lista') THEN
        ALTER TABLE lc_listas ADD COLUMN tipo_lista VARCHAR(20) NOT NULL DEFAULT 'compras';
    END IF;
    
    -- Adicionar coluna criado_por se não existir
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'lc_listas' AND column_name = 'criado_por') THEN
        ALTER TABLE lc_listas ADD COLUMN criado_por INTEGER;
    END IF;
    
    -- Adicionar coluna resumo_eventos se não existir
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'lc_listas' AND column_name = 'resumo_eventos') THEN
        ALTER TABLE lc_listas ADD COLUMN resumo_eventos TEXT;
    END IF;
    
    -- Adicionar coluna espaco_resumo se não existir
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'lc_listas' AND column_name = 'espaco_resumo') THEN
        ALTER TABLE lc_listas ADD COLUMN espaco_resumo VARCHAR(100);
    END IF;
END $$;

-- 3. Verificar a estrutura final
SELECT column_name, data_type, is_nullable, column_default
FROM information_schema.columns 
WHERE table_name = 'lc_listas' 
ORDER BY ordinal_position;

-- 4. Inserir alguns dados de teste se a tabela estiver vazia
INSERT INTO lc_listas (tipo_lista, resumo_eventos, espaco_resumo, criado_por)
SELECT 'compras', 'Evento de teste', 'Sala Principal', 1
WHERE NOT EXISTS (SELECT 1 FROM lc_listas LIMIT 1);

-- 5. Verificar se os dados foram inseridos
SELECT * FROM lc_listas;
