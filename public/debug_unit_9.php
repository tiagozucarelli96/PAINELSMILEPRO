<?php
// debug_unit_9.php - Investigar por que a unidade ID 9 não pode ser excluída
require_once 'conexao.php';

echo "<h1>🔍 Investigando Unidade ID 9</h1>";

try {
    // 1. Verificar se a unidade existe
    echo "<h2>1. Verificando se a unidade ID 9 existe:</h2>";
    $unidade = $pdo->query("SELECT * FROM smilee12_painel_smile.lc_unidades WHERE id = 9")->fetch(PDO::FETCH_ASSOC);
    if ($unidade) {
        echo "<p>✅ Unidade encontrada: " . htmlspecialchars($unidade['nome']) . " (" . htmlspecialchars($unidade['simbolo']) . ")</p>";
    } else {
        echo "<p>❌ Unidade ID 9 não encontrada!</p>";
        exit;
    }
    
    // 2. Verificar insumos usando esta unidade
    echo "<h2>2. Verificando insumos que usam esta unidade:</h2>";
    $insumos = $pdo->prepare("
        SELECT i.id, i.nome, i.unidade_padrao, u.simbolo
        FROM smilee12_painel_smile.lc_insumos i
        LEFT JOIN smilee12_painel_smile.lc_unidades u ON u.simbolo = i.unidade_padrao
        WHERE u.id = ?
    ");
    $insumos->execute([9]);
    $insumos_list = $insumos->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($insumos_list)) {
        echo "<p>✅ Nenhum insumo usa esta unidade</p>";
    } else {
        echo "<p>❌ Encontrados " . count($insumos_list) . " insumos usando esta unidade:</p>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Nome</th><th>Unidade Padrão</th><th>Símbolo</th></tr>";
        foreach ($insumos_list as $insumo) {
            echo "<tr><td>" . $insumo['id'] . "</td><td>" . htmlspecialchars($insumo['nome']) . "</td><td>" . htmlspecialchars($insumo['unidade_padrao']) . "</td><td>" . htmlspecialchars($insumo['simbolo']) . "</td></tr>";
        }
        echo "</table>";
    }
    
    // 3. Verificar itens fixos usando esta unidade
    echo "<h2>3. Verificando itens fixos que usam esta unidade:</h2>";
    $itens_fixos = $pdo->prepare("
        SELECT f.id, f.insumo_id, i.nome as insumo_nome, f.unidade_id
        FROM smilee12_painel_smile.lc_itens_fixos f
        LEFT JOIN smilee12_painel_smile.lc_insumos i ON i.id = f.insumo_id
        WHERE f.unidade_id = ?
    ");
    $itens_fixos->execute([9]);
    $itens_list = $itens_fixos->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($itens_list)) {
        echo "<p>✅ Nenhum item fixo usa esta unidade</p>";
    } else {
        echo "<p>❌ Encontrados " . count($itens_list) . " itens fixos usando esta unidade:</p>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Insumo ID</th><th>Nome do Insumo</th><th>Unidade ID</th></tr>";
        foreach ($itens_list as $item) {
            echo "<tr><td>" . $item['id'] . "</td><td>" . $item['insumo_id'] . "</td><td>" . htmlspecialchars($item['insumo_nome']) . "</td><td>" . $item['unidade_id'] . "</td></tr>";
        }
        echo "</table>";
    }
    
    // 4. Verificar componentes de receita usando esta unidade
    echo "<h2>4. Verificando componentes de receita que usam esta unidade:</h2>";
    $componentes = $pdo->prepare("
        SELECT rc.id, rc.receita_id, rc.insumo_id, i.nome as insumo_nome, rc.unidade_id
        FROM smilee12_painel_smile.lc_receita_componentes rc
        LEFT JOIN smilee12_painel_smile.lc_insumos i ON i.id = rc.insumo_id
        WHERE rc.unidade_id = ?
    ");
    $componentes->execute([9]);
    $comp_list = $componentes->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($comp_list)) {
        echo "<p>✅ Nenhum componente de receita usa esta unidade</p>";
    } else {
        echo "<p>❌ Encontrados " . count($comp_list) . " componentes de receita usando esta unidade:</p>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Receita ID</th><th>Insumo ID</th><th>Nome do Insumo</th><th>Unidade ID</th></tr>";
        foreach ($comp_list as $comp) {
            echo "<tr><td>" . $comp['id'] . "</td><td>" . $comp['receita_id'] . "</td><td>" . $comp['insumo_id'] . "</td><td>" . htmlspecialchars($comp['insumo_nome']) . "</td><td>" . $comp['unidade_id'] . "</td></tr>";
        }
        echo "</table>";
    }
    
    // 5. Verificar componentes de ficha (tabela antiga)
    echo "<h2>5. Verificando componentes de ficha (tabela antiga):</h2>";
    $ficha_comp = $pdo->prepare("
        SELECT fc.id, fc.ficha_id, fc.insumo_id, i.nome as insumo_nome, fc.unidade_id
        FROM smilee12_painel_smile.lc_ficha_componentes fc
        LEFT JOIN smilee12_painel_smile.lc_insumos i ON i.id = fc.insumo_id
        WHERE fc.unidade_id = ?
    ");
    $ficha_comp->execute([9]);
    $ficha_list = $ficha_comp->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($ficha_list)) {
        echo "<p>✅ Nenhum componente de ficha usa esta unidade</p>";
    } else {
        echo "<p>❌ Encontrados " . count($ficha_list) . " componentes de ficha usando esta unidade:</p>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Ficha ID</th><th>Insumo ID</th><th>Nome do Insumo</th><th>Unidade ID</th></tr>";
        foreach ($ficha_list as $ficha) {
            echo "<tr><td>" . $ficha['id'] . "</td><td>" . $ficha['ficha_id'] . "</td><td>" . $ficha['insumo_id'] . "</td><td>" . htmlspecialchars($ficha['insumo_nome']) . "</td><td>" . $ficha['unidade_id'] . "</td></tr>";
        }
        echo "</table>";
    }
    
    // 6. Tentar excluir manualmente
    echo "<h2>6. Tentando excluir manualmente:</h2>";
    try {
        $pdo->exec("DELETE FROM smilee12_painel_smile.lc_unidades WHERE id = 9");
        echo "<p style='color: green;'>✅ Unidade ID 9 excluída com sucesso!</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao excluir: " . $e->getMessage() . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro geral: " . $e->getMessage() . "</p>";
}
?>
