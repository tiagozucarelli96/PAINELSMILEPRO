<?php
// simple_test.php
// Teste muito simples

echo "Teste iniciado...<br>";

try {
    require_once __DIR__ . '/public/conexao.php';
    echo "Conexão carregada...<br>";
    
    if (isset($pdo) && $pdo instanceof PDO) {
        echo "✅ PDO OK<br>";
        
        $result = $pdo->query("SELECT COUNT(*) FROM lc_categorias");
        $count = $result->fetchColumn();
        echo "✅ Categorias: {$count}<br>";
        
        $result = $pdo->query("SELECT COUNT(*) FROM lc_unidades");
        $count = $result->fetchColumn();
        echo "✅ Unidades: {$count}<br>";
        
        echo "<br><strong>🎉 SUCESSO! Sistema funcionando!</strong>";
        
    } else {
        echo "❌ PDO não disponível";
    }
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage();
}

echo "<br><br>Teste concluído em: " . date('H:i:s');
?>