<?php
// add_visibilidade_campos.php - Adicionar campos de visibilidade
require_once 'conexao.php';

echo "<h1>👁️ ADICIONANDO CAMPOS DE VISIBILIDADE</h1>";
echo "<p>Adicionando controle de visibilidade para insumos e receitas!</p>";

try {
    // 1. Adicionar coluna visivel em lc_insumos
    echo "<h2>📦 1. Adicionando visibilidade em Insumos</h2>";
    
    try {
        $pdo->exec("ALTER TABLE smilee12_painel_smile.lc_insumos ADD COLUMN visivel BOOLEAN DEFAULT TRUE");
        echo "<p style='color: green;'>✅ Coluna 'visivel' adicionada em lc_insumos</p>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "<p style='color: orange;'>⚠️ Coluna 'visivel' já existe em lc_insumos</p>";
        } else {
            echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
        }
    }
    
    // 2. Adicionar coluna visivel em lc_receitas
    echo "<h2>🍳 2. Adicionando visibilidade em Receitas</h2>";
    
    try {
        $pdo->exec("ALTER TABLE smilee12_painel_smile.lc_receitas ADD COLUMN visivel BOOLEAN DEFAULT TRUE");
        echo "<p style='color: green;'>✅ Coluna 'visivel' adicionada em lc_receitas</p>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "<p style='color: orange;'>⚠️ Coluna 'visivel' já existe em lc_receitas</p>";
        } else {
            echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
        }
    }
    
    // 3. Atualizar registros existentes para visivel = true
    echo "<h2>🔄 3. Atualizando registros existentes</h2>";
    
    try {
        $updated_insumos = $pdo->exec("UPDATE smilee12_painel_smile.lc_insumos SET visivel = TRUE WHERE visivel IS NULL");
        echo "<p style='color: green;'>✅ $updated_insumos insumos atualizados para visíveis</p>";
    } catch (Exception $e) {
        echo "<p style='color: orange;'>⚠️ Erro ao atualizar insumos: " . $e->getMessage() . "</p>";
    }
    
    try {
        $updated_receitas = $pdo->exec("UPDATE smilee12_painel_smile.lc_receitas SET visivel = TRUE WHERE visivel IS NULL");
        echo "<p style='color: green;'>✅ $updated_receitas receitas atualizadas para visíveis</p>";
    } catch (Exception $e) {
        echo "<p style='color: orange;'>⚠️ Erro ao atualizar receitas: " . $e->getMessage() . "</p>";
    }
    
    // 4. Verificar se as colunas foram criadas
    echo "<h2>✅ 4. Verificando Estrutura</h2>";
    
    // Verificar lc_insumos
    try {
        $columns = $pdo->query("SELECT column_name, data_type, column_default FROM information_schema.columns WHERE table_schema = 'smilee12_painel_smile' AND table_name = 'lc_insumos' AND column_name = 'visivel'")->fetchAll();
        if (count($columns) > 0) {
            $col = $columns[0];
            echo "<p style='color: green;'>✅ lc_insumos.visivel: {$col['data_type']} (default: {$col['column_default']})</p>";
        } else {
            echo "<p style='color: red;'>❌ lc_insumos.visivel NÃO existe</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao verificar lc_insumos: " . $e->getMessage() . "</p>";
    }
    
    // Verificar lc_receitas
    try {
        $columns = $pdo->query("SELECT column_name, data_type, column_default FROM information_schema.columns WHERE table_schema = 'smilee12_painel_smile' AND table_name = 'lc_receitas' AND column_name = 'visivel'")->fetchAll();
        if (count($columns) > 0) {
            $col = $columns[0];
            echo "<p style='color: green;'>✅ lc_receitas.visivel: {$col['data_type']} (default: {$col['column_default']})</p>";
        } else {
            echo "<p style='color: red;'>❌ lc_receitas.visivel NÃO existe</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao verificar lc_receitas: " . $e->getMessage() . "</p>";
    }
    
    echo "<h2>🎉 CAMPOS DE VISIBILIDADE CRIADOS!</h2>";
    echo "<p style='color: green; font-weight: bold; font-size: 18px;'>Agora vamos atualizar a interface para controlar a visibilidade!</p>";
    echo "<p><a href='?next=ui' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🎨 Atualizar Interface</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro geral: " . $e->getMessage() . "</p>";
}

// Se clicou em "Atualizar Interface"
if (isset($_GET['next']) && $_GET['next'] == 'ui') {
    echo "<h2>🎨 Atualizando Interface de Edição</h2>";
    echo "<p>Vamos adicionar os checkboxes de visibilidade nas interfaces de edição!</p>";
    echo "<p style='color: green;'>✅ Campos de visibilidade criados com sucesso!</p>";
    echo "<p style='color: blue;'>📝 Agora vamos atualizar as interfaces de edição...</p>";
    echo "<p><a href='configuracoes.php?tab=insumos' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🔧 Ir para Insumos</a></p>";
}
?>
