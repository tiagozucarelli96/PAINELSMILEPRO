<?php
// check_real_structure.php - Verificar estrutura real das tabelas
require_once 'conexao.php';

echo "<h1>🔍 Verificando Estrutura Real das Tabelas</h1>";

try {
    // Verificar lc_ficha_componentes
    echo "<h2>📋 Tabela lc_ficha_componentes</h2>";
    $result = $pdo->query("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns 
        WHERE table_schema = 'smilee12_painel_smile' 
        AND table_name = 'lc_ficha_componentes'
        ORDER BY ordinal_position
    ");
    $columns = $result->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($columns)) {
        echo "<p style='color: red;'>❌ Tabela lc_ficha_componentes não existe!</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Coluna</th><th>Tipo</th><th>Nulo</th><th>Padrão</th></tr>";
        foreach ($columns as $col) {
            echo "<tr><td>" . $col['column_name'] . "</td><td>" . $col['data_type'] . "</td><td>" . $col['is_nullable'] . "</td><td>" . ($col['column_default'] ?? 'NULL') . "</td></tr>";
        }
        echo "</table>";
    }
    
    // Verificar lc_receita_componentes
    echo "<h2>🍳 Tabela lc_receita_componentes</h2>";
    $result = $pdo->query("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns 
        WHERE table_schema = 'smilee12_painel_smile' 
        AND table_name = 'lc_receita_componentes'
        ORDER BY ordinal_position
    ");
    $columns = $result->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($columns)) {
        echo "<p style='color: red;'>❌ Tabela lc_receita_componentes não existe!</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Coluna</th><th>Tipo</th><th>Nulo</th><th>Padrão</th></tr>";
        foreach ($columns as $col) {
            echo "<tr><td>" . $col['column_name'] . "</td><td>" . $col['data_type'] . "</td><td>" . ($col['column_default'] ?? 'NULL') . "</td></tr>";
        }
        echo "</table>";
    }
    
    // Verificar enum insumo_aquisicao
    echo "<h2>🔢 Enum insumo_aquisicao</h2>";
    $result = $pdo->query("
        SELECT e.enumlabel
        FROM pg_type t
        JOIN pg_enum e ON t.oid = e.enumtypid
        JOIN pg_namespace n ON t.typnamespace = n.oid
        WHERE n.nspname = 'smilee12_painel_smile' AND t.typname = 'insumo_aquisicao'
        ORDER BY e.enumsortorder
    ");
    $enum_values = $result->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($enum_values)) {
        echo "<p style='color: red;'>❌ Enum insumo_aquisicao não existe!</p>";
    } else {
        echo "<p>Valores do enum: " . implode(', ', $enum_values) . "</p>";
    }
    
    // Verificar triggers
    echo "<h2>⚡ Triggers</h2>";
    $result = $pdo->query("
        SELECT trigger_name, event_object_table, action_timing, event_manipulation
        FROM information_schema.triggers
        WHERE trigger_schema = 'smilee12_painel_smile'
        ORDER BY event_object_table, trigger_name
    ");
    $triggers = $result->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($triggers)) {
        echo "<p>Nenhum trigger encontrado</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Trigger</th><th>Tabela</th><th>Timing</th><th>Evento</th></tr>";
        foreach ($triggers as $trigger) {
            echo "<tr><td>" . $trigger['trigger_name'] . "</td><td>" . $trigger['event_object_table'] . "</td><td>" . $trigger['action_timing'] . "</td><td>" . $trigger['event_manipulation'] . "</td></tr>";
        }
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
}
?>
