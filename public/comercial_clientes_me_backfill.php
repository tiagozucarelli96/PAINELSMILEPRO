<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_me_helper.php';
require_once __DIR__ . '/comercial_cliente_sync_helper.php';

if (empty($_SESSION['perm_comercial']) && empty($_SESSION['perm_superadmin'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sem permissão.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function comercial_clientes_me_backfill_bool($value, bool $default = false): bool
{
    if ($value === null || $value === '') {
        return $default;
    }
    return in_array(strtolower((string)$value), ['1', 'true', 'sim', 'yes', 'on'], true);
}

function comercial_clientes_me_backfill_missing(array $row, string $field): bool
{
    return trim((string)($row[$field] ?? '')) === '';
}

function comercial_clientes_me_backfill_clean(string $field, string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (in_array($field, ['documento_numero', 'cep'], true)) {
        return comercial_cliente_sync_digits($value);
    }
    if ($field === 'endereco_estado') {
        return strtoupper(substr($value, 0, 2));
    }
    if ($field === 'tipo_pessoa') {
        return strtoupper($value) === 'PJ' ? 'PJ' : 'PF';
    }
    if ($field === 'documento_tipo') {
        return strtoupper($value) === 'CNPJ' ? 'CNPJ' : 'CPF';
    }

    return $value;
}

function comercial_clientes_me_backfill_payload_from_client(array $clientData, int $meClientId): array
{
    $normalized = comercial_cliente_sync_normalize_me_client($clientData, $meClientId);
    if ($normalized === []) {
        return [];
    }

    return comercial_cliente_sync_payload($normalized, false);
}

$limit = max(1, min(50, (int)($_GET['limit'] ?? $_POST['limit'] ?? 10)));
$afterId = max(0, (int)($_GET['after_id'] ?? $_POST['after_id'] ?? 0));
$dryRun = comercial_clientes_me_backfill_bool($_GET['dry_run'] ?? $_POST['dry_run'] ?? '0', false);

comercial_cliente_sync_ensure_schema($pdo);

$candidateSql = "
    SELECT id, me_cliente_id, nome_completo, email, telefone_whatsapp, documento_tipo, documento_numero, rg,
           cep, endereco_logradouro, endereco_numero, endereco_complemento, endereco_bairro,
           endereco_cidade, endereco_estado
    FROM comercial_cadastro_clientes
    WHERE ativo IS TRUE
      AND me_cliente_id IS NOT NULL
      AND id > :after_id
      AND (
        COALESCE(NULLIF(email, ''), '') = ''
        OR COALESCE(NULLIF(documento_numero, ''), '') = ''
        OR COALESCE(NULLIF(rg, ''), '') = ''
        OR COALESCE(NULLIF(endereco_logradouro, ''), '') = ''
        OR COALESCE(NULLIF(endereco_numero, ''), '') = ''
        OR COALESCE(NULLIF(endereco_bairro, ''), '') = ''
        OR COALESCE(NULLIF(endereco_cidade, ''), '') = ''
        OR COALESCE(NULLIF(endereco_estado, ''), '') = ''
      )
    ORDER BY id ASC
    LIMIT {$limit}
";
$stmt = $pdo->prepare($candidateSql);
$stmt->execute([':after_id' => $afterId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$summary = [
    'ok' => true,
    'dry_run' => $dryRun,
    'limit' => $limit,
    'after_id' => $afterId,
    'processed' => 0,
    'updated' => 0,
    'fields_updated' => 0,
    'api_empty' => 0,
    'api_errors' => 0,
    'last_id' => $afterId,
    'done' => count($rows) === 0,
    'items' => [],
];

$fieldMap = [
    'nome_completo' => 'nome_completo',
    'email' => 'email',
    'telefone_whatsapp' => 'telefone_whatsapp',
    'documento_tipo' => 'documento_tipo',
    'documento_numero' => 'documento_numero',
    'rg' => 'rg',
    'cep' => 'cep',
    'endereco_logradouro' => 'endereco_logradouro',
    'endereco_numero' => 'endereco_numero',
    'endereco_complemento' => 'endereco_complemento',
    'endereco_bairro' => 'endereco_bairro',
    'endereco_cidade' => 'endereco_cidade',
    'endereco_estado' => 'endereco_estado',
];

foreach ($rows as $row) {
    $summary['processed']++;
    $summary['last_id'] = max((int)$summary['last_id'], (int)$row['id']);
    $meClientId = (int)($row['me_cliente_id'] ?? 0);
    $item = [
        'id' => (int)$row['id'],
        'me_cliente_id' => $meClientId,
        'nome' => (string)($row['nome_completo'] ?? ''),
        'updated_fields' => [],
        'status' => 'pending',
    ];

    $result = eventos_me_request('GET', '/api/v1/clients/' . $meClientId);
    if (empty($result['ok'])) {
        $summary['api_errors']++;
        $item['status'] = 'api_error';
        $item['error'] = (string)($result['error'] ?? 'Falha na API ME.');
        $summary['items'][] = $item;
        continue;
    }

    $clientData = comercial_cliente_sync_data_payload(is_array($result['data'] ?? null) ? $result['data'] : []);
    $payload = comercial_clientes_me_backfill_payload_from_client($clientData, $meClientId);
    if ($payload === []) {
        $summary['api_empty']++;
        $item['status'] = 'api_empty';
        $summary['items'][] = $item;
        continue;
    }

    $set = [];
    $params = [':id' => (int)$row['id']];
    foreach ($fieldMap as $targetField => $payloadField) {
        if (!comercial_clientes_me_backfill_missing($row, $targetField)) {
            continue;
        }

        $newValue = comercial_clientes_me_backfill_clean($targetField, (string)($payload[$payloadField] ?? ''));
        if ($newValue === '') {
            continue;
        }

        $param = ':' . $targetField;
        $set[] = "{$targetField} = {$param}";
        $params[$param] = $newValue;
        $item['updated_fields'][] = $targetField;
    }

    if ($set === []) {
        $item['status'] = 'no_new_data';
        $summary['items'][] = $item;
        continue;
    }

    $summary['fields_updated'] += count($set);
    if (!$dryRun) {
        $sql = "UPDATE comercial_cadastro_clientes SET " . implode(', ', $set) . ", updated_at = NOW() WHERE id = :id";
        $updateStmt = $pdo->prepare($sql);
        $updateStmt->execute($params);
    }

    $summary['updated']++;
    $item['status'] = $dryRun ? 'would_update' : 'updated';
    $summary['items'][] = $item;
}

$summary['done'] = count($rows) < $limit;

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
