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

### 1. **Evento Criado** (`created`)
```json
{
  "id": "12345",
  "action": "created",
  "name": "Casamento João e Maria",
  "date": "2025-06-15",
  "status": "ativo",
  "type": "casamento",
  "client_name": "João Silva",
  "client_email": "joao@email.com",
  "value": 15000.00
}
```

### 2. **Evento Atualizado** (`updated`)
```json
{
  "id": "12345",
  "action": "updated",
  "name": "Casamento João e Maria - Atualizado",
  "date": "2025-06-20",
  "status": "ativo",
  "type": "casamento",
  "client_name": "João Silva",
  "client_email": "joao@email.com",
  "value": 18000.00
}
```

### 3. **Evento Excluído** (`deleted`)
```json
{
  "id": "12345",
  "action": "deleted",
  "name": "Casamento João e Maria",
  "date": "2025-06-15",
  "status": "excluido",
  "type": "casamento",
  "client_name": "João Silva",
  "client_email": "joao@email.com",
  "value": 15000.00
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
- ✅ **Evento Criado (`created`):** +1 evento ativo
- ✅ **Evento Atualizado (`updated`):** Mantém contagem
- ❌ **Evento Excluído (`deleted`):** -1 evento ativo

### **Contratos Fechados:**
- ✅ **Evento Criado (`created`):** +1 contrato fechado
- ❌ **Evento Excluído (`deleted`):** -1 contrato fechado

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
    "id": "teste123",
    "action": "created",
    "name": "Evento de Teste",
    "date": "2025-06-15",
    "status": "ativo",
    "type": "teste",
    "client_name": "Cliente Teste",
    "client_email": "teste@email.com",
    "value": 1000.00
  }'
```

## ✅ **RESPOSTAS:**

### **Sucesso (200):**
```json
{
  "status": "sucesso",
  "mensagem": "Webhook processado com sucesso",
  "evento_id": "12345",
  "webhook_tipo": "created"
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
