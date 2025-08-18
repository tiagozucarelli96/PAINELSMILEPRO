<?php
// index.php — roteador (inclui sidebar e páginas)
ini_set('display_errors', 1); error_reporting(E_ALL);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/config.php';

exigeLogin();
refreshPermissoes($pdo);

$page = $_GET['page'] ?? 'dashboard';

$routes = [
    'dashboard'            => 'dashboard-2.php',
    'tarefas'              => 'tarefas.php',
    'lista'                => 'lista_compras.php',
    'pagamentos'           => 'pagamentos.php',
    'admin_pagamentos'     => 'admin_pagamentos.php',
    'usuarios'             => 'usuarios.php',
    'usuario_novo'         => 'usuario_novo.php',
    'usuario_editar'       => 'usuario_editar.php',
    'portao'               => 'portao.php',

    // novas áreas (placeholders)
    'banco_smile'          => 'banco_smile.php',
    'banco_smile_admin'    => 'banco_smile_admin.php',
    'notas_fiscais'        => 'notas_fiscais.php',
    'estoque_logistico'    => 'estoque_logistico.php',
    'dados_contrato'       => 'dados_contrato.php',
    'uso_fiorino'          => 'uso_fiorino.php',
];

$arquivo = $routes[$page] ?? 'dashboard-2.php';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Painel Smile</title>
<link rel="stylesheet" href="estilo.css">
<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body>
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/' . $arquivo; ?>
  </div>
</body>
</html>
