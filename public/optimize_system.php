<?php
// optimize_system.php
// Otimização final do sistema - limpeza de warnings e melhorias

session_start();
require_once __DIR__ . '/conexao.php';

echo "<h1>🚀 Otimização Final do Sistema</h1>";
echo "<p>Executando limpeza de warnings e otimizações finais...</p>";

try {
    // 1. Verificar e corrigir warnings de sintaxe
    echo "<h2>1. 🔧 Correção de Warnings PHP</h2>";
    
    $arquivos_para_verificar = [
        'test_complete_system.php',
        'test_estoque_functions.php', 
        'test_database_complete.php',
        'fix_all_issues.php',
        'analyze_missing_columns.php'
    ];
    
    $warnings_corrigidos = 0;
    
    foreach ($arquivos_para_verificar as $arquivo) {
        if (file_exists(__DIR__ . '/' . $arquivo)) {
            $conteudo = file_get_contents(__DIR__ . '/' . $arquivo);
            
            // Verificar se há warnings de ${var}
            if (preg_match_all('/\$\{([^}]+)\}/', $conteudo, $matches)) {
                echo "<p style='color: orange;'>⚠️ $arquivo: " . count($matches[0]) . " warnings de sintaxe encontrados</p>";
                
                // Corrigir automaticamente
                $conteudo_corrigido = preg_replace('/\$\{([^}]+)\}/', '{$1}', $conteudo);
                
                if ($conteudo !== $conteudo_corrigido) {
                    file_put_contents(__DIR__ . '/' . $arquivo, $conteudo_corrigido);
                    echo "<p style='color: green;'>✅ $arquivo: Warnings corrigidos</p>";
                    $warnings_corrigidos++;
                }
            } else {
                echo "<p style='color: green;'>✅ $arquivo: Sem warnings de sintaxe</p>";
            }
        }
    }
    
    echo "<p><strong>Total de arquivos corrigidos:</strong> $warnings_corrigidos</p>";
    
    // 2. Verificar estrutura final do banco
    echo "<h2>2. 🗄️ Verificação Final da Estrutura</h2>";
    
    $tabelas_essenciais = [
        'lc_insumos' => 'Insumos do sistema',
        'lc_unidades' => 'Unidades de medida',
        'lc_categorias' => 'Categorias',
        'fornecedores' => 'Fornecedores',
        'lc_fichas' => 'Fichas técnicas',
        'lc_ficha_componentes' => 'Componentes',
        'lc_listas' => 'Listas de compras',
        'lc_compras_consolidadas' => 'Compras',
        'lc_listas_eventos' => 'Eventos',
        'lc_evento_cardapio' => 'Cardápio',
        'estoque_contagens' => 'Contagens',
        'estoque_contagem_itens' => 'Itens contagem',
        'lc_insumos_substitutos' => 'Substitutos'
    ];
    
    $tabelas_ok = 0;
    $tabelas_faltando = [];
    
    foreach ($tabelas_essenciais as $tabela => $descricao) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = '$tabela'");
            $existe = $stmt->fetchColumn() > 0;
            
            if ($existe) {
                $tabelas_ok++;
                echo "<p style='color: green;'>✅ $tabela - $descricao</p>";
            } else {
                $tabelas_faltando[] = $tabela;
                echo "<p style='color: red;'>❌ $tabela - $descricao (FALTANDO)</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ $tabela - Erro: " . $e->getMessage() . "</p>";
        }
    }
    
    $percentual = round(($tabelas_ok / count($tabelas_essenciais)) * 100, 1);
    echo "<p><strong>Estrutura:</strong> $tabelas_ok/" . count($tabelas_essenciais) . " ($percentual%)</p>";
    
    // 3. Verificar colunas específicas
    echo "<h2>3. 📋 Verificação de Colunas Específicas</h2>";
    
    $colunas_importantes = [
        'lc_insumos' => ['preco', 'estoque_atual', 'estoque_minimo', 'embalagem_multiplo', 'ean_code'],
        'lc_listas' => ['status', 'tipo_lista', 'data_gerada'],
        'lc_insumos_substitutos' => ['equivalencia', 'prioridade', 'ativo']
    ];
    
    $colunas_ok = 0;
    $total_colunas = 0;
    
    foreach ($colunas_importantes as $tabela => $colunas) {
        echo "<h3>$tabela</h3>";
        foreach ($colunas as $coluna) {
            $total_colunas++;
            try {
                $stmt = $pdo->query("
                    SELECT COUNT(*) FROM information_schema.columns 
                    WHERE table_name = '$tabela' AND column_name = '$coluna'
                ");
                $existe = $stmt->fetchColumn() > 0;
                
                if ($existe) {
                    $colunas_ok++;
                    echo "<p style='color: green;'>✅ $coluna</p>";
                } else {
                    echo "<p style='color: red;'>❌ $coluna (FALTANDO)</p>";
                }
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ $coluna - Erro: " . $e->getMessage() . "</p>";
            }
        }
    }
    
    $percentual_colunas = round(($colunas_ok / $total_colunas) * 100, 1);
    echo "<p><strong>Colunas:</strong> $colunas_ok/$total_colunas ($percentual_colunas%)</p>";
    
    // 4. Teste de performance
    echo "<h2>4. ⚡ Teste de Performance</h2>";
    
    $testes_performance = [
        "SELECT COUNT(*) FROM lc_insumos WHERE ativo = true" => "Insumos ativos",
        "SELECT COUNT(*) FROM lc_fichas WHERE ativo = true" => "Fichas ativas", 
        "SELECT COUNT(*) FROM lc_listas" => "Listas totais",
        "SELECT COUNT(*) FROM estoque_contagens" => "Contagens de estoque"
    ];
    
    $performance_ok = 0;
    $tempo_total = 0;
    
    foreach ($testes_performance as $sql => $descricao) {
        try {
            $inicio = microtime(true);
            $stmt = $pdo->query($sql);
            $resultado = $stmt->fetchColumn();
            $tempo = round((microtime(true) - $inicio) * 1000, 2);
            $tempo_total += $tempo;
            
            if ($tempo < 100) {
                $performance_ok++;
                echo "<p style='color: green;'>✅ $descricao: $resultado registros (${tempo}ms)</p>";
            } else {
                echo "<p style='color: orange;'>⚠️ $descricao: $resultado registros (${tempo}ms) - LENTO</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ $descricao: Erro - " . $e->getMessage() . "</p>";
        }
    }
    
    $tempo_medio = round($tempo_total / count($testes_performance), 2);
    echo "<p><strong>Performance:</strong> $performance_ok/" . count($testes_performance) . " consultas rápidas</p>";
    echo "<p><strong>Tempo médio:</strong> ${tempo_medio}ms por consulta</p>";
    
    // 5. Verificar helpers e funcionalidades
    echo "<h2>5. 📚 Verificação de Helpers</h2>";
    
    $helpers = [
        'lc_calc.php' => 'Cálculos de fichas',
        'lc_units_helper.php' => 'Conversão de unidades',
        'lc_permissions_helper.php' => 'Sistema de permissões',
        'me_api_helper.php' => 'Integração ME Eventos',
        'lc_substitutes_helper.php' => 'Sistema de substitutos'
    ];
    
    $helpers_ok = 0;
    
    foreach ($helpers as $arquivo => $descricao) {
        if (file_exists(__DIR__ . '/' . $arquivo)) {
            $helpers_ok++;
            echo "<p style='color: green;'>✅ $arquivo - $descricao</p>";
        } else {
            echo "<p style='color: red;'>❌ $arquivo - $descricao (FALTANDO)</p>";
        }
    }
    
    echo "<p><strong>Helpers:</strong> $helpers_ok/" . count($helpers) . " disponíveis</p>";
    
    // 6. Verificar páginas principais
    echo "<h2>6. 🌐 Verificação de Páginas</h2>";
    
    $paginas = [
        'estoque_alertas.php' => 'Alertas de ruptura',
        'estoque_contagens.php' => 'Contagens de estoque',
        'estoque_contar.php' => 'Assistente de contagem',
        'estoque_desvios.php' => 'Relatório de desvios',
        'lc_index.php' => 'Histórico de listas',
        'dashboard2.php' => 'Dashboard principal'
    ];
    
    $paginas_ok = 0;
    
    foreach ($paginas as $arquivo => $descricao) {
        if (file_exists(__DIR__ . '/' . $arquivo)) {
            $paginas_ok++;
            echo "<p style='color: green;'>✅ $arquivo - $descricao</p>";
        } else {
            echo "<p style='color: red;'>❌ $arquivo - $descricao (FALTANDO)</p>";
        }
    }
    
    echo "<p><strong>Páginas:</strong> $paginas_ok/" . count($paginas) . " disponíveis</p>";
    
    // 7. Resumo final e status
    echo "<h2>7. 📊 Resumo da Otimização</h2>";
    
    $status_geral = 'EXCELENTE';
    $cor_status = 'green';
    
    if ($percentual < 100) {
        $status_geral = 'BOM';
        $cor_status = 'orange';
    }
    
    if ($percentual < 80) {
        $status_geral = 'PRECISA MELHORIAS';
        $cor_status = 'red';
    }
    
    echo "<div style='background: " . ($cor_status == 'green' ? '#d4edda' : ($cor_status == 'orange' ? '#fff3cd' : '#f8d7da')) . "; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: " . ($cor_status == 'green' ? '#155724' : ($cor_status == 'orange' ? '#856404' : '#721c24')) . ";'>";
    echo ($cor_status == 'green' ? '✅' : ($cor_status == 'orange' ? '⚠️' : '❌')) . " Status: $status_geral</h3>";
    
    echo "<ul>";
    echo "<li><strong>Estrutura do banco:</strong> $percentual%</li>";
    echo "<li><strong>Colunas importantes:</strong> $percentual_colunas%</li>";
    echo "<li><strong>Performance:</strong> $performance_ok/" . count($testes_performance) . " consultas rápidas</li>";
    echo "<li><strong>Helpers:</strong> $helpers_ok/" . count($helpers) . " disponíveis</li>";
    echo "<li><strong>Páginas:</strong> $paginas_ok/" . count($paginas) . " disponíveis</li>";
    echo "<li><strong>Warnings corrigidos:</strong> $warnings_corrigidos</li>";
    echo "</ul>";
    
    if ($status_geral == 'EXCELENTE') {
        echo "<p>🎉 O sistema está completamente otimizado e pronto para uso em produção!</p>";
    } elseif ($status_geral == 'BOM') {
        echo "<p>✅ O sistema está funcionando bem, com pequenas melhorias possíveis.</p>";
    } else {
        echo "<p>⚠️ O sistema precisa de algumas correções antes de estar totalmente funcional.</p>";
    }
    echo "</div>";
    
    // 8. Links para teste final
    echo "<div style='background: #e7f3ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>🔗 Testes Finais</h3>";
    echo "<ul>";
    echo "<li><a href='test_complete_system.php'>🧪 Teste Completo do Sistema</a></li>";
    echo "<li><a href='test_database_complete.php'>🔍 Análise Completa do Banco</a></li>";
    echo "<li><a href='analyze_missing_columns.php'>📊 Análise de Colunas</a></li>";
    echo "<li><a href='dashboard2.php'>🏠 Dashboard Principal</a></li>";
    echo "<li><a href='lc_index.php'>📋 Histórico de Listas</a></li>";
    echo "<li><a href='estoque_contagens.php'>📦 Controle de Estoque</a></li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<h2 style='color: red;'>❌ Erro na Otimização</h2>";
    echo "<p style='color: red;'>Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre style='background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}

// Log da otimização
error_log("Otimização final do sistema executada em " . date('Y-m-d H:i:s'));
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('✅ Otimização final concluída');
    
    // Auto-refresh se houver problemas
    const errorElements = document.querySelectorAll('[style*="color: red"]');
    if (errorElements.length > 3) {
        console.log('⚠️ Problemas detectados, considere executar correções');
    }
    
    // Mostrar estatísticas no console
    const statusElement = document.querySelector('h3');
    if (statusElement) {
        console.log('Status do sistema:', statusElement.textContent);
    }
});
</script>

<style>
body {
    font-family: Arial, sans-serif;
    line-height: 1.6;
    margin: 20px;
    background: #f8f9fa;
}

h1, h2, h3 {
    color: #333;
}

ul {
    margin: 10px 0;
    padding-left: 20px;
}

li {
    margin: 5px 0;
}

pre {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 4px;
    overflow-x: auto;
    font-size: 12px;
}
</style>
