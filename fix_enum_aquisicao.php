<?php
// fix_enum_aquisicao.php
require_once 'public/conexao.php';

try {
    echo "🔧 Corrigindo enum insumo_aquisicao...\n";
    
    // Verificar se o enum existe e seus valores
    $result = $pdo->query("SELECT unnest(enum_range(NULL::insumo_aquisicao)) as values");
    $values = $result->fetchAll(PDO::FETCH_COLUMN);
    echo "Valores atuais do enum insumo_aquisicao: " . implode(', ', $values) . "\n";
    
    // Se não existir ou não tiver 'preparo', vamos recriar
    if (!in_array('preparo', $values)) {
        echo "Criando/atualizando enum insumo_aquisicao...\n";
        
        // Dropar o tipo se existir
        $pdo->exec('DROP TYPE IF EXISTS insumo_aquisicao CASCADE');
        
        // Recriar com os valores corretos
        $pdo->exec("CREATE TYPE insumo_aquisicao AS ENUM ('mercado', 'preparo', 'fixo')");
        
        // Alterar a coluna para usar o novo tipo
        $pdo->exec('ALTER TABLE smilee12_painel_smile.lc_insumos ALTER COLUMN aquisicao TYPE insumo_aquisicao USING aquisicao::text::insumo_aquisicao');
        
        echo "✅ Enum insumo_aquisicao criado/atualizado com sucesso!\n";
    } else {
        echo "✅ Enum já possui o valor preparo.\n";
    }
    
    // Verificar novamente
    $result = $pdo->query("SELECT unnest(enum_range(NULL::insumo_aquisicao)) as values");
    $values = $result->fetchAll(PDO::FETCH_COLUMN);
    echo "Valores finais do enum: " . implode(', ', $values) . "\n";
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
}
?>
