<?php
/**
 * Script para criar estrutura do banco de dados do módulo Contabilidade
 * Execute este arquivo uma vez para criar todas as tabelas necessárias
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Verificar se está logado e tem permissão
if (empty($_SESSION['logado']) || empty($_SESSION['perm_administrativo'])) {
    die("❌ ERRO: Você precisa estar logado e ter permissão administrativa para executar este script.");
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

// Ler o arquivo SQL
$sql_file = __DIR__ . '/../sql/contabilidade_schema.sql';
if (!file_exists($sql_file)) {
    die("❌ ERRO: Arquivo SQL não encontrado: $sql_file");
}

$sql_content = file_get_contents($sql_file);

// Dividir em comandos individuais
$commands = array_filter(
    array_map('trim', explode(';', $sql_content)),
    function($cmd) {
        return !empty($cmd) && !preg_match('/^--/', $cmd);
    }
);

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Banco - Contabilidade</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            padding: 2rem;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 2rem;
        }
        h1 { color: #1e3a8a; margin-bottom: 1rem; }
        .success { background: #d1fae5; color: #065f46; padding: 1rem; border-radius: 8px; margin: 1rem 0; }
        .error { background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 8px; margin: 1rem 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Setup Banco de Dados - Contabilidade</h1>
        
        <?php
        $erros = [];
        $sucessos = [];
        
        try {
            foreach ($commands as $command) {
                if (empty(trim($command))) continue;
                
                try {
                    $pdo->exec($command);
                    $sucessos[] = "Comando executado com sucesso";
                } catch (PDOException $e) {
                    // Ignorar erros de "já existe"
                    if (strpos($e->getMessage(), 'already exists') === false && 
                        strpos($e->getMessage(), 'duplicate') === false) {
                        $erros[] = $e->getMessage();
                    } else {
                        $sucessos[] = "Tabela/índice já existe (ignorado)";
                    }
                }
            }
            
            if (empty($erros)) {
                echo "<div class='success'><strong>✅ Setup concluído com sucesso!</strong><br>";
                echo "Todas as tabelas do módulo Contabilidade foram criadas.</div>";
            }
            
        } catch (Exception $e) {
            echo "<div class='error'><strong>❌ Erro:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        
        if (!empty($erros)) {
            echo "<div class='error'><strong>⚠️ Avisos:</strong><br>";
            foreach ($erros as $erro) {
                echo htmlspecialchars($erro) . "<br>";
            }
            echo "</div>";
        }
        ?>
        
        <div style="margin-top: 2rem; padding: 1rem; background: #f9fafb; border-radius: 8px;">
            <strong>📝 Próximos passos:</strong><br>
            1. Acesse a página de Contabilidade no menu Administrativo<br>
            2. Configure o acesso da contabilidade<br>
            3. Teste o login externo<br><br>
            <a href="index.php?page=contabilidade" style="color: #1e3a8a; text-decoration: underline;">→ Ir para Contabilidade</a>
        </div>
    </div>
</body>
</html>
