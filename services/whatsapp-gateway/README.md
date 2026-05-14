# Smile WhatsApp Gateway

Servico separado para a fase 2 do Smile Chat.

## O que entrega agora

- API HTTP para `health`, `sessions`, `connect`, `disconnect` e ingestao manual de mensagens
- Socket.IO para broadcast de status e eventos de sessao
- Persistencia em Postgres usando as tabelas `wa_*`
- Provider `mock` funcional para validar QR/status sem depender ainda de uma release instavel do motor WhatsApp
- Adaptador `baileys` preparado como ponto de extensao

## Como rodar

```bash
cd services/whatsapp-gateway
npm install
npm start
```

O servico le `DATABASE_URL` e `DB_SCHEMA` do `.env` na raiz do repositorio.

## Variaveis opcionais

- `PORT` porta injetada pela plataforma quando houver proxy publico
- `WHATSAPP_GATEWAY_PORT` fallback local default `8787`
- `WHATSAPP_GATEWAY_HOST` default `0.0.0.0`
- `WHATSAPP_GATEWAY_DEFAULT_PROVIDER` default `mock`
- `WHATSAPP_GATEWAY_SOCKET_CORS_ORIGIN` default `*`

## Endpoints principais

- `GET /health`
- `GET /api/sessions`
- `GET /api/sessions/:sessionKey`
- `POST /api/sessions/:sessionKey/connect`
- `POST /api/sessions/:sessionKey/disconnect`
- `POST /api/sessions/:sessionKey/messages/send`
- `POST /api/events/messages/inbound`

## Proxima etapa

Plugar o provider Baileys real no `src/providers/baileys-provider.js`, reaproveitando a mesma API e a mesma persistencia.
