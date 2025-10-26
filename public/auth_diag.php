<?php
declare(strict_types=1);
ini_set('display_errors','1'); error_reporting(EALL);
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
header('Content-Type: text/plain; charset=utf-8');

$info = $pdo->query("select current_database() db, current_schema() sch, current_schemas(true) schs")->fetch();
echo "DB: {$info['db']}\nSchema atual: {$info['sch']}\nSearch path: {".implode(',', $info['schs'])."}\n\n";

// mostra colunas de usuarios
$cols = $pdo->query("
  select column_name from information_schema.columns
  where table_schema=current_schema() and table_name='usuarios' order by 1
")->fetchAll(PDO::FETCH_COLUMN);
echo "usuarios cols: ".implode(', ',$cols)."\n";
