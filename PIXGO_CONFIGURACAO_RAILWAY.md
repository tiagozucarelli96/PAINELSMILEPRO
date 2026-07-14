# Configuração da PixGo no Railway

## 1. Credenciais no painel PixGo

Em `pixgo.org`, abra a área **Checkouts** e copie separadamente:

- API Key, com formato `pk_...`;
- Webhook Secret, com formato `whsec_...`.

Não use a API Key como Webhook Secret. São credenciais diferentes.

## 2. Variáveis do Railway

No serviço do Painel Smile PRO, abra **Variables** e configure:

```dotenv
PIXGO_ENABLED=true
PIXGO_API_KEY=pk_COLE_A_CHAVE_REAL_AQUI
PIXGO_WEBHOOK_SECRET=whsec_COLE_O_SEGREDO_REAL_AQUI
PIXGO_BASE_URL=https://pixgo.org/api/v1
PIXGO_WEBHOOK_URL=https://painelsmilepro-production.up.railway.app/pixgo_webhook.php
PIXGO_WEBHOOK_MAX_AGE_SECONDS=300
APP_URL=https://painelsmilepro-production.up.railway.app
```

Se o domínio público atual do Railway for diferente, substitua o domínio nas variáveis `PIXGO_WEBHOOK_URL` e `APP_URL`.

Depois de salvar as variáveis, faça um novo deploy/redeploy do serviço.

## 3. Banco de dados

Execute a migração:

```text
sql/103_pixgo_pagamentos.sql
```

Ela adiciona os campos PixGo às cobranças de eventos e formaturas e cria a tabela idempotente de webhooks.

## 4. Webhook no painel PixGo

Se o painel oferecer configuração de webhook global, cadastre também:

```text
https://painelsmilepro-production.up.railway.app/pixgo_webhook.php
```

O sistema envia essa mesma URL no campo `webhook_url` ao criar cada cobrança.

Eventos tratados:

- `payment.completed`;
- `payment.expired`;
- `payment.refunded`.

## 5. Primeiro teste

1. Confirme que a carteira Liquid foi validada no painel PixGo.
2. Abra o financeiro de um evento.
3. Escolha **PixGo** na carteira.
4. Confira nome e CPF/CNPJ de quem realmente fará o pagamento.
5. Crie uma cobrança entre R$ 10,00 e R$ 15,00.
6. Abra o link gerado e teste QR Code/Copia e Cola.
7. Após pagar, confirme que o status muda para **Pago** pelo webhook.
8. Confira o evento em `pixgo_webhook_events` e a liquidação na carteira Liquid.

Observações:

- não há sandbox; o teste é uma transação real;
- o QR Code expira em aproximadamente 20 minutos;
- PixGo fica restrita a pagamento à vista no sistema;
- somente o CPF/CNPJ informado na criação deve realizar o pagamento.
