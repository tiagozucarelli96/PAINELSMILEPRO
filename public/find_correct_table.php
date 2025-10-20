<?php
// find_correct_table.php - Encontrar a tabela correta para eventos de listas
require_once 'conexao.php';

echo "<h1>🔍 Procurando Tabelas Relacionadas a Eventos de Listas</h1>";

try {
    // Procurar todas as tabelas que contenham 'evento' ou 'lista' no nome
    $tables = $pdo->query("
        SELECT table_schema, table_name
        FROM information_schema.tables
        WHERE table_name LIKE '%evento%' 
        OR table_name LIKE '%lista%'
        OR table_name LIKE '%lc_%'
        ORDER BY table_schema, table_name
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h2>📋 Tabelas Encontradas:</h2>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Schema</th><th>Tabela</th><th>Ação</th></tr>";
    
    foreach ($tables as $table) {
        $schema = $table['table_schema'];
        $name = $table['table_name'];
        $action = "<a href='check_table_structure.php?schema={$schema}&table={$name}' target='_blank'>Ver Estrutura</a>";
        echo "<tr><td>{$schema}</td><td>{$name}</td><td>{$action}</td></tr>";
    }
    echo "</table>";
    
    // Verificar se existe alguma tabela que possa ser a correta
    $possibleTables = ['lc_eventos', 'lc_listas_eventos', 'eventos', 'lista_eventos'];
    
    echo "<h2>🎯 Verificando Tabelas Possíveis:</h2>";
    foreach ($possibleTables as $tableName) {
        $exists = $pdo->query("
            SELECT EXISTS (
                SELECT FROM information_schema.tables
                WHERE table_schema = 'smilee12_painel_smile' 
                AND table_name = '{$tableName}'
            )
        ")->fetchColumn();
        
        if ($exists) {
            echo "<p style='color: green;'>✅ Tabela {$tableName} existe!</p>";
            
            // Verificar estrutura
            $columns = $pdo->query("
                SELECT column_name, data_type
                FROM information_schema.columns 
                WHERE table_schema = 'smilee12_painel_smile' 
                AND table_name = '{$tableName}'
                ORDER BY ordinal_position
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<ul>";
            foreach ($columns as $col) {
                echo "<li>{$col['column_name']} ({$col['data_type']})</li>";
            }
            echo "</ul>";
        } else {
            echo "<p style='color: red;'>❌ Tabela {$tableName} não existe</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
}
?>
