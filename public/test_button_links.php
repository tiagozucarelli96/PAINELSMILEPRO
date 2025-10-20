<?php
// test_button_links.php - Testar especificamente os links dos botões
require_once 'conexao.php';

echo "<h1>🔗 Testando Links dos Botões</h1>";

try {
    // 1. Buscar uma lista para testar
    $lista = $pdo->query("SELECT id, tipo FROM smilee12_painel_smile.lc_listas LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    
    if (!$lista) {
        echo "<p style='color: red;'>❌ Nenhuma lista encontrada para testar</p>";
        exit;
    }
    
    $id = $lista['id'];
    $tipo = $lista['tipo'];
    
    echo "<p>Testando com Lista ID: $id, Tipo: $tipo</p>";
    
    // 2. Testar cada link individualmente
    echo "<h2>🔍 Testando Links Individuais</h2>";
    
    // Link Ver
    $ver_link = "lc_ver.php?id=$id&tipo=$tipo";
    echo "<h3>👁️ Link Ver:</h3>";
    echo "<p><code>$ver_link</code></p>";
    echo "<p><a href='$ver_link' target='_blank' style='background: #007bff; color: white; padding: 10px; text-decoration: none; border-radius: 5px;'>Testar Ver</a></p>";
    
    // Link PDF
    $pdf_link = "lc_pdf.php?id=$id&tipo=$tipo";
    echo "<h3>📄 Link PDF:</h3>";
    echo "<p><code>$pdf_link</code></p>";
    echo "<p><a href='$pdf_link' target='_blank' style='background: #28a745; color: white; padding: 10px; text-decoration: none; border-radius: 5px;'>Testar PDF</a></p>";
    
    // Link Excluir
    $excluir_link = "lc_excluir.php?id=$id";
    echo "<h3>🗑️ Link Excluir:</h3>";
    echo "<p><code>$excluir_link</code></p>";
    echo "<p><a href='$excluir_link' target='_blank' style='background: #dc3545; color: white; padding: 10px; text-decoration: none; border-radius: 5px;'>Testar Excluir</a></p>";
    
    // 3. Verificar se os arquivos respondem
    echo "<h2>📡 Testando Resposta dos Arquivos</h2>";
    
    // Testar lc_ver.php
    echo "<h3>👁️ Testando lc_ver.php</h3>";
    $ver_url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/lc_ver.php?id=$id&tipo=$tipo";
    echo "<p>URL completa: <code>$ver_url</code></p>";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $ver_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200) {
        echo "<p style='color: green;'>✅ lc_ver.php respondeu (HTTP $http_code)</p>";
        if (strpos($response, 'Fatal error') !== false) {
            echo "<p style='color: red;'>❌ Mas tem erro fatal no conteúdo</p>";
        } else {
            echo "<p style='color: green;'>✅ Conteúdo parece OK</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ lc_ver.php não respondeu corretamente (HTTP $http_code)</p>";
    }
    
    // Testar lc_pdf.php
    echo "<h3>📄 Testando lc_pdf.php</h3>";
    $pdf_url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/lc_pdf.php?id=$id&tipo=$tipo";
    echo "<p>URL completa: <code>$pdf_url</code></p>";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $pdf_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200) {
        echo "<p style='color: green;'>✅ lc_pdf.php respondeu (HTTP $http_code)</p>";
        if (strpos($response, 'Fatal error') !== false) {
            echo "<p style='color: red;'>❌ Mas tem erro fatal no conteúdo</p>";
        } else {
            echo "<p style='color: green;'>✅ Conteúdo parece OK</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ lc_pdf.php não respondeu corretamente (HTTP $http_code)</p>";
    }
    
    // Testar lc_excluir.php
    echo "<h3>🗑️ Testando lc_excluir.php</h3>";
    $excluir_url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/lc_excluir.php?id=$id";
    echo "<p>URL completa: <code>$excluir_url</code></p>";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $excluir_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200 || $http_code == 302) {
        echo "<p style='color: green;'>✅ lc_excluir.php respondeu (HTTP $http_code)</p>";
        if (strpos($response, 'Fatal error') !== false) {
            echo "<p style='color: red;'>❌ Mas tem erro fatal no conteúdo</p>";
        } else {
            echo "<p style='color: green;'>✅ Conteúdo parece OK</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ lc_excluir.php não respondeu corretamente (HTTP $http_code)</p>";
    }
    
    echo "<h2>💡 Instruções</h2>";
    echo "<p>1. Clique nos botões de teste acima</p>";
    echo "<p>2. Se não funcionarem, verifique o console do navegador (F12)</p>";
    echo "<p>3. Verifique se há erros de JavaScript ou PHP</p>";
    echo "<p>4. Teste os links diretos nas URLs completas</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
}
?>
