# Configurar Cron de Confirmacao de Visitas por WhatsApp

Use o cron-job.org para chamar o endpoint uma vez por dia, no horario de Sao Paulo.

## Cron Job

```
Title: Agenda - Confirmacao de Visitas WhatsApp

Address (URL):
https://painelsmilepro-production.up.railway.app/cron.php?tipo=agenda_visitas_whatsapp&token=SEU_TOKEN_AQUI

Schedule:
- Every day
- Time: 08:00
- Timezone: America/Sao_Paulo (UTC-03:00)

Request method: GET
```

## Comportamento esperado

Quando o cron rodar, o sistema:

1. Consulta as visitas com `tipo = visita`, `status = agendado` e data igual ao dia atual.
2. Garante uma notificacao `confirmacao_8h` para cada visita do dia.
3. Envia a mensagem pela SMClick apenas se a visita for do dia atual.
4. Cancela notificacoes antigas para evitar envio atrasado em outro dia.

## Teste manual

Para testar sem enviar WhatsApp:

```
https://painelsmilepro-production.up.railway.app/cron.php?tipo=agenda_visitas_whatsapp&dry_run=1&token=SEU_TOKEN_AQUI
```

Para executar de verdade:

```
https://painelsmilepro-production.up.railway.app/cron.php?tipo=agenda_visitas_whatsapp&token=SEU_TOKEN_AQUI
```

