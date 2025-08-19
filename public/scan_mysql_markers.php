<?php
declare(strict_types=1);
ini_set('display_errors','1'); ini_set('display_startup_errors','1');
ini_set('log_errors','1'); ini_set('error_log','php://stderr'); error_reporting(E_ALL);

$ROOT = realpath(__DIR__ . '/..'); // /app
$patterns = [
  'mysqli_' => '/\bmysqli_\w+\s*\(/i',
  'mysql_'  => '/\bmysql_\w+\s*\(/i',
  'crases'  => '/`[^`]+`/',
  'AUTO_INCREMENT' => '/\bAUTO_INCREMENT\b/i',
  'ENGINE=' => '/\bENGINE\s*=\s*\w+/i',
  'UNSIGNED'=> '/\bUNSIGNED\b/i',
  'IFNULL(' => '/\bIFNULL\s*\(/i',   // usar COALESCE
  'NOW()'   => '/\bNOW\s*\(\s*\)/i', // usar CURRENT_TIMESTAMP
  'LIMIT x,y'=> '/\bLIMIT\s+\d+\s*,\s*\d+\b/i', // usar LIMIT y OFFSET x
  'FROM_UNIXTIME' => '/\bFROM_UNIXTIME\s*\(/i',
  'UNIX_TIMESTAMP'=> '/\bUNIX_TIMESTAMP\s*\(/i',
];

$hits = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
  /** @var SplFileInfo $file */
  if (!$file->isFile()) continue;
  $path = str_replace('\\','/',$file->getPathname());
  if (str_contains($path, '/.git/')) continue;
  if (!preg_match('/\.(php|sql|inc|phtml)$/i', $path)) continue;

  $rel = ltrim(str_replace($ROOT, '', $path), '/');
  $content = @file_get_contents($path);
  if ($content === false) continue;

  $lines = explode("\n", $content);
  $foundAny = false;
  foreach ($lines as $i => $line) {
    foreach ($patterns as $label => $regex) {
      if (preg_match($regex, $line)) {
        $hits[$rel][] = [
          'line' => $i+1,
          'label'=> $label,
          'code' => trim($line)
        ];
        $foundAny = true;
      }
    }
  }
}

?><!doctype html>
<html lang="pt-BR">
<head><meta charset="utf-8"><title>Scanner MySQL → Postgres</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:system-ui;margin:0;background:#0b1220;color:#fff}
.wrap{max-width:1100px;margin:32px auto;padding:24px;background:#0f1b35;border-radius:16px}
.card{background:#0b1430;border-radius:12px;margin:12px 0;padding:12px}
.badge{display:inline-block;background:#334155;border-radius:999px;padding:2px 8px;margin-right:6px}
small{opacity:.8}
code{background:#0b1430;padding:2px 6px;border-radius:6px}
h2{margin:6px 0 0}
</style></head>
<body><div class="wrap">
<h1>🔎 Scanner de sintaxe MySQL → Postgres</h1>
<p>Raiz: <code><?=htmlspecialchars($ROOT)?></code></p>
<?php if (!$hits): ?>
  <p class="card">✅ Nenhum padrão MySQL suspeito encontrado.</p>
<?php else: foreach ($hits as $file => $items): ?>
  <div class="card">
    <h2><?=htmlspecialchars($file)?></h2>
    <?php foreach ($items as $h): ?>
      <div><span class="badge"><?=htmlspecialchars($h['label'])?></span>
      <small>L<?=intval($h['line'])?></small> — <code><?=htmlspecialchars($h['code'])?></code></div>
    <?php endforeach; ?>
  </div>
<?php endforeach; endif; ?>
</div></body></html>
