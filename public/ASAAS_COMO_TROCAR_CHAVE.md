# Como trocar a chave de API do Asaas (degustação / pagamento PIX)

Quando o Asaas exclui a chave por falta de uso (ou por segurança), siga estes passos para usar uma nova chave.

## 1. Gerar nova chave no Asaas

1. Acesse o **painel Asaas** (conta que recebe os pagamentos da degustação).
2. Vá em **Integrações** → **Chaves de API** (ou **API**).
3. Crie uma **nova chave de API** (produção, se for produção).
4. Copie a chave **inteira**, incluindo o prefixo `$aact_prod_...` (ou `$aact_hmlg_...` para sandbox). Não adicione espaços no início ou no fim.

## 2. Configurar no Railway

1. Abra o projeto no **Railway**.
2. Vá em **Variables** (variáveis de ambiente).
3. Adicione ou edite:
   - **Nome:** `ASAAS_API_KEY`
   - **Valor:** a nova chave colada (ex.: `$aact_prod_xxxxxxxx...`).
4. Salve e faça **Redeploy** do serviço para a nova variável ser aplicada.

## 3. Chave PIX (QR Code estático) – opcional

O pagamento PIX na degustação usa também uma **chave PIX** (chave aleatória da conta Asaas), que é diferente da API Key:

- **API Key** → autentica o sistema no Asaas (é essa que você está trocando).
- **Chave PIX (address key)** → é a chave que **recebe** o PIX (ex.: UUID no Asaas).

Se o QR Code PIX parar de funcionar ou der erro de chave:

1. No Asaas: **Minha conta** / **Chaves PIX** (ou equivalente).
2. Copie a **chave aleatória** (formato UUID).
3. No Railway, defina a variável:
   - **Nome:** `ASAAS_PIX_ADDRESS_KEY`
   - **Valor:** o UUID da chave PIX.

Depois faça **Redeploy**.

## 4. Onde o sistema usa a chave

- **AsaasHelper** (`asaas_helper.php`): lê `ASAAS_API_KEY` (e opcionalmente `ASAAS_BASE_URL`, `WEBHOOK_URL`).
- **config_env.php**: define os padrões; as variáveis de ambiente do Railway têm prioridade.
- **Degustação (pagamento sem contrato)**: criação de QR Code PIX e consulta de status usam a API Key; o QR em si usa ainda a `ASAAS_PIX_ADDRESS_KEY` se estiver definida.

Se algo falhar após trocar a chave, confira os logs do Railway (ou `error_log` do PHP) para mensagens como `ASAAS_API_KEY não configurada` ou erros 401 do Asaas.
