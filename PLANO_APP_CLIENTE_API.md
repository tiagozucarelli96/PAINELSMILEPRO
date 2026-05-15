# Plano App do Cliente + API

## Objetivo

Criar um app para `iOS` e `Android` para o cliente acessar o portal do evento já existente, com um novo serviço de `API` publicado separadamente na Railway.

O app deve reproduzir, como primeira versão, o que hoje já existe no portal:

- `Resumo do Evento`
- `Reunião Final`
- `Convidados`
- `Arquivos`

Base atual identificada no projeto:

- portal principal: [`public/eventos_cliente_portal.php`](/Users/tiagozucarelli/Desktop/PAINELSMILEPRO/public/eventos_cliente_portal.php:1)
- convidados: [`public/eventos_cliente_convidados.php`](/Users/tiagozucarelli/Desktop/PAINELSMILEPRO/public/eventos_cliente_convidados.php:1)
- arquivos: [`public/eventos_cliente_arquivos.php`](/Users/tiagozucarelli/Desktop/PAINELSMILEPRO/public/eventos_cliente_arquivos.php:1)
- reunião final: [`public/eventos_cliente_reuniao.php`](/Users/tiagozucarelli/Desktop/PAINELSMILEPRO/public/eventos_cliente_reuniao.php:1)
- dados do evento vindos de `me_event_snapshot` em [`sql/050_eventos_modulo.sql`](/Users/tiagozucarelli/Desktop/PAINELSMILEPRO/sql/050_eventos_modulo.sql:25)

## Arquitetura recomendada

### Serviços

`Serviço 1 - Painel atual`

- continua hospedando o painel interno
- continua com as telas web existentes
- continua sendo a origem operacional para equipe interna

`Serviço 2 - API do app`

- novo serviço na Railway
- expõe autenticação do cliente
- expõe os dados do evento em JSON
- concentra regras de sessão, rate limit e auditoria do app

`App mobile`

- base web mobile-first
- empacotado com `Capacitor`
- entrega `Android` e `iOS` com a mesma base

### Recomendação prática de domínio

- `painelpro.smileeventos.com.br` -> painel atual
- `api.smileeventos.com.br` -> API do app

## Por que separar a API

- evita misturar HTML do painel com respostas JSON
- deixa o login do cliente isolado do sistema interno
- facilita logs e bloqueios de tentativa
- simplifica a evolução do app depois
- prepara a base para push notifications

## Login do cliente

### Fluxo aprovado

O cliente entra com:

- `CPF`
- `data do evento`
- `local do evento`

Se os dados baterem, a API cria a sessão do cliente e entrega o contexto do evento.

### Regras mínimas

- normalizar CPF para apenas números
- comparar data em formato `YYYY-MM-DD`
- comparar local por `id` sempre que possível
- se hoje o local estiver apenas em texto no snapshot, criar uma normalização de nome
- não informar ao usuário qual campo errou
- aplicar rate limit por IP e por CPF
- bloquear por alguns minutos após excesso de tentativas
- expirar sessão automaticamente

### Observação importante

Hoje o portal público usa `token` na URL. Para o app, o ideal é trocar o acesso principal por sessão autenticada. O token pode continuar existindo como legado web, mas não deve ser a base do app.

## Fonte dos dados

O portal atual já usa os dados principais do evento a partir do `me_event_snapshot`, inclusive:

- `nome`
- `data`
- `local`
- dados do cliente

Isso aparece em [`public/eventos_cliente_portal.php`](/Users/tiagozucarelli/Desktop/PAINELSMILEPRO/public/eventos_cliente_portal.php:98), onde o portal carrega a reunião, lê o `snapshot` e monta os cards do cliente.

## Modelo técnico sugerido para autenticação

### Nova tabela

Criar uma tabela dedicada para sessão do app, por exemplo:

`cliente_app_sessoes`

Campos sugeridos:

- `id`
- `meeting_id`
- `cpf_hash`
- `access_token_hash`
- `device_name`
- `platform`
- `app_version`
- `ip`
- `last_seen_at`
- `expires_at`
- `revoked_at`
- `created_at`

### Tabela de auditoria

`cliente_app_login_tentativas`

Campos sugeridos:

- `id`
- `cpf_digitado`
- `data_evento_digitada`
- `local_digitado`
- `meeting_id_encontrado`
- `sucesso`
- `motivo`
- `ip`
- `user_agent`
- `created_at`

## Estratégia de vínculo do login ao evento

A autenticação precisa achar exatamente um evento elegível para aquele cliente.

Ordem sugerida:

1. localizar reuniões/eventos com `CPF` compatível
2. filtrar por `data do evento`
3. filtrar por `local do evento`
4. se sobrar um único evento, autentica
5. se houver mais de um, retornar uma etapa extra de escolha do evento

## Pré-requisito de dados

Antes de implementar o login, validar onde o `CPF do cliente` está salvo para os eventos já contratados.

Sinais encontrados no projeto:

- há referência a `me_cliente_cpf` em [`sql/add_me_contrato_columns.sql`](/Users/tiagozucarelli/Desktop/PAINELSMILEPRO/sql/add_me_contrato_columns.sql:39)
- há referência a `cpf_3_digitos` e validações comerciais já existentes

Decisão recomendada:

- padronizar um campo confiável de CPF completo por evento/cliente
- se não existir de forma consistente hoje, criar rotina de saneamento antes do login do app

## Endpoints da API - V1

### Autenticação

`POST /v1/auth/login`

Request:

```json
{
  "cpf": "00000000000",
  "event_date": "2026-09-18",
  "event_location_id": "espaco-x"
}
```

Response:

```json
{
  "token": "jwt-ou-token-opaco",
  "expires_at": "2026-09-18T22:00:00Z",
  "event": {
    "id": 123,
    "name": "Casamento Ana e Bruno",
    "date": "2026-09-18",
    "location": "Espaço X"
  }
}
```

`POST /v1/auth/logout`

`GET /v1/auth/me`

### Portal do cliente

`GET /v1/client/event/summary`

- resumo do evento
- nome
- data
- horário
- local
- cliente
- cards habilitados

`GET /v1/client/event/final-meeting`

- dados visíveis da reunião final
- seções liberadas no portal

`GET /v1/client/guests`

- lista e resumo

`POST /v1/client/guests`

- criar convidado

`PUT /v1/client/guests/{id}`

- editar convidado

`DELETE /v1/client/guests/{id}`

- remover convidado

`GET /v1/client/files`

- listar arquivos disponíveis para o cliente

`POST /v1/client/files`

- upload de arquivo do cliente

`GET /v1/client/files/{id}/download`

- gerar download controlado

## Endpoints complementares

`GET /v1/client/locations`

- retorna lista de locais válidos para o login
- útil para alimentar o `select`

`GET /v1/health`

- healthcheck da Railway

## Formato do app

### Opção recomendada

Criar um frontend mobile em web e empacotar com `Capacitor`.

Isso permite:

- publicar em `Android`
- publicar em `iOS`
- reaproveitar mais código
- ligar notificações push depois

### Estrutura sugerida

`repo atual`

- `public/` continua com painel e páginas legadas
- `api/` novo módulo da API
- `mobile-app/` nova interface mobile-first

Publicação sugerida:

- Railway serviço atual apontando para o painel
- Railway novo serviço apontando para a API
- app mobile consumindo `api.smileeventos.com.br`

## Push notifications

O caminho preparado para depois é:

- `Android` via `Firebase Cloud Messaging`
- `iOS` via `APNs`
- app registra o device token
- API salva o token do aparelho
- backend envia notificações por evento

Tabela futura sugerida:

`cliente_app_devices`

Campos:

- `id`
- `meeting_id`
- `session_id`
- `platform`
- `push_token`
- `device_name`
- `last_seen_at`
- `created_at`
- `revoked_at`

## Fases de implementação

### Fase 1 - API base

- criar estrutura `api/`
- criar bootstrap JSON
- criar autenticação `CPF + data + local`
- criar sessão do cliente
- criar endpoint `auth/me`
- criar `event/summary`

### Fase 2 - Conteúdo do portal

- expor `reunião final`
- expor `convidados`
- expor `arquivos`
- controlar permissões existentes do portal

### Fase 3 - Interface mobile

- criar frontend mobile-first
- login
- home do evento
- telas de convidados, arquivos e reunião
- preparar para uso dentro do Capacitor

### Fase 4 - Publicação mobile

- inicializar projeto `Capacitor`
- gerar `android/`
- gerar `ios/`
- configurar ícones, splash e permissões
- validar build e publicação

### Fase 5 - Push

- registrar devices
- integrar FCM/APNs
- criar primeiros eventos de notificação

## Ordem recomendada no projeto

1. mapear de forma confiável onde está o `CPF` do cliente para cada evento
2. criar a `API V1`
3. testar o login com eventos reais
4. expor os módulos já existentes do portal
5. criar a interface mobile
6. empacotar com `Capacitor`
7. publicar Android primeiro
8. publicar iOS em seguida

## Riscos conhecidos

- CPF pode não estar padronizado para todos os eventos já cadastrados
- local do evento pode estar salvo apenas como texto livre
- alguns módulos do portal atual podem misturar regra de tela com regra de negócio
- downloads de arquivos exigirão cuidado com autenticação e URL temporária

## Decisões recomendadas agora

- manter o painel atual como está
- criar `novo serviço Railway` para a API
- criar `API REST em PHP`
- usar `Capacitor` para o app
- manter a primeira versão limitada ao que já existe no portal atual

## Próximo passo técnico

Executar um levantamento objetivo de dados para responder:

- em qual tabela está o CPF final confiável do cliente por evento
- como identificar o `local do evento` de forma estável para o `select`
- qual é a melhor chave de vínculo entre evento ME, reunião e portal do cliente

Depois disso, a implementação da `API V1` pode começar com baixo risco.
