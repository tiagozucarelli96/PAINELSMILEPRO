<?php
// test_individual_files.php - Testar cada arquivo individualmente
require_once 'conexao.php';

echo "<h1>🧪 Testando Arquivos Individuais</h1>";

$test_id = 2;
$test_tipo = 'compras';

// 1. Testar lc_ver.php
echo "<h2>👁️ Testando lc_ver.php</h2>";
echo "<p>Testando com ID: $test_id, Tipo: $test_tipo</p>";

// Capturar output do lc_ver.php
ob_start();
try {
    $_GET['id'] = $test_id;
    $_GET['tipo'] = $test_tipo;
    include 'lc_ver.php';
    $output = ob_get_clean();
    
    if (strpos($output, 'Fatal error') !== false || strpos($output, 'Warning') !== false || strpos($output, 'Notice') !== false) {
        echo "<p style='color: red;'>❌ lc_ver.php tem erros:</p>";
        echo "<pre style='background: #f8f8f8; padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($output) . "</pre>";
    } else {
        echo "<p style='color: green;'>✅ lc_ver.php executou sem erros fatais</p>";
        echo "<p>Tamanho da saída: " . strlen($output) . " caracteres</p>";
        
        // Verificar se tem conteúdo HTML
        if (strpos($output, '<html') !== false || strpos($output, '<!DOCTYPE') !== false) {
            echo "<p style='color: green;'>✅ lc_ver.php gerou HTML</p>";
        } else {
            echo "<p style='color: orange;'>⚠️ lc_ver.php não gerou HTML completo</p>";
        }
    }
} catch (Exception $e) {
    ob_end_clean();
    echo "<p style='color: red;'>❌ Erro ao executar lc_ver.php: " . $e->getMessage() . "</p>";
}

// 2. Testar lc_pdf.php
echo "<h2>📄 Testando lc_pdf.php</h2>";
echo "<p>Testando com ID: $test_id, Tipo: $test_tipo</p>";

ob_start();
try {
    $_GET['id'] = $test_id;
    $_GET['tipo'] = $test_tipo;
    include 'lc_pdf.php';
    $output = ob_get_clean();
    
    if (strpos($output, 'Fatal error') !== false || strpos($output, 'Warning') !== false || strpos($output, 'Notice') !== false) {
        echo "<p style='color: red;'>❌ lc_pdf.php tem erros:</p>";
        echo "<pre style='background: #f8f8f8; padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($output) . "</pre>";
    } else {
        echo "<p style='color: green;'>✅ lc_pdf.php executou sem erros fatais</p>";
        echo "<p>Tamanho da saída: " . strlen($output) . " caracteres</p>";
        
        // Verificar se é PDF
        if (strpos($output, '%PDF') !== false) {
            echo "<p style='color: green;'>✅ lc_pdf.php gerou PDF</p>";
        } else {
            echo "<p style='color: orange;'>⚠️ lc_pdf.php não gerou PDF válido</p>";
        }
    }
} catch (Exception $e) {
    ob_end_clean();
    echo "<p style='color: red;'>❌ Erro ao executar lc_pdf.php: " . $e->getMessage() . "</p>";
}

// 3. Testar lc_excluir.php
echo "<h2>🗑️ Testando lc_excluir.php</h2>";
echo "<p>Testando com ID: $test_id</p>";

ob_start();
try {
    $_GET['id'] = $test_id;
    include 'lc_excluir.php';
    $output = ob_get_clean();
    
    if (strpos($output, 'Fatal error') !== false || strpos($output, 'Warning') !== false || strpos($output, 'Notice') !== false) {
        echo "<p style='color: red;'>❌ lc_excluir.php tem erros:</p>";
        echo "<pre style='background: #f8f8f8; padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($output) . "</pre>";
    } else {
        echo "<p style='color: green;'>✅ lc_excluir.php executou sem erros fatais</p>";
        echo "<p>Tamanho da saída: " . strlen($output) . " caracteres</p>";
        
        // Verificar se redirecionou
        if (strpos($output, 'Location:') !== false) {
            echo "<p style='color: green;'>✅ lc_excluir.php fez redirecionamento</p>";
        } else {
            echo "<p style='color: orange;'>⚠️ lc_excluir.php não fez redirecionamento</p>";
        }
    }
} catch (Exception $e) {
    ob_end_clean();
    echo "<p style='color: red;'>❌ Erro ao executar lc_excluir.php: " . $e->getMessage() . "</p>";
}

echo "<h2>🔗 Links Diretos para Teste</h2>";
echo "<p><a href='lc_ver.php?id=$test_id&tipo=$test_tipo' target='_blank'>👁️ Ver Lista (ID: $test_id)</a></p>";
echo "<p><a href='lc_pdf.php?id=$test_id&tipo=$test_tipo' target='_blank'>📄 PDF Lista (ID: $test_id)</a></p>";
echo "<p><a href='lc_excluir.php?id=$test_id' target='_blank'>🗑️ Excluir Lista (ID: $test_id)</a></p>";

echo "<h2>💡 Próximos Passos</h2>";
echo "<p>1. Clique nos links acima para testar</p>";
echo "<p>2. Verifique se há erros no console do navegador (F12)</p>";
echo "<p>3. Verifique se os arquivos estão sendo carregados corretamente</p>";
?>
