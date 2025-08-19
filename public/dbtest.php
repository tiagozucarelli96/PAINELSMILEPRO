<?php
declare(strict_types=1);
ini_set('display_errors','1'); ini_set('display_startup_errors','1');
ini_set('log_errors','1'); ini_set('error_log','php://stderr'); error_reporting(E_ALL);

// Tenta achar conexao.php em locais comuns
$tries = [
  __DIR__ . '/../conexao.php',      // raiz do repo (/app/conexao.php)
  __DIR__ . '/conexao.php',         // dentro de /public
  dirname(__DIR__) . '/conexao.php' // fallback
];
$found = false;
foreach ($tries as $p) {
  if (is_file($p)) { require_once $p; $found = true; break; }
}
if (!$found) {
  header('Content-Type: text/plain; charset=utf-8');
  echo "❌ conexao.php não encontrado.\nProcurado em:\n- " . implode("\n- ", $tries) . "\n";
  exit(1);
}

header('Content-Type: text/plain; charset=utf-8');

if (!isset($pdo) || !$pdo) {
  echo "❌ Sem conexão. \$db_error: " . (($db_error ?? '') ?: 'desconhecido') . "\n";
  exit(1);
}

try {
  $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  echo "✅ Conectado via PDO ({$driver})\n";

  if ($driver === 'pgsql') {
    $v = $pdo->query("select current_database() as db, version() as ver")->fetch();
    echo "DB: {$v['db']}\n{$v['ver']}\n\nTabelas em public:\n";
    $q = $pdo->query("select table_name from information_schema.tables where table_schema='public' order by 1");
    foreach ($q as $r) echo "- {$r['table_name']}\n";
  } else {
    echo "Aviso: driver inesperado: {$driver}\n";
  }
} catch (Throwable $e) {
  echo "❌ Erro ao consultar: ".$e->getMessage()."\n";
  exit(1);
}
