<?php
// index.php — diagnóstico temporário (NÃO inclui outros arquivos)
declare(strict_types=1);
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
ini_set('log_errors','1');
ini_set('error_log','php://stderr');
error_reporting(E_ALL);

if (isset($_GET['ping'])) {
  header('Content-Type: text/plain; charset=utf-8');
  echo "PONG\nPHP ".PHP_VERSION."\n";
  exit;
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Painel Smile — OK</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#0b1220;color:#fff}
  .wrap{max-width:720px;margin:40px auto;padding:24px;background:#0f1b35;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.25)}
  h1{margin:0 0 12px;font-size:28px}
  a{color:#8ab4ff}
  code{background:#0b1430;padding:2px 6px;border-radius:6px}
</style>
</head>
<body>
  <div class="wrap">
    <h1>✅ Container no ar</h1>
    <p>Se você está vendo isto, o Railway está roteando corretamente para <code>/public</code>.</p>
    <ul>
      <li>PHP: <strong><?php echo PHP_VERSION; ?></strong></li>
      <li><a href="?ping=1">Teste rápido (PONG)</a></li>
    </ul>
    <p>Depois validado, voltamos ao seu app.</p>
  </div>
</body>
</html>
