<?php
// Script para verificar se as tabelas foram criadas com sucesso
session_start(); // Iniciar sessão para evitar problemas
require_once __DIR__ . '/public/conexao.php';

echo "<h1>🔍 Verificação das Tabelas do Banco de Dados</h1>";

try {
    // Listar todas as tabelas do sistema
    $tables = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' 
        AND table_name LIKE 'lc_%'
        ORDER BY table_name
    ")->fetchAll(PDO::FETCH_COLUMN);

    echo "<h2>📋 Tabelas encontradas:</h2>";
    echo "<ul>";
    
    $expectedTables = [
        'lc_categorias',
        'lc_compras_consolidadas', 
        'lc_config',
        'lc_encomendas_itens',
        'lc_insumos',
        'lc_itens_fixos',
        'lc_listas',
        'lc_listas_eventos',
        'lc_unidades'
    ];
    
    $allFound = true;
    
    foreach ($expectedTables as $expected) {
        if (in_array($expected, $tables)) {
            echo "<li style='color: green;'>✅ <strong>{$expected}</strong> - OK</li>";
        } else {
            echo "<li style='color: red;'>❌ <strong>{$expected}</strong> - FALTANDO</li>";
            $allFound = false;
        }
    }
    
    echo "</ul>";
    
    // Verificar configurações
    echo "<h2>⚙️ Configurações do sistema:</h2>";
    try {
        $configs = $pdo->query("SELECT chave, valor FROM lc_config ORDER BY chave")->fetchAll(PDO::FETCH_KEY_PAIR);
        echo "<ul>";
        foreach ($configs as $key => $value) {
            echo "<li><strong>{$key}:</strong> {$value}</li>";
        }
        echo "</ul>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao verificar configurações: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // Verificar unidades
    echo "<h2>📏 Unidades de medida:</h2>";
    try {
        $units = $pdo->query("SELECT simbolo, nome FROM lc_unidades ORDER BY simbolo")->fetchAll();
        echo "<ul>";
        foreach ($units as $unit) {
            echo "<li><strong>{$unit['simbolo']}</strong> - {$unit['nome']}</li>";
        }
        echo "</ul>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao verificar unidades: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // Verificar categorias
    echo "<h2>📂 Categorias:</h2>";
    try {
        $cats = $pdo->query("SELECT nome FROM lc_categorias ORDER BY ordem, nome")->fetchAll(PDO::FETCH_COLUMN);
        echo "<ul>";
        foreach ($cats as $cat) {
            echo "<li>{$cat}</li>";
        }
        echo "</ul>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao verificar categorias: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // Resultado final
    if ($allFound) {
        echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3 style='color: #155724; margin: 0 0 10px 0;'>🎉 SUCESSO! Todas as tabelas foram criadas!</h3>";
        echo "<p style='margin: 0; color: #155724;'>O sistema está pronto para uso completo.</p>";
        echo "</div>";
        
        echo "<h3>🚀 Próximos passos:</h3>";
        echo "<ol>";
        echo "<li><a href='public/lc_index.php' target='_blank'>Acessar Lista de Compras</a></li>";
        echo "<li><a href='public/configuracoes.php' target='_blank'>Configurar Insumos e Categorias</a></li>";
        echo "<li><a href='public/lc_config_avancadas.php' target='_blank'>Ajustar Configurações Avançadas</a></li>";
        echo "</ol>";
    } else {
        echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3 style='color: #721c24; margin: 0 0 10px 0;'>⚠️ ATENÇÃO: Algumas tabelas estão faltando</h3>";
        echo "<p style='margin: 0; color: #721c24;'>Execute novamente o script create_tables.sql no TablePlus.</p>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #721c24; margin: 0 0 10px 0;'>❌ Erro ao verificar</h3>";
    echo "<p style='margin: 0; color: #721c24;'>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
?>
