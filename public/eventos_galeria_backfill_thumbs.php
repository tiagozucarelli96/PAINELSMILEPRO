<?php
/**
 * Gera thumbnails retroativas para a galeria de eventos.
 *
 * Uso CLI:
 *   php public/eventos_galeria_backfill_thumbs.php --dry-run=1 --limit=200
 *   php public/eventos_galeria_backfill_thumbs.php --dry-run=0 --limit=200 --categoria=casamento
 *
 * Uso Web (apenas superadmin):
 *   /eventos_galeria_backfill_thumbs.php?run=1&dry_run=1&limit=200
 *   /eventos_galeria_backfill_thumbs.php?run=1&dry_run=0&limit=200&categoria=casamento
 */

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/upload_magalu.php';
require_once __DIR__ . '/eventos_galeria_helper.php';

/**
 * @param mixed $value
 */
function eventos_galeria_backfill_parse_bool($value, bool $default = true): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if ($value === null || $value === '') {
        return $default;
    }

    $normalized = strtolower(trim((string)$value));
    if (in_array($normalized, ['1', 'true', 'yes', 'sim', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'nao', 'off'], true)) {
        return false;
    }

    return $default;
}

/**
 * @return array{
 *   dry_run:bool,
 *   categoria:string,
 *   limit:int,
 *   total_pendentes:int,
 *   total_considerados:int,
 *   total_processados:int,
 *   total_falhas:int,
 *   candidatos:array<int, array{id:int,categoria:string,nome:string,source_url:string}>,
 *   processados:array<int, array{id:int,thumb_public_url:string}>,
 *   falhas:array<int, array{id:int,erro:string}>,
 *   avisos:array<int, string>
 * }
 */
function eventos_galeria_backfill_thumbs(PDO $pdo, bool $dryRun = true, int $limit = 200, string $categoria = ''): array
{
    $thumbColumns = eventosGaleriaThumbColumns($pdo);
    if (empty($thumbColumns['ready'])) {
        throw new RuntimeException('As colunas de thumbnail ainda nao existem na tabela eventos_galeria.');
    }

    $limit = max(1, min($limit, 1000));
    $categoria = trim($categoria);

    $resultado = [
        'dry_run' => $dryRun,
        'categoria' => $categoria,
        'limit' => $limit,
        'total_pendentes' => 0,
        'total_considerados' => 0,
        'total_processados' => 0,
        'total_falhas' => 0,
        'candidatos' => [],
        'processados' => [],
        'falhas' => [],
        'avisos' => [],
    ];

    $where = "deleted_at IS NULL AND (COALESCE(thumb_storage_key, '') = '' OR COALESCE(thumb_public_url, '') = '')";
    $params = [];

    if ($categoria !== '') {
        $where .= " AND categoria = :categoria";
        $params[':categoria'] = $categoria;
    }

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM eventos_galeria WHERE {$where}");
    foreach ($params as $key => $value) {
        $stmtCount->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmtCount->execute();
    $resultado['total_pendentes'] = (int)$stmtCount->fetchColumn();

    if ($resultado['total_pendentes'] === 0) {
        return $resultado;
    }

    $stmt = $pdo->prepare("
        SELECT id, categoria, nome, storage_key, public_url
        FROM eventos_galeria
        WHERE {$where}
        ORDER BY uploaded_at DESC, id DESC
        LIMIT :limit
    ");
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resultado['total_considerados'] = count($rows);
    foreach ($rows as $row) {
        $sourceUrl = trim((string)($row['public_url'] ?? ''));
        if ($sourceUrl === '') {
            $sourceUrl = (string)(eventosGaleriaStoragePublicUrl((string)($row['storage_key'] ?? '')) ?? '');
        }
        $resultado['candidatos'][] = [
            'id' => (int)($row['id'] ?? 0),
            'categoria' => (string)($row['categoria'] ?? ''),
            'nome' => (string)($row['nome'] ?? ''),
            'source_url' => $sourceUrl,
        ];
    }

    if ($dryRun || empty($rows)) {
        if ($resultado['total_pendentes'] > $resultado['total_considerados']) {
            $resultado['avisos'][] = 'Existem mais registros pendentes. Rode novamente para os proximos lotes.';
        }
        return $resultado;
    }

    $uploader = new MagaluUpload();
    $stmtUpdate = $pdo->prepare("
        UPDATE eventos_galeria
        SET thumb_storage_key = :thumb_storage_key,
            thumb_public_url = :thumb_public_url
        WHERE id = :id
          AND deleted_at IS NULL
    ");

    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        $nome = trim((string)($row['nome'] ?? 'imagem-' . $id));
        $sourceUrl = trim((string)($row['public_url'] ?? ''));
        if ($sourceUrl === '') {
            $sourceUrl = (string)(eventosGaleriaStoragePublicUrl((string)($row['storage_key'] ?? '')) ?? '');
        }

        if ($id <= 0 || $sourceUrl === '') {
            $resultado['total_falhas']++;
            $resultado['falhas'][] = [
                'id' => $id,
                'erro' => 'Registro sem URL de origem valida.',
            ];
            continue;
        }

        $tmpSource = null;
        try {
            $tmpSource = eventosGaleriaDownloadSourceToTemp($sourceUrl);
            $thumb = eventosGaleriaUploadThumbnail($uploader, $tmpSource, $nome . '.jpg');
            $stmtUpdate->execute([
                ':thumb_storage_key' => $thumb['storage_key'] ?: null,
                ':thumb_public_url' => $thumb['public_url'] ?: null,
                ':id' => $id,
            ]);
            $resultado['total_processados']++;
            $resultado['processados'][] = [
                'id' => $id,
                'thumb_public_url' => (string)($thumb['public_url'] ?? ''),
            ];
        } catch (Throwable $e) {
            $resultado['total_falhas']++;
            $resultado['falhas'][] = [
                'id' => $id,
                'erro' => $e->getMessage(),
            ];
            error_log('[EVENTOS_GALERIA_BACKFILL] Falha no id=' . $id . ': ' . $e->getMessage());
        } finally {
            if (is_string($tmpSource) && $tmpSource !== '' && file_exists($tmpSource)) {
                @unlink($tmpSource);
            }
        }
    }

    if ($resultado['total_pendentes'] > $resultado['total_considerados']) {
        $resultado['avisos'][] = 'Existem mais registros pendentes. Rode novamente para os proximos lotes.';
    }

    return $resultado;
}

$isCli = php_sapi_name() === 'cli';
$cliOptions = $isCli ? getopt('', ['dry-run::', 'limit::', 'categoria::']) : [];
$shouldRun = $isCli || isset($_GET['run']);

if (!$shouldRun) {
    if (!$isCli) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok' => false,
        'message' => 'Use run=1 para executar via web ou rode este arquivo via CLI.',
        'exemplos' => [
            'web_dry_run' => 'eventos_galeria_backfill_thumbs.php?run=1&dry_run=1&limit=200',
            'web_execucao' => 'eventos_galeria_backfill_thumbs.php?run=1&dry_run=0&limit=200&categoria=casamento',
            'cli_dry_run' => 'php public/eventos_galeria_backfill_thumbs.php --dry-run=1 --limit=200',
            'cli_execucao' => 'php public/eventos_galeria_backfill_thumbs.php --dry-run=0 --limit=200 --categoria=casamento',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$isCli) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['perm_superadmin'])) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Acesso negado'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$dryRun = eventos_galeria_backfill_parse_bool($cliOptions['dry-run'] ?? ($_GET['dry_run'] ?? '1'), true);
$limit = (int)($cliOptions['limit'] ?? ($_GET['limit'] ?? 200));
if ($limit <= 0) {
    $limit = 200;
}
$categoria = trim((string)($cliOptions['categoria'] ?? ($_GET['categoria'] ?? '')));

try {
    $resultado = eventos_galeria_backfill_thumbs($pdo, $dryRun, $limit, $categoria);
    $payload = ['ok' => true, 'resultado' => $resultado];
} catch (Throwable $e) {
    http_response_code(500);
    $payload = ['ok' => false, 'error' => $e->getMessage()];
}

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
