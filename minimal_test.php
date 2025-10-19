<?php
// minimal_test.php
// Teste mínimo

echo "Iniciando...<br>";

try {
    require_once __DIR__ . '/public/conexao.php';
    echo "Conexão carregada<br>";
    
    if (isset($pdo)) {
        echo "PDO disponível<br>";
        
        $result = $pdo->query("SELECT current_schema()");
        $schema = $result->fetchColumn();
        echo "Schema: {$schema}<br>";
        
        $result = $pdo->query("SELECT COUNT(*) FROM lc_categorias");
        $count = $result->fetchColumn();
        echo "Categorias: {$count}<br>";
        
        echo "SUCESSO!<br>";
        
    } else {
        echo "PDO não disponível<br>";
    }
    
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "<br>";
}

echo "Fim do teste";
?>
