# 🚨 Solução para Erro de Produção

## ❌ **Problema Identificado:**
```
Fatal error: Uncaught PDOException: SQLSTATE[42703]: Undefined column: 7 ERROR: column "perm_agenda_ver" does not exist
```

## 🔍 **Causa do Problema:**
- A produção (Railway) não tem as colunas de permissão criadas
- O banco local tem todas as colunas, mas a produção não
- O arquivo `agenda_helper.php` está tentando acessar colunas que não existem

## ✅ **Soluções Disponíveis:**

### **1. 🌐 Solução via Web (Recomendada)**
**Acesse:** `https://painelsmilepro-production.up.railway.app/fix_production_web.php`

**O que faz:**
- Verifica todas as colunas de permissão
- Cria as colunas faltantes automaticamente
- Configura permissões para usuários existentes
- Interface visual com relatório de correções

### **2. 🖥️ Solução via Terminal (Se tiver acesso SSH)**
```bash
php fix_production_permissions.php
```

### **3. 📋 Colunas que serão criadas:**
- `perm_agenda_ver`
- `perm_agenda_editar`
- `perm_agenda_criar`
- `perm_agenda_excluir`
- `perm_demandas_ver`
- `perm_demandas_editar`
- `perm_demandas_criar`
- `perm_demandas_excluir`
- `perm_demandas_ver_produtividade`
- `perm_comercial_ver`
- `perm_comercial_deg_editar`
- `perm_comercial_deg_inscritos`
- `perm_comercial_conversao`

## 🎯 **Como Executar:**

### **Opção 1: Via Navegador (Mais Fácil)**
1. Acesse: `https://painelsmilepro-production.up.railway.app/fix_production_web.php`
2. Aguarde o script executar
3. Verifique o relatório de correções
4. Teste o dashboard novamente

### **Opção 2: Via Terminal (Se tiver acesso)**
1. Faça upload do arquivo `fix_production_permissions.php`
2. Execute: `php fix_production_permissions.php`
3. Verifique as correções aplicadas

## 📊 **O que o Script Faz:**

1. **Verifica conexão** com banco de dados
2. **Lista todas as colunas** de permissão necessárias
3. **Cria colunas faltantes** automaticamente
4. **Configura permissões** para usuários existentes
5. **Gera relatório** de correções aplicadas
6. **Testa funcionamento** das colunas criadas

## 🔧 **Arquivos Criados:**

- `fix_production_permissions.php` - Script terminal
- `public/fix_production_web.php` - Interface web
- `SOLUCAO_ERRO_PRODUCAO.md` - Esta documentação

## ⚠️ **Importante:**

- **Execute apenas uma vez** - O script é idempotente
- **Não afeta dados existentes** - Apenas adiciona colunas
- **Funciona em produção** - Detecta ambiente automaticamente
- **Seguro para usar** - Não remove dados

## 🎉 **Resultado Esperado:**

Após executar o script, o erro `column "perm_agenda_ver" does not exist` deve desaparecer e o sistema deve funcionar normalmente.

**Status:** ✅ Pronto para execução na produção!
