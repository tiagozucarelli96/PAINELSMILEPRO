<?php
// test_all_fixes.php - Testar todas as correções
require_once 'conexao.php';

echo "<h1>🧪 Teste de Todas as Correções</h1>";

try {
    // 1. Testar enum insumo_aquisicao
    echo "<h2>1. Testando enum insumo_aquisicao</h2>";
    $result = $pdo->query("SELECT unnest(enum_range(NULL::insumo_aquisicao)) as values");
    $values = $result->fetchAll(PDO::FETCH_COLUMN);
    echo "<p>Valores do enum: " . implode(', ', $values) . "</p>";
    
    if (in_array('preparo', $values)) {
        echo "<p style='color: green;'>✅ Enum insumo_aquisicao OK</p>";
    } else {
        echo "<p style='color: red;'>❌ Enum insumo_aquisicao precisa ser corrigido</p>";
    }
    
    // 2. Testar estrutura da tabela lc_insumos
    echo "<h2>2. Testando estrutura da tabela lc_insumos</h2>";
    $result = $pdo->query("
        SELECT column_name, data_type 
        FROM information_schema.columns 
        WHERE table_schema = 'smilee12_painel_smile' 
        AND table_name = 'lc_insumos'
        ORDER BY ordinal_position
    ");
    $columns = $result->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Coluna</th><th>Tipo</th></tr>";
    foreach ($columns as $col) {
        echo "<tr><td>" . $col['column_name'] . "</td><td>" . $col['data_type'] . "</td></tr>";
    }
    echo "</table>";
    
    // 3. Testar se há receitas
    echo "<h2>3. Testando receitas</h2>";
    $receitas = $pdo->query("SELECT COUNT(*) FROM lc_receitas")->fetchColumn();
    echo "<p>Total de receitas: $receitas</p>";
    
    if ($receitas > 0) {
        echo "<p style='color: green;'>✅ Há receitas para testar</p>";
        
        // Testar adicionar componente
        $receita_id = $pdo->query("SELECT id FROM lc_receitas LIMIT 1")->fetchColumn();
        $insumo_id = $pdo->query("SELECT id FROM lc_insumos LIMIT 1")->fetchColumn();
        $unidade_id = $pdo->query("SELECT id FROM lc_unidades LIMIT 1")->fetchColumn();
        
        if ($insumo_id && $unidade_id) {
            echo "<p>Testando adicionar componente à receita $receita_id...</p>";
            
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO lc_receita_componentes 
                    (receita_id, insumo_id, quantidade, unidade_id, custo_unitario, custo_total, observacoes)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $result = $stmt->execute([$receita_id, $insumo_id, 1.0, $unidade_id, 0, 0, 'Teste']);
                
                if ($result) {
                    echo "<p style='color: green;'>✅ Componente adicionado com sucesso!</p>";
                } else {
                    echo "<p style='color: red;'>❌ Erro ao adicionar componente</p>";
                }
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
            }
        } else {
            echo "<p style='color: orange;'>⚠️ Precisa de insumos e unidades para testar</p>";
        }
    } else {
        echo "<p style='color: orange;'>⚠️ Nenhuma receita encontrada</p>";
    }
    
    // 4. Testar categorias
    echo "<h2>4. Testando categorias</h2>";
    $categorias = $pdo->query("SELECT COUNT(*) FROM lc_categorias")->fetchColumn();
    echo "<p>Total de categorias: $categorias</p>";
    
    if ($categorias > 0) {
        echo "<p style='color: green;'>✅ Há categorias para testar</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ Nenhuma categoria encontrada</p>";
    }
    
    // 5. Testar unidades
    echo "<h2>5. Testando unidades</h2>";
    $unidades = $pdo->query("SELECT COUNT(*) FROM lc_unidades")->fetchColumn();
    echo "<p>Total de unidades: $unidades</p>";
    
    if ($unidades > 0) {
        echo "<p style='color: green;'>✅ Há unidades para testar</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ Nenhuma unidade encontrada</p>";
    }
    
    echo "<h2>🎯 Resumo</h2>";
    echo "<p><a href='configuracoes.php?tab=categorias'>Testar Categorias</a></p>";
    echo "<p><a href='configuracoes.php?tab=unidades'>Testar Unidades</a></p>";
    echo "<p><a href='configuracoes.php?tab=insumos'>Testar Insumos</a></p>";
    echo "<p><a href='configuracoes.php?tab=receitas'>Testar Receitas</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro geral: " . $e->getMessage() . "</p>";
}
?>
