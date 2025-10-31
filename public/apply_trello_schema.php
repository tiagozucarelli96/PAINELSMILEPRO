<?php
/**
 * apply_trello_schema.php
 * Script para aplicar o schema do sistema Trello no banco de dados
 * Acesse via: /apply_trello_schema.php?token=SEU_TOKEN_AQUI
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
$pdo = $GLOBALS['pdo'];

// Verificar autenticação ou token (mais permissivo para facilitar aplicação)
$token = $_GET['token'] ?? '';
$expectedToken = getenv('SCHEMA_APPLY_TOKEN') ?: 'apply_trello_2024';
$isLoggedIn = isset($_SESSION['logado']) && $_SESSION['logado'] == 1;

// Permitir acesso se logado OU com token correto OU se for primeira instalação (sem tabelas)
$allowAccess = $isLoggedIn || $token === $expectedToken;

// Se não está logado e não tem token, verificar se é primeira instalação
if (!$allowAccess) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'demandas_boards'");
        $tableExists = $stmt->fetchColumn() > 0;
        
        // Se tabela não existe, permitir acesso (primeira instalação)
        if (!$tableExists) {
            $allowAccess = true;
        }
    } catch (Exception $e) {
        // Se não conseguir verificar, permitir acesso (pode ser primeira instalação)
        $allowAccess = true;
    }
}

if (!$allowAccess) {
    die('Acesso negado. Para aplicar o schema, você precisa:<br>1) Estar logado no sistema, OU<br>2) Fornecer o token correto via URL: ?token=' . htmlspecialchars($expectedToken));
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Aplicar Schema Trello</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; max-width: 800px; margin: 0 auto; }
        .success { color: #10b981; background: #d1fae5; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .error { color: #ef4444; background: #fee2e2; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .info { color: #3b82f6; background: #dbeafe; padding: 10px; border-radius: 5px; margin: 10px 0; }
        pre { background: #f3f4f6; padding: 10px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>📦 Aplicar Schema Trello - Sistema de Demandas</h1>
    
    <?php
    $sqlFile = __DIR__ . '/../sql/018_demandas_trello_create.sql';
    
    if (!file_exists($sqlFile)) {
        echo '<div class="error">Arquivo SQL não encontrado: ' . htmlspecialchars($sqlFile) . '</div>';
        exit;
    }
    
    $sqlContent = file_get_contents($sqlFile);
    
    // Separar comandos SQL (dividir por ; em linhas separadas)
    $commands = [];
    $currentCommand = '';
    
    $lines = explode("\n", $sqlContent);
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Pular comentários e linhas vazias
        if (empty($line) || strpos($line, '--') === 0) {
            continue;
        }
        
        $currentCommand .= $line . "\n";
        
        // Se a linha termina com ;, é um comando completo
        if (substr(rtrim($line), -1) === ';') {
            $commands[] = trim($currentCommand);
            $currentCommand = '';
        }
    }
    
    // Adicionar último comando se não terminou com ;
    if (!empty(trim($currentCommand))) {
        $commands[] = trim($currentCommand);
    }
    
    echo '<div class="info">Encontrados ' . count($commands) . ' comandos SQL para executar.</div>';
    
    $successCount = 0;
    $errorCount = 0;
    $errors = [];
    
    echo '<h2>Executando comandos...</h2>';
    echo '<pre>';
    
    foreach ($commands as $index => $command) {
        if (empty(trim($command))) {
            continue;
        }
        
        try {
            $pdo->exec($command);
            $successCount++;
            echo "✅ Comando " . ($index + 1) . " executado com sucesso\n";
        } catch (PDOException $e) {
            $errorCount++;
            $errorMsg = "❌ Erro no comando " . ($index + 1) . ": " . $e->getMessage();
            $errors[] = $errorMsg;
            echo $errorMsg . "\n";
            
            // Se o erro for "já existe", não é crítico
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'duplicate') !== false) {
                echo "   ⚠️  (Tabela/índice já existe - ignorando)\n";
                $errorCount--; // Não contar como erro real
            }
        }
    }
    
    echo '</pre>';
    
    echo '<h2>Resumo:</h2>';
    echo '<div class="success">✅ ' . $successCount . ' comandos executados com sucesso</div>';
    
    if ($errorCount > 0) {
        echo '<div class="error">❌ ' . $errorCount . ' erros encontrados</div>';
        echo '<h3>Detalhes dos erros:</h3>';
        echo '<pre>';
        foreach ($errors as $error) {
            echo htmlspecialchars($error) . "\n";
        }
        echo '</pre>';
    } else {
        echo '<div class="success">🎉 Schema aplicado com sucesso! Todas as tabelas foram criadas.</div>';
    }
    
    // Verificar se as tabelas foram criadas
    echo '<h2>Verificação de tabelas:</h2>';
    $requiredTables = [
        'demandas_boards',
        'demandas_listas',
        'demandas_cards',
        'demandas_cards_usuarios',
        'demandas_comentarios_trello',
        'demandas_arquivos_trello',
        'demandas_notificacoes',
        'demandas_fixas',
        'demandas_fixas_log'
    ];
    
    $existingTables = [];
    foreach ($requiredTables as $table) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = '$table'");
            $exists = $stmt->fetchColumn() > 0;
            
            if ($exists) {
                echo '<div class="success">✅ Tabela ' . $table . ' existe</div>';
                $existingTables[] = $table;
            } else {
                echo '<div class="error">❌ Tabela ' . $table . ' NÃO existe</div>';
            }
        } catch (PDOException $e) {
            echo '<div class="error">❌ Erro ao verificar tabela ' . $table . ': ' . $e->getMessage() . '</div>';
        }
    }
    
    if (count($existingTables) === count($requiredTables)) {
        echo '<div class="success"><strong>🎉 Todas as tabelas foram criadas com sucesso!</strong></div>';
    } else {
        echo '<div class="error"><strong>⚠️ Algumas tabelas ainda não existem. Verifique os erros acima.</strong></div>';
    }
    ?>
    
    <hr>
    <p><a href="index.php?page=demandas">← Voltar para Demandas</a></p>
</body>
</html>

