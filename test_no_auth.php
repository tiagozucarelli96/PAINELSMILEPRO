<?php
// test_no_auth.php
// Teste sem autenticação

// Bypass de autenticação
$_SESSION['user_id'] = 1;
$_SESSION['logado'] = true;
$_SESSION['user_name'] = 'Teste';

echo "<h1>🧪 Teste do Sistema (Sem Autenticação)</h1>";

require_once __DIR__ . '/public/conexao.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    die("❌ Erro: Não foi possível conectar ao banco de dados.");
}

echo "✅ Conexão estabelecida!<br><br>";

// Testar tabelas
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

// Testar dados
$unidades = $pdo->query("SELECT COUNT(*) FROM lc_unidades")->fetchColumn();
$categorias = $pdo->query("SELECT COUNT(*) FROM lc_categorias")->fetchColumn();
$configs = $pdo->query("SELECT COUNT(*) FROM lc_config")->fetchColumn();

echo "<h2>📊 Dados iniciais:</h2>";
echo "Unidades: {$unidades}<br>";
echo "Categorias: {$categorias}<br>";
echo "Configurações: {$configs}<br>";

echo "<br><strong>🎉 Sistema funcionando!</strong>";
?>
