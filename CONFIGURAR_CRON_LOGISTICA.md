# Cron Logística (Railway)

## Endpoint
```
https://SEU_DOMINIO/logistica_cron_runner.php
```

## Token
Defina a variável de ambiente:
```
LOGISTICA_CRON_TOKEN
```

## Comando (curl)
```
curl -H "X-CRON-TOKEN: $LOGISTICA_CRON_TOKEN" https://SEU_DOMINIO/logistica_cron_runner.php
```

## Agendamento
- Rodar diariamente às 04:00 (America/Sao_Paulo).
- Se o agendador do Railway for UTC, ajuste o horário no cron para 07:00 UTC (horário padrão de Brasília).

## Observações
- O runner define `date_default_timezone_set('America/Sao_Paulo');`
- Retorno em JSON com resumo de sync, faltas e baixa do dia.
