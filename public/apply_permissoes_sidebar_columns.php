<?php
// Script para aplicar as novas colunas de permissões no banco de dados
// Execute este arquivo uma vez para adicionar as colunas

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

// Verificar se é admin
if (empty($_SESSION['logado']) || (empty($_SESSION['perm_configuracoes']) && empty($_SESSION['is_admin']))) {
    die('Acesso negado. É necessário ter permissão de configurações para executar este script.');
}

$sql_file = __DIR__ . '/../sql/add_permissoes_sidebar_columns.sql';

if (!file_exists($sql_file)) {
    die("Arquivo SQL não encontrado: $sql_file");
}

$sql_content = file_get_contents($sql_file);

// Dividir por ponto e vírgula para executar comandos separadamente
$commands = array_filter(array_map('trim', explode(';', $sql_content)));

$errors = [];
$success = [];

echo "<!DOCTYPE html>
<html lang='pt-BR'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Adicionar Colunas de Permissões</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1e3a8a;
            margin-bottom: 20px;
        }
        .success {
            color: #059669;
            background: #d1fae5;
            padding: 10px;
            border-radius: 4px;
            margin: 5px 0;
        }
        .error {
            color: #dc2626;
            background: #fee2e2;
            padding: 10px;
            border-radius: 4px;
            margin: 5px 0;
        }
        .info {
            color: #64748b;
            background: #f1f5f9;
            padding: 10px;
            border-radius: 4px;
            margin: 5px 0;
        }
        .btn {
            display: inline-block;
            background: #1e3a8a;
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🔧 Adicionar Colunas de Permissões</h1>";

try {
    foreach ($commands as $command) {
        if (empty($command) || strpos($command, '--') === 0) {
            continue; // Pular comentários e linhas vazias
        }
        
        try {
            $pdo->exec($command);
            $success[] = "Comando executado com sucesso";
        } catch (PDOException $e) {
            // Ignorar erros de coluna já existe ou índice já existe
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'duplicate') !== false) {
                $success[] = "Coluna/índice já existe (ignorado)";
            } else {
                $errors[] = "Erro: " . $e->getMessage() . " - Comando: " . substr($command, 0, 50) . "...";
            }
        }
    }
    
    echo "<div class='info'>✅ Script executado!</div>";
    
    if (!empty($success)) {
        echo "<h3>✅ Sucessos:</h3>";
        foreach ($success as $msg) {
            echo "<div class='success'>$msg</div>";
        }
    }
    
    if (!empty($errors)) {
        echo "<h3>❌ Erros:</h3>";
        foreach ($errors as $error) {
            echo "<div class='error'>$error</div>";
        }
    } else {
        echo "<div class='success'>🎉 Todas as colunas de permissões foram adicionadas com sucesso!</div>";
        echo "<div class='info'>Agora você pode atribuir permissões aos usuários através da página de usuários.</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='error'>Erro fatal: " . $e->getMessage() . "</div>";
}

echo "
        <a href='index.php?page=usuarios' class='btn'>Voltar para Usuários</a>
        <a href='index.php?page=dashboard' class='btn' style='background: #64748b;'>Voltar para Dashboard</a>
    </div>
</body>
</html>";
?>



