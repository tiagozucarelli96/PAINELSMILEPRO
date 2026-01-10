<?php
// Executar schema completo da contabilidade
require_once __DIR__ . '/conexao.php';

echo "🔧 Criando schema completo da contabilidade...\n\n";

$sql_file = __DIR__ . '/../sql/contabilidade_schema.sql';

if (!file_exists($sql_file)) {
    die("❌ Arquivo SQL não encontrado: $sql_file\n");
}

$sql_content = file_get_contents($sql_file);

// Dividir em comandos individuais
$commands = array_filter(
    array_map('trim', explode(';', $sql_content)),
    function($cmd) {
        $cmd = trim($cmd);
        return !empty($cmd) && !preg_match('/^\s*--/', $cmd);
    }
);

$sucesso = 0;
$erros = 0;
$pulados = 0;

foreach ($commands as $index => $command) {
    // Remover comentários
    $command = preg_replace('/--.*$/m', '', $command);
    $command = trim($command);
    
    if (empty($command)) {
        continue;
    }
    
    try {
        $pdo->exec($command);
        $sucesso++;
        echo "✅ Comando " . ($index + 1) . " executado\n";
    } catch (PDOException $e) {
        // Ignorar erros de "já existe"
        if (strpos($e->getMessage(), 'already exists') !== false || 
            strpos($e->getMessage(), 'duplicate') !== false ||
            strpos($e->getMessage(), 'IF NOT EXISTS') !== false) {
            $pulados++;
            echo "⚠️  Comando " . ($index + 1) . " já existe (pulado)\n";
        } else {
            $erros++;
            echo "❌ Erro no comando " . ($index + 1) . ": " . $e->getMessage() . "\n";
        }
    }
}

echo "\n📊 Resumo:\n";
echo "   ✅ Sucesso: $sucesso\n";
echo "   ⚠️  Pulados: $pulados\n";
echo "   ❌ Erros: $erros\n";
echo "\n✅ Schema executado!\n";
