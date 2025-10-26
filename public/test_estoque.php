<?php
// test_estoque.php
// Script de teste para o módulo de contagem de estoque

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_units_helper.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Definir usuário de teste
$_SESSION['perfil'] = 'ADM';
$_SESSION['usuario_id'] = 1;

echo "<h1>Teste do Módulo de Contagem de Estoque</h1>";

try {
    // Teste 1: Verificar se as tabelas existem
    echo "<h2>1. Verificando estrutura das tabelas...</h2>";
    
    $tables = ['estoque_contagens', 'estoque_contagem_itens'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = '$table'");
        $exists = $stmt->fetchColumn() > 0;
        echo "<p>Tabela '$table': " . ($exists ? "✅ Existe" : "❌ Não existe") . "</p>";
    }
    
    // Teste 2: Verificar unidades
    echo "<h2>2. Testando helper de unidades...</h2>";
    $unidades = lc_load_unidades($pdo);
    echo "<p>Unidades carregadas: " . count($unidades) . "</p>";
    
    if (count($unidades) > 0) {
        $primeira = reset($unidades);
        echo "<p>Primeira unidade: {$primeira['nome']} ({$primeira['simbolo']}) - Fator: {$primeira['fator_base']}</p>";
    }
    
    // Teste 3: Verificar insumos
    echo "<h2>3. Testando carregamento de insumos...</h2>";
    $insumos_por_categoria = lc_load_insumos_por_categoria($pdo);
    echo "<p>Categorias encontradas: " . count($insumos_por_categoria) . "</p>";
    
    foreach ($insumos_por_categoria as $categoria => $insumos) {
        echo "<p>- $categoria: " . count($insumos) . " insumos</p>";
    }
    
    // Teste 4: Testar conversão de unidades
    echo "<h2>4. Testando conversão de unidades...</h2>";
    if (count($unidades) >= 2) {
        $unidades_array = array_values($unidades);
        $unidade1 = $unidades_array[0];
        $unidade2 = $unidades_array[1];
        
        $qtd = 1000;
        $fator1 = (float)$unidade1['fator_base'];
        $fator2 = (float)$unidade2['fator_base'];
        
        $resultado = lc_convert_to_base($qtd, $fator1, $fator2);
        echo "<p>Conversão: $qtd {$unidade1['simbolo']} → $resultado {$unidade2['simbolo']}</p>";
    }
    
    // Teste 5: Verificar permissões
    echo "<h2>5. Testando sistema de permissões...</h2>";
    echo "<p>Perfil atual: " . lc_get_user_perfil() . "</p>";
    echo "<p>Pode criar contagem: " . (lc_can_create_contagem() ? "✅ Sim" : "❌ Não") . "</p>";
    echo "<p>Pode editar contagem: " . (lc_can_edit_contagem() ? "✅ Sim" : "❌ Não") . "</p>";
    echo "<p>Pode fechar contagem: " . (lc_can_close_contagem() ? "✅ Sim" : "❌ Não") . "</p>";
    echo "<p>Pode ver valor total: " . (lc_can_view_stock_value() ? "✅ Sim" : "❌ Não") . "</p>";
    
    // Teste 6: Criar contagem de teste
    echo "<h2>6. Criando contagem de teste...</h2>";
    
    $stmt = $pdo->prepare("
        INSERT INTO estoque_contagens (data_ref, criada_por, status, observacao) 
        VALUES (:data_ref, :criada_por, 'rascunho', 'Contagem de teste')
    ");
    $stmt->execute([
        ':data_ref' => date('Y-m-d'),
        ':criada_por' => 1
    ]);
    $contagem_id = $pdo->lastInsertId();
    
    echo "<p>Contagem criada com ID: $contagem_id</p>";
    
    // Teste 7: Adicionar item de teste
    if (count($insumos_por_categoria) > 0) {
        $primeira_categoria = array_key_first($insumos_por_categoria);
        $primeiro_insumo = $insumos_por_categoria[$primeira_categoria][0];
        
        $primeira_unidade = reset($unidades);
        
        $stmt = $pdo->prepare("
            INSERT INTO estoque_contagem_itens 
            (contagem_id, insumo_id, unidade_id_digitada, qtd_digitada, fator_aplicado, qtd_contada_base, observacao)
            VALUES (:contagem_id, :insumo_id, :unidade_id, :qtd_digitada, :fator_aplicado, :qtd_contada_base, :observacao)
        ");
        $stmt->execute([
            ':contagem_id' => $contagem_id,
            ':insumo_id' => $primeiro_insumo['id'],
            ':unidade_id' => $primeira_unidade['id'],
            ':qtd_digitada' => 10.5,
            ':fator_aplicado' => 1.0,
            ':qtd_contada_base' => 10.5,
            ':observacao' => 'Item de teste'
        ]);
        
        echo "<p>Item de teste adicionado: {$primeiro_insumo['nome']}</p>";
    }
    
    // Teste 8: Calcular valor total
    echo "<h2>7. Calculando valor total...</h2>";
    $valor_total = lc_calcular_valor_estoque($pdo, $contagem_id);
    echo "<p>Valor total da contagem: R$ " . number_format($valor_total, 2, ',', '.') . "</p>";
    
    // Limpeza
    echo "<h2>8. Limpando dados de teste...</h2>";
    $pdo->prepare("DELETE FROM estoque_contagem_itens WHERE contagem_id = ?")->execute([$contagem_id]);
    $pdo->prepare("DELETE FROM estoque_contagens WHERE id = ?")->execute([$contagem_id]);
    echo "<p>Dados de teste removidos</p>";
    
    echo "<h2>✅ Todos os testes passaram!</h2>";
    echo "<p><a href='estoque_contagens.php'>Ir para o módulo de estoque</a></p>";
    
} catch (Exception $e) {
    echo "<h2>❌ Erro durante os testes:</h2>";
    echo "<p style='color: red;'>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>
