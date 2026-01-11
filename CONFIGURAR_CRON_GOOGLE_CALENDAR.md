# Configuração de Cron Jobs para Google Calendar

## Jobs Necessários

### 1. Sincronização Diária (Garantia 1x/dia)
**Arquivo:** `public/cron_google_calendar_daily.php`  
**Frequência:** 1x por dia (recomendado: 2h da manhã)  
**Comando Railway:**
```
0 2 * * * php /app/public/cron_google_calendar_daily.php
```

### 2. Renovação de Webhooks (6h antes de expirar)
**Arquivo:** `public/google_calendar_watch_renewal.php`  
**Frequência:** A cada 1 hora  
**Comando Railway:**
```
0 * * * * php /app/public/google_calendar_watch_renewal.php
```

### 3. Processador de Sincronização (opcional - pode ser chamado manualmente)
**Arquivo:** `public/google_calendar_sync_processor.php`  
**Uso:** Chamado automaticamente pelo cron diário ou pode ser executado manualmente

## Configuração no Railway

1. Acesse o painel do Railway
2. Vá em **Settings** → **Cron Jobs**
3. Adicione os dois cron jobs acima

## Verificação

- Logs aparecem com prefixo `[GOOGLE_CRON_DAILY]` e `[GOOGLE_WATCH_RENEWAL]`
- Verifique os logs no Railway para confirmar execução
