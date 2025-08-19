<?php
declare(strict_types=1);
ini_set('display_errors','1'); ini_set('display_startup_errors','1');
ini_set('log_errors','1'); ini_set('error_log','php://stderr'); error_reporting(E_ALL);

/**
 * Gera MANIFEST.txt listando todos os arquivos do repositório, sem terminal.
 * - Varre a partir da raiz real do projeto (/app).
 * - Salva em /public/MANIFEST.txt para você baixar no navegador.
 */

$ROOT = realpath(__DIR__ . '/..');        // /app
$OUT  = __DIR__ . '/MANIFEST.txt';        // /app/public/MANIFEST.txt (baixável)
$ignores = [
  '/.git/', '/.github/', '/.idea/', '/.vscode/', '/node_modules/', '/vendor/',
  '/.cache/', '/.DS_Store', '/.env', '/.env.local'
];

function should_ignore(string $rel, array $ignores): bool {
  foreach ($ignores as $pat) {
    if (str_contains($rel, $pat)) return true;
  }
  return false;
}

$generated = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
  $files = [];
  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS)
  );
  foreach ($it as $file) {
    /** @var SplFileInfo $file */
    if ($file->isFile()) {
      $abs = $file->getPathname();
      $rel = str_replace('\\', '/', substr($abs, strlen($ROOT))); // caminho relativo a /app
      if (should_ignore($rel, $ignores)) continue;
      // pula o próprio MANIFEST.txt dentro de /public
      if ($rel === '/public/MANIFEST.txt') continue;
      $files[] = ltrim($rel, '/');
    }
  }
  sort($files, SORT_STRING);
  $content = implode("\n", $files) . "\n";
  file_put_contents($OUT, $content);
  $generated = true;
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Gerar MANIFEST.txt</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#0b1220;color:#fff}
  .wrap{max-width:860px;margin:40px auto;padding:24px;background:#0f1b35;border-radius:16px}
  .btn{background:#2563eb;color:#fff;border:none;border-radius:10px;padding:10px 16px;font-weight:600;cursor:pointer}
  .ok{padding:10px 12px;border-radius:10px;background:#0e7a3b;margin:16px 0}
  a{color:#8ab4ff}
  code{background:#0b1430;padding:2px 6px;border-radius:6px}
  .muted{opacity:.8}
</style>
</head>
<body>
<div class="wrap">
  <h1>📦 Gerar MANIFEST.txt</h1>
  <p class="muted">Lista todos os arquivos do projeto a partir de <code><?=htmlspecialchars($ROOT)?></code></p>

  <form method="post">
    <input type="hidden" name="generate" value="1">
    <button class="btn" type="submit">Gerar/Atualizar MANIFEST.txt</button>
  </form>

  <?php if ($generated): ?>
    <div class="ok">✅ Manifesto gerado com sucesso.</div>
    <p>Baixe aqui: <a href="/MANIFEST.txt" target="_blank"><code>/MANIFEST.txt</code></a></p>
    <p class="muted">O arquivo fica em <code><?=htmlspecialchars($OUT)?></code></p>
  <?php else: ?>
    <p>Após gerar, o arquivo ficará disponível em <code>/MANIFEST.txt</code> para download.</p>
  <?php endif; ?>
</div>
</body>
</html>
