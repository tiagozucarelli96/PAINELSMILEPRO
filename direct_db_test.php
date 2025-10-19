<?php
// direct_db_test.php
// Teste direto com as credenciais do Railway

echo "Iniciando teste direto...<br>";

// Credenciais do Railway (substitua pela senha real)
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
    
    // Verificar schema atual
    $schema = $pdo->query("SELECT current_schema()")->fetchColumn();
    echo "📋 Schema atual: <strong>{$schema}</strong><br><br>";
    
    // Listar tabelas no schema public
    $tables = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' 
        AND table_name LIKE 'lc_%'
        ORDER BY table_name
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "📋 Tabelas no schema 'public': " . count($tables) . "<br>";
    foreach ($tables as $table) {
        echo "- {$table}<br>";
    }
    
    // Listar tabelas no schema smilee12_painel_smile
    $tables_schema = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'smilee12_painel_smile' 
        AND table_name LIKE 'lc_%'
        ORDER BY table_name
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<br>📋 Tabelas no schema 'smilee12_painel_smile': " . count($tables_schema) . "<br>";
    foreach ($tables_schema as $table) {
        echo "- {$table}<br>";
    }
    
    // Testar dados no schema public
    if (count($tables) > 0) {
        echo "<br>📊 Dados no schema 'public':<br>";
        try {
            $unidades = $pdo->query("SELECT COUNT(*) FROM public.lc_unidades")->fetchColumn();
            echo "Unidades: {$unidades}<br>";
        } catch (Exception $e) {
            echo "Erro ao acessar unidades: " . $e->getMessage() . "<br>";
        }
    }
    
    // Testar dados no schema smilee12_painel_smile
    if (count($tables_schema) > 0) {
        echo "<br>📊 Dados no schema 'smilee12_painel_smile':<br>";
        try {
            $unidades = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_unidades")->fetchColumn();
            echo "Unidades: {$unidades}<br>";
        } catch (Exception $e) {
            echo "Erro ao acessar unidades: " . $e->getMessage() . "<br>";
        }
    }
    
    echo "<br><strong>🎉 Teste concluído!</strong>";
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage();
}

echo "<br><br>Teste executado em: " . date('H:i:s');
?>
