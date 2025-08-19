<?php
// public/index.php — roteador simples + diagnóstico
declare(strict_types=1);

// Debug controlado por env (APP_DEBUG=1)
$debug = getenv('APP_DEBUG') === '1';
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');
error_reporting($debug ? E_ALL : E_ALL & ~E_NOTICE);

// Ping simples (para health)
if (isset($_GET['ping'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "PONG\nPHP ".PHP_VERSION."\n";
    exit;
}

// Página de diagnóstico opcional
if (isset($_GET['diag'])) {
    $files = array_values(array_diff(scandir(__DIR__), ['.', '..']));
    ?><!doctype html>
    <html lang="pt-BR"><head><meta charset="utf-8"><title>Diag</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>body{font-family:system-ui;margin:0;background:#0b1220;color:#fff}
    .wrap{max-width:860px;margin:40px auto;padding:24px;background:#0f1b35;border-radius:16px}
    code{background:#0b1430;padding:2px 6px;border-radius:6px}</style></head><body>
    <div class="wrap">
      <h1>🔎 Diagnóstico</h1>
      <p>Dir: <code>/public</code></p>
      <ul><?php foreach ($files as $f): ?><li><code><?=htmlspecialchars($f)?></code></li><?php endforeach; ?></ul>
      <p><a href="/">Voltar</a> &middot; <a href="/?ping=1">Ping</a></p>
    </div></body></html><?php
    exit;
}

// Fluxo normal: manda pro login (mantém o comportamento atual)
$target = 'login.php';
if (is_file(__DIR__ . '/' . $target)) {
    header('Location: ' . $target, true, 302);
    exit;
}

// Se não houver login.php, mostra mensagem clara
http_response_code(500);
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><title>Erro de entrada</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>body{font-family:system-ui;margin:0;background:#0b1220;color:#fff}
.wrap{max-width:860px;margin:40px auto;padding:24px;background:#0f1b35;border-radius:16px}</style></head><body>
<div class="wrap">
  <h1>❌ Arquivo de entrada não encontrado</h1>
  <p>Esperado: <code>public/login.php</code>. Verifique os arquivos em <a href="/?diag=1">/public</a>.</p>
</div></body></html>
