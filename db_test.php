<?php
// db_test.php
// Teste direto do banco de dados

echo "Iniciando teste...<br>";

// Configurações de conexão (copiadas do conexao.php)
$host = 'containers-us-west-201.railway.app';
$port = '6543';
$dbname = 'railway';
$user = 'postgres';
$password = 'YOUR_PASSWORD_HERE'; // Substitua pela senha real

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    
    echo "✅ Conexão estabelecida!<br>";
    
    // Testar tabelas
    $tables = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' 
        AND table_name LIKE 'lc_%'
        ORDER BY table_name
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "✅ Tabelas encontradas: " . count($tables) . "<br>";
    
    foreach ($tables as $table) {
        echo "- {$table}<br>";
    }
    
    // Testar dados
    $unidades = $pdo->query("SELECT COUNT(*) FROM lc_unidades")->fetchColumn();
    $categorias = $pdo->query("SELECT COUNT(*) FROM lc_categorias")->fetchColumn();
    
    echo "<br>✅ Unidades: {$unidades}<br>";
    echo "✅ Categorias: {$categorias}<br>";
    
    echo "<br><strong>🎉 SUCESSO! Banco funcionando!</strong>";
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage();
}

echo "<br><br>Teste concluído em: " . date('H:i:s');
?>
