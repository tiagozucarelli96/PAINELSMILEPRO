<?php
require_once __DIR__ . '/logistica_tz.php';
// logistica_conexao.php — Conexão Logística (mapeamento ME + sync)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/me_config.php';

$errors = [];
$messages = [];
$sync_summary = null;
$novos_locais = [];
$db_test_result = null;
$db_summary = null;

function ensure_logistica_schema(PDO $pdo, array &$errors, array &$messages): bool {
    try {
        $required = ['logistica_unidades', 'logistica_me_locais', 'logistica_eventos_espelho'];
        $placeholders = implode(',', array_fill(0, count($required), '?'));
        $stmt = $pdo->prepare("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = 'public'
            AND table_name IN ($placeholders)
        ");
        $stmt->execute($required);
        $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $found = array_flip($found);

        $missing = [];
        foreach ($required as $table) {
            if (!isset($found[$table])) {
                $missing[] = $table;
            }
        }

        if (!$missing) {
            return true;
        }

        $sql_path = __DIR__ . '/../sql/023_logistica_base.sql';
        if (is_file($sql_path)) {
            $sql = file_get_contents($sql_path);
            $pdo->exec($sql);
            $messages[] = 'Base Logística criada automaticamente (tabelas faltantes).';
            return true;
        }

        $errors[] = 'Tabelas da Logística não encontradas (' . implode(', ', $missing) . '). Execute o SQL 023.';
        return false;
    } catch (Throwable $e) {
        $errors[] = 'Falha ao validar/criar base Logística: ' . $e->getMessage();
        return false;
    }
}

function me_request(string $path, array $params = []): array {
    $base = getenv('ME_BASE_URL') ?: (defined('ME_BASE_URL') ? ME_BASE_URL : '');
    $key  = getenv('ME_API_TOKEN') ?: getenv('ME_API_KEY') ?: (defined('ME_API_KEY') ? ME_API_KEY : '');

    if (!$base || !$key) {
        return ['ok' => false, 'error' => 'ME_BASE_URL/ME_API_KEY ausentes.'];
    }

    $url = rtrim($base, '/') . $path;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $key,
            'Content-Type: application/json',
            'Accept: application/json'
        ]
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return ['ok' => false, 'error' => 'Erro cURL: ' . $err];
    }
    if ($code < 200 || $code >= 300) {
        $tokenStatus = $key ? 'presente' : 'ausente';
        $baseInfo = $base ? $base : 'base não definida';
        return ['ok' => false, 'error' => 'HTTP ' . $code . ' da ME Eventos (base: ' . $baseInfo . ', token: ' . $tokenStatus . ')'];
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        $snippet = substr(trim((string)$resp), 0, 200);
        return ['ok' => false, 'error' => 'JSON inválido da ME Eventos: ' . ($snippet !== '' ? $snippet : 'resposta vazia')];
    }

    return ['ok' => true, 'data' => $data];
}

function fetch_me_tipos_evento(): array {
    $resp = me_request('/api/v1/eventtype');
    if (!$resp['ok']) {
        return ['ok' => false, 'error' => $resp['error']];
    }
    $raw = $resp['data'];
    $items = null;
    if (is_array($raw)) {
        if (array_keys($raw) === range(0, count($raw) - 1)) {
            $items = $raw;
        } else {
            $items = $raw['data'] ?? null;
        }
    }
    if (!is_array($items)) {
        return ['ok' => true, 'data' => []];
    }
    $tipos = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $id = $item['id'] ?? $item['idtipoevento'] ?? null;
        $nome = $item['nome'] ?? $item['tipoevento'] ?? null;
        if ($id !== null && $nome !== null) {
            $tipos[] = ['id' => (int)$id, 'nome' => (string)$nome];
        }
    }
    return ['ok' => true, 'data' => $tipos];
}

function fetch_me_locais(): array {
    $resp = me_request('/api/v1/eventlocation');
    if (!$resp['ok']) {
        return ['ok' => false, 'error' => $resp['error']];
    }

    $raw = $resp['data'];
    $items = null;
    if (is_array($raw)) {
        if (array_keys($raw) === range(0, count($raw) - 1)) {
            $items = $raw;
        } else {
            $items = $raw['data'] ?? null;
        }
    }
    if (!is_array($items)) {
        $keys = is_array($raw) ? implode(',', array_keys($raw)) : gettype($raw);
        return ['ok' => false, 'error' => 'Resposta da ME sem lista de locais. Estrutura: ' . $keys];
    }

    $locais = [];
    foreach ($items as $item) {
        $id = $item['idlocalevento'] ?? $item['id'] ?? null;
        $nome = $item['localevento'] ?? $item['nome'] ?? null;
        if ($nome === null) {
            return ['ok' => false, 'error' => 'ME retornou local sem nome (localevento).'];
        }
        $locais[] = [
            'idlocalevento' => $id !== null ? (int)$id : 0,
            'localevento' => (string)$nome
        ];
    }

    return ['ok' => true, 'data' => $locais];
}

function get_unidades_internas(PDO $pdo): array {
    $stmt = $pdo->query("SELECT id, codigo, nome FROM logistica_unidades WHERE ativo IS TRUE ORDER BY id");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function map_space_to_unidade(array $unidadesByCodigo, string $space): ?int {
    if (in_array($space, ['Lisbon Garden', 'Cristal'], true)) {
        return $unidadesByCodigo['GardenCentral'] ?? null;
    }
    if ($space === 'Lisbon 1') {
        return $unidadesByCodigo['Lisbon1'] ?? null;
    }
    if ($space === 'DiverKids') {
        return $unidadesByCodigo['DiverKids'] ?? null;
    }
    return null;
}

function load_mapeamentos(PDO $pdo): array {
    $stmt = $pdo->query("SELECT * FROM logistica_me_locais ORDER BY id");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $byId = [];
    $byName = [];
    foreach ($rows as $row) {
        if (!empty($row['me_local_id'])) {
            $byId[(int)$row['me_local_id']] = $row;
        }
        $byName[mb_strtolower(trim($row['me_local_nome']))] = $row;
    }
    return ['by_id' => $byId, 'by_name' => $byName, 'all' => $rows];
}

function build_db_diagnostic(PDO $pdo): array {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $appEnv = getenv('APP_ENV') ?: getenv('RAILWAY_ENVIRONMENT') ?: '';

    $strategy = '';
    $host = '';
    $port = '';
    $db = '';

    $dbUrl = getenv('DATABASE_URL') ?: '';
    if ($dbUrl !== '') {
        $strategy = 'DATABASE_URL';
        $dbUrl = preg_replace('#^postgresql://#', 'postgres://', $dbUrl);
        $parts = parse_url($dbUrl);
        if ($parts) {
            $host = $parts['host'] ?? '';
            $port = (string)($parts['port'] ?? '');
            $db = isset($parts['path']) ? ltrim($parts['path'], '/') : '';
        }
    } else {
        $strategy = 'PG*';
        $host = getenv('PGHOST') ?: getenv('DB_HOST') ?: '';
        $port = getenv('PGPORT') ?: getenv('DB_PORT') ?: '';
        $db = getenv('PGDATABASE') ?: getenv('DB_NAME') ?: '';
    }

    return [
        'driver' => $driver,
        'host' => $host,
        'port' => $port,
        'database' => $db,
        'strategy' => $strategy,
        'app_env' => $appEnv,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

function has_column(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = :table
          AND column_name = :column
        LIMIT 1
    ");
    $stmt->execute([':table' => $table, ':column' => $column]);
    return (bool)$stmt->fetchColumn();
}

function load_db_summary(PDO $pdo): array {
    $summary = [];
    $summary['locais_total'] = (int)$pdo->query("SELECT COUNT(*) FROM logistica_me_locais")->fetchColumn();
    $summary['locais_sem_id'] = (int)$pdo->query("SELECT COUNT(*) FROM logistica_me_locais WHERE me_local_id IS NULL OR me_local_id = 0")->fetchColumn();
    $summary['eventos_total'] = (int)$pdo->query("SELECT COUNT(*) FROM logistica_eventos_espelho")->fetchColumn();
    $summary['arquivados_total'] = (int)$pdo->query("SELECT COUNT(*) FROM logistica_eventos_espelho WHERE arquivado IS TRUE")->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM logistica_eventos_espelho
        WHERE data_evento BETWEEN :ini AND :fim
    ");
    $stmt->execute([
        ':ini' => date('Y-m-d'),
        ':fim' => date('Y-m-d', strtotime('+30 days'))
    ]);
    $summary['eventos_ativos'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM logistica_eventos_espelho
        WHERE status_mapeamento = :status
        AND arquivado IS FALSE
    ");
    $stmt->execute([':status' => 'PENDENTE']);
    $summary['pendentes_mapeamento'] = (int)$stmt->fetchColumn();

    $summary['locais_distintos_me'] = (int)$pdo->query("
        SELECT COUNT(DISTINCT localevento)
        FROM logistica_eventos_espelho
    ")->fetchColumn();

    $stmt = $pdo->query("
        SELECT DISTINCT localevento
        FROM logistica_eventos_espelho
        ORDER BY localevento ASC
        LIMIT 10
    ");
    $summary['locais_distintos_lista'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (has_column($pdo, 'logistica_eventos_espelho', 'nome_evento')) {
        $stmt = $pdo->query("
            SELECT data_evento, hora_inicio, nome_evento, localevento
            FROM logistica_eventos_espelho
            ORDER BY data_evento DESC, hora_inicio DESC NULLS LAST
            LIMIT 3
        ");
        $summary['eventos_exemplos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $summary['eventos_exemplos'] = [];
    }

    return $summary;
}

function upsert_mapeamento(PDO $pdo, array $payload): void {
    $now = date('Y-m-d H:i:s');
    $meLocalId = $payload['me_local_id'] ?? 0;
    $meLocalNome = $payload['me_local_nome'] ?? '';

    if ($meLocalId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM logistica_me_locais WHERE me_local_id = :me_local_id LIMIT 1");
        $stmt->execute([':me_local_id' => $meLocalId]);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM logistica_me_locais WHERE LOWER(me_local_nome) = LOWER(:me_local_nome) LIMIT 1");
        $stmt->execute([':me_local_nome' => $meLocalNome]);
    }
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $stmt = $pdo->prepare("
            UPDATE logistica_me_locais
            SET me_local_id = :me_local_id,
                me_local_nome = :me_local_nome,
                space_visivel = :space_visivel,
                unidade_interna_id = :unidade_interna_id,
                status_mapeamento = :status_mapeamento,
                updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->execute([
            ':me_local_id' => $meLocalId > 0 ? $meLocalId : null,
            ':me_local_nome' => $meLocalNome,
            ':space_visivel' => $payload['space_visivel'],
            ':unidade_interna_id' => $payload['unidade_interna_id'],
            ':status_mapeamento' => $payload['status_mapeamento'],
            ':updated_at' => $now,
            ':id' => $existingId
        ]);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO logistica_me_locais
        (me_local_id, me_local_nome, space_visivel, unidade_interna_id, status_mapeamento, created_at, updated_at)
        VALUES
        (:me_local_id, :me_local_nome, :space_visivel, :unidade_interna_id, :status_mapeamento, :created_at, :updated_at)
    ");
    $stmt->execute([
        ':me_local_id' => $meLocalId > 0 ? $meLocalId : null,
        ':me_local_nome' => $meLocalNome,
        ':space_visivel' => $payload['space_visivel'],
        ':unidade_interna_id' => $payload['unidade_interna_id'],
        ':status_mapeamento' => $payload['status_mapeamento'],
        ':created_at' => $now,
        ':updated_at' => $now
    ]);
}

function sync_eventos(PDO $pdo, array $mapeamentos, array $unidadesByCodigo, array &$novosLocais, bool $hasNomeEvento): array {
    $hoje = new DateTimeImmutable('today');
    $fim = $hoje->modify('+30 days');
    $resp = me_request('/api/v1/events', [
        'start' => $hoje->format('Y-m-d'),
        'end' => $fim->format('Y-m-d')
    ]);
    if (!$resp['ok']) {
        return ['ok' => false, 'error' => $resp['error']];
    }

    $raw = $resp['data'];
    $items = null;
    if (is_array($raw)) {
        if (array_keys($raw) === range(0, count($raw) - 1)) {
            $items = $raw;
        } else {
            $items = $raw['data'] ?? $raw['eventos'] ?? null;
        }
    }
    if (!is_array($items)) {
        $keys = is_array($raw) ? implode(',', array_keys($raw)) : gettype($raw);
        return ['ok' => false, 'error' => 'Resposta da ME sem lista de eventos. Estrutura: ' . $keys];
    }

    $eventIds = [];
    foreach ($items as $item) {
        if (isset($item['id'])) {
            $eventIds[] = (int)$item['id'];
        }
    }

    $existingSet = [];
    if ($eventIds) {
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $pdo->prepare("SELECT me_event_id FROM logistica_eventos_espelho WHERE me_event_id IN ($placeholders)");
        $stmt->execute($eventIds);
        $existingSet = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    $inserted = 0;
    $updated = 0;
    $pendentes = 0;

    if ($hasNomeEvento) {
        $sql = "
            INSERT INTO logistica_eventos_espelho
            (me_event_id, data_evento, hora_inicio, convidados, idlocalevento, localevento, nome_evento,
             unidade_interna_id, space_visivel, status_mapeamento, arquivado, synced_at, updated_at)
            VALUES
            (:me_event_id, :data_evento, :hora_inicio, :convidados, :idlocalevento, :localevento, :nome_evento,
             :unidade_interna_id, :space_visivel, :status_mapeamento, FALSE, NOW(), NOW())
            ON CONFLICT (me_event_id) DO UPDATE SET
                data_evento = EXCLUDED.data_evento,
                hora_inicio = EXCLUDED.hora_inicio,
                convidados = EXCLUDED.convidados,
                idlocalevento = EXCLUDED.idlocalevento,
                localevento = EXCLUDED.localevento,
                nome_evento = EXCLUDED.nome_evento,
                unidade_interna_id = EXCLUDED.unidade_interna_id,
                space_visivel = EXCLUDED.space_visivel,
                status_mapeamento = EXCLUDED.status_mapeamento,
                arquivado = FALSE,
                synced_at = NOW(),
                updated_at = NOW()
        ";
    } else {
        $sql = "
            INSERT INTO logistica_eventos_espelho
            (me_event_id, data_evento, hora_inicio, convidados, idlocalevento, localevento,
             unidade_interna_id, space_visivel, status_mapeamento, arquivado, synced_at, updated_at)
            VALUES
            (:me_event_id, :data_evento, :hora_inicio, :convidados, :idlocalevento, :localevento,
             :unidade_interna_id, :space_visivel, :status_mapeamento, FALSE, NOW(), NOW())
            ON CONFLICT (me_event_id) DO UPDATE SET
                data_evento = EXCLUDED.data_evento,
                hora_inicio = EXCLUDED.hora_inicio,
                convidados = EXCLUDED.convidados,
                idlocalevento = EXCLUDED.idlocalevento,
                localevento = EXCLUDED.localevento,
                unidade_interna_id = EXCLUDED.unidade_interna_id,
                space_visivel = EXCLUDED.space_visivel,
                status_mapeamento = EXCLUDED.status_mapeamento,
                arquivado = FALSE,
                synced_at = NOW(),
                updated_at = NOW()
        ";
    }
    $stmtUpsert = $pdo->prepare($sql);

    foreach ($items as $item) {
        $meEventId = $item['id'] ?? null;
        $dataEvento = $item['dataevento'] ?? null;
        $localEvento = $item['localevento'] ?? null;

        if ($meEventId === null || $dataEvento === null || $localEvento === null) {
            return ['ok' => false, 'error' => 'ME retornou evento sem id/dataevento/localevento.'];
        }

        $meEventId = (int)$meEventId;
        $idLocalEvento = isset($item['idlocalevento']) ? (int)$item['idlocalevento'] : 0;
        $horaInicio = !empty($item['horaevento']) ? $item['horaevento'] : null;
        $convidados = isset($item['convidados']) ? (int)$item['convidados'] : null;
        $nomeEvento = trim((string)($item['nomeevento'] ?? ''));
        if ($nomeEvento === '') {
            $fallbackNome = trim((string)($item['nomeCliente'] ?? ''));
            $fallbackTipo = trim((string)($item['tipoEvento'] ?? ''));
            $nomeEvento = trim($fallbackNome . ($fallbackTipo !== '' ? ' - ' . $fallbackTipo : ''));
            if ($nomeEvento === '') {
                $nomeEvento = 'Evento';
            }
        }

        $mapping = null;
        if ($idLocalEvento > 0 && isset($mapeamentos['by_id'][$idLocalEvento])) {
            $mapping = $mapeamentos['by_id'][$idLocalEvento];
        } else {
            $nameKey = mb_strtolower(trim((string)$localEvento));
            if (isset($mapeamentos['by_name'][$nameKey])) {
                $mapping = $mapeamentos['by_name'][$nameKey];
            }
        }

        $status = $mapping ? 'MAPEADO' : 'PENDENTE';
        $spaceVisivel = $mapping['space_visivel'] ?? null;
        $unidadeId = $mapping['unidade_interna_id'] ?? null;

        if (!$mapping) {
            $pendentes++;
            $key = ($idLocalEvento > 0 ? $idLocalEvento : 0) . '|' . $localEvento;
            $novosLocais[$key] = [
                'idlocalevento' => $idLocalEvento,
                'localevento' => $localEvento
            ];
        }

        $payload = [
            ':me_event_id' => $meEventId,
            ':data_evento' => $dataEvento,
            ':hora_inicio' => $horaInicio,
            ':convidados' => $convidados,
            ':idlocalevento' => $idLocalEvento,
            ':localevento' => $localEvento,
            ':unidade_interna_id' => $unidadeId,
            ':space_visivel' => $spaceVisivel,
            ':status_mapeamento' => $status
        ];
        if ($hasNomeEvento) {
            $payload[':nome_evento'] = $nomeEvento;
        }
        $stmtUpsert->execute($payload);

        if (isset($existingSet[$meEventId])) {
            $updated++;
        } else {
            $inserted++;
        }
    }

    $archiveDate = (new DateTimeImmutable('today'))->modify('-60 days')->format('Y-m-d');
    $stmt = $pdo->prepare("UPDATE logistica_eventos_espelho SET arquivado = TRUE WHERE data_evento < :data");
    $stmt->execute([':data' => $archiveDate]);

    return [
        'ok' => true,
        'inserted' => $inserted,
        'updated' => $updated,
        'pendentes' => $pendentes
    ];
}

if (defined('LOGISTICA_SYNC_ONLY')) {
    return;
}

$schema_ok = ensure_logistica_schema($pdo, $errors, $messages);
$has_nome_evento = $schema_ok && has_column($pdo, 'logistica_eventos_espelho', 'nome_evento');
if (!$has_nome_evento) {
    $errors[] = 'Coluna nome_evento ainda não existe. Rode o SQL 030 (logistica_eventos_nome) e sincronize novamente.';
}
$unidades = [];
$unidadesByCodigo = [];
if ($schema_ok) {
    try {
        $unidades = get_unidades_internas($pdo);
        foreach ($unidades as $u) {
            $unidadesByCodigo[$u['codigo']] = (int)$u['id'];
        }
    } catch (Throwable $e) {
        $errors[] = 'Tabela de unidades internas não encontrada. Execute o SQL de base da Logística.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_tipos_me') {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS vendas_me_tipo_evento_map (
                    tipo_painel VARCHAR(32) PRIMARY KEY,
                    me_tipo_evento_id INT NOT NULL,
                    me_tipo_nome VARCHAR(120),
                    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
                )
            ");
            $tipos_painel = ['casamento' => 'Casamento', '15anos' => '15 anos', 'infantil' => 'Infantil', 'pj' => 'PJ'];
            foreach ($tipos_painel as $key => $label) {
                $me_id = (int)($_POST['me_tipo_' . $key] ?? 0);
                if ($me_id <= 0) continue;
                $me_nome = trim((string)($_POST['me_tipo_nome_' . $key] ?? ''));
                $stmt = $pdo->prepare("
                    INSERT INTO vendas_me_tipo_evento_map (tipo_painel, me_tipo_evento_id, me_tipo_nome, atualizado_em)
                    VALUES (?, ?, ?, NOW())
                    ON CONFLICT (tipo_painel) DO UPDATE SET
                        me_tipo_evento_id = EXCLUDED.me_tipo_evento_id,
                        me_tipo_nome = EXCLUDED.me_tipo_nome,
                        atualizado_em = NOW()
                ");
                $stmt->execute([$key, $me_id, $me_nome]);
            }
            $messages[] = 'Mapeamento de tipos de evento (Vendas → ME) salvo.';
        } catch (Throwable $e) {
            $errors[] = 'Erro ao salvar tipos ME: ' . $e->getMessage();
        }
    }

    if ($action === 'save_mapeamento') {
        $spaceVisivel = $_POST['space_visivel'] ?? [];
        $meLocalId = $_POST['me_local_id'] ?? [];
        $meLocalNome = $_POST['me_local_nome'] ?? [];

        foreach ($spaceVisivel as $idx => $space) {
            $space = trim((string)$space);
            $localId = isset($meLocalId[$idx]) ? (int)$meLocalId[$idx] : 0;
            $localNome = trim((string)($meLocalNome[$idx] ?? ''));

            if ($localNome === '') {
                continue;
            }

            $status = $space !== '' ? 'MAPEADO' : 'PENDENTE';
            $unidadeId = $space !== '' ? map_space_to_unidade($unidadesByCodigo, $space) : null;

            if ($space !== '' && $unidadeId === null) {
                $errors[] = 'Não foi possível resolver unidade interna para o space ' . h($space);
                continue;
            }

            upsert_mapeamento($pdo, [
                'me_local_id' => $localId,
                'me_local_nome' => $localNome,
                'space_visivel' => $space !== '' ? $space : null,
                'unidade_interna_id' => $unidadeId,
                'status_mapeamento' => $status
            ]);
        }

        if (!$errors) {
            $messages[] = 'Mapeamento salvo com sucesso.';
        }
    }

    if ($action === 'sync') {
        if (!$schema_ok) {
            $errors[] = 'Base Logística ausente. Execute o SQL antes de sincronizar.';
        } else {
        $mapeamentos = load_mapeamentos($pdo);
        $novosLocais = [];
        $sync = sync_eventos($pdo, $mapeamentos, $unidadesByCodigo, $novosLocais, $has_nome_evento);
        if (!$sync['ok']) {
            $errors[] = $sync['error'];
        } else {
            $sync_summary = $sync;
            if (!empty($novosLocais)) {
                $novos_locais = array_values($novosLocais);
            }
            $messages[] = 'Sincronização concluída com sucesso.';
        }
        }
    }

    if ($action === 'test_db') {
        try {
            $pdo->query('SELECT 1');
            $db_test_result = ['ok' => true, 'message' => 'Conexão OK'];
        } catch (Throwable $e) {
            $db_test_result = ['ok' => false, 'message' => 'Erro na conexão: ' . $e->getMessage()];
        }
    }
}

$locais_resp = fetch_me_locais();
$me_locais = [];
if (!$locais_resp['ok']) {
    $errors[] = $locais_resp['error'];
} else {
    $me_locais = $locais_resp['data'];
}

$tipos_me_resp = fetch_me_tipos_evento();
$me_tipos_evento = [];
if ($tipos_me_resp['ok']) {
    $me_tipos_evento = $tipos_me_resp['data'];
}
$map_tipos_me = [];
if ($schema_ok) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS vendas_me_tipo_evento_map (
                tipo_painel VARCHAR(32) PRIMARY KEY,
                me_tipo_evento_id INT NOT NULL,
                me_tipo_nome VARCHAR(120),
                atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $stmt = $pdo->query("SELECT tipo_painel, me_tipo_evento_id, me_tipo_nome FROM vendas_me_tipo_evento_map");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map_tipos_me[$row['tipo_painel']] = [
                'me_tipo_evento_id' => (int)$row['me_tipo_evento_id'],
                'me_tipo_nome' => (string)($row['me_tipo_nome'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        // ignora se tabela não existir ainda
    }
}
$tipos_painel_labels = ['casamento' => 'Casamento', '15anos' => '15 anos / Debutante', 'infantil' => 'Infantil', 'pj' => 'PJ'];

$mapeamentos = ['by_id' => [], 'by_name' => [], 'all' => []];
if ($schema_ok) {
    try {
        $mapeamentos = load_mapeamentos($pdo);
    } catch (Throwable $e) {
        $errors[] = 'Erro ao carregar mapeamentos existentes: ' . $e->getMessage();
    }
}

if ($schema_ok) {
    try {
        $db_summary = load_db_summary($pdo);
    } catch (Throwable $e) {
        $errors[] = 'Erro ao carregar resumo do banco: ' . $e->getMessage();
    }
}

$space_options = [
    'Lisbon Garden',
    'Cristal',
    'Lisbon 1',
    'DiverKids'
];

includeSidebar('Configurações - Logística');
?>

<style>
.logistica-config {
    max-width: 1400px;
    margin: 0 auto;
    padding: 1.5rem;
}

.logistica-config h1 {
    font-size: 2rem;
    font-weight: 700;
    color: #1e3a8a;
    margin-bottom: 1rem;
}

.logistica-section {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.logistica-section h2 {
    margin: 0 0 1rem 0;
    font-size: 1.25rem;
    color: #0f172a;
}

.funcionalidades-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 1rem;
}

.funcionalidade-card {
    background: #ffffff;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
    transition: all 0.3s ease;
    cursor: pointer;
    text-decoration: none;
    color: inherit;
    display: flex;
    flex-direction: column;
    height: 100%;
}

.funcionalidade-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
    border-color: #3b82f6;
}

.funcionalidade-card-header {
    color: #ffffff;
    padding: 1.25rem;
}

.funcionalidade-card-icon {
    font-size: 2rem;
    margin-bottom: 0.5rem;
    display: block;
}

.funcionalidade-card-title {
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 0.25rem;
}

.funcionalidade-card-subtitle {
    font-size: 0.85rem;
    opacity: 0.9;
}

.funcionalidade-card-content {
    padding: 1rem;
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
}

.funcionalidade-card-content::after {
    content: '→';
    color: #64748b;
    font-weight: bold;
    font-size: 1.25rem;
}

.logistica-table {
    width: 100%;
    border-collapse: collapse;
}

.logistica-table th,
.logistica-table td {
    text-align: left;
    padding: 0.75rem;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: middle;
    font-size: 0.95rem;
}

.status-badge {
    display: inline-block;
    padding: 0.2rem 0.6rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
    color: #fff;
}

.status-mapeado {
    background: #16a34a;
}

.status-pendente {
    background: #f97316;
}

.btn-primary {
    background: #2563eb;
    color: #fff;
    border: none;
    padding: 0.6rem 1rem;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
}

.btn-primary:hover {
    background: #1d4ed8;
}

.alert {
    padding: 0.75rem 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
    font-size: 0.95rem;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
}

.alert-success {
    background: #dcfce7;
    color: #166534;
}
</style>

<div class="logistica-config">
    <h1>Conexão Logística</h1>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-success"><?= h($message) ?></div>
    <?php endforeach; ?>

    <?php if ($sync_summary): ?>
        <div class="alert alert-success">
            Sync concluído: <?= (int)$sync_summary['inserted'] ?> inseridos, <?= (int)$sync_summary['updated'] ?> atualizados, <?= (int)$sync_summary['pendentes'] ?> pendentes.
        </div>
        <?php if (!empty($novos_locais)): ?>
            <div class="alert alert-error">
                Locais novos sem mapeamento:
                <ul>
                    <?php foreach ($novos_locais as $novo): ?>
                        <li><?= h($novo['idlocalevento']) ?> — <?= h($novo['localevento']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($db_test_result): ?>
        <div class="alert <?= $db_test_result['ok'] ? 'alert-success' : 'alert-error' ?>">
            <?= h($db_test_result['message']) ?>
        </div>
    <?php endif; ?>

    <div class="logistica-section">
        <h2>Mapeamento de Locais (ME → Unidade/Space)</h2>
        <form method="POST">
            <input type="hidden" name="action" value="save_mapeamento">
            <table class="logistica-table">
                <thead>
                    <tr>
                        <th>ID Local (ME)</th>
                        <th>Local (ME)</th>
                        <th>Status</th>
                        <th>Space visível</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($me_locais)): ?>
                        <tr>
                            <td colspan="4">Nenhum local encontrado.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($me_locais as $idx => $local): ?>
                        <?php
                        $localId = (int)$local['idlocalevento'];
                        $localNome = (string)$local['localevento'];
                        $mapping = null;
                        if ($localId > 0 && isset($mapeamentos['by_id'][$localId])) {
                            $mapping = $mapeamentos['by_id'][$localId];
                        } else {
                            $nameKey = mb_strtolower(trim($localNome));
                            if (isset($mapeamentos['by_name'][$nameKey])) {
                                $mapping = $mapeamentos['by_name'][$nameKey];
                            }
                        }
                        $status = $mapping ? 'MAPEADO' : 'PENDENTE';
                        $spaceAtual = $mapping['space_visivel'] ?? '';
                        ?>
                        <tr>
                            <td><?= h($localId) ?></td>
                            <td><?= h($localNome) ?></td>
                            <td>
                                <span class="status-badge <?= $status === 'MAPEADO' ? 'status-mapeado' : 'status-pendente' ?>">
                                    <?= h($status) ?>
                                </span>
                            </td>
                            <td>
                                <input type="hidden" name="me_local_id[<?= $idx ?>]" value="<?= h($localId) ?>">
                                <input type="hidden" name="me_local_nome[<?= $idx ?>]" value="<?= h($localNome) ?>">
                                <select name="space_visivel[<?= $idx ?>]" class="form-input">
                                    <option value="">Selecione...</option>
                                    <?php foreach ($space_options as $opt): ?>
                                        <option value="<?= h($opt) ?>" <?= $opt === $spaceAtual ? 'selected' : '' ?>>
                                            <?= h($opt) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top: 1rem;">
                <button class="btn-primary" type="submit">Salvar mapeamentos</button>
            </div>
        </form>
    </div>

    <div class="logistica-section">
        <h2>Tipos de evento (ME) — Vendas</h2>
        <p style="color:#64748b; margin-bottom: 1rem;">Mapeie cada tipo do Painel ao tipo na ME. Na hora de aprovar um pré-contrato, o tipo sugerido será este; o usuário pode alterar no modal de aprovação.</p>
        <form method="POST">
            <input type="hidden" name="action" value="save_tipos_me">
            <table class="logistica-table">
                <thead>
                    <tr>
                        <th>Tipo no Painel</th>
                        <th>Tipo na ME (eventtype)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tipos_painel_labels as $key => $label): ?>
                        <?php $atual = $map_tipos_me[$key] ?? null; $id_atual = $atual ? (int)$atual['me_tipo_evento_id'] : 0; ?>
                        <tr>
                            <td><?= h($label) ?></td>
                            <td>
                                <select name="me_tipo_<?= h($key) ?>" class="form-input" style="min-width: 220px;">
                                    <option value="">— Não mapeado —</option>
                                    <?php foreach ($me_tipos_evento as $t): ?>
                                        <option value="<?= (int)$t['id'] ?>" data-nome="<?= h($t['nome']) ?>" <?= $id_atual === (int)$t['id'] ? 'selected' : '' ?>>
                                            <?= h($t['nome']) ?> (ID <?= (int)$t['id'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="me_tipo_nome_<?= h($key) ?>" value="<?= h($atual['me_tipo_nome'] ?? '') ?>" data-nome-for="<?= h($key) ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (empty($me_tipos_evento)): ?>
                <p class="alert alert-error">Não foi possível carregar os tipos da ME. Verifique ME_BASE_URL e ME_API_TOKEN.</p>
            <?php else: ?>
                <div style="margin-top: 1rem;">
                    <button class="btn-primary" type="submit">Salvar tipos (Vendas → ME)</button>
                </div>
            <?php endif; ?>
        </form>
        <script>
        (function(){
            document.querySelectorAll('select[name^="me_tipo_"]').forEach(function(sel){
                if (sel.getAttribute('name') && sel.getAttribute('name').indexOf('me_tipo_') === 0) {
                    var key = sel.getAttribute('name').replace('me_tipo_', '');
                    sel.addEventListener('change', function(){
                        var opt = this.options[this.selectedIndex];
                        var nome = (opt && opt.getAttribute('data-nome')) ? opt.getAttribute('data-nome') : '';
                        var inp = document.querySelector('input[name="me_tipo_nome_' + key + '"]');
                        if (inp) inp.value = nome;
                    });
                }
            });
        })();
        </script>
    </div>

    <div class="logistica-section">
        <h2>Sincronização de Eventos (hoje → +30 dias)</h2>
        <form method="POST">
            <input type="hidden" name="action" value="sync">
            <button class="btn-primary" type="submit">Sincronizar agora</button>
        </form>
    </div>

    <div class="logistica-section">
        <h2>Resumo do Banco (Logística)</h2>
        <?php if (!$db_summary): ?>
            <p>Resumo indisponível.</p>
        <?php else: ?>
            <table class="logistica-table">
                <tbody>
                    <tr><td>Locais mapeados (total)</td><td><?= (int)$db_summary['locais_total'] ?></td></tr>
                    <tr><td>Locais distintos nos eventos espelho</td><td><?= (int)$db_summary['locais_distintos_me'] ?></td></tr>
                    <tr><td>Locais sem ID (0/NULL)</td><td><?= (int)$db_summary['locais_sem_id'] ?></td></tr>
                    <tr><td>Eventos espelho (total)</td><td><?= (int)$db_summary['eventos_total'] ?></td></tr>
                    <tr><td>Eventos ativos (hoje → +30)</td><td><?= (int)$db_summary['eventos_ativos'] ?></td></tr>
                    <tr><td>Pendentes de mapeamento</td><td><?= (int)$db_summary['pendentes_mapeamento'] ?></td></tr>
                    <tr><td>Arquivados (total)</td><td><?= (int)$db_summary['arquivados_total'] ?></td></tr>
                </tbody>
            </table>

            <div style="margin-top: 1rem;">
                <strong>Últimos 10 locais distintos</strong>
                <ul>
                    <?php foreach ($db_summary['locais_distintos_lista'] as $local): ?>
                        <li><?= h($local) ?></li>
                    <?php endforeach; ?>
                    <?php if (empty($db_summary['locais_distintos_lista'])): ?>
                        <li>Nenhum local encontrado.</li>
                    <?php endif; ?>
                </ul>
            </div>

            <div style="margin-top: 1rem;">
                <strong>Exemplos de eventos (data/hora + nome + local)</strong>
                <ul>
                    <?php foreach ($db_summary['eventos_exemplos'] as $ev): ?>
                        <li>
                            <?= h(($ev['data_evento'] ?? '') . ' ' . ($ev['hora_inicio'] ?? '')) ?>
                            — <?= h($ev['nome_evento'] ?? 'Evento') ?>
                            — <?= h($ev['localevento'] ?? '') ?>
                        </li>
                    <?php endforeach; ?>
                    <?php if (empty($db_summary['eventos_exemplos'])): ?>
                        <li>Nenhum evento encontrado.</li>
                    <?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['perm_superadmin'])): ?>
        <?php $diag = build_db_diagnostic($pdo); ?>
        <div class="logistica-section">
            <h2>Diagnóstico de Conexão</h2>
            <table class="logistica-table">
                <tbody>
                    <tr><td>Driver</td><td><?= h($diag['driver']) ?></td></tr>
                    <tr><td>Host</td><td><?= h($diag['host']) ?></td></tr>
                    <tr><td>Porta</td><td><?= h($diag['port']) ?></td></tr>
                    <tr><td>Database</td><td><?= h($diag['database']) ?></td></tr>
                    <tr><td>Strategy</td><td><?= h($diag['strategy']) ?></td></tr>
                    <tr><td>Ambiente</td><td><?= h($diag['app_env']) ?></td></tr>
                    <tr><td>Timestamp</td><td><?= h($diag['timestamp']) ?></td></tr>
                </tbody>
            </table>

            <?php if ($diag['host'] === '' || $diag['database'] === '' || $diag['host'] === 'localhost'): ?>
                <div class="alert alert-error" style="margin-top: 1rem;">
                    Conexão parece apontar para banco local/placeholder. Verifique DATABASE_URL.
                </div>
            <?php endif; ?>

            <form method="POST" style="margin-top: 1rem;">
                <input type="hidden" name="action" value="test_db">
                <button class="btn-primary" type="submit">Testar conexão DB</button>
            </form>
        </div>

        <div class="logistica-section">
            <h2>Diagnóstico (rotas, migrações e cron)</h2>
            <p style="margin-top: 0.25rem;">Validação técnica do módulo Logística usando o mesmo banco do painel.</p>
            <a class="btn-primary" href="index.php?page=logistica_diagnostico">Abrir diagnóstico</a>
        </div>
    <?php endif; ?>
</div>

<?php endSidebar(); ?>
