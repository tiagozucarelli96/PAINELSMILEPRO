# Cron de notificações das degustações

O endpoint envia, por WhatsApp, a mensagem do dia para as inscrições com status
`confirmado` das degustações agendadas para a data atual.

Antes dos envios, o mesmo cron cancela automaticamente inscrições com cobrança
que continuam sem pagamento após 48 horas. Inscrições pagas e inscrições
gratuitas não são alteradas.

## Configuração no cron-job.org

- URL:
  `https://painelsmilepro-production.up.railway.app/cron.php?tipo=degustacoes_notificacoes&token=SEU_CRON_TOKEN`
- Método: `GET`
- Execução: todos os dias às `09:00`
- Fuso horário: `America/Sao_Paulo`

O endpoint responde imediatamente que o processamento foi iniciado e continua
os envios em segundo plano no Railway. Dessa forma, o cron-job.org não precisa
ficar esperando mensagem por mensagem.

Cada tentativa fica registrada por degustação e inscrição antes de o WhatsApp
ser acionado. Mesmo que o cron-job.org faça uma nova chamada, as inscrições já
iniciadas serão ignoradas para evitar mensagens duplicadas.

Uma resposta inconclusiva do provedor fica com status `incerto` e também não é
reenviada automaticamente, pois a mensagem pode ter sido aceita antes do
timeout.

## Conferência sem envio

Depois do deploy, use `dry_run=1` e `force=1` para conferir a mensagem e os
participantes encontrados sem acionar o WhatsApp:

`https://painelsmilepro-production.up.railway.app/cron.php?tipo=degustacoes_notificacoes&token=SEU_CRON_TOKEN&dry_run=1&force=1`

O parâmetro `force=1` ignora somente a trava de horário. Ele não ignora o
controle anti-duplicidade dos envios reais.

## Cancelamento de inscrições não pagas

O cancelamento já é executado pelo cron diário de notificações. Para aplicar a
regra mais perto do momento em que cada inscrição completa 48 horas, também é
possível configurar uma execução de hora em hora:

`https://painelsmilepro-production.up.railway.app/cron.php?tipo=degustacoes_cancelar_nao_pagas&token=SEU_CRON_TOKEN`

Prévia sem alterar inscrições:

`https://painelsmilepro-production.up.railway.app/cron.php?tipo=degustacoes_cancelar_nao_pagas&token=SEU_CRON_TOKEN&dry_run=1`
