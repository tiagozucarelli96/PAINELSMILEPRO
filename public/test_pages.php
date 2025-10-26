<?php
// test_pages.php - Teste de carregamento das páginas
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Simular sessão de admin para teste
$_SESSION['logado'] = 1;
$_SESSION['user_id'] = 1;
$_SESSION['nome'] = 'Teste';
$_SESSION['perfil'] = 'ADM';
$_SESSION['perm_usuarios'] = 1;
$_SESSION['perm_comercial_ver'] = 1;

echo "<h1>Teste de Carregamento das Páginas</h1>";

$pages_to_test = [
    'comercial' => 'comercial.php',
    'comercial_degustacoes' => 'comercial_degustacoes.php',
    'comercial_clientes' => 'comercial_clientes.php',
    'comercial_degust_inscricoes' => 'comercial_degust_inscricoes.php',
    'lista_compras' => 'lista_compras.php',
    'ver' => 'ver.php',
    'estoque_logistico' => 'estoque_logistico.php',
    'config_insumos' => 'config_insumos.php'
];

foreach ($pages_to_test as $page_name => $file) {
    echo "<h2>Testando: $page_name ($file)</h2>";
    
    if (file_exists($file)) {
        echo "✅ Arquivo existe<br>";
        
        // Testar se há erros de sintaxe
        $output = shell_exec("php -l $file 2>&1");
        if (strpos($output, 'No syntax errors') !== false) {
            echo "✅ Sintaxe OK<br>";
        } else {
            echo "❌ Erro de sintaxe: $output<br>";
        }
        
        // Testar includes
        try {
            ob_start();
            include $file;
            $content = ob_get_clean();
            
            if (!empty($content)) {
                echo "✅ Carregamento OK<br>";
                echo "Tamanho do conteúdo: " . strlen($content) . " bytes<br>";
            } else {
                echo "⚠️ Conteúdo vazio<br>";
            }
        } catch (Exception $e) {
            echo "❌ Erro ao carregar: " . $e->getMessage() . "<br>";
        }
    } else {
        echo "❌ Arquivo não existe<br>";
    }
    
    echo "<hr>";
}
?>
