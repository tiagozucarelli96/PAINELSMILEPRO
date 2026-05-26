<?php
/**
 * Rota dedicada para o modelo de lançamento do Portal do Cliente.
 */
$_GET['page'] = 'cliente_notificacoes';
$_GET['modelo_chave'] = 'portal_cliente_lancamento';

$queryParams = [];
parse_str((string)($_SERVER['QUERY_STRING'] ?? ''), $queryParams);
$queryParams['modelo_chave'] = 'portal_cliente_lancamento';
$_SERVER['QUERY_STRING'] = http_build_query($queryParams);

require __DIR__ . '/cliente_notificacoes.php';

