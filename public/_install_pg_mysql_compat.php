<?php
// _install_pg_mysql_compat.php — roda 1x e apaga
ini_set('display_errors', 1); error_reporting(E_ALL);
require_once __DIR__ . '/conexao.php';

/*
 MySQL usa DATABASE() nas consultas ao information_schema (TABLE_SCHEMA = DATABASE()).
 No Postgres não existe DATABASE(). Vamos criar um "alias" compatível:
   database() → current_schema()   (ex.: 'public')
 Isso faz TABLE_SCHEMA = database() virar TABLE_SCHEMA = 'public', que é o esperado.
*/
$sql = <<<SQL
CREATE OR REPLACE FUNCTION database()
RETURNS text
LANGUAGE sql
STABLE
AS $$ SELECT current_schema()::text $$;
SQL;

try {
  $pdo->exec($sql);
  echo "OK: função compatível 'database()' criada no Postgres.";
} catch (Throwable $e) {
  http_response_code(500);
  echo "Falhou ao criar função: " . $e->getMessage();
}
