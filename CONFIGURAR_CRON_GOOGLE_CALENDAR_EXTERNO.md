# Configuração de Cron Jobs do Google Calendar (Serviço Externo)

Como o Railway não tem interface para cron jobs, vamos usar um serviço externo (cron-job.org) que chama os endpoints HTTP.

## 🔑 Token de Segurança

Primeiro, certifique-se de que a variável `CRON_TOKEN` está configurada no Railway:
- Vá em **Settings** → **Variables**
- Adicione: `CRON_TOKEN` = `seu_token_seguro_aqui`
- Use o mesmo token que já está configurado para outros crons

## 📋 Configuração no cron-job.org

### 1. Acesse: https://cron-job.org/
- Crie uma conta gratuita (ou faça login)

### 2. Cron Job 1: Sincronização Diária

**Clique em "Create cronjob"**

```
Title: Google Calendar - Sincronização Diária

Address (URL): 
https://painelsmilepro-production.up.railway.app/cron.php?tipo=google_calendar_daily&token=SEU_TOKEN_AQUI

Schedule:
- Selecionar: "Every day"
- Time: 02:00
- Timezone: (UTC-03:00) America/Sao_Paulo

Request method: GET

Save
```

### 3. Cron Job 2: Renovação de Webhooks

**Clique em "Create cronjob" novamente**

```
Title: Google Calendar - Renovação de Webhooks

Address (URL): 
https://painelsmilepro-production.up.railway.app/cron.php?tipo=google_calendar_renewal&token=SEU_TOKEN_AQUI

Schedule:
- Selecionar: "Every hour"
- Time: 00:00 (início da hora)
- Timezone: (UTC-03:00) America/Sao_Paulo

Request method: GET

Save
```

## 🧪 Testar Agora

### Teste 1: Sincronização Diária
```
https://painelsmilepro-production.up.railway.app/cron.php?tipo=google_calendar_daily&token=SEU_TOKEN_AQUI
```

**Deve retornar:**
```json
{
  "success": true,
  "message": "Sincronização diária do Google Calendar iniciada"
}
```

### Teste 2: Renovação de Webhooks
```
https://painelsmilepro-production.up.railway.app/cron.php?tipo=google_calendar_renewal&token=SEU_TOKEN_AQUI
```

**Deve retornar:**
```json
{
  "success": true,
  "message": "Renovação de webhooks do Google Calendar iniciada"
}
```

## ✅ Checklist

- [ ] Variável `CRON_TOKEN` configurada no Railway
- [ ] Cron job 1 criado (sincronização diária às 2h)
- [ ] Cron job 2 criado (renovação a cada hora)
- [ ] Teste "Run now" funcionou para ambos
- [ ] Verificar logs do Railway para confirmar execução

## 📊 Verificação

Após configurar, verifique os logs no Railway:
- Procure por `[GOOGLE_CRON_DAILY]` para sincronização diária
- Procure por `[GOOGLE_WATCH_RENEWAL]` para renovação de webhooks

---

**Pronto!** 🎉 

Os cron jobs vão executar automaticamente:
- **Sincronização diária:** Todos os dias às 2h da manhã
- **Renovação de webhooks:** A cada hora (verifica se precisa renovar)
