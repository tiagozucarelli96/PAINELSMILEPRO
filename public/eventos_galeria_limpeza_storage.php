<?php
/**
 * eventos_galeria_limpeza_storage.php
 * Limpeza retroativa dos arquivos da galeria que já foram removidos visualmente
 * (deleted_at preenchido), mas ainda podem existir no storage.
 *
 * Uso CLI:
 *   php public/eventos_galeria_limpeza_storage.php --dry-run=1 --limit=500
 *   php public/eventos_galeria_limpeza_storage.php --dry-run=0 --limit=500 --categoria=15_anos
 *
 * Uso Web (apenas superadmin):
 *   /eventos_galeria_limpeza_storage.php?run=1&dry_run=1&limit=500
 *   /eventos_galeria_limpeza_storage.php?run=1&dry_run=0&limit=500&categoria=15_anos
 */

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/upload_magalu.php';

/**
 * @param mixed $value
 */
function eventos_galeria_cleanup_parse_bool($value, bool $default = true): bool
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
 *   total_removidos_storage:int,
 *   total_falhas_storage:int,
 *   candidatos:array<int, array{id:int,categoria:string,nome:string,storage_key:string,deleted_at:string}>,
 *   removidos:array<int, array{id:int,storage_key:string}>,
 *   falhas:array<int, array{id:int,storage_key:string,erro:string}>,
 *   avisos:array<int, string>
 * }
 */
function eventos_galeria_limpar_storage_soft_deleted(PDO $pdo, bool $dryRun = true, int $limit = 500, string $categoria = ''): array
{
    $limit = max(1, min($limit, 5000));
    $categoria = trim($categoria);

    $resultado = [
        'dry_run' => $dryRun,
        'categoria' => $categoria,
        'limit' => $limit,
        'total_pendentes' => 0,
        'total_considerados' => 0,
        'total_removidos_storage' => 0,
        'total_falhas_storage' => 0,
        'candidatos' => [],
        'removidos' => [],
        'falhas' => [],
        'avisos' => []
    ];

    // Só pega registros soft-deleted que ainda têm chave no storage.
    // Após limpeza bem-sucedida, a chave é esvaziada para evitar reprocessamento.
    $where = "deleted_at IS NOT NULL AND COALESCE(storage_key, '') <> ''";
    $params = [];

    if ($categoria !== '') {
        $where .= " AND categoria = :categoria";
        $params[':categoria'] = $categoria;
    }

    $countSql = "SELECT COUNT(*) FROM eventos_galeria WHERE {$where}";
    $stmtCount = $pdo->prepare($countSql);
    foreach ($params as $key => $value) {
        $stmtCount->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmtCount->execute();
    $resultado['total_pendentes'] = (int)$stmtCount->fetchColumn();

    if ($resultado['total_pendentes'] === 0) {
        return $resultado;
    }

    $selectSql = "
        SELECT id, categoria, nome, storage_key, deleted_at
        FROM eventos_galeria
        WHERE {$where}
        ORDER BY deleted_at ASC, id ASC
        LIMIT :limite
    ";
    $stmtSelect = $pdo->prepare($selectSql);
    foreach ($params as $key => $value) {
        $stmtSelect->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmtSelect->bindValue(':limite', $limit, PDO::PARAM_INT);
    $stmtSelect->execute();
    $rows = $stmtSelect->fetchAll(PDO::FETCH_ASSOC);

    $resultado['total_considerados'] = count($rows);

    foreach ($rows as $row) {
        $resultado['candidatos'][] = [
            'id' => (int)($row['id'] ?? 0),
            'categoria' => (string)($row['categoria'] ?? ''),
            'nome' => (string)($row['nome'] ?? ''),
            'storage_key' => (string)($row['storage_key'] ?? ''),
            'deleted_at' => (string)($row['deleted_at'] ?? '')
        ];
    }

    if ($dryRun || empty($rows)) {
        if ($resultado['total_pendentes'] > $resultado['total_considerados']) {
            $resultado['avisos'][] = 'Existem mais registros pendentes. Rode novamente para processar os proximos lotes.';
        }
        return $resultado;
    }

    $uploader = null;
    try {
        $uploader = new MagaluUpload();
    } catch (Throwable $e) {
        $resultado['avisos'][] = 'Falha ao inicializar o cliente de storage: ' . $e->getMessage();
        $resultado['total_falhas_storage'] = $resultado['total_considerados'];
        foreach ($rows as $row) {
            $resultado['falhas'][] = [
                'id' => (int)($row['id'] ?? 0),
                'storage_key' => (string)($row['storage_key'] ?? ''),
                'erro' => 'Cliente de storage indisponivel'
            ];
        }
        return $resultado;
    }

    $stmtMarkClean = $pdo->prepare("
        UPDATE eventos_galeria
        SET storage_key = '',
            public_url = NULL
        WHERE id = :id
          AND deleted_at IS NOT NULL
    ");

    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        $key = trim((string)($row['storage_key'] ?? ''));
        if ($id <= 0 || $key === '') {
            continue;
        }

        try {
            $ok = $uploader->delete($key);
            if ($ok) {
                $stmtMarkClean->execute([':id' => $id]);
                $resultado['total_removidos_storage']++;
                $resultado['removidos'][] = [
                    'id' => $id,
                    'storage_key' => $key
                ];
                error_log('[EVENTOS_GALERIA_CLEANUP] Arquivo removido do storage. id=' . $id . ' key=' . $key);
            } else {
                $resultado['total_falhas_storage']++;
                $resultado['falhas'][] = [
                    'id' => $id,
                    'storage_key' => $key,
                    'erro' => 'delete retornou false'
                ];
                error_log('[EVENTOS_GALERIA_CLEANUP] Falha ao remover arquivo. id=' . $id . ' key=' . $key);
            }
        } catch (Throwable $e) {
            $resultado['total_falhas_storage']++;
            $resultado['falhas'][] = [
                'id' => $id,
                'storage_key' => $key,
                'erro' => $e->getMessage()
            ];
            error_log('[EVENTOS_GALERIA_CLEANUP] Excecao ao remover arquivo. id=' . $id . ' key=' . $key . ' erro=' . $e->getMessage());
        }
    }

    if ($resultado['total_pendentes'] > $resultado['total_considerados']) {
        $resultado['avisos'][] = 'Existem mais registros pendentes. Rode novamente para processar os proximos lotes.';
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
            'web_dry_run' => 'eventos_galeria_limpeza_storage.php?run=1&dry_run=1&limit=500',
            'web_execucao' => 'eventos_galeria_limpeza_storage.php?run=1&dry_run=0&limit=500&categoria=15_anos',
            'cli_dry_run' => 'php public/eventos_galeria_limpeza_storage.php --dry-run=1 --limit=500',
            'cli_execucao' => 'php public/eventos_galeria_limpeza_storage.php --dry-run=0 --limit=500 --categoria=15_anos'
        ]
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

$dryRunInput = $cliOptions['dry-run'] ?? ($_GET['dry_run'] ?? '1');
$limitInput = $cliOptions['limit'] ?? ($_GET['limit'] ?? 500);
$categoriaInput = $cliOptions['categoria'] ?? ($_GET['categoria'] ?? '');

$dryRun = eventos_galeria_cleanup_parse_bool($dryRunInput, true);
$limit = (int)$limitInput;
if ($limit <= 0) {
    $limit = 500;
}
$categoria = trim((string)$categoriaInput);

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

try {
    $resultado = eventos_galeria_limpar_storage_soft_deleted($pdo, $dryRun, $limit, $categoria);
    error_log(
        '[EVENTOS_GALERIA_CLEANUP] Execucao finalizada. '
        . 'dry_run=' . ($dryRun ? '1' : '0')
        . '; categoria=' . ($categoria !== '' ? $categoria : 'todas')
        . '; considerados=' . $resultado['total_considerados']
        . '; removidos=' . $resultado['total_removidos_storage']
        . '; falhas=' . $resultado['total_falhas_storage']
    );

    echo json_encode([
        'ok' => true,
        'resultado' => $resultado,
        'executado_em' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'executado_em' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
