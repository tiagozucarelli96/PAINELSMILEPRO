# Estudo da API PixGo para o Painel Smile PRO

Data da análise: 14/07/2026  
Escopo principal: PixGo.org API v1  
Contexto do sistema: aplicação PHP/PostgreSQL hospedada no Railway, com pagamentos PIX atualmente integrados ao Asaas.

## 1. Resumo executivo

A API do `pixgo.org` é tecnicamente simples de integrar ao Painel Smile PRO. Ela oferece criação de cobrança PIX, consulta, status e webhooks assinados. Uma primeira integração controlada exigiria um helper HTTP, um endpoint de webhook e pequenas extensões no banco.

Entretanto, **não recomendo substituir o Asaas pela PixGo.org neste momento**. O principal motivo não é técnico: o produto financeiro é diferente. O Asaas liquida pagamentos em uma conta de pagamentos; a PixGo.org converte o PIX recebido em DEPIX, um criptoativo/stablecoin na Liquid Network, e o envia para uma carteira Liquid em D+1. Os próprios termos exigem que o comerciante informe isso claramente ao pagador antes da cobrança.

Recomendação:

1. Manter o Asaas como provedor principal.
2. Se houver interesse estratégico em receber DEPIX, executar primeiro validação jurídica, fiscal e contábil.
3. Depois dessa aprovação, implementar a PixGo como **provedor opcional**, atrás de uma camada comum de pagamentos, sem remover o Asaas.
4. Fazer piloto de produção com valores pequenos e poucos usuários, pois a PixGo.org não possui sandbox.

## 2. Atenção: há dois serviços chamados PixGo

Foram encontrados dois produtos independentes com nome semelhante:

| Serviço | Base/endpoint | Modelo | Autenticação |
|---|---|---|---|
| PixGo.org | `https://pixgo.org/api/v1` | PIX convertido em DEPIX e liquidado em carteira Liquid | `X-API-Key: pk_...` |
| PixGo API Brasil | `https://pixgo.api.br/gerar-pix` | Intermediário que usa a credencial Mercado Pago do cliente e cobra créditos por requisição | `Authorization: Bearer ...` |

Este estudo prioriza o **PixGo.org**, porque sua documentação é mais recente e completa e contempla webhook assinado, idempotência e eventos de estorno. A solução `pixgo.api.br` é resumida na seção 12.

## 3. Modelo operacional da PixGo.org

O fluxo real é:

```text
Painel Smile PRO
      |
      | POST /payment/create
      v
PixGo.org
      |
      | QR Code PIX
      v
Pagador realiza PIX (CPF/CNPJ previamente informado)
      |
      v
PLEBANK / Fitbank processam o PIX
      |
      v
Valor é convertido em DEPIX
      |
      | webhook payment.completed
      v
Painel atualiza a receita
      |
      v
DEPIX é liquidado em carteira Liquid em D+1
```

Consequências para o Smile PRO:

- o valor recebido não é liquidado como saldo bancário BRL comum;
- a empresa precisa operar e proteger uma carteira Liquid;
- há custódia temporária da PixGo antes da liquidação;
- a conciliação deve registrar valor bruto, taxas e valor líquido em DEPIX;
- a contabilidade precisa definir o tratamento do recebimento e da conversão;
- o cliente deve ser avisado de que o pagamento envolve aquisição/conversão para DEPIX;
- disputas ficam sujeitas também aos parceiros PLEBANK/Fitbank e aos termos de uma empresa sediada nos Estados Unidos.

Fonte: [Termos de Uso da PixGo.org](https://pixgo.org/termos?lang=pt).

## 4. Autenticação e ambientes

### Base URL

```text
https://pixgo.org/api/v1
```

### Header

```http
X-API-Key: pk_sua_chave
Content-Type: application/json
```

Características:

- a chave é de produção;
- não existe sandbox separado;
- gerar uma chave nova invalida a anterior;
- a chave deve ficar exclusivamente no backend/variável de ambiente;
- nunca deve ser enviada ao navegador nem registrada em logs;
- a liberação pode depender de aprovação/KYB e depósito de validação.

Variáveis propostas no Railway:

```dotenv
PIXGO_ENABLED=false
PIXGO_API_KEY=
PIXGO_BASE_URL=https://pixgo.org/api/v1
PIXGO_WEBHOOK_URL=https://SEU-DOMINIO/pixgo_webhook.php
PIXGO_WEBHOOK_MAX_AGE_SECONDS=300
```

Fonte: [Documentação oficial da API v1](https://pixgo.org/api/v1/docs).

## 5. Endpoints principais

### 5.1 Criar pagamento

```http
POST /payment/create
Idempotency-Key: UUID-UNICO-DA-COBRANCA
```

Exemplo de request:

```json
{
  "amount": 100.00,
  "description": "Receita do evento 123",
  "external_id": "evento_receita:456",
  "receiver_name": "Nome do responsável",
  "receiver_cpf": "12345678901",
  "receiver_email": "cliente@example.com",
  "receiver_phone": "11999998888",
  "webhook_url": "https://SEU-DOMINIO/pixgo_webhook.php"
}
```

Resposta esperada: HTTP `201`.

```json
{
  "success": true,
  "data": {
    "payment_id": "019c8b9149ec7433a5065b834cb45ccc",
    "external_id": "evento_receita:456",
    "amount": 100.00,
    "status": "pending",
    "qr_code": "00020126...",
    "qr_image_url": "https://pixgo.org/qr/....png",
    "expires_at": "2026-07-14T14:30:00-03:00",
    "created_at": "2026-07-14T14:00:00-03:00"
  }
}
```

Regras relevantes em 14/07/2026:

- valor mínimo: R$ 10,00;
- valor máximo: depende do nível da conta, chegando a R$ 6.000,00 por QR Code;
- limite diário de R$ 6.000,00 por CPF/CNPJ pagador;
- `receiver_cpf` é obrigatório desde 25/06/2026;
- somente o CPF/CNPJ informado pode pagar o QR Code;
- pagamento por terceiro é rejeitado e o reembolso pode levar até 48 horas;
- CPF deve ter 11 dígitos e CNPJ 14, sem pontuação e com dígitos verificadores válidos;
- `external_id`: até 50 caracteres;
- `description`: até 200 caracteres;
- QR Code avulso expira em 20 minutos.

Há uma inconsistência na documentação: um artigo publicado em maio ainda mostra `receiver_cpf` como opcional, mas a documentação live o declara obrigatório desde 25/06/2026. A implementação deve seguir a documentação live.

### 5.2 Consultar pagamento completo

```http
GET /payment/{payment_id}
```

Uso recomendado: conciliação, diagnóstico e recuperação de estado. O limite informado é de 10.000 requisições por 24 horas.

### 5.3 Consultar somente o status

```http
GET /payment/{payment_id}/status
```

Estados documentados:

| PixGo | Estado local sugerido |
|---|---|
| `pending` | `pendente` |
| `completed` | `pago` |
| `expired` | `vencido` ou `cancelado`, conforme regra existente |
| `cancelled` | `cancelado` |
| evento `payment.refunded` | `estornado` |

Esse endpoint aceita somente 1.000 requisições por 24 horas por chave. Deve servir como fallback da interface e da reconciliação, não como mecanismo principal de confirmação.

## 6. Webhooks

Eventos informados:

- `order.created`;
- `payment.completed`;
- `payment.expired`;
- `payment.refunded`.

Headers relevantes:

```http
X-Webhook-Event: payment.completed
X-Webhook-Timestamp: 1715789430
X-Webhook-Signature: assinatura_hmac_sha256
```

Validação indicada pela PixGo:

```text
mensagem = timestamp + "." + corpo_bruto
assinatura_esperada = HMAC-SHA256(mensagem, PIXGO_API_KEY)
```

Requisitos adicionais para uma implementação segura:

1. Ler o corpo bruto antes de executar `json_decode`.
2. Comparar a assinatura com `hash_equals` para evitar timing attack.
3. Rejeitar timestamp ausente, inválido ou com diferença superior a 5 minutos, reduzindo replay attacks.
4. Confirmar que o evento do header é igual ao evento do JSON.
5. Deduplicar por `payment_id + event` com índice único.
6. Conferir se o `external_id`, valor e `payment_id` pertencem à cobrança local.
7. Nunca marcar como pago usando somente dados enviados pelo frontend.
8. Responder `2xx` rapidamente; o timeout informado é de aproximadamente 10 segundos.
9. Guardar payload bruto, assinatura, resultado da validação e resultado do processamento.
10. Tratar `payment.refunded` obrigatoriamente e desfazer a baixa financeira de forma auditável.

A documentação apresenta duas formas de payload — campos dentro de `data` na referência live e exemplo mais antigo com campos no nível raiz no artigo. O parser deve preferir `payload.data`, mas aceitar temporariamente o formato raiz com log de compatibilidade. Isso deve ser confirmado com um webhook real no piloto.

Fonte: [Guia de integração para desenvolvedores](https://pixgo.org/blog/api-desenvolvedores?lang=pt).

## 7. Erros, retry e idempotência

| HTTP | Significado | Conduta |
|---|---|---|
| 200 | consulta bem-sucedida | processar |
| 201 | pagamento criado | persistir `payment_id` e resposta |
| 400 | JSON/campo obrigatório inválido | corrigir request; não retentar automaticamente |
| 401 | chave ausente, inválida ou revogada | bloquear integração e alertar operação |
| 403 | conta/chave sem permissão | não retentar até correção operacional |
| 404 | pagamento não encontrado | conferir ID e conciliação |
| 410 | recurso removido | não retentar |
| 422 | regra de negócio/valor inválido | mostrar erro controlado e corrigir dados |
| 429 | rate limit | respeitar `Retry-After` |
| 500/502/503/504 | falha transitória do provedor | retry com backoff e limite de tentativas |

Para criação, enviar `Idempotency-Key` estável por cobrança. Em timeout ou erro 5xx, repetir a mesma chave, nunca gerar outra. Estratégia sugerida: 1 s, 2 s e 4 s; depois registrar pendência para conciliação manual. Antes de tentar criar outra cobrança, consultar o estado armazenado.

Observação: o artigo de erros documenta o header `Idempotency-Key`, mas ele não aparece na página principal da referência. Deve ser validado em piloto com o suporte/provedor antes de depender dele como única proteção contra duplicidade.

Fonte: [Códigos de erro e depuração da API](https://pixgo.org/blog/api-erros-debug?lang=pt).

## 8. Taxas, limites e liquidação

Taxas publicadas em 14/07/2026:

- cobranças de R$ 10,00 a R$ 50,00: 2% + R$ 1,00;
- cobranças acima de R$ 50,00: 2%;
- liquidação D+1 para carteira Liquid: R$ 0,50 por envio;
- operações posteriores para converter/sacar DEPIX podem ter outras taxas de terceiros.

Exemplos:

| Cliente paga | Taxa PixGo | Liquidação | Líquido estimado em DEPIX |
|---:|---:|---:|---:|
| R$ 30,00 | R$ 1,60 | R$ 0,50 | R$ 27,90 |
| R$ 100,00 | R$ 2,00 | R$ 0,50 | R$ 97,50 |
| R$ 500,00 | R$ 10,00 | R$ 0,50 | R$ 489,50 |

O webhook concluído expõe `amounts.gross`, `fee_pixgo`, `fee_liquid`, `fee_total`, `net` e `currency`. Esses valores devem ser persistidos; não devem ser recalculados como fonte contábil definitiva.

Fonte: [Estrutura de taxas da PixGo](https://pixgo.org/blog/taxas-pixgo?lang=pt).

## 9. Encaixe no código atual

O projeto já possui boa parte do padrão necessário:

- `public/asaas_helper.php`: cliente HTTP e criação/consulta de cobranças;
- `public/asaas_webhook.php`: recepção, log e idempotência de eventos;
- `public/comercial_pagamento.php`: apresentação de QR Code e Copia e Cola;
- `public/eventos_formatura.php`: criação de receitas e vínculo por referência externa;
- `asaas_webhook_events`: modelo de tabela de auditoria/deduplicação.

Não é aconselhável copiar toda a lógica do Asaas e trocar nomes. A melhor evolução é introduzir uma interface de provedor:

```php
interface PixPaymentProvider
{
    public function createPayment(array $data): array;
    public function getPayment(string $providerPaymentId): array;
    public function getPaymentStatus(string $providerPaymentId): array;
}
```

Implementações:

```text
AsaasPixProvider
PixGoProvider
```

Modelo de dados sugerido para novas cobranças:

| Campo | Finalidade |
|---|---|
| `provider` | `asaas` ou `pixgo` |
| `provider_payment_id` | identificador da cobrança no provedor |
| `external_id` | identificador local estável |
| `idempotency_key` | chave usada na criação |
| `status_provider` | status bruto do provedor |
| `status_local` | status normalizado |
| `amount_gross` | valor cobrado |
| `fee_total` | total de taxas informado pelo provedor |
| `amount_net` | valor líquido informado pelo provedor |
| `currency` | `BRL` |
| `settlement_asset` | `BRL` para fluxo tradicional ou `DEPIX` para PixGo.org |
| `qr_code` | Copia e Cola, preferencialmente cifrado ou com retenção limitada |
| `qr_image_url` | URL do QR Code |
| `expires_at` | validade da cobrança |
| `provider_payload` | resposta JSON para auditoria, com acesso restrito |

Tabela independente proposta para eventos:

```text
payment_webhook_events
- provider
- provider_payment_id
- event_type
- signature
- payload_raw
- signature_valid
- processing_status
- received_at
- processed_at
- error_message
UNIQUE(provider, provider_payment_id, event_type)
```

Para preservar o sistema atual, a primeira versão pode adicionar colunas `pixgo_*` às tabelas usadas, mas a tabela genérica é melhor para evitar uma terceira migração quando outro provedor for incorporado.

## 10. Plano de implantação sugerido

### Fase 0 — aprovação de negócio

- validar termos com jurídico;
- validar escrituração, emissão fiscal, custódia e conversão de DEPIX com contador;
- definir quem guarda a seed da carteira e procedimento de recuperação;
- definir política de acesso, dupla custódia e saída de colaboradores;
- confirmar com a PixGo limites, SLA, suporte e comportamento exato da idempotência;
- aprovar texto obrigatório exibido ao pagador.

### Fase 1 — fundação técnica

- criar configuração por variáveis de ambiente;
- criar `PixGoProvider` sem expor credenciais em logs;
- criar tabela genérica de cobranças/eventos;
- mapear estados e erros;
- implementar testes unitários usando respostas HTTP simuladas.

### Fase 2 — webhook

- endpoint dedicado `public/pixgo_webhook.php`;
- validação HMAC sobre corpo bruto;
- proteção contra replay;
- idempotência transacional;
- tratamento de completo, expirado e estornado;
- conciliação pelo `payment_id` e pelo `external_id`.

### Fase 3 — interface

- seletor de provedor disponível somente a administradores;
- tela de QR Code/Copia e Cola;
- aviso claro sobre DEPIX antes de gerar a cobrança;
- exibição de expiração e atualização de status sem polling agressivo;
- trilha de auditoria da escolha do provedor.

### Fase 4 — piloto

- feature flag `PIXGO_ENABLED` inicialmente desligada;
- testar valores entre R$ 10 e R$ 15 em produção;
- testar pagamento concluído, expiração, duplicidade de webhook e reenvio;
- solicitar ao provedor teste assistido de estorno;
- confirmar o crédito líquido e a liquidação D+1 na carteira;
- reconciliar painel, webhook, dashboard PixGo e carteira Liquid.

### Fase 5 — decisão

- medir disponibilidade, tempo de confirmação, divergências, suporte e custo total de saída para BRL;
- só então liberar para uma unidade ou tipo de evento;
- manter fallback operacional no Asaas.

## 11. Riscos e decisão recomendada

| Risco | Nível | Mitigação |
|---|---|---|
| Tratamento contábil/fiscal de DEPIX | Alto | parecer contábil e jurídico antes do desenvolvimento de produção |
| Obrigação de transparência ao pagador | Alto | aceite/texto obrigatório antes de criar o QR |
| Custódia e perda da seed Liquid | Alto | política formal, backup offline e dupla custódia |
| Empresa/foro no exterior | Alto | revisão contratual e plano de contingência |
| Ausência de sandbox | Médio/alto | testes pequenos em produção e feature flag |
| Limites progressivos baixos no início | Médio | piloto e validação prévia do volume |
| Dependência PixGo + PLEBANK + Fitbank + Depix | Médio/alto | monitoramento, conciliação e fallback Asaas |
| Rate limit de status | Médio | webhook como fonte principal |
| Divergências entre páginas da documentação | Médio | contrato tolerante, testes reais e confirmação com suporte |
| Estorno/MED | Alto | evento obrigatório, reversão contábil e auditoria |

**Decisão indicada:** `GO` apenas para um piloto opcional após aprovação jurídica/contábil; `NO-GO` para substituir o Asaas agora.

## 12. Resumo da alternativa pixgo.api.br

O outro serviço chamado PixGo usa estes endpoints:

```http
POST https://pixgo.api.br/gerar-pix
Authorization: Bearer SUA_API_KEY_PIXGO
```

```http
GET https://pixgo.api.br/consultar-pix?reference=PEDIDO_XXX
Authorization: Bearer SUA_API_KEY_PIXGO
```

Ele exige cadastrar na plataforma o token de produção do Mercado Pago, que o site afirma armazenar criptografado. O modelo cobra créditos por chamada, além das condições do próprio Mercado Pago. A documentação localizada descreve geração e consulta, mas não apresenta o mesmo nível de detalhe sobre webhook assinado, idempotência, rate limits e estorno.

Para o Painel Smile PRO, esse modelo oferece pouco benefício sobre integrar diretamente ao Mercado Pago e ainda adiciona um intermediário que guarda uma credencial financeira sensível. Portanto, também não é recomendado sem uma justificativa específica e uma análise de segurança do fornecedor.

Fontes: [Como funciona o PixGo API Brasil](https://pixgo.api.br/como-funciona/) e [visão geral do serviço](https://pixgo.api.br/sobre/).

## 13. Perguntas que precisam de resposta antes da integração

1. O objetivo é receber DEPIX ou receber reais em conta bancária?
2. A empresa e o contador aceitam o fluxo PIX → DEPIX → eventual conversão para BRL?
3. Quem será responsável legal e operacional pela carteira Liquid e pela seed?
4. Como o cliente será informado e dará ciência antes de pagar?
5. O limite inicial por QR atende aos valores de eventos e formaturas?
6. Existe SLA contratual da API e do webhook?
7. Qual é a retenção exata dos dados pessoais e como exercer direitos LGPD?
8. O `Idempotency-Key` é garantido contratualmente e por quanto tempo?
9. Qual é o schema canônico atual do webhook: campos na raiz ou dentro de `data`?
10. Como simular estorno/MED e qual o prazo de notificação?
11. Qual é o custo total para converter e sacar DEPIX em BRL?
12. A PixGo oferece exportação contábil e extrato de liquidação por lote?

## 14. Referências principais

- [Documentação oficial PixGo API v1](https://pixgo.org/api/v1/docs)
- [Guia oficial para desenvolvedores](https://pixgo.org/blog/api-desenvolvedores?lang=pt)
- [Códigos de erro e depuração](https://pixgo.org/blog/api-erros-debug?lang=pt)
- [Taxas da PixGo](https://pixgo.org/blog/taxas-pixgo?lang=pt)
- [FAQ: taxas, limites e carteira Liquid](https://pixgo.org/faq?lang=pt)
- [Termos e Condições de Uso](https://pixgo.org/termos?lang=pt)
- [PixGo API Brasil — documentação](https://pixgo.api.br/como-funciona/)

> Este documento é um estudo técnico e de riscos, não um parecer jurídico, fiscal ou contábil.
