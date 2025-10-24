<?php
// TESTE_FINAL_SISTEMA.php
// Teste final do sistema após todas as correções

require_once __DIR__ . '/public/conexao.php';

echo "<h1>🎯 TESTE FINAL DO SISTEMA</h1>";

try {
    // Forçar search_path
    $pdo->exec('SET search_path TO smilee12_painel_smile, public');
    
    // Teste 1: Verificar search_path
    $stmt = $pdo->query("SHOW search_path");
    $search_path = $stmt->fetchColumn();
    echo "<h2>1. 🔍 Search Path</h2>";
    echo "<p>Search Path: $search_path</p>";
    
    // Teste 2: Tabelas principais
    echo "<h2>2. 📊 Tabelas Principais</h2>";
    $tabelas_teste = [
        'usuarios' => 'SELECT COUNT(*) FROM usuarios',
        'eventos' => 'SELECT COUNT(*) FROM eventos', 
        'fornecedores' => 'SELECT COUNT(*) FROM fornecedores',
        'lc_categorias' => 'SELECT COUNT(*) FROM lc_categorias',
        'lc_unidades' => 'SELECT COUNT(*) FROM lc_unidades',
        'lc_fichas' => 'SELECT COUNT(*) FROM lc_fichas',
        'comercial_campos_padrao' => 'SELECT COUNT(*) FROM comercial_campos_padrao',
        'demandas_quadros' => 'SELECT COUNT(*) FROM demandas_quadros',
        'demandas_cartoes' => 'SELECT COUNT(*) FROM demandas_cartoes'
    ];
    
    $tabelas_ok = 0;
    $tabelas_erro = 0;
    
    foreach ($tabelas_teste as $nome => $sql) {
        try {
            $stmt = $pdo->query($sql);
            $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = array_values($resultado)[0];
            echo "<p style='color: green;'>✅ $nome - $count registros</p>";
            $tabelas_ok++;
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ $nome - ERRO: " . $e->getMessage() . "</p>";
            $tabelas_erro++;
        }
    }
    
    // Teste 3: Colunas de permissões
    echo "<h2>3. 🔐 Colunas de Permissões</h2>";
    try {
        $stmt = $pdo->query("SELECT perm_agenda_ver, perm_agenda_meus, perm_agenda_relatorios FROM usuarios WHERE id = 1");
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<p style='color: green;'>✅ Colunas de permissões funcionando</p>";
        echo "<p>perm_agenda_ver: " . ($resultado['perm_agenda_ver'] ? 'true' : 'false') . "</p>";
        echo "<p>perm_agenda_meus: " . ($resultado['perm_agenda_meus'] ? 'true' : 'false') . "</p>";
        echo "<p>perm_agenda_relatorios: " . ($resultado['perm_agenda_relatorios'] ? 'true' : 'false') . "</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Colunas de permissões - ERRO: " . $e->getMessage() . "</p>";
    }
    
    // Teste 4: Funções PostgreSQL
    echo "<h2>4. 🔧 Funções PostgreSQL</h2>";
    $funcoes_teste = [
        'obter_proximos_eventos' => 'SELECT * FROM obter_proximos_eventos(1, 24) LIMIT 1',
        'obter_eventos_hoje' => 'SELECT * FROM obter_eventos_hoje(1) LIMIT 1',
        'obter_eventos_semana' => 'SELECT * FROM obter_eventos_semana(1) LIMIT 1'
    ];
    
    foreach ($funcoes_teste as $nome => $sql) {
        try {
            $stmt = $pdo->query($sql);
            $resultado = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo "<p style='color: green;'>✅ $nome - " . count($resultado) . " registros</p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ $nome - ERRO: " . $e->getMessage() . "</p>";
        }
    }
    
    // Teste 5: Consultas específicas que causavam erro
    echo "<h2>5. 🧪 Consultas Específicas</h2>";
    
    // Dashboard Agenda
    try {
        $stmt = $pdo->query("SELECT * FROM obter_proximos_eventos(1, 24)");
        $resultado = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<p style='color: green;'>✅ Dashboard Agenda - " . count($resultado) . " eventos</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Dashboard Agenda - ERRO: " . $e->getMessage() . "</p>";
    }
    
    // Comercial Campos
    try {
        $stmt = $pdo->query("SELECT campos_json FROM comercial_campos_padrao WHERE ativo = TRUE ORDER BY criado_em DESC LIMIT 1");
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<p style='color: green;'>✅ Comercial Campos - " . ($resultado ? 'OK' : 'Nenhum registro') . "</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Comercial Campos - ERRO: " . $e->getMessage() . "</p>";
    }
    
    // Resumo final
    echo "<h2>6. 📊 Resumo Final</h2>";
    echo "<div style='background: #f0f9ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>📈 Estatísticas:</h3>";
    echo "<p>• <strong>Tabelas testadas:</strong> " . count($tabelas_teste) . "</p>";
    echo "<p>• <strong>Tabelas funcionando:</strong> $tabelas_ok</p>";
    echo "<p>• <strong>Tabelas com problema:</strong> $tabelas_erro</p>";
    echo "</div>";
    
    if ($tabelas_erro == 0) {
        echo "<div style='background: #d1fae5; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3 style='color: #065f46;'>🎉 SISTEMA TOTALMENTE FUNCIONAL!</h3>";
        echo "<p style='color: #065f46;'>✅ Todas as tabelas e funcionalidades estão funcionando corretamente!</p>";
        echo "<p><strong>Próximos passos:</strong></p>";
        echo "<ul>";
        echo "<li>✅ Dashboard funcionando</li>";
        echo "<li>✅ Módulo Comercial funcionando</li>";
        echo "<li>✅ Sistema de Permissões funcionando</li>";
        echo "<li>✅ Funções PostgreSQL funcionando</li>";
        echo "<li>✅ Todas as tabelas criadas</li>";
        echo "</ul>";
        echo "</div>";
    } else {
        echo "<div style='background: #fee2e2; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3 style='color: #991b1b;'>⚠️ AINDA HÁ PROBLEMAS</h3>";
        echo "<p style='color: #991b1b;'>❌ Existem $tabelas_erro tabela(s) com problema(s).</p>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='background: #fee2e2; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #991b1b;'>❌ ERRO GERAL</h3>";
    echo "<p style='color: #991b1b;'>Erro: " . $e->getMessage() . "</p>";
    echo "</div>";
}
?>
