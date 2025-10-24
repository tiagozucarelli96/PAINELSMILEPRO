-- =====================================================
-- VERIFICAÇÃO COMPLETA DO BANCO DE DADOS
-- Script para verificar o estado atual e identificar problemas
-- =====================================================

-- 1. VERIFICAR TABELAS EXISTENTES
-- =====================================================

SELECT '=== VERIFICANDO TABELAS EXISTENTES ===' as status;

-- Listar todas as tabelas
SELECT table_name, 
       (SELECT COUNT(*) FROM information_schema.columns WHERE table_name = t.table_name) as colunas
FROM information_schema.tables t
WHERE table_schema = 'public' 
ORDER BY table_name;

-- 2. VERIFICAR ESTRUTURA DA TABELA EVENTOS
-- =====================================================

SELECT '=== VERIFICANDO TABELA EVENTOS ===' as status;

-- Verificar se tabela eventos existe
SELECT CASE 
    WHEN EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'eventos') 
    THEN 'Tabela eventos EXISTE' 
    ELSE 'Tabela eventos NÃO EXISTE' 
END as status_eventos;

-- Se existir, verificar estrutura
SELECT 'Estrutura da tabela eventos:' as info;
SELECT column_name, data_type, is_nullable, column_default
FROM information_schema.columns 
WHERE table_name = 'eventos' 
ORDER BY ordinal_position;

-- 3. VERIFICAR ESTRUTURA DA TABELA USUARIOS
-- =====================================================

SELECT '=== VERIFICANDO TABELA USUARIOS ===' as status;

-- Verificar se tabela usuarios existe
SELECT CASE 
    WHEN EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'usuarios') 
    THEN 'Tabela usuarios EXISTE' 
    ELSE 'Tabela usuarios NÃO EXISTE' 
END as status_usuarios;

-- Se existir, verificar estrutura
SELECT 'Estrutura da tabela usuarios:' as info;
SELECT column_name, data_type, is_nullable, column_default
FROM information_schema.columns 
WHERE table_name = 'usuarios' 
ORDER BY ordinal_position;

-- 4. VERIFICAR FUNÇÕES POSTGRESQL
-- =====================================================

SELECT '=== VERIFICANDO FUNÇÕES POSTGRESQL ===' as status;

-- Listar todas as funções
SELECT routine_name, routine_type, data_type
FROM information_schema.routines 
WHERE routine_schema = 'public' 
ORDER BY routine_name;

-- 5. VERIFICAR TABELAS DE LISTA DE COMPRAS
-- =====================================================

SELECT '=== VERIFICANDO TABELAS DE LISTA DE COMPRAS ===' as status;

-- Verificar tabelas relacionadas a lista de compras
SELECT table_name
FROM information_schema.tables 
WHERE table_schema = 'public' 
AND (table_name LIKE 'lc_%' OR table_name LIKE '%lista%' OR table_name LIKE '%compra%')
ORDER BY table_name;

-- 6. VERIFICAR TABELAS DE FORNECEDORES
-- =====================================================

SELECT '=== VERIFICANDO TABELAS DE FORNECEDORES ===' as status;

-- Verificar tabelas relacionadas a fornecedores
SELECT table_name
FROM information_schema.tables 
WHERE table_schema = 'public' 
AND (table_name LIKE '%fornecedor%' OR table_name LIKE '%supplier%')
ORDER BY table_name;

-- 7. VERIFICAR ÍNDICES
-- =====================================================

SELECT '=== VERIFICANDO ÍNDICES ===' as status;

-- Listar índices existentes
SELECT indexname, tablename, indexdef
FROM pg_indexes 
WHERE schemaname = 'public'
ORDER BY tablename, indexname;

-- 8. VERIFICAR CHAVES ESTRANGEIRAS
-- =====================================================

SELECT '=== VERIFICANDO CHAVES ESTRANGEIRAS ===' as status;

-- Listar chaves estrangeiras
SELECT 
    tc.table_name, 
    kcu.column_name, 
    ccu.table_name AS foreign_table_name,
    ccu.column_name AS foreign_column_name 
FROM information_schema.table_constraints AS tc 
JOIN information_schema.key_column_usage AS kcu
    ON tc.constraint_name = kcu.constraint_name
    AND tc.table_schema = kcu.table_schema
JOIN information_schema.constraint_column_usage AS ccu
    ON ccu.constraint_name = tc.constraint_name
    AND ccu.table_schema = tc.table_schema
WHERE tc.constraint_type = 'FOREIGN KEY' 
AND tc.table_schema = 'public'
ORDER BY tc.table_name;

-- 9. VERIFICAR DADOS EXISTENTES
-- =====================================================

SELECT '=== VERIFICANDO DADOS EXISTENTES ===' as status;

-- Contar registros em tabelas principais
SELECT 'Contagem de registros:' as info;
SELECT 'usuarios' as tabela, COUNT(*) as registros FROM usuarios
UNION ALL
SELECT 'eventos' as tabela, COUNT(*) as registros FROM eventos
UNION ALL
SELECT 'lc_insumos' as tabela, COUNT(*) as registros FROM lc_insumos
UNION ALL
SELECT 'lc_listas' as tabela, COUNT(*) as registros FROM lc_listas
UNION ALL
SELECT 'lc_fornecedores' as tabela, COUNT(*) as registros FROM lc_fornecedores;

-- 10. VERIFICAR PROBLEMAS ESPECÍFICOS
-- =====================================================

SELECT '=== VERIFICANDO PROBLEMAS ESPECÍFICOS ===' as status;

-- Verificar se colunas de permissão existem
SELECT 'Colunas de permissão existentes:' as info;
SELECT column_name
FROM information_schema.columns 
WHERE table_name = 'usuarios' 
AND column_name LIKE 'perm_%'
ORDER BY column_name;

-- Verificar se ENUM eventos_status existe
SELECT 'Verificando ENUM eventos_status:' as info;
SELECT CASE 
    WHEN EXISTS (SELECT 1 FROM pg_type WHERE typname = 'eventos_status') 
    THEN 'ENUM eventos_status EXISTE' 
    ELSE 'ENUM eventos_status NÃO EXISTE' 
END as status_enum;

-- =====================================================
-- VERIFICAÇÃO COMPLETA FINALIZADA!
-- =====================================================

SELECT '🎉 VERIFICAÇÃO COMPLETA FINALIZADA!' as status;
SELECT 'Execute este script e analise os resultados para identificar problemas.' as resultado;
