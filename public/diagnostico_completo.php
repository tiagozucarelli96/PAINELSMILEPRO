<?php
// diagnostico_completo.php - Diagnóstico completo do banco
require_once 'conexao.php';

echo "<h1>🔍 DIAGNÓSTICO COMPLETO DO BANCO</h1>";
echo "<p>Vamos identificar exatamente onde estão os problemas!</p>";

try {
    // 1. Verificar estrutura das tabelas
    echo "<h2>📊 1. Estrutura das Tabelas</h2>";
    
    $tables = ['lc_listas', 'lc_listas_eventos', 'lc_compras_consolidadas', 'lc_encomendas_itens', 'lc_categorias', 'lc_unidades', 'lc_insumos'];
    
    foreach ($tables as $table) {
        try {
            $columns = $pdo->query("SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_schema = 'smilee12_painel_smile' AND table_name = '$table' ORDER BY ordinal_position")->fetchAll();
            echo "<h3>📋 $table</h3>";
            echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0;'>";
            echo "<tr><th>Coluna</th><th>Tipo</th><th>Nulo</th></tr>";
            foreach ($columns as $col) {
                echo "<tr><td>{$col['column_name']}</td><td>{$col['data_type']}</td><td>{$col['is_nullable']}</td></tr>";
            }
            echo "</table>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Erro ao verificar $table: " . $e->getMessage() . "</p>";
        }
    }
    
    // 2. Verificar foreign keys
    echo "<h2>🔗 2. Foreign Keys</h2>";
    
    $fks = $pdo->query("
        SELECT 
            tc.table_name, 
            kcu.column_name, 
            ccu.table_name AS foreign_table_name,
            ccu.column_name AS foreign_column_name 
        FROM 
            information_schema.table_constraints AS tc 
            JOIN information_schema.key_column_usage AS kcu
              ON tc.constraint_name = kcu.constraint_name
              AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage AS ccu
              ON ccu.constraint_name = tc.constraint_name
              AND ccu.table_schema = tc.table_schema
        WHERE tc.constraint_type = 'FOREIGN KEY' 
        AND tc.table_schema = 'smilee12_painel_smile'
        ORDER BY tc.table_name, kcu.column_name
    ")->fetchAll();
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0;'>";
    echo "<tr><th>Tabela</th><th>Coluna</th><th>Referencia</th><th>Coluna Ref</th></tr>";
    foreach ($fks as $fk) {
        echo "<tr><td>{$fk['table_name']}</td><td>{$fk['column_name']}</td><td>{$fk['foreign_table_name']}</td><td>{$fk['foreign_column_name']}</td></tr>";
    }
    echo "</table>";
    
    // 3. Verificar dados problemáticos
    echo "<h2>🐛 3. Dados Problemáticos</h2>";
    
    // Verificar se há referências quebradas
    echo "<h3>🔍 Referências Quebradas</h3>";
    
    // Verificar lc_listas_eventos
    try {
        $broken_events = $pdo->query("
            SELECT le.*, l.id as lista_exists 
            FROM smilee12_painel_smile.lc_listas_eventos le 
            LEFT JOIN smilee12_painel_smile.lc_listas l ON le.lista_id = l.id 
            WHERE l.id IS NULL
        ")->fetchAll();
        
        if (count($broken_events) > 0) {
            echo "<p style='color: red;'>❌ Encontrados " . count($broken_events) . " eventos órfãos em lc_listas_eventos</p>";
            echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0;'>";
            echo "<tr><th>ID</th><th>Lista ID</th><th>Evento</th><th>Data</th></tr>";
            foreach ($broken_events as $event) {
                echo "<tr><td>{$event['id']}</td><td>{$event['lista_id']}</td><td>{$event['evento']}</td><td>{$event['data_evento']}</td></tr>";
            }
            echo "</table>";
        } else {
            echo "<p style='color: green;'>✅ Nenhum evento órfão encontrado</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: orange;'>⚠️ Erro ao verificar eventos: " . $e->getMessage() . "</p>";
    }
    
    // 4. Verificar se as colunas existem
    echo "<h2>🔍 4. Verificação de Colunas Específicas</h2>";
    
    $problem_columns = [
        'lc_listas_eventos' => 'lista_id',
        'lc_compras_consolidadas' => 'lista_id',
        'lc_encomendas_itens' => 'lista_id'
    ];
    
    foreach ($problem_columns as $table => $column) {
        try {
            $exists = $pdo->query("
                SELECT COUNT(*) 
                FROM information_schema.columns 
                WHERE table_schema = 'smilee12_painel_smile' 
                AND table_name = '$table' 
                AND column_name = '$column'
            ")->fetchColumn();
            
            if ($exists > 0) {
                echo "<p style='color: green;'>✅ $table.$column existe</p>";
            } else {
                echo "<p style='color: red;'>❌ $table.$column NÃO existe</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Erro ao verificar $table.$column: " . $e->getMessage() . "</p>";
        }
    }
    
    // 5. Sugestões de correção
    echo "<h2>💡 5. Sugestões de Correção</h2>";
    
    echo "<div style='background: #f8f9fa; padding: 20px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>🎯 Plano de Ação:</h3>";
    echo "<ol>";
    echo "<li><strong>Limpar dados órfãos:</strong> Remover registros que referenciam dados inexistentes</li>";
    echo "<li><strong>Corrigir estrutura:</strong> Adicionar colunas faltantes se necessário</li>";
    echo "<li><strong>Testar funcionalidades:</strong> Verificar se os botões funcionam após correções</li>";
    echo "<li><strong>Manter dados válidos:</strong> Preservar dados que estão corretos</li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<p><a href='?action=fix' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🔧 Executar Correções Automáticas</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro geral: " . $e->getMessage() . "</p>";
}

// Se clicou em "Executar Correções"
if (isset($_GET['action']) && $_GET['action'] == 'fix') {
    echo "<h2>🔧 Executando Correções...</h2>";
    
    try {
        // 1. Limpar dados órfãos
        echo "<h3>🧹 Limpando dados órfãos...</h3>";
        
        // Limpar eventos órfãos
        $deleted_events = $pdo->exec("
            DELETE FROM smilee12_painel_smile.lc_listas_eventos 
            WHERE lista_id NOT IN (SELECT id FROM smilee12_painel_smile.lc_listas)
        ");
        echo "<p style='color: green;'>✅ Removidos $deleted_events eventos órfãos</p>";
        
        // Limpar compras consolidadas órfãs
        $deleted_compras = $pdo->exec("
            DELETE FROM smilee12_painel_smile.lc_compras_consolidadas 
            WHERE lista_id NOT IN (SELECT id FROM smilee12_painel_smile.lc_listas)
        ");
        echo "<p style='color: green;'>✅ Removidas $deleted_compras compras órfãs</p>";
        
        // Limpar encomendas órfãs
        $deleted_encomendas = $pdo->exec("
            DELETE FROM smilee12_painel_smile.lc_encomendas_itens 
            WHERE lista_id NOT IN (SELECT id FROM smilee12_painel_smile.lc_listas)
        ");
        echo "<p style='color: green;'>✅ Removidas $deleted_encomendas encomendas órfãs</p>";
        
        echo "<h3>✅ Correções Concluídas!</h3>";
        echo "<p style='color: green; font-weight: bold;'>Agora teste os botões novamente!</p>";
        echo "<p><a href='lc_index.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🏠 Testar Sistema</a></p>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro durante correções: " . $e->getMessage() . "</p>";
    }
}
?>
