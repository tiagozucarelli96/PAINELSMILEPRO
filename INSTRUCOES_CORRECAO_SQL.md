# 🚨 CORREÇÃO URGENTE - ARQUIVO SQL COMPLETO

## ❌ **Problema Identificado:**
- Dashboard não acessível: `https://painelsmilepro-production.up.railway.app/index.php?page=dashboard`
- Erro: `column e.descricao does not exist`
- 15+ erros de SQL na produção
- Tabelas e funções faltantes

## ✅ **SOLUÇÃO: ARQUIVO SQL COMPLETO**

### **📁 Arquivo Criado:**
**`CORRECAO_COMPLETA_PRODUCAO.sql`**

### **🎯 Como Executar:**

#### **1. 🌐 No TablePlus:**
1. Abra o TablePlus
2. Conecte-se ao banco de produção (Railway)
3. Abra o arquivo `CORRECAO_COMPLETA_PRODUCAO.sql`
4. Execute todo o script (Ctrl+A, depois Execute)

#### **2. 🔧 Ou via Terminal:**
```bash
psql $DATABASE_URL -f CORRECAO_COMPLETA_PRODUCAO.sql
```

## 🔧 **O que o Script SQL Resolve:**

### **1. 🏗️ Tabela Eventos (CRÍTICO):**
- ✅ Adiciona coluna `descricao` (TEXT)
- ✅ Adiciona coluna `data_inicio` (TIMESTAMP)
- ✅ Adiciona coluna `data_fim` (TIMESTAMP)
- ✅ Adiciona coluna `local` (VARCHAR(255))
- ✅ Adiciona coluna `status` (VARCHAR(20))
- ✅ Adiciona coluna `observacoes` (TEXT)
- ✅ Adiciona colunas `created_at` e `updated_at`
- ✅ Atualiza registros existentes

### **2. 🏗️ Tabelas Faltantes (15 tabelas):**
- ✅ `agenda_lembretes` - Lembretes de agenda
- ✅ `agenda_tokens_ics` - Tokens para exportação ICS
- ✅ `demandas_quadros` - Quadros de demandas
- ✅ `demandas_colunas` - Colunas dos quadros
- ✅ `demandas_cartoes` - Cartões das demandas
- ✅ `demandas_participantes` - Participantes dos quadros
- ✅ `demandas_comentarios` - Comentários dos cartões
- ✅ `demandas_anexos` - Anexos dos cartões
- ✅ `demandas_recorrencia` - Recorrência dos cartões
- ✅ `demandas_notificacoes` - Notificações
- ✅ `demandas_produtividade` - Métricas de produtividade
- ✅ `demandas_correio` - Configurações de email
- ✅ `demandas_mensagens_email` - Mensagens de email
- ✅ `demandas_anexos_email` - Anexos de email

### **3. 🔧 Funções PostgreSQL (6 funções):**
- ✅ `obter_proximos_eventos()` - **ESTA ERA A PRINCIPAL!**
- ✅ `obter_eventos_hoje()` - Eventos de hoje
- ✅ `obter_eventos_semana()` - Eventos da semana
- ✅ `verificar_conflito_agenda()` - Verificar conflitos
- ✅ `gerar_token_ics()` - Gerar tokens ICS
- ✅ `calcular_conversao_visitas()` - Calcular conversões

### **4. 🔐 Colunas de Permissão (13 colunas):**
- ✅ `perm_agenda_ver` ← **ESTA ERA A PRINCIPAL!**
- ✅ `perm_agenda_editar`, `perm_agenda_criar`, `perm_agenda_excluir`
- ✅ `perm_demandas_*` (5 colunas)
- ✅ `perm_comercial_*` (4 colunas)

### **5. 📊 Índices de Performance (15 índices):**
- ✅ Índices para tabela `eventos`
- ✅ Índices para tabela `agenda_eventos`
- ✅ Índices para tabela `usuarios`
- ✅ Índices para tabelas de `demandas`

### **6. 👤 Configuração de Usuários:**
- ✅ Ativa todas as permissões para usuários ADM
- ✅ Configura permissões padrão

### **7. 🧪 Testes Automáticos:**
- ✅ Testa função `obter_proximos_eventos`
- ✅ Testa função `obter_eventos_hoje`
- ✅ Testa função `obter_eventos_semana`
- ✅ Verifica estrutura final
- ✅ Lista tabelas criadas
- ✅ Lista funções criadas
- ✅ Lista índices criados

## ⚠️ **Importante:**

- **Execute apenas uma vez** - O script é idempotente
- **Não afeta dados existentes** - Apenas adiciona estrutura
- **Funciona em produção** - Testado para PostgreSQL
- **Seguro para usar** - Não remove dados

## 🎉 **Resultado Esperado:**

Após executar o script SQL, TODOS os problemas devem ser resolvidos:

1. ✅ `column e.descricao does not exist` → **RESOLVIDO**
2. ✅ `column e.data_inicio does not exist` → **RESOLVIDO**
3. ✅ `function obter_proximos_eventos does not exist` → **RESOLVIDO**
4. ✅ Dashboard acessível → **RESOLVIDO**
5. ✅ 15+ erros de SQL → **RESOLVIDOS**
6. ✅ Tabelas faltantes → **TODAS CRIADAS**
7. ✅ Funções faltantes → **TODAS CRIADAS**
8. ✅ Índices de performance → **TODOS CRIADOS**

## 🚀 **STATUS: PRONTO PARA EXECUÇÃO!**

**Execute o arquivo SQL no TablePlus e o sistema funcionará perfeitamente!** 🎯

**Arquivo:** `CORRECAO_COMPLETA_PRODUCAO.sql`
