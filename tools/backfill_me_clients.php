<?php
/**
 * Backfill de clientes da ME para o cadastro comercial local.
 *
 * Uso:
 *   ME_BASE_URL=... ME_API_TOKEN=... php tools/backfill_me_clients.php --from=2026-06-27 --dry-run=1
 *   ME_BASE_URL=... ME_API_TOKEN=... php tools/backfill_me_clients.php --from=2026-06-27 --dry-run=0
 *   ME_BASE_URL=... ME_API_TOKEN=... php tools/backfill_me_clients.php --from=2026-06-27 --to=2030-12-31 --sync-events=1 --dry-run=0
 */

declare(strict_types=1);

require_once __DIR__ . '/../public/conexao.php';
require_once __DIR__ . '/../public/comercial_cliente_sync_helper.php';
require_once __DIR__ . '/../public/eventos_me_helper.php';
require_once __DIR__ . '/../public/agenda_eventos_sync_helper.php';

function backfill_me_clients_bool($value, bool $default = false): bool
{
    if ($value === null || $value === '') {
        return $default;
    }
    return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'sim', 'on'], true);
}

function backfill_me_clients_is_list(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

function backfill_me_clients_data_payload($payload): array
{
    if (!is_array($payload)) {
        return [];
    }

    $current = $payload;
    for ($i = 0; $i < 4; $i++) {
        if (!isset($current['data']) || !is_array($current['data'])) {
            break;
        }

        $data = $current['data'];
        if ($data === []) {
            return [];
        }

        $current = backfill_me_clients_is_list($data) ? ($data[0] ?? []) : $data;
        if (!is_array($current)) {
            return [];
        }
    }

    if (isset($current['event']) && is_array($current['event'])) {
        $current = $current['event'];
    }

    if (isset($current['client']) && is_array($current['client'])) {
        $current = $current['client'];
    }

    if (backfill_me_clients_is_list($current)) {
        $first = $current[0] ?? [];
        return is_array($first) ? $first : [];
    }

    return $current;
}

function backfill_me_clients_pick(array $data, array $keys, string $default = ''): string
{
    if (function_exists('comercial_cliente_sync_pick')) {
        return comercial_cliente_sync_pick($data, $keys, $default);
    }
    return $default;
}

function backfill_me_clients_fetch_event(PDO $pdo, int $meEventId): array
{
    if ($meEventId <= 0 || !function_exists('eventos_me_buscar_por_id')) {
        return [];
    }

    $result = eventos_me_buscar_por_id($pdo, $meEventId);
    if (empty($result['ok']) || empty($result['event']) || !is_array($result['event'])) {
        return [];
    }

    return backfill_me_clients_data_payload($result['event']);
}

function backfill_me_clients_fetch_client(int $meClientId): array
{
    if ($meClientId <= 0 || !function_exists('eventos_me_request')) {
        return [];
    }

    $result = eventos_me_request('GET', '/api/v1/clients/' . $meClientId);
    if (empty($result['ok']) || empty($result['data']) || !is_array($result['data'])) {
        return [];
    }

    return backfill_me_clients_data_payload($result['data']);
}

function backfill_me_clients_list_payload($payload): array
{
    if (!is_array($payload)) {
        return [];
    }

    if (isset($payload['data']) && is_array($payload['data']) && backfill_me_clients_is_list($payload['data'])) {
        return $payload['data'];
    }

    if (backfill_me_clients_is_list($payload)) {
        return $payload;
    }

    return [];
}

function backfill_me_clients_normalize_client(array $client, int $meClientId): array
{
    if ($client === []) {
        return [];
    }

    $nome = backfill_me_clients_pick($client, [
        'nome',
        'nomecliente',
        'nomeCliente',
        'name',
        'razao_social',
        'razaoSocial',
        'cliente.nome',
    ]);
    $email = backfill_me_clients_pick($client, [
        'email',
        'emailcliente',
        'emailCliente',
        'mail',
        'cliente.email',
    ]);
    $telefone = backfill_me_clients_pick($client, [
        'telefone',
        'telefonecliente',
        'telefoneCliente',
        'celular',
        'whatsapp',
        'phone',
        'mobile',
        'cliente.telefone',
        'cliente.celular',
    ]);
    $cpf = backfill_me_clients_pick($client, [
        'cpf',
        'cpfcliente',
        'cpfCliente',
        'documento',
        'cliente.cpf',
    ]);
    $cnpj = backfill_me_clients_pick($client, [
        'cnpj',
        'cnpjcliente',
        'cnpjCliente',
        'cliente.cnpj',
    ]);

    return [
        'cliente' => array_merge($client, [
            'id' => $meClientId,
            'nome' => $nome,
            'email' => $email,
            'telefone' => $telefone,
            'cpf' => $cpf,
            'cnpj' => $cnpj,
        ]),
        'idcliente' => (string)$meClientId,
        'nomecliente' => $nome,
        'emailcliente' => $email,
        'telefonecliente' => $telefone,
        'cpf' => $cpf,
        'cnpj' => $cnpj,
        'rg' => backfill_me_clients_pick($client, ['rg', 'rgcliente', 'cliente.rg']),
        'cep' => backfill_me_clients_pick($client, ['cep', 'cepcliente', 'cliente.cep']),
        'logradourocliente' => backfill_me_clients_pick($client, ['logradouro', 'endereco', 'rua', 'cliente.logradouro', 'cliente.endereco']),
        'numerocliente' => backfill_me_clients_pick($client, ['numero', 'numeroendereco', 'cliente.numero']),
        'complementocliente' => backfill_me_clients_pick($client, ['complemento', 'cliente.complemento']),
        'bairrocliente' => backfill_me_clients_pick($client, ['bairro', 'cliente.bairro']),
        'cidadecliente' => backfill_me_clients_pick($client, ['cidade', 'cliente.cidade']),
        'estadocliente' => backfill_me_clients_pick($client, ['estado', 'uf', 'cliente.uf']),
    ];
}

function backfill_me_clients_payload_from_row(PDO $pdo, array $row, bool $useApi): array
{
    $payload = [];

    $webhook = json_decode((string)($row['webhook_data'] ?? ''), true);
    if (is_array($webhook)) {
        $payload = backfill_me_clients_data_payload($webhook);
    }

    $snapshot = json_decode((string)($row['me_event_snapshot'] ?? ''), true);
    if (is_array($snapshot)) {
        $payload = array_replace_recursive($payload, $snapshot);
    }

    $meEventId = (int)($row['me_event_id'] ?? 0);
    if ($useApi) {
        $eventPayload = backfill_me_clients_fetch_event($pdo, $meEventId);
        if ($eventPayload !== []) {
            $payload = array_replace_recursive($payload, $eventPayload);
        }
    }

    $meClientId = (int)backfill_me_clients_pick($payload, [
        'idcliente',
        'id_cliente',
        'cliente.id',
        'client_id',
        'clienteId',
    ], '0');

    if ($useApi && $meClientId > 0) {
        $clientPayload = backfill_me_clients_fetch_client($meClientId);
        $normalizedClient = backfill_me_clients_normalize_client($clientPayload, $meClientId);
        if ($normalizedClient !== []) {
            $payload = array_replace_recursive($payload, $normalizedClient);
        }
    }

    return array_merge($payload, [
        'id' => (string)$meEventId,
        'nomeevento' => (string)($row['nome_evento'] ?? ''),
        'dataevento' => (string)($row['data_evento'] ?? ''),
        'localevento' => (string)($row['localevento'] ?? ''),
        'telefone' => (string)($row['telefone_cliente'] ?? ''),
        'whatsapp' => (string)($row['whatsapp_cliente'] ?? ''),
    ]);
}

function backfill_me_clients_event_has_identity(array $event): bool
{
    $eventId = (int)backfill_me_clients_pick($event, ['id', 'idevento', 'id_evento'], '0');
    $eventDate = backfill_me_clients_pick($event, ['dataevento', 'data_evento', 'data']);
    $eventLocation = backfill_me_clients_pick($event, ['localevento', 'local_evento', 'local', 'nomelocal']);

    return $eventId > 0 && $eventDate !== '' && $eventLocation !== '';
}

function backfill_me_clients_fetch_future_events(PDO $pdo, string $from, string $to, int $pageLimit, int $maxPages, bool $enrichClients): array
{
    $events = [];
    $seen = [];
    $clientCache = [];
    $errors = [];
    $pageLimit = max(1, min(200, $pageLimit));
    $maxPages = max(1, min(500, $maxPages));

    for ($page = 1; $page <= $maxPages; $page++) {
        $result = eventos_me_request('GET', '/api/v1/events', [
            'start' => $from,
            'end' => $to,
            'limit' => $pageLimit,
            'page' => $page,
        ]);

        if (empty($result['ok'])) {
            $errors[] = [
                'page' => $page,
                'error' => (string)($result['error'] ?? 'Falha ao buscar eventos na ME.'),
            ];
            break;
        }

        $pageEvents = backfill_me_clients_list_payload($result['data'] ?? []);
        if ($pageEvents === []) {
            break;
        }

        $newOnPage = 0;
        foreach ($pageEvents as $event) {
            if (!is_array($event)) {
                continue;
            }

            $eventId = (int)backfill_me_clients_pick($event, ['id', 'idevento', 'id_evento'], '0');
            if ($eventId <= 0 || isset($seen[$eventId])) {
                continue;
            }
            $seen[$eventId] = true;
            $newOnPage++;

            $meClientId = (int)backfill_me_clients_pick($event, [
                'idcliente',
                'id_cliente',
                'cliente.id',
                'client_id',
                'clienteId',
            ], '0');

            if ($enrichClients && $meClientId > 0) {
                if (!array_key_exists($meClientId, $clientCache)) {
                    $clientPayload = backfill_me_clients_fetch_client($meClientId);
                    $clientCache[$meClientId] = backfill_me_clients_normalize_client($clientPayload, $meClientId);
                }

                if ($clientCache[$meClientId] !== []) {
                    $event = array_replace_recursive($event, $clientCache[$meClientId]);
                }
            }

            $events[] = $event;
        }

        if (count($pageEvents) < $pageLimit || $newOnPage === 0) {
            break;
        }
    }

    return [
        'events' => $events,
        'client_cache_count' => count($clientCache),
        'errors' => $errors,
    ];
}

function backfill_me_clients_sync_events_from_me(PDO $pdo, string $from, string $to, int $pageLimit, int $maxPages, bool $dryRun, bool $enrichClients): array
{
    $fetch = backfill_me_clients_fetch_future_events($pdo, $from, $to, $pageLimit, $maxPages, $enrichClients);
    $summary = [
        'from' => $from,
        'to' => $to,
        'fetched' => count($fetch['events']),
        'client_details_fetched' => (int)$fetch['client_cache_count'],
        'events_upserted' => 0,
        'clients_linked' => 0,
        'skipped' => 0,
        'pending' => 0,
        'errors' => count($fetch['errors']),
        'pending_events' => [],
        'error_events' => $fetch['errors'],
    ];

    foreach ($fetch['events'] as $event) {
        if (!is_array($event)) {
            continue;
        }
        if (function_exists('comercial_cliente_sync_enrich_payload_with_me_client')) {
            $event = comercial_cliente_sync_enrich_payload_with_me_client($event);
        }

        $meEventId = (int)backfill_me_clients_pick($event, ['id', 'idevento', 'id_evento'], '0');
        if (!backfill_me_clients_event_has_identity($event)) {
            $summary['skipped']++;
            $summary['pending_events'][] = [
                'me_event_id' => $meEventId,
                'nome_evento' => backfill_me_clients_pick($event, ['nomeevento', 'nome_evento', 'nome']),
                'reason' => 'Evento sem id/data/local suficientes para o espelho.',
            ];
            continue;
        }

        $clientPayload = comercial_cliente_sync_payload($event, false);
        if (trim((string)$clientPayload['nome_completo']) === '') {
            $summary['pending']++;
            $summary['pending_events'][] = [
                'me_event_id' => $meEventId,
                'data_evento' => backfill_me_clients_pick($event, ['dataevento', 'data_evento', 'data']),
                'nome_evento' => backfill_me_clients_pick($event, ['nomeevento', 'nome_evento', 'nome']),
                'reason' => 'Evento sem nome real de cliente.',
            ];
        }

        if ($dryRun) {
            $summary['events_upserted']++;
            if (trim((string)$clientPayload['nome_completo']) !== '') {
                $summary['clients_linked']++;
            }
            continue;
        }

        $sync = agenda_eventos_sync_me_payload($pdo, $event, 'api_backfill');
        if (empty($sync['ok'])) {
            $summary['errors']++;
            $summary['error_events'][] = [
                'me_event_id' => $meEventId,
                'error' => (string)($sync['error'] ?? 'Falha ao atualizar espelho.'),
            ];
            continue;
        }

        $summary['events_upserted']++;

        if (trim((string)$clientPayload['nome_completo']) === '') {
            continue;
        }

        $result = comercial_cliente_sync_upsert_from_event($pdo, $event, 'me_api_backfill', false);
        if (!empty($result['ok'])) {
            $summary['clients_linked']++;
            continue;
        }

        $summary['errors']++;
        $summary['error_events'][] = [
            'me_event_id' => $meEventId,
            'error' => (string)($result['error'] ?? 'Falha ao sincronizar cliente.'),
        ];
    }

    return $summary;
}

$options = getopt('', ['from::', 'to::', 'limit::', 'dry-run::', 'all::', 'use-api::', 'sync-events::', 'page-limit::', 'max-pages::', 'enrich-clients::']);
$from = (string)($options['from'] ?? date('Y-m-d'));
$to = (string)($options['to'] ?? date('Y-m-d', strtotime($from . ' +5 years')));
$limit = max(1, min(2000, (int)($options['limit'] ?? 1000)));
$dryRun = backfill_me_clients_bool($options['dry-run'] ?? '1', true);
$includeLinked = backfill_me_clients_bool($options['all'] ?? '0', false);
$useApi = backfill_me_clients_bool($options['use-api'] ?? '1', true);
$syncEvents = backfill_me_clients_bool($options['sync-events'] ?? '0', false);
$pageLimit = max(1, min(200, (int)($options['page-limit'] ?? 200)));
$maxPages = max(1, min(500, (int)($options['max-pages'] ?? 200)));
$enrichClients = backfill_me_clients_bool($options['enrich-clients'] ?? '1', true);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    fwrite(STDERR, "Data inválida em --from. Use YYYY-MM-DD.\n");
    exit(2);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    fwrite(STDERR, "Data inválida em --to. Use YYYY-MM-DD.\n");
    exit(2);
}

comercial_cliente_sync_ensure_schema($pdo);

$whereLinked = $includeLinked ? '' : 'AND e.cliente_cadastro_id IS NULL';
$stmt = $pdo->prepare("
    SELECT e.*, w.webhook_data, r.me_event_snapshot
    FROM logistica_eventos_espelho e
    LEFT JOIN LATERAL (
        SELECT webhook_data
        FROM me_eventos_webhook w
        WHERE w.evento_id = e.me_event_id::text
        ORDER BY w.recebido_em DESC NULLS LAST, w.id DESC
        LIMIT 1
    ) w ON TRUE
    LEFT JOIN LATERAL (
        SELECT me_event_snapshot
        FROM eventos_reunioes r
        WHERE r.me_event_id = e.me_event_id
        ORDER BY r.updated_at DESC NULLS LAST, r.id DESC
        LIMIT 1
    ) r ON TRUE
    WHERE COALESCE(e.arquivado, FALSE) = FALSE
      AND e.data_evento >= :from
      {$whereLinked}
    ORDER BY e.data_evento ASC, e.id ASC
    LIMIT {$limit}
");
$stmt->execute([':from' => $from]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$summary = [
    'ok' => true,
    'from' => $from,
    'to' => $to,
    'dry_run' => $dryRun,
    'use_api' => $useApi,
    'sync_events' => $syncEvents,
    'event_sync' => null,
    'candidates' => count($rows),
    'linked' => 0,
    'pending' => 0,
    'errors' => 0,
    'pending_events' => [],
    'error_events' => [],
];

if ($syncEvents) {
    $summary['event_sync'] = backfill_me_clients_sync_events_from_me(
        $pdo,
        $from,
        $to,
        $pageLimit,
        $maxPages,
        $dryRun,
        $enrichClients
    );
    $summary['errors'] += (int)($summary['event_sync']['errors'] ?? 0);
}

foreach ($rows as $row) {
    $payload = backfill_me_clients_payload_from_row($pdo, $row, $useApi);
    if (function_exists('comercial_cliente_sync_enrich_payload_with_me_client')) {
        $payload = comercial_cliente_sync_enrich_payload_with_me_client($payload);
    }
    $clientPayload = comercial_cliente_sync_payload($payload, false);

    if (trim((string)$clientPayload['nome_completo']) === '') {
        $summary['pending']++;
        $summary['pending_events'][] = [
            'me_event_id' => (int)($row['me_event_id'] ?? 0),
            'data_evento' => (string)($row['data_evento'] ?? ''),
            'nome_evento' => (string)($row['nome_evento'] ?? ''),
            'reason' => 'Sem nome real de cliente no snapshot/webhook/API.',
        ];
        continue;
    }

    if ($dryRun) {
        $summary['linked']++;
        continue;
    }

    $result = comercial_cliente_sync_upsert_from_event($pdo, $payload, 'me_backfill_future', false);
    if (!empty($result['ok'])) {
        $summary['linked']++;
        continue;
    }

    $summary['errors']++;
    $summary['error_events'][] = [
        'me_event_id' => (int)($row['me_event_id'] ?? 0),
        'data_evento' => (string)($row['data_evento'] ?? ''),
        'nome_evento' => (string)($row['nome_evento'] ?? ''),
        'error' => (string)($result['error'] ?? 'Falha desconhecida.'),
    ];
}

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($summary['errors'] > 0 ? 1 : 0);
