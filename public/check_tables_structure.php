<?php
// check_tables_structure.php - Verificar estrutura das tabelas
require_once 'conexao.php';

echo "<h1>🔍 Verificação da Estrutura das Tabelas</h1>";

try {
    // Verificar lc_categorias
    echo "<h2>📂 Tabela lc_categorias</h2>";
    $result = $pdo->query("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns 
        WHERE table_schema = 'smilee12_painel_smile' 
        AND table_name = 'lc_categorias'
        ORDER BY ordinal_position
    ");
    $columns = $result->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Coluna</th><th>Tipo</th><th>Nulo</th><th>Padrão</th></tr>";
    foreach ($columns as $col) {
        echo "<tr><td>" . $col['column_name'] . "</td><td>" . $col['data_type'] . "</td><td>" . $col['is_nullable'] . "</td><td>" . ($col['column_default'] ?? 'NULL') . "</td></tr>";
    }
    echo "</table>";
    
    // Verificar lc_unidades
    echo "<h2>📏 Tabela lc_unidades</h2>";
    $result = $pdo->query("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns 
        WHERE table_schema = 'smilee12_painel_smile' 
        AND table_name = 'lc_unidades'
        ORDER BY ordinal_position
    ");
    $columns = $result->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Coluna</th><th>Tipo</th><th>Nulo</th><th>Padrão</th></tr>";
    foreach ($columns as $col) {
        echo "<tr><td>" . $col['column_name'] . "</td><td>" . $col['data_type'] . "</td><td>" . $col['is_nullable'] . "</td><td>" . ($col['column_default'] ?? 'NULL') . "</td></tr>";
    }
    echo "</table>";
    
    // Verificar lc_insumos
    echo "<h2>🥘 Tabela lc_insumos</h2>";
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
        echo "<tr><td>" . $col['column_name'] . "</td><td>" . $col['data_type'] . "</td><td>" . ($col['column_default'] ?? 'NULL') . "</td></tr>";
    }
    echo "</table>";
    
    // Testar se há dados
    echo "<h2>📊 Dados nas Tabelas</h2>";
    $categorias = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_categorias")->fetchColumn();
    $unidades = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_unidades")->fetchColumn();
    $insumos = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_insumos")->fetchColumn();
    
    echo "<p>Categorias: $categorias</p>";
    echo "<p>Unidades: $unidades</p>";
    echo "<p>Insumos: $insumos</p>";
    
    // Verificar se há insumos usando unidade ID 9
    echo "<h2>🔍 Verificação de Dependências</h2>";
    $check = $pdo->prepare("SELECT COUNT(*) FROM smilee12_painel_smile.lc_insumos WHERE unidade_padrao = (SELECT simbolo FROM smilee12_painel_smile.lc_unidades WHERE id = ?)");
    $check->execute([9]);
    $count = $check->fetchColumn();
    echo "<p>Insumos usando unidade ID 9: $count</p>";
    
    if ($count > 0) {
        echo "<h3>Insumos que usam esta unidade:</h3>";
        $insumos_using = $pdo->prepare("
            SELECT i.*, u.simbolo 
            FROM smilee12_painel_smile.lc_insumos i
            LEFT JOIN smilee12_painel_smile.lc_unidades u ON u.simbolo = i.unidade_padrao
            WHERE u.id = ?
        ");
        $insumos_using->execute([9]);
        $insumos_list = $insumos_using->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Nome</th><th>Unidade Padrão</th><th>Símbolo</th></tr>";
        foreach ($insumos_list as $insumo) {
            echo "<tr><td>" . $insumo['id'] . "</td><td>" . $insumo['nome'] . "</td><td>" . $insumo['unidade_padrao'] . "</td><td>" . $insumo['simbolo'] . "</td></tr>";
        }
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
}
?>
