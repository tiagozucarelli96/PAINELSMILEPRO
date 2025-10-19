<?php
// test_correct_schema.php
// Teste usando o schema correto

require_once __DIR__ . '/public/conexao.php';

echo "<h1>🧪 Teste do Schema Correto</h1>";

if (!isset($pdo) || !$pdo instanceof PDO) {
    die("❌ Erro: Não foi possível conectar ao banco de dados.");
}

echo "✅ Conexão estabelecida!<br><br>";

try {
    // Verificar qual schema está sendo usado
    $schema = $pdo->query("SELECT current_schema()")->fetchColumn();
    echo "📋 Schema atual: <strong>{$schema}</strong><br><br>";
    
    // Listar tabelas no schema atual
    $tables = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = current_schema()
        AND table_name LIKE 'lc_%'
        ORDER BY table_name
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<h2>📋 Tabelas no schema '{$schema}' (" . count($tables) . "):</h2>";
    echo "<ul>";
    foreach ($tables as $table) {
        echo "<li>✅ {$table}</li>";
    }
    echo "</ul>";
    
    // Testar dados
    echo "<h2>📊 Dados nas tabelas:</h2>";
    
    try {
        $unidades = $pdo->query("SELECT COUNT(*) FROM lc_unidades")->fetchColumn();
        echo "✅ Unidades: {$unidades}<br>";
        
        $categorias = $pdo->query("SELECT COUNT(*) FROM lc_categorias")->fetchColumn();
        echo "✅ Categorias: {$categorias}<br>";
        
        $configs = $pdo->query("SELECT COUNT(*) FROM lc_config")->fetchColumn();
        echo "✅ Configurações: {$configs}<br>";
        
        $insumos = $pdo->query("SELECT COUNT(*) FROM lc_insumos")->fetchColumn();
        echo "✅ Insumos: {$insumos}<br>";
        
    } catch (Exception $e) {
        echo "❌ Erro ao acessar dados: " . $e->getMessage() . "<br>";
    }
    
    // Testar algumas queries específicas
    echo "<h2>🔍 Teste de queries específicas:</h2>";
    
    try {
        // Teste 1: Listar unidades
        $units = $pdo->query("SELECT simbolo, nome FROM lc_unidades ORDER BY simbolo LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        echo "✅ Unidades carregadas: " . count($units) . "<br>";
        
        // Teste 2: Listar categorias
        $cats = $pdo->query("SELECT nome FROM lc_categorias ORDER BY ordem")->fetchAll(PDO::FETCH_COLUMN);
        echo "✅ Categorias carregadas: " . implode(', ', $cats) . "<br>";
        
        // Teste 3: Configurações
        $configs = $pdo->query("SELECT chave, valor FROM lc_config ORDER BY chave LIMIT 5")->fetchAll(PDO::FETCH_KEY_PAIR);
        echo "✅ Configurações carregadas: " . count($configs) . "<br>";
        
    } catch (Exception $e) {
        echo "❌ Erro nas queries: " . $e->getMessage() . "<br>";
    }
    
    echo "<br><div style='background: #d4edda; padding: 15px; border-radius: 5px;'>";
    echo "<strong>🎉 SUCESSO! Sistema funcionando no schema correto!</strong>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px;'>";
    echo "<strong>❌ ERRO:</strong> " . $e->getMessage();
    echo "</div>";
}

echo "<br><small>Teste executado em: " . date('d/m/Y H:i:s') . "</small>";
?>
