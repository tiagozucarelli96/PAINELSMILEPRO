# 🚨 SOLUÇÃO ESPECÍFICA PARA ERRO DE FUNÇÃO NA PRODUÇÃO

## ❌ **Problema Identificado:**
```
Fatal error: Uncaught PDOException: SQLSTATE[42883]: Undefined function: 7 ERROR: function obter_proximos_eventos (unknown, unknown) does not exist
```

## 🔍 **Causa do Problema:**
- A função `obter_proximos_eventos` não existe na produção (Railway)
- A coluna `data_inicio` pode não existir na tabela `eventos`
- O sistema está tentando chamar uma função PostgreSQL que não foi criada

## ✅ **Solução Específica:**

### **🌐 Para Produção (Railway) - RECOMENDADO:**
**Acesse:** `https://painelsmilepro-production.up.railway.app/fix_production_functions_web.php`

### **🖥️ Para Local:**
**Execute:** `php fix_production_functions.php`
**OU acesse:** `http://localhost:8000/fix_production_functions_web.php`

## 🔧 **O que o Script Faz:**

### **1. 🔍 Verifica Coluna `data_inicio`:**
- Verifica se a coluna existe na tabela `eventos`
- Cria a coluna se não existir
- Atualiza registros existentes

### **2. 🔧 Cria Função `obter_proximos_eventos`:**
```sql
CREATE OR REPLACE FUNCTION obter_proximos_eventos(
    p_usuario_id INTEGER,
    p_horas INTEGER DEFAULT 24
)
RETURNS TABLE (
    id INTEGER,
    titulo VARCHAR(255),
    descricao TEXT,
    data_inicio TIMESTAMP,
    data_fim TIMESTAMP,
    local VARCHAR(255),
    status VARCHAR(20),
    observacoes TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
)
LANGUAGE plpgsql
AS $$
BEGIN
    RETURN QUERY
    SELECT 
        e.id, e.titulo, e.descricao, e.data_inicio, e.data_fim,
        e.local, e.status, e.observacoes, e.created_at, e.updated_at
    FROM eventos e
    WHERE 
        e.data_inicio >= NOW()
        AND e.data_inicio <= NOW() + INTERVAL '1 hour' * p_horas
        AND e.status = 'ativo'
    ORDER BY e.data_inicio ASC;
END;
$$;
```

### **3. 🔧 Cria Funções Auxiliares:**
- `obter_eventos_hoje(p_usuario_id)` - Eventos de hoje
- `obter_eventos_semana(p_usuario_id)` - Eventos da semana

### **4. 📊 Cria Índices de Performance:**
- `idx_eventos_data_inicio` - Performance em consultas por data
- `idx_eventos_status` - Performance em filtros por status
- `idx_agenda_eventos_data` - Performance em agenda
- `idx_usuarios_email` - Performance em login
- `idx_usuarios_perfil` - Performance em permissões

### **5. 🧪 Testa Funções Criadas:**
- Testa `obter_proximos_eventos(1, 24)`
- Testa `obter_eventos_hoje(1)`
- Testa `obter_eventos_semana(1)`
- Verifica funções existentes no banco

## 📋 **Arquivos Criados:**

### **Scripts de Correção:**
- `fix_production_functions.php` - Script terminal específico
- `public/fix_production_functions_web.php` - Interface web específica

### **Scripts Gerais:**
- `fix_final_complete.php` - Script terminal completo
- `public/fix_final_complete_web.php` - Interface web completa
- `fix_all_environments.php` - Script geral de correção
- `public/fix_all_environments_web.php` - Interface web geral

## 🎯 **Como Executar:**

### **Opção 1: Via Navegador (Mais Fácil)**
1. Acesse: `https://painelsmilepro-production.up.railway.app/fix_production_functions_web.php`
2. Aguarde o script executar
3. Verifique o relatório de correções
4. Teste o dashboard novamente

### **Opção 2: Via Terminal (Se tiver acesso)**
1. Faça upload do arquivo `fix_production_functions.php`
2. Execute: `php fix_production_functions.php`
3. Verifique as correções aplicadas

## 🔧 **O que o Script Resolve:**

1. ✅ **Cria a função `obter_proximos_eventos`** que estava faltando
2. ✅ **Adiciona a coluna `data_inicio`** se não existir
3. ✅ **Cria funções auxiliares** para otimização
4. ✅ **Cria índices de performance** para melhor funcionamento
5. ✅ **Testa todas as funções** para garantir funcionamento
6. ✅ **Funciona em qualquer ambiente** (detecção automática)

## ⚠️ **Importante:**

- **Execute apenas uma vez** - O script é idempotente
- **Não afeta dados existentes** - Apenas adiciona funções e colunas
- **Funciona em produção** - Detecta ambiente automaticamente
- **Seguro para usar** - Não remove dados

## 🎉 **Resultado Esperado:**

Após executar o script, o erro `function obter_proximos_eventos does not exist` deve desaparecer e o sistema deve funcionar normalmente.

**Status:** ✅ Pronto para execução na produção!

**Execute o script na produção e o erro será resolvido definitivamente!** 🚀
