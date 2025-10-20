<?php
// test_final_fixes.php - Testar se todas as correções funcionam
require_once 'conexao.php';

echo "<h1>🧪 Testando Todas as Correções</h1>";

try {
    $schema = 'smilee12_painel_smile';
    
    // 1. Testar enum insumo_aquisicao
    echo "<h2>1. Testando Enum insumo_aquisicao</h2>";
    $result = $pdo->query("
        SELECT e.enumlabel
        FROM pg_type t
        JOIN pg_enum e ON t.oid = e.enumtypid
        JOIN pg_namespace n ON t.typnamespace = n.oid
        WHERE n.nspname = '$schema' AND t.typname = 'insumo_aquisicao'
        ORDER BY e.enumsortorder
    ");
    $enum_values = $result->fetchAll(PDO::FETCH_COLUMN);
    echo "<p>Valores do enum: " . implode(', ', $enum_values) . "</p>";
    
    if (in_array('preparo', $enum_values) && in_array('fixo', $enum_values)) {
        echo "<p style='color: green;'>✅ Enum funcionando corretamente</p>";
    } else {
        echo "<p style='color: red;'>❌ Enum não tem os valores corretos</p>";
    }
    
    // 2. Testar inserção de insumo
    echo "<h2>2. Testando Inserção de Insumo</h2>";
    try {
        // Pegar uma categoria e unidade existentes
        $categoria_id = $pdo->query("SELECT id FROM $schema.lc_categorias LIMIT 1")->fetchColumn();
        $unidade_id = $pdo->query("SELECT id FROM $schema.lc_unidades LIMIT 1")->fetchColumn();
        
        if ($categoria_id && $unidade_id) {
            $stmt = $pdo->prepare("
                INSERT INTO $schema.lc_insumos 
                (nome, categoria_id, unidade_padrao, custo_unit, aquisicao, ativo) 
                VALUES (?, ?, (SELECT simbolo FROM $schema.lc_unidades WHERE id = ?), ?, ?, true)
            ");
            $stmt->execute([
                'Teste Insumo ' . time(),
                $categoria_id,
                $unidade_id,
                10.50,
                'preparo'
            ]);
            echo "<p style='color: green;'>✅ Insumo inserido com sucesso</p>";
            
            // Remover o insumo de teste
            $pdo->exec("DELETE FROM $schema.lc_insumos WHERE nome LIKE 'Teste Insumo%'");
            echo "<p>Insumo de teste removido</p>";
        } else {
            echo "<p style='color: orange;'>⚠️ Não há categorias ou unidades para testar</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao inserir insumo: " . $e->getMessage() . "</p>";
    }
    
    // 3. Testar exclusão de unidade
    echo "<h2>3. Testando Exclusão de Unidade</h2>";
    try {
        // Criar uma unidade de teste
        $pdo->exec("
            INSERT INTO $schema.lc_unidades (nome, simbolo, tipo, fator_base, ativo) 
            VALUES ('Unidade Teste', 'UT', 'unidade', 1.0, true)
        ");
        $unidade_teste_id = $pdo->query("SELECT id FROM $schema.lc_unidades WHERE simbolo = 'UT'")->fetchColumn();
        
        // Tentar excluir
        $pdo->exec("DELETE FROM $schema.lc_unidades WHERE id = $unidade_teste_id");
        echo "<p style='color: green;'>✅ Unidade de teste excluída com sucesso</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao excluir unidade: " . $e->getMessage() . "</p>";
    }
    
    // 4. Testar ficha técnica
    echo "<h2>4. Testando Ficha Técnica</h2>";
    try {
        // Verificar se há receitas
        $receitas = $pdo->query("SELECT COUNT(*) FROM $schema.lc_receitas")->fetchColumn();
        if ($receitas > 0) {
            echo "<p>Há $receitas receita(s) disponível(is) para teste</p>";
            echo "<p><a href='ficha_tecnica_simple.php?id=1' target='_blank'>Testar Ficha Técnica</a></p>";
        } else {
            echo "<p style='color: orange;'>⚠️ Nenhuma receita para testar</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao verificar receitas: " . $e->getMessage() . "</p>";
    }
    
    // 5. Testar trigger
    echo "<h2>5. Testando Trigger</h2>";
    try {
        $triggers = $pdo->query("
            SELECT trigger_name 
            FROM information_schema.triggers 
            WHERE trigger_schema = '$schema' 
            AND event_object_table = 'lc_receita_componentes'
        ")->fetchAll(PDO::FETCH_COLUMN);
        
        if (in_array('trigger_atualizar_custo_receita', $triggers)) {
            echo "<p style='color: green;'>✅ Trigger existe e deve funcionar</p>";
        } else {
            echo "<p style='color: orange;'>⚠️ Trigger não encontrado</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao verificar trigger: " . $e->getMessage() . "</p>";
    }
    
    echo "<h2>📊 Resumo dos Testes</h2>";
    echo "<p>Se todos os testes passaram, o sistema deve estar funcionando corretamente!</p>";
    echo "<p><strong>Próximos passos:</strong></p>";
    echo "<ul>";
    echo "<li>Teste a exclusão da unidade ID 9 novamente</li>";
    echo "<li>Teste a adição de insumos com tipo 'preparo'</li>";
    echo "<li>Teste a ficha técnica</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro geral: " . $e->getMessage() . "</p>";
}
?>
