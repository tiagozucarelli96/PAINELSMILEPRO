-- Script para mover as tabelas do schema 'public' para o schema correto 'smilee12_painel_smile'
-- Execute este script no TablePlus

-- 1. Criar o schema se não existir
CREATE SCHEMA IF NOT EXISTS smilee12_painel_smile;

-- 2. Mover todas as tabelas lc_* do public para o schema correto
DO $$
DECLARE
    table_name TEXT;
    tables_to_move TEXT[] := ARRAY[
        'lc_categorias', 'lc_unidades', 'lc_insumos', 'fornecedores', 'usuarios',
        'lc_config', 'lc_fichas', 'lc_ficha_componentes', 'lc_itens', 'lc_itens_fixos',
        'lc_listas', 'lc_listas_eventos', 'lc_rascunhos',
        'lc_compras_consolidadas', 'lc_encomendas_itens', 'lc_encomendas_overrides'
    ];
BEGIN
    FOREACH table_name IN ARRAY tables_to_move
    LOOP
        -- Verificar se a tabela existe no schema public
        IF EXISTS (
            SELECT 1 FROM information_schema.tables 
            WHERE table_schema = 'public' AND table_name = table_name
        ) THEN
            -- Mover a tabela para o schema correto
            EXECUTE format('ALTER TABLE public.%I SET SCHEMA smilee12_painel_smile', table_name);
            RAISE NOTICE 'Tabela % movida para smilee12_painel_smile', table_name;
        ELSE
            RAISE NOTICE 'Tabela % não encontrada no schema public', table_name;
        END IF;
    END LOOP;
END $$;

-- 3. Mover também as sequências (SERIAL)
DO $$
DECLARE
    seq_name TEXT;
    sequences_to_move TEXT[] := ARRAY[
        'lc_categorias_id_seq', 'lc_unidades_id_seq', 'fornecedores_id_seq', 'usuarios_id_seq',
        'lc_config_chave_seq', 'lc_fichas_id_seq', 'lc_ficha_componentes_id_seq', 
        'lc_itens_id_seq', 'lc_itens_fixos_id_seq', 'lc_listas_id_seq', 
        'lc_listas_eventos_id_seq', 'lc_rascunhos_id_seq', 'lc_compras_consolidadas_id_seq',
        'lc_encomendas_itens_id_seq', 'lc_encomendas_overrides_id_seq'
    ];
BEGIN
    FOREACH seq_name IN ARRAY sequences_to_move
    LOOP
        -- Verificar se a sequência existe no schema public
        IF EXISTS (
            SELECT 1 FROM information_schema.sequences 
            WHERE sequence_schema = 'public' AND sequence_name = seq_name
        ) THEN
            -- Mover a sequência para o schema correto
            EXECUTE format('ALTER SEQUENCE public.%I SET SCHEMA smilee12_painel_smile', seq_name);
            RAISE NOTICE 'Sequência % movida para smilee12_painel_smile', seq_name;
        ELSE
            RAISE NOTICE 'Sequência % não encontrada no schema public', seq_name;
        END IF;
    END LOOP;
END $$;

-- 4. Verificar se as tabelas foram movidas corretamente
SELECT 
    table_schema,
    table_name,
    (SELECT COUNT(*) FROM information_schema.columns 
     WHERE table_name = t.table_name AND table_schema = t.table_schema) as column_count
FROM information_schema.tables t
WHERE table_schema = 'smilee12_painel_smile'
AND table_name LIKE 'lc_%'
ORDER BY table_name;

-- 5. Verificar dados nas tabelas movidas
SELECT 'lc_categorias' as tabela, COUNT(*) as registros FROM smilee12_painel_smile.lc_categorias
UNION ALL
SELECT 'lc_unidades', COUNT(*) FROM smilee12_painel_smile.lc_unidades
UNION ALL
SELECT 'lc_config', COUNT(*) FROM smilee12_painel_smile.lc_config
UNION ALL
SELECT 'lc_insumos', COUNT(*) FROM smilee12_painel_smile.lc_insumos;
