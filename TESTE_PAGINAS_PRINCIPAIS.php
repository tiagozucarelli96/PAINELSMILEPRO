<?php
/**
 * TESTE DE PÁGINAS PRINCIPAIS
 * Verifica se as principais páginas estão funcionando sem erros SQL
 */

header('Content-Type: text/html; charset=utf-8');
echo "<h1>🧪 TESTE DE PÁGINAS PRINCIPAIS</h1>";

$paginas_para_testar = [
    'dashboard' => 'https://painelsmilepro-production.up.railway.app/index.php?page=dashboard',
    'agenda' => 'https://painelsmilepro-production.up.railway.app/agenda.php',
    'compras' => 'https://painelsmilepro-production.up.railway.app/lc_index.php',
    'usuarios' => 'https://painelsmilepro-production.up.railway.app/usuarios.php',
    'configuracoes' => 'https://painelsmilepro-production.up.railway.app/configuracoes.php',
    'pagamentos' => 'https://painelsmilepro-production.up.railway.app/pagamentos.php',
    'demandas' => 'https://painelsmilepro-production.up.railway.app/demandas.php',
    'comercial' => 'https://painelsmilepro-production.up.railway.app/comercial_degustacoes.php'
];

echo "<h2>1. 🔍 Testando páginas principais...</h2>";

$resultados = [];
$sucessos = 0;
$erros = 0;

foreach ($paginas_para_testar as $nome => $url) {
    echo "<h3>📄 Testando: $nome</h3>";
    echo "<p>URL: <a href='$url' target='_blank'>$url</a></p>";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; TestBot/1.0)');
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo "<p style='color: red;'>❌ Erro de conexão: " . htmlspecialchars($error) . "</p>";
        $resultados[$nome] = ['status' => 'erro', 'erro' => $error];
        $erros++;
    } elseif ($http_code >= 400) {
        echo "<p style='color: red;'>❌ HTTP $http_code</p>";
        $resultados[$nome] = ['status' => 'erro', 'erro' => "HTTP $http_code"];
        $erros++;
    } elseif (strpos($response, 'Fatal error') !== false || strpos($response, 'PDOException') !== false) {
        echo "<p style='color: red;'>❌ Erro SQL detectado</p>";
        
        // Extrair detalhes do erro
        if (preg_match('/Fatal error:.*?PDOException:.*?ERROR:.*?column.*?does not exist/s', $response, $matches)) {
            echo "<p style='color: red;'>🔍 Detalhes: " . htmlspecialchars(substr($matches[0], 0, 200)) . "...</p>";
        }
        
        $resultados[$nome] = ['status' => 'erro_sql', 'erro' => 'Erro SQL detectado'];
        $erros++;
    } elseif (strpos($response, '<!DOCTYPE html>') !== false || strpos($response, '<html') !== false) {
        echo "<p style='color: green;'>✅ Página carregou com sucesso</p>";
        $resultados[$nome] = ['status' => 'sucesso', 'tamanho' => strlen($response)];
        $sucessos++;
    } else {
        echo "<p style='color: orange;'>⚠️ Resposta inesperada (tamanho: " . strlen($response) . " bytes)</p>";
        $resultados[$nome] = ['status' => 'inesperado', 'tamanho' => strlen($response)];
    }
    
    echo "<hr>";
}

echo "<h2>2. 📊 Resumo dos Testes</h2>";
echo "<div style='background: #f0f9ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
echo "<h3>📈 Estatísticas:</h3>";
echo "<p>• <strong>Total de páginas testadas:</strong> " . count($paginas_para_testar) . "</p>";
echo "<p>• <strong>Sucessos:</strong> $sucessos</p>";
echo "<p>• <strong>Erros:</strong> $erros</p>";
echo "<p>• <strong>Taxa de sucesso:</strong> " . round(($sucessos / count($paginas_para_testar)) * 100, 1) . "%</p>";
echo "</div>";

if ($erros > 0) {
    echo "<h3>🔴 Páginas com problemas:</h3>";
    echo "<ul>";
    foreach ($resultados as $nome => $resultado) {
        if ($resultado['status'] === 'erro' || $resultado['status'] === 'erro_sql') {
            echo "<li><strong>$nome:</strong> " . htmlspecialchars($resultado['erro']) . "</li>";
        }
    }
    echo "</ul>";
}

if ($sucessos > 0) {
    echo "<h3>✅ Páginas funcionando:</h3>";
    echo "<ul>";
    foreach ($resultados as $nome => $resultado) {
        if ($resultado['status'] === 'sucesso') {
            echo "<li><strong>$nome:</strong> OK (" . $resultado['tamanho'] . " bytes)</li>";
        }
    }
    echo "</ul>";
}

if ($erros === 0) {
    echo "<div style='background: #d1fae5; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #065f46;'>🎉 TODAS AS PÁGINAS FUNCIONANDO!</h3>";
    echo "<p style='color: #065f46;'>✅ Nenhum erro SQL detectado nas páginas principais!</p>";
    echo "<p><strong>Status:</strong> Sistema 100% funcional!</p>";
    echo "</div>";
} else {
    echo "<div style='background: #fee2e2; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #991b1b;'>⚠️ ALGUMAS PÁGINAS COM PROBLEMAS</h3>";
    echo "<p style='color: #991b1b;'>❌ $erros páginas ainda apresentam erros.</p>";
    echo "<p>Verifique os detalhes acima para mais informações.</p>";
    echo "</div>";
}

// Salvar resultados
file_put_contents('/tmp/teste_paginas_resultados.json', json_encode($resultados, JSON_PRETTY_PRINT));

echo "<h2>💾 Teste concluído</h2>";
echo "<p>Resultados salvos em: /tmp/teste_paginas_resultados.json</p>";
?>
