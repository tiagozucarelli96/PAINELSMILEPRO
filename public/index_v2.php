<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap_v2.php';

exige_login();

// Mapeie apenas os arquivos que EXISTEM no /public
$rotas = [
    'dashboard'        => __DIR__ . '/dashboard.php',
    'usuarios'         => __DIR__ . '/usuarios.php',
    'usuario_novo'     => __DIR__ . '/usuario_novo.php',
    'usuario_editar'   => __DIR__ . '/usuario_editar.php',
    'tarefas'          => __DIR__ . '/tarefas.php',
    'pagamentos'       => __DIR__ . '/pagamentos.php',
    'pagamentos_admin' => __DIR__ . '/pagamentos_admin.php',
    'uso_fiorino'      => __DIR__ . '/uso_fiorino.php',
    'portao'           => __DIR__ . '/portao.php',
];

$page = preg_replace('/[^a-z0-9_]/i', '', (string)($_GET['page'] ?? 'dashboard'));
$alvo = $rotas[$page] ?? null;

if (!$alvo || !is_file($alvo)) {
    http_response_code(404);
    echo "<!doctype html><meta charset='utf-8'><style>body{font-family:system-ui;background:#0b1a33;color:#fff;padding:40px}</style><h1>404</h1><p>Página não encontrada.</p>";
    exit;
}

// Se tiver layout/sidebar, inclua aqui: require __DIR__.'/sidebar.php';
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Painel Smile — <?=htmlspecialchars($page)?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="/favicon.ico">
<link rel="stylesheet" href="/estilo.css">
</head>
<body>
<?php require $alvo; ?>
</body>
</html>
