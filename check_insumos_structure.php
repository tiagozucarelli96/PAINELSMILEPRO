<?php
// check_insumos_structure.php - Verificar estrutura da tabela lc_insumos

require_once 'public/conexao.php';

try {
    echo "Verificando estrutura da tabela lc_insumos...\n\n";
    
    // Verificar se a tabela existe
    $tableExists = $pdo->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables
            WHERE table_schema = 'smilee12_painel_smile' AND table_name = 'lc_insumos'
        )
    ")->fetchColumn();
    
    if (!$tableExists) {
        echo "❌ Tabela lc_insumos não existe!\n";
        exit;
    }
    
    echo "✅ Tabela lc_insumos existe.\n\n";
    
    // Listar todas as colunas da tabela
    $columns = $pdo->query("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_schema = 'smilee12_painel_smile' 
        AND table_name = 'lc_insumos'
        ORDER BY ordinal_position
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Colunas da tabela lc_insumos:\n";
    echo "================================\n";
    foreach ($columns as $col) {
        echo "- {$col['column_name']} ({$col['data_type']}) - Nullable: {$col['is_nullable']} - Default: {$col['column_default']}\n";
    }
    
    echo "\n";
    
    // Verificar se existe coluna ativo
    $hasAtivo = false;
    foreach ($columns as $col) {
        if ($col['column_name'] === 'ativo') {
            $hasAtivo = true;
            break;
        }
    }
    
    if ($hasAtivo) {
        echo "✅ Coluna 'ativo' existe.\n";
    } else {
        echo "❌ Coluna 'ativo' NÃO existe.\n";
        echo "Vou adicionar a coluna 'ativo'...\n";
        
        $pdo->exec("
            ALTER TABLE smilee12_painel_smile.lc_insumos 
            ADD COLUMN ativo BOOLEAN DEFAULT true
        ");
        
        echo "✅ Coluna 'ativo' adicionada com sucesso!\n";
    }
    
    // Verificar dados existentes
    $count = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_insumos")->fetchColumn();
    echo "\nTotal de insumos: $count\n";
    
    if ($count > 0) {
        $sample = $pdo->query("SELECT id, nome, ativo FROM smilee12_painel_smile.lc_insumos LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
        echo "\nAmostra dos dados:\n";
        foreach ($sample as $row) {
            echo "- ID: {$row['id']}, Nome: {$row['nome']}, Ativo: " . ($row['ativo'] ? 'true' : 'false') . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
}
?>
