<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Acesso negado.\n";
    exit(1);
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/comercial_degustacao_notificacao_helper.php';

$optionsCli = getopt('', ['ref-datetime::', 'force']);
$options = [
    'ref_datetime' => $optionsCli['ref-datetime'] ?? null,
    'force' => array_key_exists('force', $optionsCli),
];

try {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Conexão com o banco de dados indisponível.');
    }

    $resultado = degustacao_notificacao_processar($pdo, $options);
    error_log(
        '[DEGUSTACAO_NOTIFICACAO_RUNNER] '
        . json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    exit(!empty($resultado['success']) ? 0 : 1);
} catch (Throwable $e) {
    error_log('[DEGUSTACAO_NOTIFICACAO_RUNNER] Erro: ' . $e->getMessage());
    exit(1);
}
