<?php
// check_lc_listas_eventos.php - Verificar estrutura real da tabela lc_listas_eventos
require_once 'conexao.php';

echo "<h1>🔍 Verificando Estrutura da Tabela lc_listas_eventos</h1>";

try {
    // Verificar se a tabela existe
    $tableExists = $pdo->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables
            WHERE table_schema = 'smilee12_painel_smile' 
            AND table_name = 'lc_listas_eventos'
        )
    ")->fetchColumn();
    
    if (!$tableExists) {
        echo "<p style='color: red;'>❌ Tabela lc_listas_eventos não existe no schema smilee12_painel_smile!</p>";
        
        // Verificar se existe em outros schemas
        $otherSchemas = $pdo->query("
            SELECT table_schema, table_name
            FROM information_schema.tables
            WHERE table_name = 'lc_listas_eventos'
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($otherSchemas)) {
            echo "<p>📋 Tabela encontrada em outros schemas:</p>";
            foreach ($otherSchemas as $schema) {
                echo "<p>- Schema: {$schema['table_schema']}, Tabela: {$schema['table_name']}</p>";
            }
        }
    } else {
        echo "<p style='color: green;'>✅ Tabela lc_listas_eventos existe no schema smilee12_painel_smile!</p>";
        
        // Verificar estrutura da tabela
        echo "<h2>📋 Estrutura da Tabela lc_listas_eventos</h2>";
        $result = $pdo->query("
            SELECT column_name, data_type, is_nullable, column_default
            FROM information_schema.columns 
            WHERE table_schema = 'smilee12_painel_smile' 
            AND table_name = 'lc_listas_eventos'
            ORDER BY ordinal_position
        ");
        $columns = $result->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Coluna</th><th>Tipo</th><th>Nulo</th><th>Padrão</th></tr>";
        foreach ($columns as $col) {
            echo "<tr><td>" . $col['column_name'] . "</td><td>" . $col['data_type'] . "</td><td>" . $col['is_nullable'] . "</td><td>" . ($col['column_default'] ?? 'NULL') . "</td></tr>";
        }
        echo "</table>";
        
        // Verificar dados existentes
        echo "<h2>📊 Dados Existentes</h2>";
        try {
            $count = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_listas_eventos")->fetchColumn();
            echo "<p>Total de registros: $count</p>";
            
            if ($count > 0) {
                echo "<h3>Primeiros 3 registros:</h3>";
                $sample = $pdo->query("SELECT * FROM smilee12_painel_smile.lc_listas_eventos LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
                echo "<pre>" . print_r($sample, true) . "</pre>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>Erro ao consultar dados: " . $e->getMessage() . "</p>";
        }
    }
    
    // Verificar também lc_listas
    echo "<h2>📋 Estrutura da Tabela lc_listas</h2>";
    $result = $pdo->query("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns 
        WHERE table_schema = 'smilee12_painel_smile' 
        AND table_name = 'lc_listas'
        ORDER BY ordinal_position
    ");
    $columns = $result->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($columns)) {
        echo "<p style='color: red;'>❌ Tabela lc_listas não existe!</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Coluna</th><th>Tipo</th><th>Nulo</th><th>Padrão</th></tr>";
        foreach ($columns as $col) {
            echo "<tr><td>" . $col['column_name'] . "</td><td>" . $col['data_type'] . "</td><td>" . $col['is_nullable'] . "</td><td>" . ($col['column_default'] ?? 'NULL') . "</td></tr>";
        }
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
}
?>
