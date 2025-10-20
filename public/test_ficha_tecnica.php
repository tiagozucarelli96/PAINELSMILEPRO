<?php
// test_ficha_tecnica.php - Testar ficha técnica
require_once 'conexao.php';

try {
    echo "<h2>🧪 Teste da Ficha Técnica</h2>";
    
    // Verificar se há receitas
    $receitas = $pdo->query("SELECT * FROM lc_receitas LIMIT 1")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($receitas)) {
        echo "<p>❌ Nenhuma receita encontrada. Crie uma receita primeiro.</p>";
        exit;
    }
    
    $receita_id = $receitas[0]['id'];
    echo "<p>✅ Testando com receita ID: $receita_id</p>";
    
    // Testar adicionar componente
    echo "<h3>Teste de Adicionar Componente:</h3>";
    
    // Verificar se há insumos
    $insumos = $pdo->query("SELECT * FROM lc_insumos LIMIT 1")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($insumos)) {
        echo "<p>❌ Nenhum insumo encontrado. Crie um insumo primeiro.</p>";
        exit;
    }
    
    $insumo_id = $insumos[0]['id'];
    echo "<p>✅ Usando insumo ID: $insumo_id</p>";
    
    // Verificar se há unidades
    $unidades = $pdo->query("SELECT * FROM lc_unidades LIMIT 1")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($unidades)) {
        echo "<p>❌ Nenhuma unidade encontrada. Crie uma unidade primeiro.</p>";
        exit;
    }
    
    $unidade_id = $unidades[0]['id'];
    echo "<p>✅ Usando unidade ID: $unidade_id</p>";
    
    // Simular adição de componente
    $quantidade = 1.0;
    $custo_unitario = $insumos[0]['custo_unit'] ?? 0;
    $custo_total = $quantidade * $custo_unitario;
    
    echo "<p>Tentando adicionar componente...</p>";
    echo "<p>Quantidade: $quantidade</p>";
    echo "<p>Custo unitário: $custo_unitario</p>";
    echo "<p>Custo total: $custo_total</p>";
    
    $stmt = $pdo->prepare("
        INSERT INTO lc_receita_componentes 
        (receita_id, insumo_id, quantidade, unidade_id, custo_unitario, custo_total, observacoes)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $result = $stmt->execute([$receita_id, $insumo_id, $quantidade, $unidade_id, $custo_unitario, $custo_total, 'Teste']);
    
    if ($result) {
        echo "<p style='color: green;'>✅ Componente adicionado com sucesso!</p>";
        
        // Verificar se foi realmente adicionado
        $componentes = $pdo->prepare("
            SELECT rc.*, i.nome AS insumo_nome, u.simbolo AS unidade_simbolo
            FROM lc_receita_componentes rc
            LEFT JOIN lc_insumos i ON i.id = rc.insumo_id
            LEFT JOIN lc_unidades u ON u.id = rc.unidade_id
            WHERE rc.receita_id = ?
        ");
        $componentes->execute([$receita_id]);
        $componentes = $componentes->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<p>Componentes na receita: " . count($componentes) . "</p>";
        echo "<pre>" . print_r($componentes, true) . "</pre>";
    } else {
        echo "<p style='color: red;'>❌ Erro ao adicionar componente</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
}
?>
