<?php
// verify_tables.php
// Verificação simples das tabelas criadas

require_once __DIR__ . '/public/conexao.php';

echo "<h1>🔍 Verificação das Tabelas</h1>";

if (!isset($pdo) || !$pdo instanceof PDO) {
    die("❌ Erro: Não foi possível conectar ao banco de dados.");
}

echo "✅ Conexão estabelecida!<br><br>";

// Listar todas as tabelas lc_*
$tables = $pdo->query("
    SELECT table_name, 
           (SELECT COUNT(*) FROM information_schema.columns 
            WHERE table_name = t.table_name AND table_schema = 'public') as column_count
    FROM information_schema.tables t
    WHERE table_schema = 'public' 
    AND table_name LIKE 'lc_%'
    ORDER BY table_name
")->fetchAll(PDO::FETCH_ASSOC);

echo "<h2>📋 Tabelas encontradas (" . count($tables) . "):</h2>";
echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>Tabela</th><th>Colunas</th></tr>";

foreach ($tables as $table) {
    echo "<tr>";
    echo "<td>{$table['table_name']}</td>";
    echo "<td>{$table['column_count']}</td>";
    echo "</tr>";
}
echo "</table>";

// Verificar dados iniciais
echo "<h2>📊 Dados iniciais:</h2>";

$unidades = $pdo->query("SELECT COUNT(*) FROM lc_unidades")->fetchColumn();
$categorias = $pdo->query("SELECT COUNT(*) FROM lc_categorias")->fetchColumn();
$configs = $pdo->query("SELECT COUNT(*) FROM lc_config")->fetchColumn();

echo "Unidades: {$unidades}<br>";
echo "Categorias: {$categorias}<br>";
echo "Configurações: {$configs}<br>";

// Testar algumas queries básicas
echo "<h2>🧪 Teste de queries:</h2>";

try {
    $units = $pdo->query("SELECT simbolo, nome FROM lc_unidades ORDER BY simbolo LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    echo "✅ Unidades carregadas: " . count($units) . "<br>";
    
    $cats = $pdo->query("SELECT nome FROM lc_categorias ORDER BY ordem")->fetchAll(PDO::FETCH_COLUMN);
    echo "✅ Categorias carregadas: " . implode(', ', $cats) . "<br>";
    
    $configs = $pdo->query("SELECT chave, valor FROM lc_config ORDER BY chave LIMIT 5")->fetchAll(PDO::FETCH_KEY_PAIR);
    echo "✅ Configurações carregadas: " . count($configs) . "<br>";
    
} catch (Exception $e) {
    echo "❌ Erro nas queries: " . $e->getMessage() . "<br>";
}

echo "<br><strong>✅ Verificação concluída!</strong>";
?>