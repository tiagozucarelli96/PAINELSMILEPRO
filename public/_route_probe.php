<?php
// _route_probe.php — diagnóstico rápido de rotas/arquivos (APAGAR DEPOIS)
header('Content-Type: text/plain; charset=utf-8');

echo "PHP __FILE__: ".__FILE__."\n";
echo "PHP __DIR__ : ".__DIR__."\n\n";

// Mostra se dashboard.php e dashboard-2.php existem
$targets = [
  __DIR__.'/dashboard.php',
  __DIR__.'/dashboard-2.php',
  __DIR__.'/Dashboard.php',
  __DIR__.'/Dashboard-2.php',
  __DIR__.'/index.php',
];
foreach ($targets as $t) {
  echo basename($t).": ".(is_file($t) ? "FOUND" : "MISSING");
  if (is_file($t)) { echo "  realpath=".realpath($t); }
  echo "\n";
}

// Lista .php do diretório public
echo "\n=== PHP files in /public ===\n";
$all = array_filter(scandir(__DIR__), fn($f) => substr($f, -4) === '.php');
sort($all);
foreach ($all as $f) {
  $p = __DIR__.'/'.$f;
  echo $f."  ".(is_file($p) ? "file" : "nope")."\n";
}
