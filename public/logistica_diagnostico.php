<?php
// logistica_diagnostico.php — Diagnóstico técnico (rotas, migrações e cron)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

if (empty($_SESSION['perm_superadmin'])) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

function table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT to_regclass(:name) IS NOT NULL");
    $stmt->execute([':name' => $table]);
    return (bool)$stmt->fetchColumn();
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    $schema = $pdo->query("SELECT current_schema()")->fetchColumn();
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = :schema AND table_name = :table AND column_name = :column
        LIMIT 1
    ");
    $stmt->execute([':schema' => $schema, ':table' => $table, ':column' => $column]);
    return (bool)$stmt->fetchColumn();
}

$tables = [
    'logistica_eventos_espelho' => ['nome_evento'],
    'logistica_listas' => ['status', 'separado_garden', 'separado_em', 'separado_por', 'baixada_em', 'baixada_por'],
    'logistica_lista_eventos' => [],
    'logistica_lista_itens' => [],
    'logistica_estoque_saldos' => [],
    'logistica_estoque_contagens' => ['posicao_atual', 'posicao_atual_em'],
    'logistica_estoque_contagens_itens' => [],
    'logistica_estoque_movimentos' => [],
    'logistica_transferencias' => ['lista_id'],
    'logistica_transferencias_itens' => [],
    'logistica_alertas_log' => [],
    'logistica_lista_evento_itens' => [],
    'logistica_evento_baixas' => [],
    'logistica_cron_execucoes' => []
];

$current_schema = $pdo->query("SELECT current_schema()")->fetchColumn();
$table_status = [];
foreach ($tables as $table => $cols) {
    $exists = table_exists($pdo, $table);
    $col_status = [];
    foreach ($cols as $col) {
        $col_status[$col] = $exists ? column_exists($pdo, $table, $col) : false;
    }
    $table_status[$table] = [
        'exists' => $exists,
        'columns' => $col_status
    ];
}

$map = require __DIR__ . '/permissoes_map.php';
$route_files = glob(__DIR__ . '/logistica_*.php') ?: [];
$routes = [];
foreach ($route_files as $file) {
    $base = basename($file, '.php');
    if (preg_match('/_helper$/', $base)) { continue; }
    if ($base === 'logistica_cron_runner') { continue; }
    if ($base === 'logistica_baixa_eventos') { continue; }
    $routes[] = $base;
}
sort($routes);

$routes_map_status = [];
foreach ($routes as $route) {
    $routes_map_status[] = [
        'route' => $route,
        'mapped' => array_key_exists($route, $map),
        'perm' => $map[$route] ?? '—',
        'file' => basename($route . '.php')
    ];
}

$map_missing_files = [];
foreach ($map as $route => $perm) {
    if (strpos($route, 'logistica_') !== 0) { continue; }
    $path = __DIR__ . '/' . $route . '.php';
    if (!is_file($path)) {
        $map_missing_files[] = ['route' => $route, 'perm' => $perm];
    }
}

$run_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_daily') {
    try {
        if (!defined('LOGISTICA_CRON_INTERNAL')) {
            define('LOGISTICA_CRON_INTERNAL', true);
        }
        require_once __DIR__ . '/logistica_cron_runner.php';
        $run_result = logistica_run_daily($pdo, []);
    } catch (Throwable $e) {
        $run_result = ['ok' => false, 'error' => $e->getMessage()];
    }
}

includeSidebar('Logística - Diagnóstico');
?>

<style>
.diag-section{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:16px}
.diag-table{width:100%;border-collapse:collapse}
.diag-table th,.diag-table td{border-bottom:1px solid #e5e7eb;padding:8px;text-align:left;font-size:13px}
.badge-ok{color:#166534;background:#dcfce7;padding:2px 6px;border-radius:10px;font-size:12px}
.badge-fail{color:#991b1b;background:#fee2e2;padding:2px 6px;border-radius:10px;font-size:12px}
.btn-primary{background:#2563eb;color:#fff;border:none;padding:8px 12px;border-radius:8px;cursor:pointer}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px}
.muted{color:#6b7280}
</style>

<div class="logistica-config">
    <h1>Diagnóstico Logística</h1>
    <p class="muted">Schema ativo: <span class="mono"><?= h($current_schema) ?></span></p>

    <div class="diag-section">
        <h2>Rotas (logistica_*)</h2>
        <table class="diag-table">
            <thead>
                <tr>
                    <th>Rota</th>
                    <th>Arquivo</th>
                    <th>Mapeada</th>
                    <th>Permissão</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($routes_map_status as $r): ?>
                    <tr>
                        <td><?= h($r['route']) ?></td>
                        <td><?= h($r['file']) ?></td>
                        <td>
                            <?php if ($r['mapped']): ?>
                                <span class="badge-ok">OK</span>
                            <?php else: ?>
                                <span class="badge-fail">FALTA</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($r['perm']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($routes_map_status)): ?>
                    <tr><td colspan="4">Nenhuma rota encontrada.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php if (!empty($map_missing_files)): ?>
            <div style="margin-top:8px">
                <strong>Entradas no permissoes_map sem arquivo:</strong>
                <ul>
                    <?php foreach ($map_missing_files as $m): ?>
                        <li><?= h($m['route']) ?> <span class="muted">(<?= h($m['perm']) ?>)</span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <div class="diag-section">
        <h2>Migrações (030–037)</h2>
        <table class="diag-table">
            <thead>
                <tr>
                    <th>Tabela</th>
                    <th>Existe</th>
                    <th>Colunas-chave</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($table_status as $table => $info): ?>
                    <tr>
                        <td><?= h($table) ?></td>
                        <td>
                            <?php if ($info['exists']): ?>
                                <span class="badge-ok">OK</span>
                            <?php else: ?>
                                <span class="badge-fail">FALTA</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (empty($info['columns'])): ?>
                                <span class="muted">—</span>
                            <?php else: ?>
                                <?php foreach ($info['columns'] as $col => $ok): ?>
                                    <span class="<?= $ok ? 'badge-ok' : 'badge-fail' ?>">
                                        <?= h($col) ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="diag-section">
        <h2>Runner diário (interno)</h2>
        <form method="POST">
            <input type="hidden" name="action" value="run_daily">
            <button class="btn-primary" type="submit">Rodar rotina agora</button>
        </form>
        <?php if ($run_result !== null): ?>
            <pre class="mono" style="margin-top:12px;white-space:pre-wrap"><?= h(json_encode($run_result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
        <?php endif; ?>
    </div>
</div>

<?php endSidebar(); ?>
