# 🔍 PLANO DE RESTAURAÇÃO SEGURA - BANCO DE DADOS

## 🎯 **OBJETIVO:**
Restaurar a estrutura do banco de dados de forma segura, sem perder dados existentes.

## 📋 **PASSO A PASSO:**

### **1. 🔍 VERIFICAÇÃO INICIAL**
**Execute:** `VERIFICACAO_COMPLETA_BANCO.sql`

**O que fazer:**
1. Execute o script no Postico/psql
2. Analise os resultados
3. Identifique quais tabelas existem
4. Identifique quais funções existem
5. Identifique quais colunas de permissão existem

### **2. 📊 ANÁLISE DOS RESULTADOS**

#### **2.1 Tabelas que DEVEM existir:**
- ✅ `usuarios` - Usuários do sistema
- ✅ `eventos` - Eventos da agenda
- ✅ `lc_insumos` - Insumos/ingredientes
- ✅ `lc_listas` - Listas de compras
- ✅ `lc_fornecedores` - Fornecedores
- ✅ `agenda_eventos` - Eventos da agenda
- ✅ `agenda_espacos` - Espaços da agenda

#### **2.2 Colunas que DEVEM existir na tabela `usuarios`:**
- ✅ `id`, `nome`, `email`, `perfil`
- ✅ `perm_agenda_ver`, `perm_agenda_editar`, `perm_agenda_criar`, `perm_agenda_excluir`
- ✅ `perm_agenda_relatorios`, `perm_agenda_meus`
- ✅ `perm_demandas_*` (5 colunas)
- ✅ `perm_comercial_*` (4 colunas)

#### **2.3 Colunas que DEVEM existir na tabela `eventos`:**
- ✅ `id`, `titulo`, `descricao`, `data_inicio`, `data_fim`
- ✅ `local`, `status`, `observacoes`, `created_at`, `updated_at`

#### **2.4 Funções que DEVEM existir:**
- ✅ `obter_proximos_eventos(integer,integer)`
- ✅ `obter_eventos_hoje(integer)`
- ✅ `obter_eventos_semana(integer)`

### **3. 🔧 CORREÇÕES BASEADAS NA ANÁLISE**

#### **3.1 Se tabelas não existirem:**
- Criar tabelas faltantes
- Configurar chaves estrangeiras
- Criar índices básicos

#### **3.2 Se colunas não existirem:**
- Adicionar colunas faltantes
- Configurar valores padrão
- Atualizar permissões para usuários ADM

#### **3.3 Se funções não existirem:**
- Criar funções faltantes
- Testar funções criadas
- Verificar tipos de retorno

#### **3.4 Se ENUM eventos_status existir:**
- Remover ENUM
- Converter coluna status para VARCHAR
- Recriar funções

### **4. 🧪 TESTES DE VALIDAÇÃO**

#### **4.1 Testes de Estrutura:**
- Verificar se todas as tabelas existem
- Verificar se todas as colunas existem
- Verificar se todas as funções existem

#### **4.2 Testes de Funcionalidade:**
- Testar função `obter_proximos_eventos`
- Testar função `obter_eventos_hoje`
- Testar função `obter_eventos_semana`
- Testar consultas de permissão

#### **4.3 Testes de Dados:**
- Verificar se dados existentes foram preservados
- Verificar se permissões foram configuradas
- Verificar se usuários ADM têm acesso

### **5. 🚀 IMPLEMENTAÇÃO**

#### **5.1 Scripts de Correção:**
- `CORRECAO_TABELAS_FALTANTES.sql` - Para tabelas
- `CORRECAO_COLUNAS_FALTANTES.sql` - Para colunas
- `CORRECAO_FUNCOES_FALTANTES.sql` - Para funções
- `CORRECAO_PERMISSOES_FALTANTES.sql` - Para permissões

#### **5.2 Scripts de Teste:**
- `TESTE_ESTRUTURA.sql` - Testar estrutura
- `TESTE_FUNCIONALIDADE.sql` - Testar funcionalidade
- `TESTE_DADOS.sql` - Testar dados

### **6. ⚠️ PRECAUÇÕES**

#### **6.1 Backup:**
- Fazer backup antes de qualquer alteração
- Documentar estado atual
- Testar em ambiente de desenvolvimento primeiro

#### **6.2 Execução:**
- Executar scripts um por vez
- Verificar resultados após cada execução
- Parar se houver erros

#### **6.3 Validação:**
- Testar cada correção individualmente
- Verificar se dados foram preservados
- Confirmar se funcionalidade está funcionando

## 🎯 **PRÓXIMOS PASSOS:**

1. **Execute** `VERIFICACAO_COMPLETA_BANCO.sql`
2. **Analise** os resultados
3. **Identifique** problemas específicos
4. **Execute** scripts de correção específicos
5. **Teste** cada correção
6. **Valide** funcionamento completo

## 📝 **RESULTADO ESPERADO:**

Após seguir este plano, o banco de dados estará:
- ✅ Estruturalmente correto
- ✅ Funcionalmente completo
- ✅ Dados preservados
- ✅ Sistema funcionando perfeitamente

**Status:** 🔍 Pronto para verificação inicial!
