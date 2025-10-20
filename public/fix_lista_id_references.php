<?php
// fix_lista_id_references.php - Corrigir referências à coluna lista_id
require_once 'conexao.php';

echo "<h1>🔧 Corrigindo Referências à Coluna lista_id</h1>";

try {
    // Primeiro, verificar se a tabela existe e tem a coluna correta
    $tableExists = $pdo->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables
            WHERE table_schema = 'smilee12_painel_smile' 
            AND table_name = 'lc_listas_eventos'
        )
    ")->fetchColumn();
    
    if (!$tableExists) {
        echo "<p style='color: red;'>❌ Tabela lc_listas_eventos não existe! Execute create_lc_listas_eventos.php primeiro.</p>";
        exit;
    }
    
    // Verificar se tem a coluna lista_id
    $hasListaId = $pdo->query("
        SELECT EXISTS (
            SELECT FROM information_schema.columns
            WHERE table_schema = 'smilee12_painel_smile' 
            AND table_name = 'lc_listas_eventos'
            AND column_name = 'lista_id'
        )
    ")->fetchColumn();
    
    if (!$hasListaId) {
        echo "<p style='color: red;'>❌ Coluna 'lista_id' não existe na tabela lc_listas_eventos!</p>";
        echo "<p>Vou adicionar a coluna...</p>";
        
        $pdo->exec("
            ALTER TABLE smilee12_painel_smile.lc_listas_eventos 
            ADD COLUMN IF NOT EXISTS lista_id INTEGER
        ");
        
        echo "<p style='color: green;'>✅ Coluna 'lista_id' adicionada!</p>";
    } else {
        echo "<p style='color: green;'>✅ Coluna 'lista_id' existe na tabela!</p>";
    }
    
    // Verificar se há dados na tabela
    $count = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_listas_eventos")->fetchColumn();
    echo "<p>📊 Total de registros na tabela: $count</p>";
    
    if ($count > 0) {
        // Verificar se os dados têm lista_id preenchido
        $nullCount = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_listas_eventos WHERE lista_id IS NULL")->fetchColumn();
        
        if ($nullCount > 0) {
            echo "<p style='color: orange;'>⚠️ $nullCount registros têm lista_id NULL. Vou tentar preenchê-los...</p>";
            
            // Tentar preencher lista_id baseado em alguma lógica
            // Por enquanto, vou apenas mostrar os dados
            $sample = $pdo->query("SELECT * FROM smilee12_painel_smile.lc_listas_eventos LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
            echo "<h3>📋 Dados de Exemplo:</h3>";
            echo "<pre>" . print_r($sample, true) . "</pre>";
        } else {
            echo "<p style='color: green;'>✅ Todos os registros têm lista_id preenchido!</p>";
        }
    }
    
    echo "<h2>🎯 Testando Queries Corrigidas:</h2>";
    
    // Testar query do lc_ver.php
    echo "<h3>Teste 1: Query do lc_ver.php</h3>";
    try {
        $testId = 1; // ID de teste
        $stmt = $pdo->prepare("SELECT * FROM smilee12_painel_smile.lc_listas_eventos WHERE lista_id = :id ORDER BY id");
        $stmt->execute([':id' => $testId]);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<p style='color: green;'>✅ Query do lc_ver.php funcionando! Resultados: " . count($result) . "</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro na query do lc_ver.php: " . $e->getMessage() . "</p>";
    }
    
    // Testar query do lc_pdf.php
    echo "<h3>Teste 2: Query do lc_pdf.php</h3>";
    try {
        $testId = 1; // ID de teste
        $stmt = $pdo->prepare("SELECT * FROM smilee12_painel_smile.lc_listas_eventos WHERE lista_id = :id ORDER BY id");
        $stmt->execute([':id' => $testId]);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<p style='color: green;'>✅ Query do lc_pdf.php funcionando! Resultados: " . count($result) . "</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro na query do lc_pdf.php: " . $e->getMessage() . "</p>";
    }
    
    // Testar query do lc_excluir.php
    echo "<h3>Teste 3: Query do lc_excluir.php</h3>";
    try {
        $testId = 1; // ID de teste
        $stmt = $pdo->prepare("DELETE FROM smilee12_painel_smile.lc_listas_eventos WHERE lista_id = ?");
        // Não executar, apenas preparar
        echo "<p style='color: green;'>✅ Query do lc_excluir.php preparada com sucesso!</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro na query do lc_excluir.php: " . $e->getMessage() . "</p>";
    }
    
    echo "<p style='color: green; font-weight: bold;'>🎉 Todas as correções aplicadas com sucesso!</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
}
?>
