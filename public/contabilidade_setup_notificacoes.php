<?php
// contabilidade_setup_notificacoes.php — Executar schema de notificações
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Permitir acesso apenas para administradores ou via token
$token_valido = isset($_GET['token']) && $_GET['token'] === getenv('SETUP_TOKEN');
$is_admin = !empty($_SESSION['logado']) && !empty($_SESSION['perm_administrativo']);

if (!$token_valido && !$is_admin) {
    http_response_code(403);
    die('Acesso negado');
}

require_once __DIR__ . '/conexao.php';

$pdo = $GLOBALS['pdo'];
$erros = [];
$sucessos = [];

// Ler e executar o arquivo SQL
$sql_file = __DIR__ . '/../sql/contabilidade_notificacoes_schema.sql';

if (!file_exists($sql_file)) {
    die("Arquivo SQL não encontrado: $sql_file");
}

$sql_content = file_get_contents($sql_file);

// Dividir em comandos individuais
$commands = array_filter(
    array_map('trim', explode(';', $sql_content)),
    function($cmd) {
        return !empty($cmd) && !preg_match('/^\s*--/', $cmd) && !preg_match('/^\s*\/\*/', $cmd);
    }
);

foreach ($commands as $command) {
    if (empty(trim($command))) continue;
    
    try {
        $pdo->exec($command);
        $sucessos[] = "Comando executado com sucesso";
    } catch (PDOException $e) {
        $erros[] = "Erro: " . $e->getMessage() . " (Comando: " . substr($command, 0, 100) . "...)";
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Setup - Sistema de Notificações</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        .success { color: green; margin: 10px 0; }
        .error { color: red; margin: 10px 0; }
        h1 { color: #1e40af; }
    </style>
</head>
<body>
    <h1>📧 Setup - Sistema de Notificações</h1>
    
    <?php if (!empty($sucessos)): ?>
        <div class="success">
            <strong>✅ Sucessos:</strong>
            <ul>
                <?php foreach ($sucessos as $sucesso): ?>
                    <li><?= htmlspecialchars($sucesso) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($erros)): ?>
        <div class="error">
            <strong>❌ Erros:</strong>
            <ul>
                <?php foreach ($erros as $erro): ?>
                    <li><?= htmlspecialchars($erro) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <?php if (empty($erros)): ?>
        <p><strong>✅ Setup concluído com sucesso!</strong></p>
    <?php endif; ?>
    
    <p><a href="index.php?page=contabilidade">← Voltar para Contabilidade</a></p>
</body>
</html>
