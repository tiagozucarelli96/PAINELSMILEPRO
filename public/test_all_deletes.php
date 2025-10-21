<?php
// test_all_deletes.php - Testar todas as exclusões do sistema
require_once 'conexao.php';

echo "<h1>🧪 Testando Todas as Exclusões do Sistema</h1>";

try {
    // Testar exclusão de categorias
    echo "<h2>📂 Testando Exclusão de Categorias</h2>";
    $categorias = $pdo->query("SELECT id, nome FROM smilee12_painel_smile.lc_categorias ORDER BY id LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($categorias as $cat) {
        echo "<h3>Categoria ID {$cat['id']} - {$cat['nome']}</h3>";
        
        // Verificar dependências
        $insumos = $pdo->prepare("SELECT COUNT(*) FROM smilee12_painel_smile.lc_insumos WHERE categoria_id = ?");
        $insumos->execute([$cat['id']]);
        $count_insumos = $insumos->fetchColumn();
        
        $receitas = $pdo->prepare("SELECT COUNT(*) FROM smilee12_painel_smile.lc_receitas WHERE categoria_id = ?");
        $receitas->execute([$cat['id']]);
        $count_receitas = $receitas->fetchColumn();
        
        echo "<p>Insumos vinculados: $count_insumos</p>";
        echo "<p>Receitas vinculadas: $count_receitas</p>";
        
        if ($count_insumos > 0 || $count_receitas > 0) {
            echo "<p style='color: orange;'>⚠️ Não pode ser excluída (há dependências)</p>";
        } else {
            echo "<p style='color: green;'>✅ Pode ser excluída</p>";
        }
    }
    
    // Testar exclusão de unidades
    echo "<h2>📏 Testando Exclusão de Unidades</h2>";
    $unidades = $pdo->query("SELECT id, nome, simbolo FROM smilee12_painel_smile.lc_unidades ORDER BY id LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($unidades as $uni) {
        echo "<h3>Unidade ID {$uni['id']} - {$uni['nome']} ({$uni['simbolo']})</h3>";
        
        // Verificar dependências
        $insumos = $pdo->prepare("SELECT COUNT(*) FROM smilee12_painel_smile.lc_insumos WHERE unidade_padrao = ?");
        $insumos->execute([$uni['simbolo']]);
        $count_insumos = $insumos->fetchColumn();
        
        $itens_fixos = $pdo->prepare("SELECT COUNT(*) FROM smilee12_painel_smile.lc_itens_fixos WHERE unidade_id = ?");
        $itens_fixos->execute([$uni['id']]);
        $count_itens = $itens_fixos->fetchColumn();
        
        $componentes = $pdo->prepare("SELECT COUNT(*) FROM smilee12_painel_smile.lc_receita_componentes WHERE unidade_id = ?");
        $componentes->execute([$uni['id']]);
        $count_componentes = $componentes->fetchColumn();
        
        $ficha_comp = $pdo->prepare("SELECT COUNT(*) FROM smilee12_painel_smile.lc_ficha_componentes WHERE unidade_id = ?");
        $ficha_comp->execute([$uni['id']]);
        $count_ficha = $ficha_comp->fetchColumn();
        
        echo "<p>Insumos vinculados: $count_insumos</p>";
        echo "<p>Itens fixos vinculados: $count_itens</p>";
        echo "<p>Componentes de receita vinculados: $count_componentes</p>";
        echo "<p>Componentes de ficha vinculados: $count_ficha</p>";
        
        $total_deps = $count_insumos + $count_itens + $count_componentes + $count_ficha;
        if ($total_deps > 0) {
            echo "<p style='color: orange;'>⚠️ Não pode ser excluída (há $total_deps dependência(s))</p>";
        } else {
            echo "<p style='color: green;'>✅ Pode ser excluída</p>";
        }
    }
    
    // Testar exclusão de insumos
    echo "<h2>🥘 Testando Exclusão de Insumos</h2>";
    $insumos = $pdo->query("SELECT id, nome FROM smilee12_painel_smile.lc_insumos ORDER BY id LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($insumos as $ins) {
        echo "<h3>Insumo ID {$ins['id']} - {$ins['nome']}</h3>";
        
        // Verificar dependências
        $itens_fixos = $pdo->prepare("SELECT COUNT(*) FROM smilee12_painel_smile.lc_itens_fixos WHERE insumo_id = ?");
        $itens_fixos->execute([$ins['id']]);
        $count_itens = $itens_fixos->fetchColumn();
        
        $componentes = $pdo->prepare("SELECT COUNT(*) FROM smilee12_painel_smile.lc_receita_componentes WHERE insumo_id = ?");
        $componentes->execute([$ins['id']]);
        $count_componentes = $componentes->fetchColumn();
        
        $ficha_comp = $pdo->prepare("SELECT COUNT(*) FROM smilee12_painel_smile.lc_ficha_componentes WHERE insumo_id = ?");
        $ficha_comp->execute([$ins['id']]);
        $count_ficha = $ficha_comp->fetchColumn();
        
        echo "<p>Itens fixos vinculados: $count_itens</p>";
        echo "<p>Componentes de receita vinculados: $count_componentes</p>";
        echo "<p>Componentes de ficha vinculados: $count_ficha</p>";
        
        $total_deps = $count_itens + $count_componentes + $count_ficha;
        if ($total_deps > 0) {
            echo "<p style='color: orange;'>⚠️ Não pode ser excluído (há $total_deps dependência(s))</p>";
        } else {
            echo "<p style='color: green;'>✅ Pode ser excluído</p>";
        }
    }
    
    // Testar exclusão de receitas
    echo "<h2>🍳 Testando Exclusão de Receitas</h2>";
    $receitas = $pdo->query("SELECT id, nome FROM smilee12_painel_smile.lc_receitas ORDER BY id LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($receitas as $rec) {
        echo "<h3>Receita ID {$rec['id']} - {$rec['nome']}</h3>";
        
        // Verificar dependências
        $componentes = $pdo->prepare("SELECT COUNT(*) FROM smilee12_painel_smile.lc_receita_componentes WHERE receita_id = ?");
        $componentes->execute([$rec['id']]);
        $count_componentes = $componentes->fetchColumn();
        
        echo "<p>Componentes vinculados: $count_componentes</p>";
        
        if ($count_componentes > 0) {
            echo "<p style='color: orange;'>⚠️ Não pode ser excluída (há $count_componentes componente(s))</p>";
        } else {
            echo "<p style='color: green;'>✅ Pode ser excluída</p>";
        }
    }
    
    echo "<h2>📊 Resumo</h2>";
    echo "<p>Este teste mostra quais registros podem ser excluídos sem problemas e quais têm dependências.</p>";
    echo "<p>Para excluir registros com dependências, primeiro é necessário remover ou atualizar os registros dependentes.</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
}
?>
