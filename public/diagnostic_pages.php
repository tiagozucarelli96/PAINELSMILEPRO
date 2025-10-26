<?php
// diagnostic_pages.php - Diagnóstico específico das páginas problemáticas
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Simular sessão de admin para teste
$_SESSION['logado'] = 1;
$_SESSION['user_id'] = 1;
$_SESSION['nome'] = 'Teste';
$_SESSION['perfil'] = 'ADM';
$_SESSION['perm_usuarios'] = 1;
$_SESSION['perm_comercial_ver'] = 1;

echo "<h1>Diagnóstico das Páginas Problemáticas</h1>";

// Testar cada página específica mencionada pelo usuário
$problematic_pages = [
    'comercial_clientes' => 'comercial_clientes.php',
    'lista_compras' => 'lista_compras.php', 
    'ver' => 'ver.php',
    'estoque_logistico' => 'estoque_logistico.php',
    'config_insumos' => 'config_insumos.php'
];

foreach ($problematic_pages as $page_name => $file) {
    echo "<h2>🔍 Diagnóstico: $page_name</h2>";
    
    // 1. Verificar se arquivo existe
    if (file_exists($file)) {
        echo "✅ Arquivo existe<br>";
    } else {
        echo "❌ Arquivo não existe<br>";
        continue;
    }
    
    // 2. Verificar sintaxe
    $syntax_check = shell_exec("php -l $file 2>&1");
    if (strpos($syntax_check, 'No syntax errors') !== false) {
        echo "✅ Sintaxe OK<br>";
    } else {
        echo "❌ Erro de sintaxe:<br><pre>$syntax_check</pre>";
        continue;
    }
    
    // 3. Verificar includes
    $file_content = file_get_contents($file);
    $includes = [];
    preg_match_all('/require.*?[\'"]([^\'"]+)[\'"]/', $file_content, $matches);
    if (!empty($matches[1])) {
        $includes = $matches[1];
    }
    
    echo "📁 Includes encontrados: " . implode(', ', $includes) . "<br>";
    
    // 4. Verificar se includes existem
    foreach ($includes as $include) {
        if (file_exists($include)) {
            echo "✅ Include OK: $include<br>";
        } else {
            echo "❌ Include faltando: $include<br>";
        }
    }
    
    // 5. Testar carregamento básico
    try {
        ob_start();
        include $file;
        $content = ob_get_clean();
        
        if (!empty($content)) {
            echo "✅ Carregamento OK<br>";
            echo "📊 Tamanho: " . strlen($content) . " bytes<br>";
            
            // Verificar se tem sidebar
            if (strpos($content, 'sidebar') !== false) {
                echo "✅ Sidebar detectada<br>";
            } else {
                echo "⚠️ Sidebar não detectada<br>";
            }
            
            // Verificar se tem conteúdo principal
            if (strpos($content, 'main-content') !== false || strpos($content, 'page-container') !== false) {
                echo "✅ Conteúdo principal detectado<br>";
            } else {
                echo "⚠️ Conteúdo principal não detectado<br>";
            }
        } else {
            echo "❌ Conteúdo vazio<br>";
        }
    } catch (Exception $e) {
        echo "❌ Erro ao carregar: " . $e->getMessage() . "<br>";
    } catch (Error $e) {
        echo "❌ Erro fatal: " . $e->getMessage() . "<br>";
    }
    
    echo "<hr>";
}

// Testar roteamento
echo "<h2>🔗 Teste de Roteamento</h2>";
$routes_to_test = ['comercial_clientes', 'lista_compras', 'ver', 'estoque_logistico'];

foreach ($routes_to_test as $route) {
    echo "Testando rota: $route<br>";
    
    // Simular GET request
    $_GET['page'] = $route;
    
    // Verificar se rota existe no index.php
    $index_content = file_get_contents('index.php');
    if (strpos($index_content, "'$route'") !== false) {
        echo "✅ Rota definida no index.php<br>";
    } else {
        echo "❌ Rota não encontrada no index.php<br>";
    }
    
    echo "<br>";
}
?>
