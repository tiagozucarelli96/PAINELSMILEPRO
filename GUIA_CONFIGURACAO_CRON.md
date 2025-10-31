# 📅 Guia Completo: Configuração do Cron Job para Demandas Fixas

## 🕐 Timezone do Sistema

**O sistema está configurado para usar o horário de Brasília (America/Sao_Paulo).**

O script `cron_demandas_trello_fixas.php` agora define explicitamente:
```php
date_default_timezone_set('America/Sao_Paulo');
```

Isso significa que todas as verificações de data/hora são feitas no horário de Brasília.

---

## 🚂 Configuração no Railway

### Opção 1: Usando Railway Cron Jobs (Recomendado)

1. **Acesse seu projeto no Railway**
   - Vá para: https://railway.app
   - Selecione seu projeto

2. **Adicione um Novo Cron Job**
   - Clique em **"New"** → **"Cron Job"**
   - Configure:
     ```
     Schedule: 0 0 * * *  (diário às 00:00 UTC = 21:00 horário de Brasília)
     Command: curl -X GET "https://seu-app.railway.app/public/cron_demandas_trello_fixas.php?token=SEU_TOKEN"
     ```

3. **⚠️ ATENÇÃO: Horário UTC vs Brasília**
   - Railway usa **UTC** por padrão
   - **Brasília está UTC-3** (durante horário padrão)
   - **Brasília está UTC-2** (durante horário de verão - se aplicável)
   
   **Para executar às 00:00 de Brasília:**
   ```
   Schedule: 0 3 * * *  (03:00 UTC = 00:00 Brasília no horário padrão)
   ```
   
   Ou se preferir executar de manhã (08:00 Brasília):
   ```
   Schedule: 0 11 * * *  (11:00 UTC = 08:00 Brasília)
   ```

4. **Configure a Variável de Ambiente**
   - No Railway, vá em **"Variables"**
   - Adicione:
     ```
     CRON_TOKEN=seu_token_seguro_aqui
     ```

### Opção 2: Usando Scheduler Externo (EasyCron, cron-job.org)

1. **Escolha um serviço:**
   - https://www.easycron.com/ (gratuito)
   - https://cron-job.org/ (gratuito)
   - https://cronitor.io/ (pago)

2. **Configure o Job:**
   ```
   URL: https://seu-app.railway.app/public/cron_demandas_trello_fixas.php?token=SEU_TOKEN
   Schedule: 0 0 * * * (diário às 00:00 no timezone do serviço)
   Timezone: America/Sao_Paulo ou UTC-3
   Method: GET
   ```

### Opção 3: Usando Heroku Scheduler (se migrar)

Se você usar Heroku no futuro:
```
$ heroku addons:create scheduler:standard
$ heroku addons:open scheduler
```

Configure:
```
Command: curl "https://seu-app.herokuapp.com/public/cron_demandas_trello_fixas.php?token=SEU_TOKEN"
Frequency: Daily
At: 00:00 (horário do Heroku, configure UTC-3 ou ajuste manualmente)
```

---

## 🔐 Segurança: Token de Acesso

### Gerar um Token Seguro

Execute no terminal:
```bash
# Opção 1: Gere um token aleatório
openssl rand -hex 32

# Opção 2: Use um gerador online
# https://randomkeygen.com/
```

### Configurar Token no Railway

1. **No Railway Dashboard:**
   - Vá em **"Variables"**
   - Clique em **"New Variable"**
   - Nome: `CRON_TOKEN`
   - Valor: `[seu_token_gerado]`
   - Salve

2. **Teste o Token:**
   ```bash
   curl "https://seu-app.railway.app/public/cron_demandas_trello_fixas.php?token=SEU_TOKEN"
   ```

   Deve retornar JSON:
   ```json
   {
     "success": true,
     "gerados": 0,
     "total_fixas": 2,
     "erros": []
   }
   ```

---

## 🧪 Testando Manualmente

### Via Navegador
```
https://seu-app.railway.app/public/cron_demandas_trello_fixas.php?token=SEU_TOKEN
```

### Via Terminal (cURL)
```bash
curl "https://seu-app.railway.app/public/cron_demandas_trello_fixas.php?token=SEU_TOKEN"
```

### Via PHP (local)
```bash
cd /caminho/do/projeto
php public/cron_demandas_trello_fixas.php
```

---

## ⏰ Tabela de Conversão UTC → Brasília

| Horário Desejado (Brasília) | UTC (Horário Padrão) | Schedule Cron |
|----------------------------|---------------------|---------------|
| 00:00 (meia-noite)         | 03:00               | `0 3 * * *`   |
| 01:00                      | 04:00               | `0 4 * * *`   |
| 06:00                      | 09:00               | `0 9 * * *`   |
| 08:00                      | 11:00               | `0 11 * * *`  |
| 09:00                      | 12:00               | `0 12 * * *`  |

**Nota:** Durante horário de verão brasileiro (se aplicável), ajuste -1 hora (ex: 00:00 Brasília = 02:00 UTC).

---

## 📋 Exemplo de Configuração Completa

### Railway Cron Job:
```
Schedule: 0 3 * * *
Command: curl "https://painelsmile.railway.app/public/cron_demandas_trello_fixas.php?token=abc123def456"
```

### Variável de Ambiente:
```
CRON_TOKEN=abc123def456
```

---

## ✅ Checklist de Verificação

- [ ] Timezone configurado no script (`America/Sao_Paulo`)
- [ ] Token gerado e configurado no Railway
- [ ] Cron job agendado no Railway ou serviço externo
- [ ] Horário ajustado corretamente (UTC vs Brasília)
- [ ] Teste manual executado com sucesso
- [ ] Pelo menos uma demanda fixa criada e ativa
- [ ] Log de execução verificado

---

## 🐛 Troubleshooting

### Erro: "Token inválido"
- Verifique se `CRON_TOKEN` está configurado no Railway
- Verifique se o token na URL está correto
- Execute: `echo $CRON_TOKEN` para confirmar

### Erro: "Não autenticado"
- Verifique a sessão do banco de dados
- Confirme que as tabelas `demandas_fixas` e `demandas_fixas_log` existem

### Cards não estão sendo gerados
- Verifique se a demanda fixa está `ativo = TRUE`
- Verifique se a periodicidade corresponde ao dia atual
- Verifique o log: `SELECT * FROM demandas_fixas_log ORDER BY id DESC LIMIT 10;`

### Horário errado
- Railway usa UTC, não Brasília
- Ajuste o schedule conforme tabela de conversão acima
- Ou use um scheduler externo que permita escolher timezone

---

## 📞 Suporte

Se tiver dúvidas:
1. Verifique os logs do Railway
2. Execute o script manualmente para debug
3. Verifique as tabelas `demandas_fixas` e `demandas_fixas_log` no banco

---

**Última atualização:** 2024
**Timezone:** America/Sao_Paulo (UTC-3)

