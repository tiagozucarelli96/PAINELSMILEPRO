-- ============================================
-- SCRIPT DROP SEGURO - MÓDULO ESTOQUE + LISTA DE COMPRAS
-- ============================================
-- Este script remove completamente o módulo de Estoque e Lista de Compras
-- Data: <?= date('d/m/Y H:i:s') ?>
-- 
-- ATENÇÃO: Execute com cuidado. Este script remove dados permanentemente.
-- ============================================

BEGIN;

-- ============================================
-- 1. REMOVER VIEWS (antes das tabelas)
-- ============================================

DROP VIEW IF EXISTS v_kardex_completo CASCADE;
DROP VIEW IF EXISTS v_resumo_movimentos_insumo CASCADE;

-- ============================================
-- 2. REMOVER FUNÇÕES (antes das tabelas)
-- ============================================

DROP FUNCTION IF EXISTS lc_calcular_saldo_insumo(INT, TIMESTAMP) CASCADE;
DROP FUNCTION IF EXISTS lc_calcular_saldo_insumo_data(INT, TIMESTAMP, TIMESTAMP) CASCADE;
DROP FUNCTION IF EXISTS lc_auditar_movimento() CASCADE;

-- ============================================
-- 3. REMOVER TRIGGERS
-- ============================================

DROP TRIGGER IF EXISTS tr_auditar_movimento ON lc_movimentos_estoque;

-- ============================================
-- 4. REMOVER TABELAS DE ESTOQUE
-- ============================================

-- Ordem: primeiro dependentes, depois principais
DROP TABLE IF EXISTS estoque_contagem_itens CASCADE;
DROP TABLE IF EXISTS estoque_contagens CASCADE;

DROP TABLE IF EXISTS lc_movimentos_estoque CASCADE;
DROP TABLE IF EXISTS lc_eventos_baixados CASCADE;
DROP TABLE IF EXISTS lc_ajustes_estoque CASCADE;
DROP TABLE IF EXISTS lc_perdas_devolucoes CASCADE;
DROP TABLE IF EXISTS lc_config_estoque CASCADE;

-- ============================================
-- 5. REMOVER TABELAS DE LISTA DE COMPRAS
-- ============================================

-- Ordem: primeiro dependentes, depois principais
DROP TABLE IF EXISTS lc_listas_eventos CASCADE;
DROP TABLE IF EXISTS lc_compras_consolidadas CASCADE;
DROP TABLE IF EXISTS lc_encomendas_itens CASCADE;
DROP TABLE IF EXISTS lc_encomendas_overrides CASCADE;
DROP TABLE IF EXISTS lc_listas CASCADE;
DROP TABLE IF EXISTS lc_config CASCADE;

-- ============================================
-- 6. REMOVER TABELAS DE FICHAS TÉCNICAS / INSUMOS
-- ============================================

-- Ordem: primeiro dependentes, depois principais
DROP TABLE IF EXISTS lc_ficha_componentes CASCADE;
DROP TABLE IF EXISTS lc_fichas CASCADE;
DROP TABLE IF EXISTS lc_itens_fixos CASCADE;
DROP TABLE IF EXISTS lc_itens CASCADE;
DROP TABLE IF EXISTS lc_insumos_substitutos CASCADE;
DROP TABLE IF EXISTS lc_insumos CASCADE;
DROP TABLE IF EXISTS lc_categorias CASCADE;
DROP TABLE IF EXISTS lc_unidades CASCADE;

-- ============================================
-- 7. REMOVER COLUNAS ADICIONADAS EM TABELAS EXISTENTES
-- ============================================
-- (Se houver colunas adicionadas em tabelas que não pertencem ao módulo)

-- Verificar e remover colunas de estoque em lc_insumos (se ainda existir)
DO $$
BEGIN
    -- Estas colunas foram adicionadas pelo módulo de estoque
    IF EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'lc_insumos' AND column_name = 'ean_code'
    ) THEN
        ALTER TABLE lc_insumos DROP COLUMN IF EXISTS ean_code;
    END IF;
    
    IF EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'lc_insumos' AND column_name = 'estoque_atual'
    ) THEN
        ALTER TABLE lc_insumos DROP COLUMN IF EXISTS estoque_atual;
    END IF;
    
    IF EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'lc_insumos' AND column_name = 'estoque_minimo'
    ) THEN
        ALTER TABLE lc_insumos DROP COLUMN IF EXISTS estoque_minimo;
    END IF;
    
    IF EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'lc_insumos' AND column_name = 'embalagem_multiplo'
    ) THEN
        ALTER TABLE lc_insumos DROP COLUMN IF EXISTS embalagem_multiplo;
    END IF;
END $$;

-- ============================================
-- 8. VERIFICAÇÃO FINAL
-- ============================================

DO $$
DECLARE
    tabela_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO tabela_count 
    FROM information_schema.tables 
    WHERE table_schema = 'smilee12_painel_smile'
    AND table_name IN (
        'estoque_contagens', 'estoque_contagem_itens',
        'lc_movimentos_estoque', 'lc_eventos_baixados', 'lc_ajustes_estoque', 
        'lc_perdas_devolucoes', 'lc_config_estoque',
        'lc_listas', 'lc_listas_eventos', 'lc_compras_consolidadas',
        'lc_encomendas_itens', 'lc_encomendas_overrides', 'lc_config',
        'lc_fichas', 'lc_ficha_componentes', 'lc_itens', 'lc_itens_fixos',
        'lc_insumos', 'lc_insumos_substitutos', 'lc_categorias', 'lc_unidades'
    );
    
    IF tabela_count > 0 THEN
        RAISE NOTICE 'ATENÇÃO: Ainda existem % tabela(s) do módulo no banco!', tabela_count;
    ELSE
        RAISE NOTICE 'SUCESSO: Todas as tabelas do módulo foram removidas.';
    END IF;
END $$;

COMMIT;

-- ============================================
-- FIM DO SCRIPT
-- ============================================
