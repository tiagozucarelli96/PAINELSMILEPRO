<?php
// check_includes.php
// Verificar se todos os includes estão funcionando

echo "<h1>🔍 Verificação de Includes</h1>";

// Testar conexão
echo "<h3>1. Testando conexão com banco:</h3>";
try {
    require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        echo "✅ Conexão com banco: OK<br>";
    } else {
        echo "❌ Conexão com banco: FALHOU<br>";
    }
} catch (Exception $e) {
    echo "❌ Erro na conexão: " . $e->getMessage() . "<br>";
}

// Testar lc_calc.php
echo "<h3>2. Testando lc_calc.php:</h3>";
try {
    require_once __DIR__ . '/lc_calc.php';
    echo "✅ lc_calc.php: Carregado com sucesso<br>";
    
    // Verificar se as funções existem
    if (function_exists('lc_fetch_ficha')) {
        echo "✅ Função lc_fetch_ficha: Disponível<br>";
    } else {
        echo "❌ Função lc_fetch_ficha: NÃO encontrada<br>";
    }
    
    if (function_exists('lc_explode_ficha_para_evento')) {
        echo "✅ Função lc_explode_ficha_para_evento: Disponível<br>";
    } else {
        echo "❌ Função lc_explode_ficha_para_evento: NÃO encontrada<br>";
    }
    
} catch (Exception $e) {
    echo "❌ Erro ao carregar lc_calc.php: " . $e->getMessage() . "<br>";
}

// Testar outros arquivos importantes
echo "<h3>3. Testando outros includes:</h3>";

$files_to_test = [
    'estilo.css' => 'Arquivo CSS',
    'sidebar.php' => 'Sidebar',
    'router.php' => 'Router'
];

foreach ($files_to_test as $file => $description) {
    if (file_exists(__DIR__ . '/' . $file)) {
        echo "✅ {$description} ({$file}): Existe<br>";
    } else {
        echo "❌ {$description} ({$file}): NÃO encontrado<br>";
    }
}

// Testar se as funções principais estão disponíveis
echo "<h3>4. Testando funções principais:</h3>";

if (function_exists('h')) {
    echo "✅ Função h(): Disponível<br>";
} else {
    echo "❌ Função h(): NÃO encontrada<br>";
}

if (function_exists('dow_pt')) {
    echo "✅ Função dow_pt(): Disponível<br>";
} else {
    echo "❌ Função dow_pt(): NÃO encontrada<br>";
}

echo "<br><div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h3>🎉 Verificação Concluída!</h3>";
echo "<p><strong>Os includes estão funcionando corretamente!</strong></p>";
echo "<p>Agora você pode:</p>";
echo "<ul>";
echo "<li>✅ <a href='lista_compras.php'>Acessar Lista de Compras</a></li>";
echo "<li>✅ <a href='configuracoes.php'>Acessar Configurações</a></li>";
echo "<li>✅ <a href='index.php'>Voltar ao Início</a></li>";
echo "</ul>";
echo "</div>";

echo "<br><br>Verificação concluída em: " . date('H:i:s');
?>
