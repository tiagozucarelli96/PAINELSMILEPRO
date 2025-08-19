<?php
declare(strict_types=1);
ini_set('display_errors','1'); error_reporting(E_ALL);
session_start();
require_once __DIR__ . '/conexao.php';
header('Content-Type: text/html; charset=utf-8');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// DB/Schemas/Tabelas (ignora schemas do sistema)
$db   = $pdo->query("select current_database() as db")->fetch()['db'] ?? '?';
$schs = $pdo->query("
  select nspname as schema
  from pg_namespace
  where nspname not in ('pg_catalog','information_schema','pg_toast')
  order by 1
")->fetchAll(PDO::FETCH_COLUMN);

// Todas as tabelas visíveis nesses schemas
$tbls = $pdo->query("
  select schemaname, tablename
  from pg_catalog.pg_tables
  where schemaname not in ('pg_catalog','information_schema','pg_toast')
  order by schemaname, tablename
")->fetchAll(PDO::FETCH_ASSOC);

// Expected (as principais do app)
$expected = [
  'usuarios',
  'lc_categorias','lc_itens','lc_fornecedores','lc_fichas','lc_ficha_componentes',
  'lc_itens_fixos','lc_pref_encomendas',
  'lc_geracoes','lc_geracoes_input','lc_lista_eventos',
  'lc_compras_consolidadas','lc_encomendas_itens','lc_listas'
];

echo "<h2>DB atual: <code>".h($db)."</code></h2>";
echo "<p>Schemas visíveis: <code>".h(implode(', ', $schs))."</code></p>";

if (!$tbls) {
  echo "<p>❌ Nenhuma tabela de usuário encontrada.</p>";
  exit;
}

echo "<h3>Tabelas encontradas</h3>";
echo "<ul>";
foreach ($tbls as $t) {
  $qn = $pdo->query("select count(*) from {$t['schemaname']}.".$t['tablename'])->fetchColumn();
  echo "<li><code>".h($t['schemaname'].".".$t['tablename'])."</code> — linhas: ".(int)$qn."</li>";
}
echo "</ul>";

// Checagem de “faltantes” no schema public
$have = array_map(fn($t)=>$t['tablename'], array_filter($tbls, fn($t)=>$t['schemaname']==='public'));
$missing = array_values(array_diff($expected, $have));

echo "<h3>Checagem do schema <code>public</code></h3>";
if ($missing) {
  echo "<p>⚠️ Faltando no <code>public</code>:</p><ul>";
  foreach ($missing as $m) echo "<li><code>$m</code></li>";
  echo "</ul>";
} else {
  echo "<p>✅ Todas as tabelas esperadas do app estão no <code>public</code>.</p>";
}
