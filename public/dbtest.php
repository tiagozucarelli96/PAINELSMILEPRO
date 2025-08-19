<?php
declare(strict_types=1);
ini_set('display_errors','1'); ini_set('display_startup_errors','1');
ini_set('log_errors','1'); ini_set('error_log','php://stderr'); error_reporting(E_ALL);

require_once __DIR__ . '/../conexao.php';

header('Content-Type: text/plain; charset=utf-8');

if (!$pdo) {
  echo "❌ Sem conexão. \$db_error: " . ($db_error ?: 'desconhecido') . "\n";
  exit(1);
}

try {
  echo "✅ Conectado via PDO (pgsql)\n";
  $v = $pdo->query("select current_database() as db, version() as ver")->fetch();
  echo "DB: {$v['db']}\n{$v['ver']}\n\nTabelas (schema public):\n";
  $q = $pdo->query("select table_name from information_schema.tables where table_schema='public' order by 1");
  foreach ($q as $r) echo "- {$r['table_name']}\n";
} catch (Throwable $e) {
  echo "❌ Erro ao consultar: ".$e->getMessage()."\n";
  exit(1);
}
