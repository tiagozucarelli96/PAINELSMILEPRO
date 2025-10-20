<?php
// fix_enum_properly.php - Corrigir enum insumo_aquisicao corretamente
require_once 'conexao.php';

echo "<h1>🔧 Corrigindo Enum insumo_aquisicao</h1>";

try {
    $schema = 'smilee12_painel_smile';
    
    // 1. Verificar se o enum existe
    echo "<h2>1. Verificando enum existente...</h2>";
    $result = $pdo->query("
        SELECT e.enumlabel
        FROM pg_type t
        JOIN pg_enum e ON t.oid = e.enumtypid
        JOIN pg_namespace n ON t.typnamespace = n.oid
        WHERE n.nspname = '$schema' AND t.typname = 'insumo_aquisicao'
        ORDER BY e.enumsortorder
    ");
    $current_values = $result->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($current_values)) {
        echo "<p>Enum não existe, criando...</p>";
    } else {
        echo "<p>Enum existe com valores: " . implode(', ', $current_values) . "</p>";
    }
    
    // 2. Remover enum existente se houver
    echo "<h2>2. Removendo enum existente...</h2>";
    try {
        $pdo->exec("DROP TYPE IF EXISTS $schema.insumo_aquisicao CASCADE");
        echo "<p style='color: green;'>✅ Enum removido (se existia)</p>";
    } catch (Exception $e) {
        echo "<p style='color: orange;'>⚠️ Aviso: " . $e->getMessage() . "</p>";
    }
    
    // 3. Criar enum com valores corretos
    echo "<h2>3. Criando enum com valores corretos...</h2>";
    $pdo->exec("
        CREATE TYPE $schema.insumo_aquisicao AS ENUM ('mercado', 'preparo', 'fixo')
    ");
    echo "<p style='color: green;'>✅ Enum criado com valores: mercado, preparo, fixo</p>";
    
    // 4. Verificar se a coluna aquisicao existe na tabela lc_insumos
    echo "<h2>4. Verificando coluna aquisicao...</h2>";
    $column_exists = $pdo->query("
        SELECT EXISTS (
            SELECT FROM information_schema.columns
            WHERE table_schema = '$schema' 
            AND table_name = 'lc_insumos' 
            AND column_name = 'aquisicao'
        )
    ")->fetchColumn();
    
    if (!$column_exists) {
        echo "<p>Coluna aquisicao não existe, criando...</p>";
        $pdo->exec("
            ALTER TABLE $schema.lc_insumos 
            ADD COLUMN aquisicao $schema.insumo_aquisicao DEFAULT 'mercado'
        ");
        echo "<p style='color: green;'>✅ Coluna aquisicao criada</p>";
    } else {
        echo "<p>Coluna aquisicao já existe, alterando tipo...</p>";
        $pdo->exec("
            ALTER TABLE $schema.lc_insumos
            ALTER COLUMN aquisicao TYPE $schema.insumo_aquisicao
            USING aquisicao::text::insumo_aquisicao
        ");
        echo "<p style='color: green;'>✅ Coluna aquisicao alterada para usar o enum</p>";
    }
    
    // 5. Atualizar registros existentes com valores inválidos
    echo "<h2>5. Atualizando registros existentes...</h2>";
    $pdo->exec("
        UPDATE $schema.lc_insumos 
        SET aquisicao = 'mercado' 
        WHERE aquisicao IS NULL OR aquisicao NOT IN ('mercado', 'preparo', 'fixo')
    ");
    echo "<p style='color: green;'>✅ Registros atualizados</p>";
    
    // 6. Verificar resultado final
    echo "<h2>6. Verificação final...</h2>";
    $result = $pdo->query("
        SELECT e.enumlabel
        FROM pg_type t
        JOIN pg_enum e ON t.oid = e.enumtypid
        JOIN pg_namespace n ON t.typnamespace = n.oid
        WHERE n.nspname = '$schema' AND t.typname = 'insumo_aquisicao'
        ORDER BY e.enumsortorder
    ");
    $final_values = $result->fetchAll(PDO::FETCH_COLUMN);
    echo "<p style='color: green;'>✅ Enum final: " . implode(', ', $final_values) . "</p>";
    
    echo "<h2>🎉 Correção Concluída!</h2>";
    echo "<p>Agora você pode salvar insumos com os valores: mercado, preparo, fixo</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
}
?>
