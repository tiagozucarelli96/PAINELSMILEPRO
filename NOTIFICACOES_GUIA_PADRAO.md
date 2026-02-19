# Guia Padrão de Notificações (Centralizado)

Este documento define como criar notificações no sistema sem voltar à fragmentação antiga.

## 1. Ponto único de envio

Use sempre:

- `/Users/tiagozucarelli/Desktop/PAINELSMILEPRO/public/core/notification_dispatcher.php`
- Classe: `NotificationDispatcher`

Métodos principais:

- `ensureInternalSchema()`: garante estrutura mínima da `demandas_notificacoes`.
- `dispatch(array $recipients, array $payload, array $channels): array`

## 2. Regra obrigatória

Para novas funcionalidades:

- Não inserir direto em `demandas_notificacoes`.
- Não instanciar `PushHelper`/`EmailGlobalHelper` direto no módulo (salvo exceção documentada).
- Centralizar no `NotificationDispatcher`.

## 3. Contrato do dispatcher

### 3.1 Destinatários (`$recipients`)

Aceita:

- `[1, 2, 3]`
- `[['id' => 1], ['id' => 2, 'email' => 'x@dominio.com']]`

### 3.2 Payload (`$payload`)

Campos usados:

- `tipo` (string): identificador técnico (`agenda_evento_criado`, `eventos_cliente_enviou_dj`, etc.)
- `titulo` (string): título da notificação
- `mensagem` (string): texto principal
- `url_destino` (string): link de navegação no painel
- `referencia_id` (int|null): id de entidade relacionada
- `push_titulo`, `push_mensagem`, `push_data` (opcional)
- `email_assunto`, `email_html` (opcional)

### 3.3 Canais (`$channels`)

- `internal` => `true|false`
- `push` => `true|false`
- `email` => `true|false`

Retorno:

- `total_destinatarios`
- `enviados_interno`, `enviados_push`, `enviados_email`
- `falhas_interno`, `falhas_push`, `falhas_email`
- `emails_sem_endereco`

## 4. Exemplo padrão (copiar e adaptar)

```php
require_once __DIR__ . '/core/notification_dispatcher.php';

$dispatcher = new NotificationDispatcher($pdo);
$dispatcher->ensureInternalSchema();

$resultado = $dispatcher->dispatch(
    [['id' => $usuarioId]],
    [
        'tipo' => 'meu_modulo_nova_acao',
        'referencia_id' => $entidadeId,
        'titulo' => 'Título da notificação',
        'mensagem' => 'Mensagem da notificação',
        'url_destino' => 'index.php?page=minha_pagina',
    ],
    [
        'internal' => true,
        'push' => true,
        'email' => false,
    ]
);
```

## 5. Fluxo central atual (estado do projeto)

Já estão usando o dispatcher:

- `/Users/tiagozucarelli/Desktop/PAINELSMILEPRO/public/administrativo_notificacoes.php`
- `/Users/tiagozucarelli/Desktop/PAINELSMILEPRO/public/demandas_trello_api.php`
- `/Users/tiagozucarelli/Desktop/PAINELSMILEPRO/public/vendas_kanban_api.php`
- `/Users/tiagozucarelli/Desktop/PAINELSMILEPRO/public/eventos_notificacoes.php`
- `/Users/tiagozucarelli/Desktop/PAINELSMILEPRO/public/agenda_helper.php`

Compatibilidade legada:

- `/Users/tiagozucarelli/Desktop/PAINELSMILEPRO/public/core/notificacoes_helper.php`
  - mantém fila antiga quando existe
  - faz fallback para envio imediato via dispatcher quando tabelas antigas não existem

## 6. Push: requisito de link

O service worker usa `notification.data.url` como destino.

Arquivo:

- `/Users/tiagozucarelli/Desktop/PAINELSMILEPRO/public/service-worker.js`

Ao enviar push, sempre informar URL de destino coerente.

## 7. Cron de notificações

Arquivo principal:

- `/Users/tiagozucarelli/Desktop/PAINELSMILEPRO/public/cron.php?tipo=notificacoes`

Retorno padronizado:

- `success: true` (execução do cron)
- `enviado: true|false` (se houve envio)

## 8. Checklist antes de publicar nova notificação

1. Usa `NotificationDispatcher`?
2. Define `tipo` técnico estável?
3. Define `url_destino` válido?
4. Evita SQL direto em `demandas_notificacoes`?
5. Testou `php -l` no arquivo alterado?
6. Testou dispatch real com ao menos 1 usuário ativo?
7. Confirmou que não gerou erro de push/email quando ambiente não tem configuração?

## 9. Anti-padrões proibidos

- Criar novo helper de notificação paralelo ao dispatcher.
- Duplicar lógica de insert/push/email em páginas diferentes.
- Acoplar notificação a schema específico sem fallback.

