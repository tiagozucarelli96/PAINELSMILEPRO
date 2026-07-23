# Cron de notificações das degustações

O endpoint envia, por WhatsApp, a mensagem do dia para as inscrições com status
`confirmado` das degustações agendadas para a data atual.

## Configuração no cron-job.org

- URL:
  `https://painelsmilepro-production.up.railway.app/cron.php?tipo=degustacoes_notificacoes&token=SEU_CRON_TOKEN`
- Método: `GET`
- Execução: todos os dias às `09:00`
- Fuso horário: `America/Sao_Paulo`

É seguro configurar novas tentativas em caso de erro: cada envio aceito pelo
provedor fica registrado por degustação e inscrição e não é enviado novamente.

## Conferência sem envio

Depois do deploy, use `dry_run=1` e `force=1` para conferir a mensagem e os
participantes encontrados sem acionar o WhatsApp:

`https://painelsmilepro-production.up.railway.app/cron.php?tipo=degustacoes_notificacoes&token=SEU_CRON_TOKEN&dry_run=1&force=1`

O parâmetro `force=1` ignora somente a trava de horário. Ele não ignora o
controle anti-duplicidade dos envios reais.
