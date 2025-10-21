<?php
// fix_enum_web.php - Corrigir enum insumo_aquisicao via web
require_once 'conexao.php';

try {
    echo "<h2>🔧 Corrigindo enum insumo_aquisicao...</h2>";
    
    // Verificar se o enum existe e seus valores
    $result = $pdo->query("SELECT unnest(enum_range(NULL::insumo_aquisicao)) as values");
    $values = $result->fetchAll(PDO::FETCH_COLUMN);
    echo "<p>Valores atuais do enum insumo_aquisicao: " . implode(', ', $values) . "</p>";
    
    // Se não existir ou não tiver 'preparo', vamos recriar
    if (!in_array('preparo', $values)) {
        echo "<p>Criando/atualizando enum insumo_aquisicao...</p>";
        
        // Dropar o tipo se existir
        $pdo->exec('DROP TYPE IF EXISTS insumo_aquisicao CASCADE');
        
        // Recriar com os valores corretos
        $pdo->exec("CREATE TYPE insumo_aquisicao AS ENUM ('mercado', 'preparo', 'fixo')");
        
        // Alterar a coluna para usar o novo tipo
        $pdo->exec('ALTER TABLE smilee12_painel_smile.lc_insumos ALTER COLUMN aquisicao TYPE insumo_aquisicao USING aquisicao::text::insumo_aquisicao');
        
        echo "<p style='color: green;'>✅ Enum insumo_aquisicao criado/atualizado com sucesso!</p>";
    } else {
        echo "<p style='color: green;'>✅ Enum já possui o valor preparo.</p>";
    }
    
    // Verificar novamente
    $result = $pdo->query("SELECT unnest(enum_range(NULL::insumo_aquisicao)) as values");
    $values = $result->fetchAll(PDO::FETCH_COLUMN);
    echo "<p>Valores finais do enum: " . implode(', ', $values) . "</p>";
    
    echo "<p><a href='configuracoes.php?tab=insumos'>← Voltar para Insumos</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
}
?>
