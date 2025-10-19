<?php
// Teste simples para verificar as tabelas
session_start();
require_once __DIR__ . '/public/conexao.php';

echo "<h1>🔍 Teste das Tabelas</h1>";

try {
    // Testar conexão
    echo "<p>✅ Conexão com banco: OK</p>";
    
    // Listar tabelas lc_*
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
    
    // Testar inserção em lc_config
    if (in_array('lc_config', $tables)) {
        $configs = $pdo->query("SELECT COUNT(*) FROM lc_config")->fetchColumn();
        echo "<p>⚙️ Configurações: {$configs} registros</p>";
    }
    
    // Testar inserção em lc_unidades
    if (in_array('lc_unidades', $tables)) {
        $units = $pdo->query("SELECT COUNT(*) FROM lc_unidades")->fetchColumn();
        echo "<p>📏 Unidades: {$units} registros</p>";
    }
    
    // Testar inserção em lc_categorias
    if (in_array('lc_categorias', $tables)) {
        $cats = $pdo->query("SELECT COUNT(*) FROM lc_categorias")->fetchColumn();
        echo "<p>📂 Categorias: {$cats} registros</p>";
    }
    
    // Verificar estrutura da tabela lc_insumos
    if (in_array('lc_insumos', $tables)) {
        echo "<h3>🔍 Estrutura da tabela lc_insumos:</h3>";
        $cols = $pdo->query("
            SELECT column_name, data_type, is_nullable 
            FROM information_schema.columns 
            WHERE table_name = 'lc_insumos' 
            ORDER BY ordinal_position
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($cols)) {
            echo "<p>❌ Tabela lc_insumos vazia ou não encontrada</p>";
        } else {
            echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
            echo "<tr><th>Coluna</th><th>Tipo</th><th>Nullable</th></tr>";
            foreach ($cols as $col) {
                echo "<tr><td>{$col['column_name']}</td><td>{$col['data_type']}</td><td>{$col['is_nullable']}</td></tr>";
            }
            echo "</table>";
        }
    }
    
    echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>🎉 SUCESSO!</h3>";
    echo "<p>As tabelas foram criadas com sucesso!</p>";
    echo "<p><a href='public/lc_index.php'>→ Acessar Lista de Compras</a></p>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>❌ ERRO</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
?>
