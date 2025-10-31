<?php
/**
 * apply_trello_schema_direct.php
 * Script para aplicar schema diretamente via linha de comando ou web
 * Executa automaticamente quando chamado
 */

// Permitir execução via web ou CLI
if (php_sapi_name() !== 'cli') {
    // Execução via web
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Verificar se está logado
    if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1) {
        // Tentar aplicar mesmo assim se for chamado diretamente
        // (útil para primeira instalação)
        echo "⚠️ Não está logado, mas tentando aplicar schema mesmo assim...\n\n";
    }
}

// Buscar o arquivo de conexão na pasta public
$conexaoPath = __DIR__ . '/public/conexao.php';
if (!file_exists($conexaoPath)) {
    die("❌ Arquivo conexao.php não encontrado em: $conexaoPath\n");
}

require_once $conexaoPath;

// Verificar conexão
if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo']) {
    die("❌ Erro: Não foi possível conectar ao banco de dados\n");
}

$pdo = $GLOBALS['pdo'];

// Carregar schema SQL
$sqlFile = __DIR__ . '/sql/018_demandas_trello_create.sql';
if (!file_exists($sqlFile)) {
    die("❌ Arquivo SQL não encontrado: $sqlFile\n");
}

$sqlContent = file_get_contents($sqlFile);

echo "📦 Aplicando Schema Trello - Sistema de Demandas\n";
echo "================================================\n\n";

// Separar comandos SQL
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
        $command = trim($currentCommand);
        if (!empty($command)) {
            $commands[] = $command;
        }
        $currentCommand = '';
    }
}

// Adicionar último comando se não terminou com ;
if (!empty(trim($currentCommand))) {
    $commands[] = trim($currentCommand);
}

echo "📋 Encontrados " . count($commands) . " comandos SQL para executar.\n\n";

$successCount = 0;
$errorCount = 0;
$errors = [];

foreach ($commands as $index => $command) {
    if (empty(trim($command))) {
        continue;
    }
    
    try {
        $pdo->exec($command);
        $successCount++;
        echo "✅ Comando " . ($index + 1) . " executado com sucesso\n";
    } catch (PDOException $e) {
        $errorMsg = $e->getMessage();
        
        // Se o erro for "já existe", não é crítico
        if (strpos($errorMsg, 'already exists') !== false || 
            strpos($errorMsg, 'duplicate') !== false ||
            strpos($errorMsg, 'já existe') !== false) {
            echo "⚠️  Comando " . ($index + 1) . ": Tabela/índice já existe (ignorando)\n";
            $successCount++; // Contar como sucesso
        } else {
            $errorCount++;
            $errors[] = "Erro no comando " . ($index + 1) . ": " . $errorMsg;
            echo "❌ Comando " . ($index + 1) . ": " . $errorMsg . "\n";
        }
    }
}

echo "\n================================================\n";
echo "📊 Resumo:\n";
echo "✅ $successCount comandos executados com sucesso\n";

if ($errorCount > 0) {
    echo "❌ $errorCount erros encontrados\n\n";
    echo "Detalhes dos erros:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
} else {
    echo "🎉 Schema aplicado com sucesso!\n";
}

// Verificar tabelas criadas
echo "\n================================================\n";
echo "🔍 Verificando tabelas criadas:\n\n";

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
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = '$table'");
        $exists = $stmt->fetchColumn() > 0;
        
        if ($exists) {
            echo "✅ Tabela $table existe\n";
            $existingTables[] = $table;
        } else {
            echo "❌ Tabela $table NÃO existe\n";
        }
    } catch (PDOException $e) {
        echo "❌ Erro ao verificar tabela $table: " . $e->getMessage() . "\n";
    }
}

echo "\n================================================\n";
if (count($existingTables) === count($requiredTables)) {
    echo "🎉 SUCESSO! Todas as " . count($requiredTables) . " tabelas foram criadas!\n";
    echo "✅ O sistema está pronto para uso.\n";
} else {
    echo "⚠️  ATENÇÃO: Apenas " . count($existingTables) . " de " . count($requiredTables) . " tabelas foram criadas.\n";
    echo "Verifique os erros acima.\n";
}

echo "\n";

