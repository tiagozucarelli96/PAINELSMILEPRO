<?php
/**
 * Sincroniza eventos futuros da ME para o espelho local e vincula clientes em lote.
 *
 * Uso:
 *   ME_BASE_URL=... ME_API_TOKEN=... php tools/sync_me_future_events_bulk.php --from=2026-06-27 --to=2031-06-27 --dry-run=1
 *   ME_BASE_URL=... ME_API_TOKEN=... php tools/sync_me_future_events_bulk.php --from=2026-06-27 --to=2031-06-27 --dry-run=0
 */

declare(strict_types=1);

require_once __DIR__ . '/../public/conexao.php';
require_once __DIR__ . '/../public/eventos_me_helper.php';
require_once __DIR__ . '/../public/agenda_eventos_sync_helper.php';
require_once __DIR__ . '/../public/comercial_cliente_sync_helper.php';

function sync_me_bulk_bool($value, bool $default = false): bool
{
    if ($value === null || $value === '') {
        return $default;
    }
    return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'sim', 'on'], true);
}

function sync_me_bulk_is_list(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

function sync_me_bulk_list_payload($payload): array
{
    if (!is_array($payload)) {
        return [];
    }
    if (isset($payload['data']) && is_array($payload['data']) && sync_me_bulk_is_list($payload['data'])) {
        return $payload['data'];
    }
    return sync_me_bulk_is_list($payload) ? $payload : [];
}

function sync_me_bulk_pick(array $data, array $keys, string $default = ''): string
{
    if (function_exists('comercial_cliente_sync_pick')) {
        return comercial_cliente_sync_pick($data, $keys, $default);
    }
    foreach ($keys as $key) {
        if (isset($data[$key]) && trim((string)$data[$key]) !== '') {
            return trim((string)$data[$key]);
        }
    }
    return $default;
}

function sync_me_bulk_fetch_events(string $from, string $to, int $pageLimit, int $maxPages): array
{
    $events = [];
    $seen = [];
    $errors = [];

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

        $pageEvents = sync_me_bulk_list_payload($result['data'] ?? []);
        if ($pageEvents === []) {
            break;
        }

        $newOnPage = 0;
        foreach ($pageEvents as $event) {
            if (!is_array($event)) {
                continue;
            }
            $eventId = (int)sync_me_bulk_pick($event, ['id', 'idevento', 'id_evento'], '0');
            if ($eventId <= 0 || isset($seen[$eventId])) {
                continue;
            }
            $seen[$eventId] = true;
            $events[] = $event;
            $newOnPage++;
        }

        if (count($pageEvents) < $pageLimit || $newOnPage === 0) {
            break;
        }
    }

    return ['events' => $events, 'errors' => $errors];
}

function sync_me_bulk_load_mappings(PDO $pdo): array
{
    $byId = [];
    $byName = [];
    if (!agenda_eventos_sync_table_exists($pdo, 'logistica_me_locais')) {
        return ['by_id' => $byId, 'by_name' => $byName];
    }

    $rows = $pdo->query("SELECT me_local_id, me_local_nome, unidade_interna_id, space_visivel FROM logistica_me_locais")
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $row) {
        $id = (int)($row['me_local_id'] ?? 0);
        if ($id > 0) {
            $byId[$id] = $row;
        }
        $name = strtolower(trim((string)($row['me_local_nome'] ?? '')));
        if ($name !== '') {
            $byName[$name] = $row;
        }
    }

    return ['by_id' => $byId, 'by_name' => $byName];
}

function sync_me_bulk_event_row(array $event, array $mappings): ?array
{
    $meEventId = (int)sync_me_bulk_pick($event, ['id', 'idevento', 'id_evento'], '0');
    $dataEvento = agenda_eventos_sync_date(sync_me_bulk_pick($event, ['dataevento', 'data_evento', 'data']));
    $localEvento = sync_me_bulk_pick($event, ['localevento', 'local_evento', 'local', 'nomelocal']);
    if ($meEventId <= 0 || $dataEvento === null || $localEvento === '') {
        return null;
    }

    $idLocalEvento = (int)sync_me_bulk_pick($event, ['idlocalevento', 'id_local_evento', 'local_id'], '0');
    $mapping = $mappings['by_id'][$idLocalEvento] ?? $mappings['by_name'][strtolower(trim($localEvento))] ?? null;

    $nomeEvento = sync_me_bulk_pick($event, ['nomeevento', 'nome_evento', 'nome', 'titulo']);
    if ($nomeEvento === '') {
        $nomeCliente = sync_me_bulk_pick($event, ['nomeCliente', 'nomecliente', 'client_name']);
        $tipoEvento = sync_me_bulk_pick($event, ['tipoEvento', 'tipoevento', 'tipo']);
        $nomeEvento = trim($nomeCliente . ($tipoEvento !== '' ? ' - ' . $tipoEvento : ''));
    }
    if ($nomeEvento === '') {
        $nomeEvento = 'Evento';
    }

    $telefoneCliente = sync_me_bulk_pick($event, [
        'celular',
        'telefone',
        'telefone2',
        'whatsapp',
        'client_phone',
        'client_whatsapp',
        'phone',
        'mobile',
    ]);
    $ddiCliente = sync_me_bulk_pick($event, ['ddicelular', 'dditelefone', 'ddi', 'client_phone_ddi']);
    $whatsappCliente = trim($ddiCliente . ($ddiCliente !== '' && $telefoneCliente !== '' ? ' ' : '') . $telefoneCliente);

    return [
        'me_event_id' => $meEventId,
        'data_evento' => $dataEvento,
        'hora_inicio' => agenda_eventos_sync_time(sync_me_bulk_pick($event, ['horaevento', 'hora_inicio', 'horainicio', 'hora'])),
        'hora_fim' => agenda_eventos_sync_time(sync_me_bulk_pick($event, ['horatermino', 'hora_fim', 'horafim', 'hora_termino', 'horaeventofim', 'fim'])),
        'convidados' => (int)sync_me_bulk_pick($event, ['convidados', 'nconvidados', 'num_convidados'], '0'),
        'idlocalevento' => $idLocalEvento,
        'localevento' => $localEvento,
        'nome_evento' => $nomeEvento,
        'unidade_interna_id' => $mapping['unidade_interna_id'] ?? null,
        'space_visivel' => $mapping['space_visivel'] ?? null,
        'status_mapeamento' => $mapping ? 'MAPEADO' : 'PENDENTE',
        'whatsapp_cliente' => $whatsappCliente,
        'telefone_cliente' => $telefoneCliente !== '' ? $telefoneCliente : $whatsappCliente,
    ];
}

function sync_me_bulk_upsert_events(PDO $pdo, array $rows): int
{
    if ($rows === []) {
        return 0;
    }

    $pdo->exec("ALTER TABLE logistica_eventos_espelho ADD COLUMN IF NOT EXISTS hora_fim TIME");

    $columns = [
        'me_event_id',
        'data_evento',
        'hora_inicio',
        'hora_fim',
        'convidados',
        'idlocalevento',
        'localevento',
        'nome_evento',
        'unidade_interna_id',
        'space_visivel',
        'status_mapeamento',
        'whatsapp_cliente',
        'telefone_cliente',
    ];
    $values = [];
    $params = [];
    foreach ($rows as $i => $row) {
        $placeholders = [];
        foreach ($columns as $column) {
            $key = ':' . $column . '_' . $i;
            $placeholders[] = $key;
            $params[$key] = $row[$column] ?? null;
        }
        $values[] = '(' . implode(', ', $placeholders) . ')';
    }

    $sql = "
        INSERT INTO logistica_eventos_espelho
        (" . implode(', ', $columns) . ", arquivado, synced_at, updated_at)
        VALUES " . implode(",\n", array_map(static fn($value) => substr($value, 0, -1) . ', FALSE, NOW(), NOW())', $values)) . "
        ON CONFLICT (me_event_id) DO UPDATE SET
            data_evento = EXCLUDED.data_evento,
            hora_inicio = EXCLUDED.hora_inicio,
            hora_fim = EXCLUDED.hora_fim,
            convidados = EXCLUDED.convidados,
            idlocalevento = EXCLUDED.idlocalevento,
            localevento = EXCLUDED.localevento,
            nome_evento = EXCLUDED.nome_evento,
            unidade_interna_id = EXCLUDED.unidade_interna_id,
            space_visivel = EXCLUDED.space_visivel,
            status_mapeamento = EXCLUDED.status_mapeamento,
            whatsapp_cliente = EXCLUDED.whatsapp_cliente,
            telefone_cliente = EXCLUDED.telefone_cliente,
            arquivado = FALSE,
            synced_at = NOW(),
            updated_at = NOW()
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return count($rows);
}

function sync_me_bulk_archive_events(PDO $pdo, array $eventIds): int
{
    $eventIds = array_values(array_unique(array_filter(array_map('intval', $eventIds))));
    if ($eventIds === []) {
        return 0;
    }

    $placeholders = implode(', ', array_fill(0, count($eventIds), '?'));
    $stmt = $pdo->prepare("
        UPDATE logistica_eventos_espelho
        SET arquivado = TRUE,
            updated_at = NOW()
        WHERE me_event_id IN ({$placeholders})
    ");
    $stmt->execute($eventIds);
    return $stmt->rowCount();
}

function sync_me_bulk_client_rows(array $events): array
{
    $rows = [];
    $eventClient = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        if (function_exists('comercial_cliente_sync_enrich_payload_with_me_client')) {
            $event = comercial_cliente_sync_enrich_payload_with_me_client($event);
        }
        $payload = comercial_cliente_sync_payload($event, false);
        $meClientId = (int)$payload['me_cliente_id'];
        $meEventId = (int)$payload['me_event_id'];
        if ($meClientId <= 0 || $meEventId <= 0 || trim((string)$payload['nome_completo']) === '') {
            continue;
        }

        $eventClient[$meEventId] = $meClientId;
        $rows[$meClientId] = $payload;
    }

    return ['clients' => $rows, 'event_client' => $eventClient];
}

function sync_me_bulk_upsert_clients(PDO $pdo, array $clientRows): array
{
    if ($clientRows === []) {
        return [];
    }

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_comercial_cadastro_clientes_me_cliente_id ON comercial_cadastro_clientes(me_cliente_id) WHERE me_cliente_id IS NOT NULL");

    $columns = [
        'tipo_pessoa',
        'nome_completo',
        'email',
        'telefone_whatsapp',
        'documento_tipo',
        'documento_numero',
        'rg',
        'cep',
        'endereco_logradouro',
        'endereco_numero',
        'endereco_complemento',
        'endereco_bairro',
        'endereco_cidade',
        'endereco_estado',
        'origem_cliente',
        'tipo_interesse',
        'me_cliente_id',
        'ultimo_me_event_id',
        'origem_importacao',
    ];

    $values = [];
    $params = [];
    $i = 0;
    foreach ($clientRows as $payload) {
        $row = [
            'tipo_pessoa' => (string)$payload['tipo_pessoa'],
            'nome_completo' => (string)$payload['nome_completo'],
            'email' => (string)$payload['email'],
            'telefone_whatsapp' => (string)$payload['telefone_whatsapp'],
            'documento_tipo' => (string)$payload['documento_tipo'],
            'documento_numero' => (string)$payload['documento_numero'],
            'rg' => (string)$payload['rg'] !== '' ? (string)$payload['rg'] : null,
            'cep' => (string)$payload['cep'] !== '' ? (string)$payload['cep'] : null,
            'endereco_logradouro' => (string)$payload['endereco_logradouro'] !== '' ? (string)$payload['endereco_logradouro'] : null,
            'endereco_numero' => (string)$payload['endereco_numero'] !== '' ? (string)$payload['endereco_numero'] : null,
            'endereco_complemento' => (string)$payload['endereco_complemento'] !== '' ? (string)$payload['endereco_complemento'] : null,
            'endereco_bairro' => (string)$payload['endereco_bairro'] !== '' ? (string)$payload['endereco_bairro'] : null,
            'endereco_cidade' => (string)$payload['endereco_cidade'] !== '' ? (string)$payload['endereco_cidade'] : null,
            'endereco_estado' => (string)$payload['endereco_estado'] !== '' ? (string)$payload['endereco_estado'] : null,
            'origem_cliente' => 'ME Eventos',
            'tipo_interesse' => (string)$payload['tipo_interesse'] !== '' ? (string)$payload['tipo_interesse'] : null,
            'me_cliente_id' => (int)$payload['me_cliente_id'],
            'ultimo_me_event_id' => (int)$payload['me_event_id'],
            'origem_importacao' => 'me_api_bulk',
        ];

        $placeholders = [];
        foreach ($columns as $column) {
            $key = ':' . $column . '_' . $i;
            $placeholders[] = $key;
            $params[$key] = $row[$column];
        }
        $values[] = '(' . implode(', ', $placeholders) . ', NOW(), TRUE, NOW(), NOW())';
        $i++;
    }

    $sql = "
        INSERT INTO comercial_cadastro_clientes
        (" . implode(', ', $columns) . ", imported_at, ativo, created_at, updated_at)
        VALUES " . implode(",\n", $values) . "
        ON CONFLICT (me_cliente_id) WHERE me_cliente_id IS NOT NULL DO UPDATE SET
            tipo_pessoa = COALESCE(NULLIF(EXCLUDED.tipo_pessoa, ''), comercial_cadastro_clientes.tipo_pessoa),
            nome_completo = COALESCE(NULLIF(EXCLUDED.nome_completo, ''), comercial_cadastro_clientes.nome_completo),
            email = COALESCE(NULLIF(EXCLUDED.email, ''), comercial_cadastro_clientes.email),
            telefone_whatsapp = COALESCE(NULLIF(EXCLUDED.telefone_whatsapp, ''), comercial_cadastro_clientes.telefone_whatsapp),
            documento_tipo = COALESCE(NULLIF(EXCLUDED.documento_tipo, ''), comercial_cadastro_clientes.documento_tipo),
            documento_numero = COALESCE(NULLIF(EXCLUDED.documento_numero, ''), comercial_cadastro_clientes.documento_numero),
            rg = COALESCE(NULLIF(EXCLUDED.rg, ''), comercial_cadastro_clientes.rg),
            cep = COALESCE(NULLIF(EXCLUDED.cep, ''), comercial_cadastro_clientes.cep),
            endereco_logradouro = COALESCE(NULLIF(EXCLUDED.endereco_logradouro, ''), comercial_cadastro_clientes.endereco_logradouro),
            endereco_numero = COALESCE(NULLIF(EXCLUDED.endereco_numero, ''), comercial_cadastro_clientes.endereco_numero),
            endereco_complemento = COALESCE(NULLIF(EXCLUDED.endereco_complemento, ''), comercial_cadastro_clientes.endereco_complemento),
            endereco_bairro = COALESCE(NULLIF(EXCLUDED.endereco_bairro, ''), comercial_cadastro_clientes.endereco_bairro),
            endereco_cidade = COALESCE(NULLIF(EXCLUDED.endereco_cidade, ''), comercial_cadastro_clientes.endereco_cidade),
            endereco_estado = COALESCE(NULLIF(EXCLUDED.endereco_estado, ''), comercial_cadastro_clientes.endereco_estado),
            origem_cliente = EXCLUDED.origem_cliente,
            tipo_interesse = COALESCE(NULLIF(EXCLUDED.tipo_interesse, ''), comercial_cadastro_clientes.tipo_interesse),
            ultimo_me_event_id = EXCLUDED.ultimo_me_event_id,
            origem_importacao = EXCLUDED.origem_importacao,
            imported_at = NOW(),
            updated_at = NOW()
        RETURNING id, me_cliente_id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $map[(int)$row['me_cliente_id']] = (int)$row['id'];
    }

    return $map;
}

function sync_me_bulk_link_events(PDO $pdo, array $eventClient, array $clientMap): int
{
    $pairs = [];
    foreach ($eventClient as $meEventId => $meClientId) {
        $clientId = $clientMap[(int)$meClientId] ?? 0;
        if ($clientId > 0) {
            $pairs[(int)$meEventId] = $clientId;
        }
    }
    if ($pairs === []) {
        return 0;
    }

    $values = [];
    $params = [];
    $i = 0;
    foreach ($pairs as $meEventId => $clientId) {
        $eventKey = ':event_' . $i;
        $clientKey = ':client_' . $i;
        $values[] = '(' . $eventKey . '::bigint, ' . $clientKey . '::bigint)';
        $params[$eventKey] = $meEventId;
        $params[$clientKey] = $clientId;
        $i++;
    }

    $sql = "
        UPDATE logistica_eventos_espelho e
        SET cliente_cadastro_id = v.cliente_id,
            updated_at = NOW()
        FROM (VALUES " . implode(",\n", $values) . ") AS v(me_event_id, cliente_id)
        WHERE e.me_event_id = v.me_event_id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return count($pairs);
}

$options = getopt('', ['from::', 'to::', 'dry-run::', 'page-limit::', 'max-pages::']);
$from = (string)($options['from'] ?? date('Y-m-d'));
$to = (string)($options['to'] ?? date('Y-m-d', strtotime($from . ' +5 years')));
$dryRun = sync_me_bulk_bool($options['dry-run'] ?? '1', true);
$pageLimit = max(1, min(200, (int)($options['page-limit'] ?? 200)));
$maxPages = max(1, min(500, (int)($options['max-pages'] ?? 200)));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    fwrite(STDERR, "Datas inválidas. Use --from=YYYY-MM-DD --to=YYYY-MM-DD.\n");
    exit(2);
}

comercial_cliente_sync_ensure_schema($pdo);
$fetch = sync_me_bulk_fetch_events($from, $to, $pageLimit, $maxPages);
$mappings = sync_me_bulk_load_mappings($pdo);
$events = [];
foreach ($fetch['events'] as $event) {
    if (!is_array($event)) {
        continue;
    }
    if (function_exists('comercial_cliente_sync_enrich_payload_with_me_client')) {
        $event = comercial_cliente_sync_enrich_payload_with_me_client($event);
    }
    $events[] = $event;
}

$eventRows = [];
$invalidEvents = [];
$canceledEventIds = [];
foreach ($events as $event) {
    $eventId = (int)sync_me_bulk_pick($event, ['id', 'idevento', 'id_evento'], '0');
    if ($eventId > 0 && eventos_me_evento_cancelado($event)) {
        $canceledEventIds[] = $eventId;
        continue;
    }

    $row = sync_me_bulk_event_row($event, $mappings);
    if ($row === null) {
        $invalidEvents[] = [
            'me_event_id' => $eventId,
            'nome_evento' => sync_me_bulk_pick($event, ['nomeevento', 'nome_evento', 'nome']),
        ];
        continue;
    }
    $eventRows[] = $row;
}

$clientData = sync_me_bulk_client_rows($events);
$summary = [
    'ok' => true,
    'from' => $from,
    'to' => $to,
    'dry_run' => $dryRun,
    'fetched' => count($events),
    'valid_events' => count($eventRows),
    'invalid_events' => count($invalidEvents),
    'canceled_events' => count(array_unique($canceledEventIds)),
    'unique_clients' => count($clientData['clients']),
    'events_upserted' => 0,
    'events_archived' => 0,
    'clients_upserted' => 0,
    'events_linked' => 0,
    'errors' => count($fetch['errors']),
    'fetch_errors' => $fetch['errors'],
];

if (!$dryRun) {
    $pdo->beginTransaction();
    try {
        $summary['events_upserted'] = sync_me_bulk_upsert_events($pdo, $eventRows);
        $summary['events_archived'] = sync_me_bulk_archive_events($pdo, $canceledEventIds);
        $clientMap = sync_me_bulk_upsert_clients($pdo, $clientData['clients']);
        $summary['clients_upserted'] = count($clientMap);
        $summary['events_linked'] = sync_me_bulk_link_events($pdo, $clientData['event_client'], $clientMap);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $summary['ok'] = false;
        $summary['errors']++;
        $summary['error'] = $e->getMessage();
    }
} else {
    $summary['events_upserted'] = count($eventRows);
    $summary['clients_upserted'] = count($clientData['clients']);
    $summary['events_linked'] = count($clientData['event_client']);
}

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit(!empty($summary['ok']) && $summary['errors'] === 0 ? 0 : 1);
