<?php
// Teste simples sem dependências do sistema
echo "<h1>🔍 Teste Direto do Banco</h1>";

// Conexão direta
$dbUrl = getenv('DATABASE_URL');
if (!$dbUrl) {
    die("DATABASE_URL não encontrado");
}

try {
    $pdo = new PDO($dbUrl);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p>✅ Conexão com banco: OK</p>";
    
    // Listar tabelas
    $tables = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' 
        AND table_name LIKE 'lc_%'
        ORDER BY table_name
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<h2>📋 Tabelas encontradas (" . count($tables) . "):</h2>";
    echo "<ul>";
    foreach ($tables as $table) {
        echo "<li>✅ {$table}</li>";
    }
    echo "</ul>";
    
    // Verificar estrutura da tabela lc_listas
    if (in_array('lc_listas', $tables)) {
        echo "<h2>🔍 Estrutura da tabela lc_listas:</h2>";
        $columns = $pdo->query("
            SELECT column_name, data_type 
            FROM information_schema.columns 
            WHERE table_name = 'lc_listas' 
            ORDER BY ordinal_position
        ")->fetchAll();
        
        echo "<ul>";
        foreach ($columns as $col) {
            echo "<li><strong>{$col['column_name']}</strong> ({$col['data_type']})</li>";
        }
        echo "</ul>";
        
        // Verificar se tem registros
        $count = $pdo->query("SELECT COUNT(*) FROM lc_listas")->fetchColumn();
        echo "<p>📊 Registros na tabela lc_listas: {$count}</p>";
    }
    
    // Verificar outras tabelas importantes
    $importantTables = ['lc_config', 'lc_unidades', 'lc_categorias'];
    foreach ($importantTables as $table) {
        if (in_array($table, $tables)) {
            $count = $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            echo "<p>📊 {$table}: {$count} registros</p>";
        }
    }
    
    echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>🎉 Teste concluído!</h3>";
    echo "<p>O banco está funcionando. Agora vou corrigir o problema da coluna tipo_lista.</p>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>❌ ERRO</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
?>
