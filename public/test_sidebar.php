<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// Simula um usuário logado para teste
$_SESSION['logado'] = true;
$_SESSION['user_id'] = 1;
$_SESSION['perm_tarefas'] = true;
$_SESSION['perm_lista'] = true;
$_SESSION['perm_demandas'] = true;
$_SESSION['perm_portao'] = true;
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Teste Sidebar</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="estilo.css">
</head>
<body class="panel">
<?php if (is_file(__DIR__.'/sidebar.php')) include __DIR__.'/sidebar.php'; ?>
<div class="main-content">
    <h1>Teste da Sidebar</h1>
    <p>Se você consegue ver esta página com a sidebar à esquerda e o conteúdo à direita, a correção funcionou!</p>
    <p>A sidebar deve estar azul com o logo e os links de navegação.</p>
</div>
</body>
</html>
