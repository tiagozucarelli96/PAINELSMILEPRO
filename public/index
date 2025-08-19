<?php
declare(strict_types=1);
/teste
/**
 * Index — roteador canônico (sem loops)
 * - Usa suas deps originais
 * - Mantém exigeLogin()/refreshPermissoes() se existirem
 * - Mapeia os arquivos reais que você TEM no /public (conforme o probe)
 * - Buffer para evitar "headers already sent"
 */

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/config.php';

// Segurança/permissões do seu projeto (se existirem)
if (function_exists('exigeLogin')) exigeLogin();
if (function_exists('refreshPermissoes')) {
  try { refreshPermissoes($pdo); } catch (Throwable $e) {
    if (getenv('APP_DEBUG') === '1') error_log('[refreshPermissoes] '.$e->getMessage());
  }
}

// Página solicitada
$page = (string)($_GET['page'] ?? 'dashboard');
$page = preg_replace('/[^a-z0-9_\-]/i', '', $page);

// Mapa de rotas -> arquivos reais (baseado na listagem do _route_probe.php)
$routes = [
  'dashboard'           => __DIR__ . '/dashboard.php',
  'dashboard-2'         => __DIR__ . '/dashboard.php',
  'dashboard2'          => __DIR__ . '/dashboard.php',

  'tarefas'             => __DIR__ . '/tarefas.php',
  'usuarios'            => __DIR__ . '/usuarios.php',
  'usuario_novo'        => __DIR__ . '/usuario_novo.php',
  'usuario_editar'      => __DIR__ . '/usuario_editar.php',

  'pagamentos'          => __DIR__ . '/pagamentos.php',
  'admin_pagamentos'    => __DIR__ . '/admin_pagamentos.php',

  'uso_fiorino'         => __DIR__ . '/uso_fiorino.php',
  'portao'              => __DIR__ . '/portao.php',
  'demandas'            => __DIR__ . '/demandas.php',

  'notas_fiscais'       => __DIR__ . '/notas_fiscais.php',
  'estoque_logistico'   => __DIR__ . '/estoque_logistico.php',
  'dados_contrato'      => __DIR__ . '/dados_contrato.php',

  'banco_smile'         => __DIR__ . '/banco_smile.php',
  'banco_smile_admin'   => __DIR__ . '/banco_smile_admin.php',

  // Aliases para seus nomes reais
  'lista'               => __DIR__ . '/lista_compras.php',
  'lista_compras'       => __DIR__ . '/lista_compras.php',
  'lista_gerar'         => __DIR__ . '/lista_compras_gerar.php',
  'lista_lixeira'       => __DIR__ . '/lista_compras_lixeira.php',
];

// Resolve alvo
$alvo = $routes[$page] ?? null;

// Fallback: tenta page.php e page-2.php se existirem
if (!$alvo || !is_file($alvo)) {
  foreach ([__DIR__."/$page.php", __DIR__."/$page-2.php"] as $c) {
    if (is_file($c)) { $alvo = $c; break; }
  }
}

// 404 se não achou
if (!$alvo || !is_file($alvo)) {
  http_response_code(404);
  echo "<!doctype html><meta charset='utf-8'><style>body{font-family:system-ui;background:#0b1a33;color:#fff;padding:40px}</style><h1>404</h1><p>Página não encontrada.</p>";
  if (getenv('APP_DEBUG') === '1') error_log("[route404] page=$page");
  exit;
}

// Buffer — evita warnings se a página der header('Location: ...')
ob_start();
require $alvo;
$conteudo = ob_get_clean();

// Se houve redirect, não imprime layout
foreach (headers_list() as $h) { if (stripos($h, 'Location:') === 0) exit; }
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Painel Smile — <?=htmlspecialchars($page)?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="/favicon.ico">
<link rel="stylesheet" href="/estilo.css">
</head>
<body>
<?php if (is_file(__DIR__ . '/sidebar.php')) require __DIR__ . '/sidebar.php'; ?>
<?= $conteudo ?>
</body>
</html>
