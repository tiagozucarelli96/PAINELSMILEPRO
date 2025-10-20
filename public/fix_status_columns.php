<?php
// fix_status_columns.php - Corrigir colunas de status
require_once 'conexao.php';

echo "<h1>🔧 Corrigindo Colunas de Status</h1>";

try {
    // Verificar e corrigir lc_categorias
    echo "<h2>📂 Corrigindo lc_categorias</h2>";
    
    // Verificar se a coluna ativo existe
    $result = $pdo->query("
        SELECT column_name, data_type 
        FROM information_schema.columns 
        WHERE table_schema = 'smilee12_painel_smile' 
        AND table_name = 'lc_categorias' 
        AND column_name = 'ativo'
    ");
    $ativo_exists = $result->fetch();
    
    if (!$ativo_exists) {
        echo "<p>Adicionando coluna 'ativo' em lc_categorias...</p>";
        $pdo->exec("ALTER TABLE smilee12_painel_smile.lc_categorias ADD COLUMN ativo BOOLEAN DEFAULT true");
        echo "<p style='color: green;'>✅ Coluna 'ativo' adicionada em lc_categorias</p>";
    } else {
        echo "<p>Coluna 'ativo' já existe em lc_categorias (tipo: " . $ativo_exists['data_type'] . ")</p>";
    }
    
    // Verificar e corrigir lc_unidades
    echo "<h2>📏 Corrigindo lc_unidades</h2>";
    
    $result = $pdo->query("
        SELECT column_name, data_type 
        FROM information_schema.columns 
        WHERE table_schema = 'smilee12_painel_smile' 
        AND table_name = 'lc_unidades' 
        AND column_name = 'ativo'
    ");
    $ativo_exists = $result->fetch();
    
    if (!$ativo_exists) {
        echo "<p>Adicionando coluna 'ativo' em lc_unidades...</p>";
        $pdo->exec("ALTER TABLE smilee12_painel_smile.lc_unidades ADD COLUMN ativo BOOLEAN DEFAULT true");
        echo "<p style='color: green;'>✅ Coluna 'ativo' adicionada em lc_unidades</p>";
    } else {
        echo "<p>Coluna 'ativo' já existe em lc_unidades (tipo: " . $ativo_exists['data_type'] . ")</p>";
    }
    
    // Verificar e corrigir lc_insumos
    echo "<h2>🥘 Corrigindo lc_insumos</h2>";
    
    $result = $pdo->query("
        SELECT column_name, data_type 
        FROM information_schema.columns 
        WHERE table_schema = 'smilee12_painel_smile' 
        AND table_name = 'lc_insumos' 
        AND column_name = 'ativo'
    ");
    $ativo_exists = $result->fetch();
    
    if (!$ativo_exists) {
        echo "<p>Adicionando coluna 'ativo' em lc_insumos...</p>";
        $pdo->exec("ALTER TABLE smilee12_painel_smile.lc_insumos ADD COLUMN ativo BOOLEAN DEFAULT true");
        echo "<p style='color: green;'>✅ Coluna 'ativo' adicionada em lc_insumos</p>";
    } else {
        echo "<p>Coluna 'ativo' já existe em lc_insumos (tipo: " . $ativo_exists['data_type'] . ")</p>";
    }
    
    // Atualizar registros existentes para ter valor padrão
    echo "<h2>🔄 Atualizando registros existentes</h2>";
    
    $pdo->exec("UPDATE smilee12_painel_smile.lc_categorias SET ativo = true WHERE ativo IS NULL");
    $pdo->exec("UPDATE smilee12_painel_smile.lc_unidades SET ativo = true WHERE ativo IS NULL");
    $pdo->exec("UPDATE smilee12_painel_smile.lc_insumos SET ativo = true WHERE ativo IS NULL");
    
    echo "<p style='color: green;'>✅ Registros atualizados com valores padrão</p>";
    
    // Testar uma atualização
    echo "<h2>🧪 Testando atualização de status</h2>";
    
    // Pegar uma categoria para testar
    $categoria = $pdo->query("SELECT id, nome, ativo FROM smilee12_painel_smile.lc_categorias LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($categoria) {
        echo "<p>Testando categoria ID {$categoria['id']} - Status atual: " . ($categoria['ativo'] ? 'Ativa' : 'Inativa') . "</p>";
        
        $new_status = !$categoria['ativo'];
        $stmt = $pdo->prepare("UPDATE smilee12_painel_smile.lc_categorias SET ativo = ? WHERE id = ?");
        $result = $stmt->execute([$new_status, $categoria['id']]);
        
        if ($result) {
            echo "<p style='color: green;'>✅ Teste de atualização bem-sucedido!</p>";
            
            // Reverter para não afetar os dados
            $stmt->execute([$categoria['ativo'], $categoria['id']]);
            echo "<p>Status revertido para o valor original</p>";
        } else {
            echo "<p style='color: red;'>❌ Erro no teste de atualização</p>";
        }
    }
    
    echo "<p><a href='configuracoes.php?tab=categorias'>Testar Categorias</a></p>";
    echo "<p><a href='configuracoes.php?tab=unidades'>Testar Unidades</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
}
?>
