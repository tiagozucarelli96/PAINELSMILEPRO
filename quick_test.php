<?php
// quick_test.php
// Teste rápido e direto

echo "Teste iniciado...<br>";

try {
    require_once __DIR__ . '/public/conexao.php';
    
    if (isset($pdo) && $pdo instanceof PDO) {
        echo "✅ PDO OK<br>";
        
        $schema = $pdo->query("SELECT current_schema()")->fetchColumn();
        echo "Schema: {$schema}<br>";
        
        $tables = $pdo->query("
            SELECT COUNT(*) 
            FROM information_schema.tables 
            WHERE table_schema = 'smilee12_painel_smile' 
            AND table_name LIKE 'lc_%'
        ")->fetchColumn();
        
        echo "Tabelas lc_*: {$tables}<br>";
        
        $categorias = $pdo->query("SELECT COUNT(*) FROM lc_categorias")->fetchColumn();
        echo "Categorias: {$categorias}<br>";
        
        $unidades = $pdo->query("SELECT COUNT(*) FROM lc_unidades")->fetchColumn();
        echo "Unidades: {$unidades}<br>";
        
        echo "<br><strong>🎉 SUCESSO!</strong>";
        
    } else {
        echo "❌ PDO não disponível";
    }
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage();
}

echo "<br><br>Teste concluído em: " . date('H:i:s');
?>
