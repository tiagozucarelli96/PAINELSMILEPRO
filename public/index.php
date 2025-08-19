<?php
declare(strict_types=1);

$debug = getenv('APP_DEBUG') === '1';
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');
error_reporting($debug ? E_ALL : (E_ALL & ~E_NOTICE));

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Health
if (isset($_GET['ping'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "PONG\nPHP ".PHP_VERSION."\n";
    exit;
}

// Diagnóstico
if (isset($_GET['diag'])) {
    $files = array_values(array_diff(scandir(__DIR__), ['.', '..']));
    ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Diag</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>body{font-family:system-ui;margin:0;background:#0b1220;color:#fff}
    .wrap{max-width:860px;margin:40px auto;padding:24px;background:#0f1b35;border-radius:16px}
    code{background:#0b1430;padding:2px 6px;border-radius:6px}</style></head><body>
    <div class="wrap">
      <h1>🔎 Diagnóstico</h1>
      <p>Dir: <code>/public</code></p>
      <ul><?php foreach ($files as $f): ?><li><code><?=htmlspecialchars($f)?></code></li><?php endforeach; ?></ul>
      <p><a href="/">Voltar</a> · <a href="/?ping=1">Ping</a></p>
    </div></body></html><?php
    exit;
}

// Só redireciona na RAIZ
if ($path === '/' || $path === '' || $path === '/index.php') {
    $target = 'login.php';
    if (is_file(__DIR__ . '/' . $target)) {
        header('Location: ' . $target, true, 302);
        exit;
    }
    http_response_code(500);
    echo "Arquivo de entrada ausente: public/{$target}";
    exit;
}

// Se chegou aqui é porque o router mandou algo inexistente pra cá
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "404 - Rota não encontrada\n";
