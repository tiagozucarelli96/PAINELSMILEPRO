<?php
// corrigir_estrutura.php - Corrigir estrutura das tabelas
require_once 'conexao.php';

echo "<h1>🔧 CORRIGINDO ESTRUTURA DAS TABELAS</h1>";
echo "<p>Adicionando colunas faltantes para resolver os erros!</p>";

try {
    // 1. Adicionar coluna lista_id em lc_compras_consolidadas
    echo "<h2>📋 1. Corrigindo lc_compras_consolidadas</h2>";
    
    try {
        $pdo->exec("ALTER TABLE smilee12_painel_smile.lc_compras_consolidadas ADD COLUMN lista_id INTEGER");
        echo "<p style='color: green;'>✅ Coluna lista_id adicionada em lc_compras_consolidadas</p>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "<p style='color: orange;'>⚠️ Coluna lista_id já existe em lc_compras_consolidadas</p>";
        } else {
            echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
        }
    }
    
    // 2. Adicionar coluna lista_id em lc_encomendas_itens
    echo "<h2>📦 2. Corrigindo lc_encomendas_itens</h2>";
    
    try {
        $pdo->exec("ALTER TABLE smilee12_painel_smile.lc_encomendas_itens ADD COLUMN lista_id INTEGER");
        echo "<p style='color: green;'>✅ Coluna lista_id adicionada em lc_encomendas_itens</p>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "<p style='color: orange;'>⚠️ Coluna lista_id já existe em lc_encomendas_itens</p>";
        } else {
            echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
        }
    }
    
    // 3. Adicionar foreign key constraints
    echo "<h2>🔗 3. Adicionando Foreign Keys</h2>";
    
    // FK para lc_compras_consolidadas.lista_id -> lc_listas.id
    try {
        $pdo->exec("ALTER TABLE smilee12_painel_smile.lc_compras_consolidadas ADD CONSTRAINT fk_compras_lista FOREIGN KEY (lista_id) REFERENCES smilee12_painel_smile.lc_listas(id) ON DELETE CASCADE");
        echo "<p style='color: green;'>✅ FK adicionada: lc_compras_consolidadas.lista_id -> lc_listas.id</p>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "<p style='color: orange;'>⚠️ FK já existe para lc_compras_consolidadas.lista_id</p>";
        } else {
            echo "<p style='color: red;'>❌ Erro FK compras: " . $e->getMessage() . "</p>";
        }
    }
    
    // FK para lc_encomendas_itens.lista_id -> lc_listas.id
    try {
        $pdo->exec("ALTER TABLE smilee12_painel_smile.lc_encomendas_itens ADD CONSTRAINT fk_encomendas_lista FOREIGN KEY (lista_id) REFERENCES smilee12_painel_smile.lc_listas(id) ON DELETE CASCADE");
        echo "<p style='color: green;'>✅ FK adicionada: lc_encomendas_itens.lista_id -> lc_listas.id</p>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "<p style='color: orange;'>⚠️ FK já existe para lc_encomendas_itens.lista_id</p>";
        } else {
            echo "<p style='color: red;'>❌ Erro FK encomendas: " . $e->getMessage() . "</p>";
        }
    }
    
    // 4. Verificar se as correções funcionaram
    echo "<h2>✅ 4. Verificando Correções</h2>";
    
    // Verificar lc_compras_consolidadas
    try {
        $columns = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'smilee12_painel_smile' AND table_name = 'lc_compras_consolidadas' AND column_name = 'lista_id'")->fetchAll();
        if (count($columns) > 0) {
            echo "<p style='color: green;'>✅ lc_compras_consolidadas.lista_id existe</p>";
        } else {
            echo "<p style='color: red;'>❌ lc_compras_consolidadas.lista_id NÃO existe</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao verificar: " . $e->getMessage() . "</p>";
    }
    
    // Verificar lc_encomendas_itens
    try {
        $columns = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'smilee12_painel_smile' AND table_name = 'lc_encomendas_itens' AND column_name = 'lista_id'")->fetchAll();
        if (count($columns) > 0) {
            echo "<p style='color: green;'>✅ lc_encomendas_itens.lista_id existe</p>";
        } else {
            echo "<p style='color: red;'>❌ lc_encomendas_itens.lista_id NÃO existe</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao verificar: " . $e->getMessage() . "</p>";
    }
    
    // 5. Testar os botões
    echo "<h2>🧪 5. Testando Funcionalidades</h2>";
    
    echo "<p><strong>🎯 Agora teste os botões:</strong></p>";
    echo "<ul>";
    echo "<li>✅ <strong>Ver:</strong> Deve funcionar sem erro de coluna</li>";
    echo "<li>✅ <strong>PDF:</strong> Deve funcionar sem erro de coluna</li>";
    echo "<li>✅ <strong>Excluir:</strong> Deve funcionar sem erro de coluna</li>";
    echo "</ul>";
    
    echo "<p><a href='lc_index.php' style='background: #28a745; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-size: 18px; font-weight: bold;'>🚀 TESTAR BOTÕES AGORA</a></p>";
    
    echo "<h2>🎉 CORREÇÃO CONCLUÍDA!</h2>";
    echo "<p style='color: green; font-weight: bold; font-size: 18px;'>As colunas faltantes foram adicionadas! Os botões devem funcionar agora!</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro geral: " . $e->getMessage() . "</p>";
}
?>
