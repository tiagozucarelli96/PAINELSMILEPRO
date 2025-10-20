<?php
// debug_buttons.php - Debug dos botões que não funcionam
require_once 'conexao.php';

echo "<h1>🔍 Debug dos Botões</h1>";

try {
    // 1. Verificar se os arquivos existem
    echo "<h2>📁 Verificando Arquivos</h2>";
    
    $files = ['lc_ver.php', 'lc_pdf.php', 'lc_excluir.php'];
    foreach ($files as $file) {
        if (file_exists($file)) {
            echo "<p style='color: green;'>✅ $file existe</p>";
        } else {
            echo "<p style='color: red;'>❌ $file NÃO existe</p>";
        }
    }
    
    // 2. Testar conexão com banco
    echo "<h2>🗄️ Testando Conexão com Banco</h2>";
    $test_query = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_listas");
    $count = $test_query->fetchColumn();
    echo "<p style='color: green;'>✅ Conexão OK - $count listas encontradas</p>";
    
    // 3. Testar lc_ver.php diretamente
    echo "<h2>👁️ Testando lc_ver.php</h2>";
    $test_id = 2;
    echo "<p>Testando com ID: $test_id</p>";
    
    // Simular requisição GET
    $_GET['id'] = $test_id;
    $_GET['tipo'] = 'compras';
    
    echo "<p><a href='lc_ver.php?id=$test_id&tipo=compras' target='_blank'>🔗 Testar lc_ver.php</a></p>";
    
    // 4. Testar lc_pdf.php diretamente
    echo "<h2>📄 Testando lc_pdf.php</h2>";
    echo "<p><a href='lc_pdf.php?id=$test_id&tipo=compras' target='_blank'>🔗 Testar lc_pdf.php</a></p>";
    
    // 5. Testar lc_excluir.php diretamente
    echo "<h2>🗑️ Testando lc_excluir.php</h2>";
    echo "<p><a href='lc_excluir.php?id=$test_id' target='_blank'>🔗 Testar lc_excluir.php</a></p>";
    
    // 6. Verificar se há erros nos arquivos
    echo "<h2>⚠️ Verificando Erros nos Arquivos</h2>";
    
    // Verificar lc_ver.php
    $lc_ver_content = file_get_contents('lc_ver.php');
    if (strpos($lc_ver_content, 'error_reporting') === false) {
        echo "<p style='color: orange;'>⚠️ lc_ver.php não tem error_reporting ativado</p>";
    }
    
    // Verificar lc_pdf.php
    $lc_pdf_content = file_get_contents('lc_pdf.php');
    if (strpos($lc_pdf_content, 'error_reporting') === false) {
        echo "<p style='color: orange;'>⚠️ lc_pdf.php não tem error_reporting ativado</p>";
    }
    
    // 7. Testar query específica que pode estar falhando
    echo "<h2>🔍 Testando Query Específica</h2>";
    
    try {
        $stmt = $pdo->prepare("
            SELECT l.*, u.nome AS criado_por_nome
            FROM smilee12_painel_smile.lc_listas l
            LEFT JOIN smilee12_painel_smile.usuarios u ON u.id = l.criado_por
            WHERE l.id = :id
        ");
        $stmt->execute([':id' => $test_id]);
        $lista = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($lista) {
            echo "<p style='color: green;'>✅ Query principal funcionando</p>";
            echo "<p>Lista encontrada: " . htmlspecialchars($lista['tipo']) . "</p>";
        } else {
            echo "<p style='color: red;'>❌ Lista não encontrada</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro na query principal: " . $e->getMessage() . "</p>";
    }
    
    // 8. Verificar se há redirecionamentos
    echo "<h2>🔄 Verificando Redirecionamentos</h2>";
    
    // Verificar se há header() ou exit() no código
    if (strpos($lc_ver_content, 'header(') !== false) {
        echo "<p style='color: orange;'>⚠️ lc_ver.php tem redirecionamentos (header)</p>";
    }
    
    if (strpos($lc_pdf_content, 'header(') !== false) {
        echo "<p style='color: orange;'>⚠️ lc_pdf.php tem redirecionamentos (header)</p>";
    }
    
    // 9. Criar versão de teste sem redirecionamentos
    echo "<h2>🧪 Criando Versão de Teste</h2>";
    
    // Criar lc_ver_test.php
    $lc_ver_test = str_replace('header(', '// header(', $lc_ver_content);
    $lc_ver_test = str_replace('exit;', '// exit;', $lc_ver_test);
    file_put_contents('lc_ver_test.php', $lc_ver_test);
    echo "<p><a href='lc_ver_test.php?id=$test_id&tipo=compras' target='_blank'>🔗 Testar lc_ver.php (sem redirecionamentos)</a></p>";
    
    echo "<h2>📋 Resumo do Debug</h2>";
    echo "<p>1. Verifique se os arquivos existem</p>";
    echo "<p>2. Teste os links diretos acima</p>";
    echo "<p>3. Verifique se há erros no console do navegador</p>";
    echo "<p>4. Teste a versão sem redirecionamentos</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro geral: " . $e->getMessage() . "</p>";
}
?>
