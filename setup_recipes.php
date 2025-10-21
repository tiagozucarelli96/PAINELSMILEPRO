<?php
// setup_recipes.php - Executar script de criação das tabelas de receitas

require_once 'public/conexao.php';

try {
    echo "Executando script de criação das tabelas de receitas...\n";
    
    $sql = file_get_contents('create_recipes_tables.sql');
    $pdo->exec($sql);
    
    echo "✅ Tabelas de receitas criadas com sucesso!\n";
    
    // Verificar se as tabelas foram criadas
    $tables = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'smilee12_painel_smile' 
        AND table_name IN ('lc_receitas', 'lc_receita_componentes')
        ORDER BY table_name
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Tabelas criadas: " . implode(', ', $tables) . "\n";
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
}
?>
