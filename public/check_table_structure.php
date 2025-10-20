<?php
// check_table_structure.php - Verificar estrutura de uma tabela específica
require_once 'conexao.php';

$schema = $_GET['schema'] ?? 'smilee12_painel_smile';
$table = $_GET['table'] ?? '';

if (!$table) {
    echo "❌ Nome da tabela não fornecido.";
    exit;
}

echo "<h1>🔍 Estrutura da Tabela: {$schema}.{$table}</h1>";

try {
    // Verificar se a tabela existe
    $exists = $pdo->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables
            WHERE table_schema = '{$schema}' 
            AND table_name = '{$table}'
        )
    ")->fetchColumn();
    
    if (!$exists) {
        echo "<p style='color: red;'>❌ Tabela {$schema}.{$table} não existe!</p>";
        exit;
    }
    
    echo "<p style='color: green;'>✅ Tabela {$schema}.{$table} existe!</p>";
    
    // Verificar estrutura
    $columns = $pdo->query("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns 
        WHERE table_schema = '{$schema}' 
        AND table_name = '{$table}'
        ORDER BY ordinal_position
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h2>📋 Colunas:</h2>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Coluna</th><th>Tipo</th><th>Nulo</th><th>Padrão</th></tr>";
    foreach ($columns as $col) {
        echo "<tr><td>{$col['column_name']}</td><td>{$col['data_type']}</td><td>{$col['is_nullable']}</td><td>" . ($col['column_default'] ?? 'NULL') . "</td></tr>";
    }
    echo "</table>";
    
    // Verificar dados de exemplo
    echo "<h2>📊 Dados de Exemplo (primeiros 3 registros):</h2>";
    try {
        $sample = $pdo->query("SELECT * FROM {$schema}.{$table} LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($sample)) {
            echo "<p>Tabela vazia.</p>";
        } else {
            echo "<pre>" . print_r($sample, true) . "</pre>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>Erro ao consultar dados: " . $e->getMessage() . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
}
?>
