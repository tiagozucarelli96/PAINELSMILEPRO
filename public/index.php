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
  'dashboard'           => 'sidebar_unified.php',
  'comercial'           => 'comercial_degustacoes.php',
  'logistico'           => 'lc_index.php',
  'configuracoes'       => 'configuracoes.php',
  'cadastros'           => 'usuarios.php',
  'financeiro'          => 'pagamentos.php',
  'administrativo'      => 'config_insumos.php',
  'tarefas'             => 'tarefas.php',
  'lista'               => 'lista_compras.php',
  'lista_compras'       => 'lista_compras.php',
  'lc_index'            => 'lc_index.php',
  'pagamentos'          => 'pagamentos.php',
  'admin_pagamentos'    => 'admin_pagamentos.php',
  'usuarios'            => 'usuarios.php',
  'config_fornecedores' => 'config_fornecedores.php',
  'config_insumos'      => 'config_insumos.php',
  'comercial_degustacoes' => 'comercial_degustacoes.php',
  'comercial_degust_inscritos' => 'comercial_degust_inscritos.php',
  'comercial_degustacao_editar' => 'comercial_degustacao_editar.php',
  'comercial_degust_public' => 'comercial_degust_public.php',
  'comercial_pagamento' => 'comercial_pagamento.php',
  'estoque_alertas' => 'estoque_alertas.php',
  'estoque_kardex' => 'estoque_kardex.php',
  'estoque_contagens' => 'estoque_contagens.php',
  'config_categorias' => 'config_categorias.php',
  'config_fichas' => 'config_fichas.php',
  'config_itens' => 'config_itens.php',
  'config_itens_fixos' => 'config_itens_fixos.php',
  'usuario_novo' => 'usuario_novo.php',
  'usuario_editar' => 'usuario_editar.php',
  'pagamentos_solicitar' => 'pagamentos_solicitar.php',
  'pagamentos_painel' => 'pagamentos_painel.php',
  'pagamentos_minhas' => 'pagamentos_minhas.php',
  'pagamentos_ver' => 'pagamentos_ver.php',
  'freelancer_cadastro' => 'freelancer_cadastro.php',
  'fornecedores' => 'fornecedores.php',
  'fornecedor_link' => 'fornecedor_link.php',
  'lc_pdf' => 'lc_pdf.php',
  'ver'                 => 'ver.php',
  'portao'              => 'portao.php',
  'banco_smile'         => 'banco_smile.php',
  'banco_smile_admin'   => 'banco_smile_admin.php',
  'notas_fiscais'       => 'notas_fiscais.php',
  'estoque_logistico'   => 'estoque_logistico.php',
  'dados_contrato'      => 'dados_contrato.php',
  'uso_fiorino'         => 'uso_fiorino.php',
  'test_sidebar'        => 'test_sidebar.php',
  'agenda'              => 'agenda.php',
  'agenda_config'       => 'agenda_config.php',
  'agenda_relatorios'   => 'agenda_relatorios.php',
  'demandas'            => 'demandas.php',
  'webhook_me_eventos' => 'webhook_me_eventos.php',
  'contab_link'         => 'contab_link.php',
  'contab_gerar_link'   => 'contab_gerar_link.php',
  'config_categorias'   => 'config_categorias.php',
  'verificacao_completa_erros' => 'verificacao_completa_erros.php',
  'test_dashboard_connection' => 'test_dashboard_connection.php',
  'test_pages' => 'test_pages.php',
  'diagnostic_pages' => 'diagnostic_pages.php',
  'fix_old_sidebars' => 'fix_old_sidebars.php',
  'diagnostic_completo' => 'diagnostic_completo.php',
  'fix_all_pages' => 'fix_all_pages.php',
  'fix_critical_issues' => 'fix_critical_issues.php',
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
