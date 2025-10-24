-- =====================================================
-- CORREÇÃO DE PERMISSÕES FALTANTES
-- Script para adicionar todas as colunas de permissão que estão faltando
-- =====================================================

-- 1. ADICIONAR COLUNAS DE PERMISSÃO FALTANTES
-- =====================================================

-- Adicionar perm_agenda_relatorios se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_agenda_relatorios') THEN
        ALTER TABLE usuarios ADD COLUMN perm_agenda_relatorios BOOLEAN DEFAULT false;
    END IF;
END $$;

-- Adicionar perm_agenda_ver se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_agenda_ver') THEN
        ALTER TABLE usuarios ADD COLUMN perm_agenda_ver BOOLEAN DEFAULT false;
    END IF;
END $$;

-- Adicionar perm_agenda_editar se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_agenda_editar') THEN
        ALTER TABLE usuarios ADD COLUMN perm_agenda_editar BOOLEAN DEFAULT false;
    END IF;
END $$;

-- Adicionar perm_agenda_criar se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_agenda_criar') THEN
        ALTER TABLE usuarios ADD COLUMN perm_agenda_criar BOOLEAN DEFAULT false;
    END IF;
END $$;

-- Adicionar perm_agenda_excluir se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_agenda_excluir') THEN
        ALTER TABLE usuarios ADD COLUMN perm_agenda_excluir BOOLEAN DEFAULT false;
    END IF;
END $$;

-- Adicionar perm_agenda_meus se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_agenda_meus') THEN
        ALTER TABLE usuarios ADD COLUMN perm_agenda_meus BOOLEAN DEFAULT false;
    END IF;
END $$;

-- Adicionar perm_demandas_ver se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_demandas_ver') THEN
        ALTER TABLE usuarios ADD COLUMN perm_demandas_ver BOOLEAN DEFAULT false;
    END IF;
END $$;

-- Adicionar perm_demandas_editar se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_demandas_editar') THEN
        ALTER TABLE usuarios ADD COLUMN perm_demandas_editar BOOLEAN DEFAULT false;
    END IF;
END $$;

-- Adicionar perm_demandas_criar se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_demandas_criar') THEN
        ALTER TABLE usuarios ADD COLUMN perm_demandas_criar BOOLEAN DEFAULT false;
    END IF;
END $$;

-- Adicionar perm_demandas_excluir se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_demandas_excluir') THEN
        ALTER TABLE usuarios ADD COLUMN perm_demandas_excluir BOOLEAN DEFAULT false;
    END IF;
END $$;

-- Adicionar perm_demandas_ver_produtividade se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_demandas_ver_produtividade') THEN
        ALTER TABLE usuarios ADD COLUMN perm_demandas_ver_produtividade BOOLEAN DEFAULT false;
    END IF;
END $$;

-- Adicionar perm_comercial_ver se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_comercial_ver') THEN
        ALTER TABLE usuarios ADD COLUMN perm_comercial_ver BOOLEAN DEFAULT false;
    END IF;
END $$;

-- Adicionar perm_comercial_deg_editar se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_comercial_deg_editar') THEN
        ALTER TABLE usuarios ADD COLUMN perm_comercial_deg_editar BOOLEAN DEFAULT false;
    END IF;
END $$;

-- Adicionar perm_comercial_deg_inscritos se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_comercial_deg_inscritos') THEN
        ALTER TABLE usuarios ADD COLUMN perm_comercial_deg_inscritos BOOLEAN DEFAULT false;
    END IF;
END $$;

-- Adicionar perm_comercial_conversao se não existir
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name = 'usuarios' AND column_name = 'perm_comercial_conversao') THEN
        ALTER TABLE usuarios ADD COLUMN perm_comercial_conversao BOOLEAN DEFAULT false;
    END IF;
END $$;

-- 2. CONFIGURAR PERMISSÕES PARA USUÁRIOS EXISTENTES
-- =====================================================

-- Ativar todas as permissões para usuários ADM
UPDATE usuarios SET 
    perm_agenda_relatorios = true,
    perm_agenda_ver = true,
    perm_agenda_editar = true,
    perm_agenda_criar = true,
    perm_agenda_excluir = true,
    perm_agenda_meus = true,
    perm_demandas_ver = true,
    perm_demandas_editar = true,
    perm_demandas_criar = true,
    perm_demandas_excluir = true,
    perm_demandas_ver_produtividade = true,
    perm_comercial_ver = true,
    perm_comercial_deg_editar = true,
    perm_comercial_deg_inscritos = true,
    perm_comercial_conversao = true
WHERE perfil = 'ADM';

-- 3. VERIFICAR COLUNAS CRIADAS
-- =====================================================

-- Verificar todas as colunas de permissão
SELECT 'Verificando colunas de permissão criadas...' as status;
SELECT column_name, data_type, is_nullable, column_default
FROM information_schema.columns 
WHERE table_name = 'usuarios' 
AND column_name LIKE 'perm_%'
ORDER BY column_name;

-- 4. TESTAR CONSULTA QUE ESTAVA FALHANDO
-- =====================================================

-- Testar consulta que estava falhando
SELECT 'Testando consulta perm_agenda_relatorios...' as status;
SELECT perm_agenda_relatorios FROM usuarios WHERE id = 1;

-- Testar consulta perm_agenda_meus
SELECT 'Testando consulta perm_agenda_meus...' as status;
SELECT perm_agenda_meus FROM usuarios WHERE id = 1;

-- =====================================================
-- CORREÇÃO DE PERMISSÕES FINALIZADA!
-- =====================================================

SELECT '🎉 CORREÇÃO DE PERMISSÕES FINALIZADA!' as status;
SELECT 'Todas as colunas de permissão foram criadas.' as resultado;
SELECT 'O sidebar agora deve funcionar perfeitamente.' as conclusao;
