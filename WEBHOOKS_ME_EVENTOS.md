# 📡 WEBHOOKS ME EVENTOS - DOCUMENTAÇÃO

## 🔗 **URL DO WEBHOOK:**
```
https://painelsmilepro-production.up.railway.app/webhook_me_eventos.php
```

## 🔑 **TOKEN DE AUTENTICAÇÃO:**
```
smile-token-2025
```

## 📋 **TIPOS DE WEBHOOK SUPORTADOS:**

### 1. **Evento Criado** (`evento_criado`)
```json
{
  "evento_id": "12345",
  "nome": "Casamento João e Maria",
  "data_evento": "2025-06-15",
  "status": "ativo",
  "tipo_evento": "casamento",
  "cliente_nome": "João Silva",
  "cliente_email": "joao@email.com",
  "valor": 15000.00,
  "webhook_tipo": "evento_criado"
}
```

### 2. **Evento Atualizado** (`evento_atualizado`)
```json
{
  "evento_id": "12345",
  "nome": "Casamento João e Maria - Atualizado",
  "data_evento": "2025-06-20",
  "status": "ativo",
  "tipo_evento": "casamento",
  "cliente_nome": "João Silva",
  "cliente_email": "joao@email.com",
  "valor": 18000.00,
  "webhook_tipo": "evento_atualizado"
}
```

### 3. **Evento Excluído** (`evento_excluido`)
```json
{
  "evento_id": "12345",
  "nome": "Casamento João e Maria",
  "data_evento": "2025-06-15",
  "status": "excluido",
  "tipo_evento": "casamento",
  "cliente_nome": "João Silva",
  "cliente_email": "joao@email.com",
  "valor": 15000.00,
  "webhook_tipo": "evento_excluido"
}
```

## 🔐 **AUTENTICAÇÃO:**

### **Header Authorization:**
```
Authorization: smile-token-2025
```

### **Header X-Token:**
```
X-Token: smile-token-2025
```

### **Query Parameter:**
```
?token=smile-token-2025
```

## 📊 **IMPACTO NO DASHBOARD:**

### **Eventos Ativos:**
- ✅ **Evento Criado:** +1 evento ativo
- ✅ **Evento Atualizado:** Mantém contagem
- ❌ **Evento Excluído:** -1 evento ativo

### **Contratos Fechados:**
- ✅ **Evento Criado:** +1 contrato fechado
- ❌ **Evento Excluído:** -1 contrato fechado

### **Leads e Vendas:**
- 📈 **Leads Total:** Contagem de eventos criados
- 📈 **Leads Negociação:** Eventos em status "negociacao"
- 📈 **Vendas Realizadas:** Eventos com valor > 0

## 🗄️ **TABELAS CRIADAS:**

### **`me_eventos_webhook`**
- Armazena todos os webhooks recebidos
- Dados completos do evento
- Histórico de alterações

### **`me_eventos_stats`**
- Estatísticas mensais consolidadas
- Contadores automáticos
- Dados para o dashboard

## 🔄 **PROCESSAMENTO AUTOMÁTICO:**

1. **Webhook Recebido** → Validação do token
2. **Dados Processados** → Inserção no banco
3. **Estatísticas Atualizadas** → Trigger automático
4. **Dashboard Atualizado** → Dados em tempo real

## 📝 **LOGS:**

### **Arquivo de Log:**
```
/logs/webhook_me_eventos.log
```

### **Formato do Log:**
```
[2025-01-25 14:30:15] Webhook recebido: {"evento_id":"12345"...}
[2025-01-25 14:30:15] Webhook processado com sucesso: evento_criado - 12345
```

## 🧪 **TESTE DO WEBHOOK:**

### **cURL de Teste:**
```bash
curl -X POST https://painelsmilepro-production.up.railway.app/webhook_me_eventos.php \
  -H "Content-Type: application/json" \
  -H "Authorization: smile-token-2025" \
  -d '{
    "evento_id": "teste123",
    "nome": "Evento de Teste",
    "data_evento": "2025-06-15",
    "status": "ativo",
    "tipo_evento": "teste",
    "cliente_nome": "Cliente Teste",
    "cliente_email": "teste@email.com",
    "valor": 1000.00,
    "webhook_tipo": "evento_criado"
  }'
```

## ✅ **RESPOSTAS:**

### **Sucesso (200):**
```json
{
  "status": "sucesso",
  "mensagem": "Webhook processado com sucesso",
  "evento_id": "12345",
  "webhook_tipo": "evento_criado"
}
```

### **Erro (401):**
```json
{
  "erro": "Token inválido"
}
```

### **Erro (400):**
```json
{
  "erro": "JSON inválido"
}
```

## 🎯 **INTEGRAÇÃO COM ME EVENTOS:**

1. **Configurar Webhook** na ME Eventos
2. **URL:** `https://painelsmilepro-production.up.railway.app/webhook_me_eventos.php`
3. **Token:** `smile-token-2025`
4. **Eventos:** Criado, Atualizado, Excluído
5. **Dashboard** atualiza automaticamente

## 📈 **MONITORAMENTO:**

- ✅ **Logs automáticos** de todos os webhooks
- ✅ **Estatísticas em tempo real** no dashboard
- ✅ **Contadores automáticos** de eventos
- ✅ **Histórico completo** de alterações
