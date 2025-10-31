# 🚀 Configuração Rápida do Cron Job

## ✅ Você já tem:
- [x] Variável `CRON_TOKEN` criada no Railway

## 🔧 Agora vamos configurar o Cron Job

### Opção Recomendada: cron-job.org (Grátis)

1. **Acesse:** https://cron-job.org/
   - Crie uma conta gratuita (ou faça login)

2. **Clique em "Create cronjob"**

3. **Configure:**
   ```
   Title: Gerar Demandas Fixas
   
   Address (URL): 
   https://painelsmilepro-production.up.railway.app/cron.php?tipo=demandas_fixas&token=a82b0934e5b5adeee5ebf7ed7bdad85a211e79bde81ef783d0274459dc7dcecb
   
   Schedule:
   - Selecionar: "Every day"
   - Time: 00:00
   - Timezone: (UTC-03:00) America/Sao_Paulo
   
   Request method: GET
   
   Save
   ```

4. **Teste imediatamente:**
   - Clique em "Run now" para testar
   - Deve retornar JSON com `"success": true`

---

### Opção Alternativa: EasyCron (Grátis)

1. **Acesse:** https://www.easycron.com/
   - Crie conta gratuita

2. **Adicione novo Cron Job:**
   ```
   Cron Job Name: Gerar Demandas Fixas
   
   URL: 
   https://painelsmilepro-production.up.railway.app/cron.php?tipo=demandas_fixas&token=a82b0934e5b5adeee5ebf7ed7bdad85a211e79bde81ef783d0274459dc7dcecb
   
   Schedule: 0 0 * * *
   Timezone: America/Sao_Paulo
   
   HTTP Method: GET
   ```

---

## 🧪 Testar Agora (Antes de Agendar)

### Via Navegador:
Cole no navegador (URL SIMPLIFICADA):
```
https://painelsmilepro-production.up.railway.app/cron.php?tipo=demandas_fixas&token=a82b0934e5b5adeee5ebf7ed7bdad85a211e79bde81ef783d0274459dc7dcecb
```

**OU** a URL antiga (também funciona):
```
https://painelsmilepro-production.up.railway.app/public/cron_demandas_trello_fixas.php?token=a82b0934e5b5adeee5ebf7ed7bdad85a211e79bde81ef783d0274459dc7dcecb
```

### Deve retornar:
```json
{
  "success": true,
  "gerados": 0,
  "total_fixas": 1,
  "erros": []
}
```

---

## ⚠️ Importante

- **Domínio:** `painelsmilepro-production.up.railway.app` ✅
- O token já está configurado no Railway ✅
- O cron vai executar **diariamente às 00:00 (meia-noite) horário de Brasília**
- Cards serão gerados automaticamente quando for o dia certo (diária, semanal ou mensal)

---

## ✅ Checklist Final

- [ ] URL testada no navegador (retornou JSON)
- [ ] Cron job criado no cron-job.org ou EasyCron
- [ ] Schedule configurado: 00:00 America/Sao_Paulo
- [ ] Teste "Run now" funcionou
- [ ] Pelo menos 1 demanda fixa criada e ativa no sistema

---

**Pronto!** 🎉 

O cron vai executar automaticamente todos os dias à meia-noite (horário de Brasília) e gerar os cards das demandas fixas que estiverem configuradas para o dia atual.

