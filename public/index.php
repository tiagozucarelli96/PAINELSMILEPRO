<?php
declare(strict_types=1);

session_start();

/* ===== ATALHO TEMPORÁRIO (REMOVER APÓS O TESTE) ===== */
if (
  (isset($_GET['route']) && $_GET['route'] === 'me_proxy') ||
  (isset($_GET['page'])  && $_GET['page']  === 'me_proxy')
) {
  header('Content-Type: application/json; charset=utf-8');
  require __DIR__ . '/me_proxy.php';
  exit;
}
/* ===== FIM DO ATALHO TEMPORÁRIO ===== */

$debug = getenv('APP_DEBUG') === '1';
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');
error_reporting($debug ? E_ALL : (E_ALL & ~E_NOTICE));

/* utilidades */
if (isset($_GET['ping'])) {
  header('Content-Type: text/plain; charset=utf-8');
  echo "PONG\nPHP ".PHP_VERSION."\n";
  exit;
}
if (isset($_GET['diag'])) {
  $files = array_values(array_diff(scandir(__DIR__), ['.', '..']));
  ?><!doctype html><meta charset="utf-8"><title>Diag</title><body style="font-family:system-ui;background:#0b1220;color:#fff">
  <div style="max-width:860px;margin:40px auto;padding:24px;background:#0f1b35;border-radius:16px">
    <h1>/public</h1><ul><?php foreach ($files as $f): ?><li><code><?=htmlspecialchars($f)?></code></li><?php endforeach; ?></ul>
    <p><a href="/">Voltar</a></p>
  </div></body><?php
  exit;
}

/* sem ?page -> manda para login ou dashboard */
$page = $_GET['page'] ?? '';
if ($page === '' || $page === null) {
  if (!empty($_SESSION['logado'])) {
    header('Location: index.php?page=dashboard');
  } else {
    header('Location: login.php');
  }
  exit;
}

/* rotas permitidas */
$routes = [
  'dashboard'           => 'dashboard2.php',
  'tarefas'             => 'tarefas.php',
  'lista'               => 'lista_compras.php',
  'pagamentos'          => 'pagamentos.php',
  'admin_pagamentos'    => 'admin_pagamentos.php',
  'usuarios'            => 'usuarios.php',
  'portao'              => 'portao.php',
  'banco_smile'         => 'banco_smile.php',
  'banco_smile_admin'   => 'banco_smile_admin.php',
  'notas_fiscais'       => 'notas_fiscais.php',
  'estoque_logistico'   => 'estoque_logistico.php',
  'dados_contrato'      => 'dados_contrato.php',
  'uso_fiorino'         => 'uso_fiorino.php',
  'test_sidebar'        => 'test_sidebar.php',
];

/* exige login */
if (empty($_SESSION['logado'])) {
  header('Location: login.php');
  exit;
}

/* >>> popula as permissões da sessão sem mexer no login */
require __DIR__ . '/permissoes_boot.php';

/* resolve e inclui a página */
$file = $routes[$page] ?? null;
$path = $file ? (__DIR__.'/'.$file) : null;

if ($path && is_file($path)) {
  require $path;
  exit;
}

/* 404 simples */
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "404 - Rota não encontrada";
