<?php
// check_insumos_structure.php - Verificar estrutura real da tabela lc_insumos
require_once 'conexao.php';

try {
    echo "<h2>🔍 Estrutura da tabela lc_insumos</h2>";
    
    // Verificar colunas da tabela
    $result = $pdo->query("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns 
        WHERE table_schema = 'smilee12_painel_smile' 
        AND table_name = 'lc_insumos'
        ORDER BY ordinal_position
    ");
    
    $columns = $result->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Coluna</th><th>Tipo</th><th>Nulo</th><th>Padrão</th></tr>";
    
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>" . $col['column_name'] . "</td>";
        echo "<td>" . $col['data_type'] . "</td>";
        echo "<td>" . $col['is_nullable'] . "</td>";
        echo "<td>" . ($col['column_default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Verificar se há dados
    $count = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_insumos")->fetchColumn();
    echo "<p><strong>Total de registros:</strong> $count</p>";
    
    if ($count > 0) {
        echo "<h3>Primeiro registro:</h3>";
        $first = $pdo->query("SELECT * FROM smilee12_painel_smile.lc_insumos LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        echo "<pre>" . print_r($first, true) . "</pre>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
}
?>
