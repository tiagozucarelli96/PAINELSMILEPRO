<?php
/**
 * Rota dedicada para o modelo de lançamento do Portal do Cliente.
 */
define('CLIENTE_NOTIFICACOES_PORTAL_ROUTE', true);

$_GET['page'] = 'cliente_notificacoes';
$_GET['modelo'] = '26';
$_GET['modelo_chave'] = 'portal_cliente_lancamento';
$_REQUEST['page'] = 'cliente_notificacoes';
$_REQUEST['modelo'] = '26';
$_REQUEST['modelo_chave'] = 'portal_cliente_lancamento';

$queryParams = [];
parse_str((string)($_SERVER['QUERY_STRING'] ?? ''), $queryParams);
$queryParams['modelo'] = '26';
$queryParams['modelo_chave'] = 'portal_cliente_lancamento';
$_SERVER['QUERY_STRING'] = http_build_query($queryParams);

require __DIR__ . '/cliente_notificacoes.php';
