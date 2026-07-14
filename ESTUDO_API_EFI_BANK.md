# Estudo da API Pix Efí Bank para o Painel Smile PRO

Data da analise: 14/07/2026  
Empresa analisada: Efí Bank, antiga Gerencianet  
Documentacao oficial: https://dev.efipay.com.br/docs/api-pix/credenciais/

## 1. Resumo executivo

A Efí Bank é uma alternativa mais tradicional e robusta que a PixGo para recebimento via Pix. Ela trabalha como conta de pagamento/banco, possui API Pix oficial, ambiente de homologacao, webhooks, SDKs, Pix imediato, Pix com vencimento, devolucao, extrato e recursos avancados como Split Pix.

Para o Painel Smile PRO, a Efí é tecnicamente viavel, mas a integracao é mais pesada que a PixGo. A PixGo usa uma chave simples `X-API-Key`; a Efí exige OAuth2 com `Client_Id` e `Client_Secret` e tambem certificado mTLS P12/PEM em todas as chamadas, inclusive na geracao do token.

Recomendacao tecnica:

1. Se a prioridade for simplicidade e cobranca Pix avulsa rapida, a PixGo continua mais simples.
2. Se a prioridade for operacao financeira tradicional, ambiente de teste, conta brasileira e Pix com vencimento, a Efí é uma alternativa superior.
3. Para o Smile PRO, a Efí faria mais sentido como provider adicional para Pix bancario, especialmente em cobrancas que precisam ficar validas por mais de 20 minutos.

## 2. Diferenca principal versus PixGo

| Ponto | PixGo | Efí Bank |
|---|---|---|
| Modelo financeiro | Pix convertido em DEPIX | Pix bancario/conta Efí |
| Autenticacao | `X-API-Key` | OAuth2 + certificado mTLS |
| Sandbox/homologacao | Nao identificado | Sim |
| Expiracao Pix imediato | Aproximadamente 20 minutos | Configuravel em segundos |
| Pix com vencimento | Nao identificado | Sim, `CobV` |
| Complexidade de deploy | Baixa | Media/alta por certificado |
| Melhor uso no painel | Pagamento imediato a vista | Pix imediato e Pix com vencimento |

## 3. Credenciais e ambientes

Para integrar a API Pix Efí, é necessario ter uma Conta Digital Efí e criar uma aplicacao no painel da Efí.

A aplicacao gera pares de credenciais separados para producao e homologacao:

- `Client_Id`;
- `Client_Secret`;
- certificado P12/PEM.

Rotas base:

```text
Producao:    https://pix.api.efipay.com.br
Homologacao: https://pix-h.api.efipay.com.br
```

A documentacao informa que o certificado P12/PEM é obrigatorio em todas as requisicoes da API Pix, inclusive na chamada de autorizacao.

Escopos minimos para o nosso caso:

- `cob.write`: criar/alterar cobrancas Pix imediatas;
- `cob.read`: consultar cobrancas Pix imediatas;
- `cobv.write`: criar/alterar cobrancas Pix com vencimento, se formos usar vencimento;
- `cobv.read`: consultar cobrancas Pix com vencimento;
- `webhook.write`: configurar webhook;
- `webhook.read`: consultar webhook.

Fonte: https://dev.efipay.com.br/docs/api-pix/credenciais/

## 4. Autenticacao

Fluxo:

1. O backend chama `POST /oauth/token`.
2. Usa HTTP Basic Auth com `Client_Id` e `Client_Secret`.
3. Envia o certificado mTLS na conexao.
4. Recebe `access_token`, `token_type`, `expires_in` e `scope`.
5. Usa o token nas proximas chamadas com `Authorization: Bearer ...`.

Esse token deve ser cacheado no backend ate perto da expiracao. Nao deve ir para navegador, log ou banco.

## 5. Criacao de cobranca Pix imediata

Endpoint:

```http
POST /v2/cob
```

Escopo requerido:

```text
cob.write
```

Payload base:

```json
{
  "calendario": {
    "expiracao": 3600
  },
  "devedor": {
    "cpf": "12345678909",
    "nome": "Francisco da Silva"
  },
  "valor": {
    "original": "123.45"
  },
  "chave": "71cdf9ba-c695-4e3c-b010-abb521a3f1be",
  "solicitacaoPagador": "Receita do evento 123"
}
```

Resposta esperada:

- HTTP `201`;
- `txid`;
- `status`;
- `location`;
- `pixCopiaECola`, em alguns retornos/listagens;
- dados de calendario e valor.

Status principais:

| Efí | Estado local sugerido |
|---|---|
| `ATIVA` | `pendente` |
| `CONCLUIDA` | `pago` |
| `REMOVIDA_PELO_USUARIO_RECEBEDOR` | `cancelado` |
| `REMOVIDA_PELO_PSP` | `cancelado` |

Observacao importante: na Efí, a expiracao do Pix imediato é configurada no campo `calendario.expiracao`, em segundos. Exemplo: `3600` = 1 hora. Isso resolve melhor o problema da PixGo, onde o QR fica disponivel por cerca de 20 minutos.

Fonte: https://dev.efipay.com.br/docs/api-pix/cobrancas-imediatas/

## 6. Criacao de Pix com vencimento

Endpoint:

```http
PUT /v2/cobv/:txid
```

Escopo requerido:

```text
cobv.write
```

Payload base:

```json
{
  "calendario": {
    "dataDeVencimento": "2026-07-31",
    "validadeAposVencimento": 30
  },
  "devedor": {
    "logradouro": "Endereco do cliente",
    "cidade": "Sao Paulo",
    "uf": "SP",
    "cep": "01001000",
    "cpf": "12345678909",
    "nome": "Francisco da Silva"
  },
  "valor": {
    "original": "123.45"
  },
  "chave": "5f84a4c5-c5cb-4599-9f13-7eb4d419dacc",
  "solicitacaoPagador": "Receita do evento 123"
}
```

Esse modelo é relevante para o Painel Smile PRO porque permite gerar uma cobranca Pix que nao expira em poucos minutos. Para cobrancas de eventos, formaturas ou parcelas, pode ser mais adequado do que Pix imediato.

Ponto de atencao: CobV exige mais dados do devedor, incluindo endereco, cidade, UF e CEP. Precisariamos verificar se o cadastro atual do sistema ja guarda esses campos com qualidade suficiente.

Fonte: https://dev.efipay.com.br/docs/api-pix/cobrancas-com-vencimento/

## 7. Consulta de cobranca

Pix imediato:

```http
GET /v2/cob/:txid
GET /v2/cob?inicio=...&fim=...
```

Pix com vencimento:

```http
GET /v2/cobv/:txid
GET /v2/cobv?inicio=...&fim=...
```

Essas consultas servem para:

- atualizar status manualmente;
- reconciliar cobrancas pendentes;
- recuperar estado caso o webhook falhe;
- montar rotinas de auditoria financeira.

## 8. Webhooks

A Efí envia callbacks para:

```text
POST url-webhook-cadastrada/pix
```

O webhook é disparado quando ha alteracao no status de Pix associado a chave cadastrada. O corpo vem em JSON e pode conter um array `pix`.

Exemplo resumido:

```json
{
  "pix": [
    {
      "endToEndId": "E1803615022211340s08793XPJ",
      "txid": "fc9a43k6ff384ryP5f41719",
      "chave": "2c3c7441-b91e-4982-3c25-6105581e18ae",
      "valor": "0.01",
      "horario": "2020-12-21T13:40:34.000Z",
      "infoPagador": "pagando o pix"
    }
  ]
}
```

Pontos tecnicos relevantes:

- callback usa `POST`;
- a URL cadastrada recebe o sufixo `/pix`;
- a documentacao informa que esse servico usa autenticacao mTLS;
- cada callback tem timeout de 60 segundos;
- em homologacao, é possivel simular status de cobrancas Pix Cob e CobV.

Para o Smile PRO, o webhook deve localizar a receita pelo `txid`, validar valor e marcar como `pago`.

Fonte: https://dev.efipay.com.br/docs/api-pix/webhooks/

## 9. Limites

A documentacao oficial informa limite para endpoints `PUT` e `POST` da API Pix:

```text
500 requisicoes por segundo
```

Esse limite é muito acima do uso esperado no Smile PRO. O gargalo real nao deve ser limite de API, mas qualidade dos dados do pagador, configuracao de certificado e tratamento correto dos webhooks.

Fonte: https://dev.efipay.com.br/docs/api-pix/limites-de-consumo/

## 10. Variaveis Railway sugeridas

Para uma integracao futura, eu usaria estas variaveis:

```dotenv
EFI_PIX_ENABLED=false
EFI_PIX_ENV=production
EFI_PIX_CLIENT_ID=
EFI_PIX_CLIENT_SECRET=
EFI_PIX_CERT_PATH=/app/secrets/efi_pix_certificate.pem
EFI_PIX_CERT_KEY_PATH=/app/secrets/efi_pix_private_key.pem
EFI_PIX_CERT_PASSPHRASE=
EFI_PIX_PIX_KEY=
EFI_PIX_BASE_URL=https://pix.api.efipay.com.br
EFI_PIX_WEBHOOK_URL=https://painelsmilepro-production.up.railway.app/efi_pix_webhook.php
EFI_PIX_TOKEN_CACHE_SECONDS=3000
```

Para homologacao:

```dotenv
EFI_PIX_ENV=sandbox
EFI_PIX_BASE_URL=https://pix-h.api.efipay.com.br
```

Observacao: o ponto mais sensivel no Railway é o certificado. Railway variables aceitam texto, mas arquivo PEM geralmente precisa ser montado em runtime a partir de variavel segura, ou armazenado como secret/file conforme a estrategia do deploy. Nao recomendo deixar certificado dentro do repositorio.

## 11. Impacto de implementacao no Painel Smile PRO

Componentes provaveis:

1. `efi_pix_helper.php`
   - token OAuth2;
   - chamadas HTTP com certificado;
   - criacao de Cob e CobV;
   - consulta de status.

2. `efi_pix_webhook.php`
   - receber callback;
   - processar array `pix`;
   - deduplicar por `endToEndId`;
   - atualizar receita por `txid`.

3. Banco de dados
   - guardar `efi_txid`;
   - guardar `efi_location`;
   - guardar `efi_pix_copia_cola`;
   - guardar `efi_status`;
   - guardar payload de criacao/consulta;
   - criar tabela de eventos webhook.

4. Interface
   - adicionar carteira `Efí Pix` ou `Pix Efí`;
   - permitir escolher Pix imediato ou Pix com vencimento;
   - se CobV for usado, exigir endereco completo do devedor;
   - mostrar copia e cola, status e vencimento.

## 12. Riscos e pontos de atencao

- A integracao é mais complexa que PixGo por causa de mTLS.
- Precisamos confirmar se a hospedagem Railway permite usar o certificado com cURL/PHP no formato escolhido.
- CobV exige dados cadastrais melhores do que a PixGo.
- Webhook da Efí depende de configuracao por chave Pix e rota com `/pix`.
- O certificado nao pode ser versionado no Git.
- Precisamos testar primeiro em homologacao antes de producao.

## 13. Veredito

A Efí é uma boa candidata se o objetivo for um Pix mais convencional, com ambiente de teste e possibilidade de cobrancas com vencimento. Ela é mais adequada que a PixGo para links/cobrancas que precisam ficar disponiveis por horas ou dias.

Para o painel atual, eu nao substituiria a PixGo por Efí automaticamente. Eu criaria como uma nova carteira opcional:

```text
Manual
Asaas
PixGo
Efí Pix
```

Minha recomendacao pratica seria:

1. manter a PixGo para cobrancas imediatas quando fizer sentido;
2. usar Asaas para o fluxo ja validado;
3. considerar Efí para Pix bancario com vencimento, depois de validar conta, credenciais, certificado e dados de endereco dos clientes.
