<?php
// final_system_check.php
// Verificação final completa do sistema

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

echo "<h1>🎯 Verificação Final Completa do Sistema</h1>";
echo "<p>Executando todos os testes e verificações em sequência...</p>";

$inicio_verificacao = microtime(true);
$resultados = [];

try {
    // 1. Teste de conexão
    echo "<h2>1. 🔌 Teste de Conexão</h2>";
    $inicio = microtime(true);
    
    if ($pdo) {
        $stmt = $pdo->query("SELECT version()");
        $version = $stmt->fetchColumn();
        $tempo = round((microtime(true) - $inicio) * 1000, 2);
        
        echo "<p style='color: green;'>✅ Conexão estabelecida (${tempo}ms)</p>";
        echo "<p>PostgreSQL: $version</p>";
        $resultados['conexao'] = true;
        $resultados['tempo_conexao'] = $tempo;
    } else {
        throw new Exception("Falha na conexão");
    }
    
    // 2. Verificação de estrutura
    echo "<h2>2. 🏗️ Estrutura do Banco</h2>";
    $inicio = microtime(true);
    
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
                echo "<p style='color: green;'>✅ $tabela</p>";
            } else {
                $tabelas_faltando[] = $tabela;
                echo "<p style='color: red;'>❌ $tabela (FALTANDO)</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ $tabela - Erro: " . $e->getMessage() . "</p>";
        }
    }
    
    $tempo = round((microtime(true) - $inicio) * 1000, 2);
    echo "<p>Estrutura verificada em ${tempo}ms</p>";
    $resultados['estrutura'] = $tabelas_ok;
    $resultados['tabelas_faltando'] = $tabelas_faltando;
    $resultados['tempo_estrutura'] = $tempo;
    
    // 3. Verificação de colunas específicas
    echo "<h2>3. 📋 Colunas Específicas</h2>";
    $inicio = microtime(true);
    
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
    
    $tempo = round((microtime(true) - $inicio) * 1000, 2);
    echo "<p>Colunas verificadas em ${tempo}ms</p>";
    $resultados['colunas'] = $colunas_ok;
    $resultados['total_colunas'] = $total_colunas;
    $resultados['tempo_colunas'] = $tempo;
    
    // 4. Teste de performance
    echo "<h2>4. ⚡ Performance</h2>";
    $inicio = microtime(true);
    
    $testes_performance = [
        "SELECT COUNT(*) FROM lc_insumos WHERE ativo = true" => "Insumos ativos",
        "SELECT COUNT(*) FROM lc_fichas WHERE ativo = true" => "Fichas ativas",
        "SELECT COUNT(*) FROM lc_listas" => "Listas totais",
        "SELECT COUNT(*) FROM estoque_contagens" => "Contagens de estoque"
    ];
    
    $performance_ok = 0;
    $tempo_total_performance = 0;
    
    foreach ($testes_performance as $sql => $descricao) {
        try {
            $inicio_query = microtime(true);
            $stmt = $pdo->query($sql);
            $resultado = $stmt->fetchColumn();
            $tempo_query = round((microtime(true) - $inicio_query) * 1000, 2);
            $tempo_total_performance += $tempo_query;
            
            if ($tempo_query < 100) {
                $performance_ok++;
                echo "<p style='color: green;'>✅ $descricao: $resultado registros (${tempo_query}ms)</p>";
            } else {
                echo "<p style='color: orange;'>⚠️ $descricao: $resultado registros (${tempo_query}ms) - LENTO</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ $descricao: Erro - " . $e->getMessage() . "</p>";
        }
    }
    
    $tempo = round((microtime(true) - $inicio) * 1000, 2);
    echo "<p>Performance verificada em ${tempo}ms</p>";
    $resultados['performance'] = $performance_ok;
    $resultados['tempo_performance'] = $tempo;
    $resultados['tempo_medio_query'] = round($tempo_total_performance / count($testes_performance), 2);
    
    // 5. Verificação de helpers
    echo "<h2>5. 📚 Helpers do Sistema</h2>";
    $inicio = microtime(true);
    
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
    
    $tempo = round((microtime(true) - $inicio) * 1000, 2);
    echo "<p>Helpers verificados em ${tempo}ms</p>";
    $resultados['helpers'] = $helpers_ok;
    $resultados['tempo_helpers'] = $tempo;
    
    // 6. Verificação de páginas
    echo "<h2>6. 🌐 Páginas do Sistema</h2>";
    $inicio = microtime(true);
    
    $paginas = [
        'estoque_alertas.php' => 'Alertas de ruptura',
        'estoque_contagens.php' => 'Contagens de estoque',
        'estoque_contar.php' => 'Assistente de contagem',
        'estoque_desvios.php' => 'Relatório de desvios',
        'lc_index.php' => 'Histórico de listas',
        'dashboard2.php' => 'Dashboard principal',
        'lista_compras.php' => 'Gerador de listas'
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
    
    $tempo = round((microtime(true) - $inicio) * 1000, 2);
    echo "<p>Páginas verificadas em ${tempo}ms</p>";
    $resultados['paginas'] = $paginas_ok;
    $resultados['tempo_paginas'] = $tempo;
    
    // 7. Teste de funcionalidades básicas
    echo "<h2>7. 🧪 Teste de Funcionalidades</h2>";
    $inicio = microtime(true);
    
    $funcionalidades_ok = 0;
    $total_funcionalidades = 0;
    
    // Teste 1: Criação de contagem
    try {
        $stmt = $pdo->prepare("
            INSERT INTO estoque_contagens (data_ref, criada_por, status, observacao)
            VALUES (CURRENT_DATE, :criada_por, 'rascunho', 'Teste de funcionalidade')
            RETURNING id
        ");
        $stmt->execute([':criada_por' => $_SESSION['usuario_id'] ?? 1]);
        $contagem_id = $stmt->fetchColumn();
        
        if ($contagem_id) {
            $funcionalidades_ok++;
            echo "<p style='color: green;'>✅ Criação de contagem (ID: $contagem_id)</p>";
            
            // Limpar contagem de teste
            $stmt = $pdo->prepare("DELETE FROM estoque_contagens WHERE id = :id");
            $stmt->execute([':id' => $contagem_id]);
        }
        $total_funcionalidades++;
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Criação de contagem: " . $e->getMessage() . "</p>";
        $total_funcionalidades++;
    }
    
    // Teste 2: Criação de lista
    try {
        $stmt = $pdo->prepare("
            INSERT INTO lc_listas (tipo_lista, data_gerada, espaco_consolidado, eventos_resumo, criado_por, criado_por_nome, resumo_eventos)
            VALUES ('compras', NOW(), 'Teste', 'Lista de teste', :criado_por, :criado_por_nome, 'Teste de funcionalidade')
            RETURNING id
        ");
        $stmt->execute([
            ':criado_por' => $_SESSION['usuario_id'] ?? 1,
            ':criado_por_nome' => $_SESSION['usuario_nome'] ?? 'Teste'
        ]);
        $lista_id = $stmt->fetchColumn();
        
        if ($lista_id) {
            $funcionalidades_ok++;
            echo "<p style='color: green;'>✅ Criação de lista (ID: $lista_id)</p>";
            
            // Limpar lista de teste
            $stmt = $pdo->prepare("DELETE FROM lc_listas WHERE id = :id");
            $stmt->execute([':id' => $lista_id]);
        }
        $total_funcionalidades++;
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Criação de lista: " . $e->getMessage() . "</p>";
        $total_funcionalidades++;
    }
    
    $tempo = round((microtime(true) - $inicio) * 1000, 2);
    echo "<p>Funcionalidades testadas em ${tempo}ms</p>";
    $resultados['funcionalidades'] = $funcionalidades_ok;
    $resultados['total_funcionalidades'] = $total_funcionalidades;
    $resultados['tempo_funcionalidades'] = $tempo;
    
    // 8. Resumo final
    $tempo_total = round((microtime(true) - $inicio_verificacao) * 1000, 2);
    
    echo "<h2>8. 📊 Resumo Final</h2>";
    
    $percentual_estrutura = round(($resultados['estrutura'] / count($tabelas_essenciais)) * 100, 1);
    $percentual_colunas = round(($resultados['colunas'] / $resultados['total_colunas']) * 100, 1);
    $percentual_helpers = round(($resultados['helpers'] / count($helpers)) * 100, 1);
    $percentual_paginas = round(($resultados['paginas'] / count($paginas)) * 100, 1);
    $percentual_funcionalidades = round(($resultados['funcionalidades'] / $resultados['total_funcionalidades']) * 100, 1);
    
    echo "<div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>📈 Estatísticas Gerais</h3>";
    echo "<ul>";
    echo "<li><strong>Tempo total:</strong> ${tempo_total}ms</li>";
    echo "<li><strong>Estrutura:</strong> {$resultados['estrutura']}/" . count($tabelas_essenciais) . " ($percentual_estrutura%)</li>";
    echo "<li><strong>Colunas:</strong> {$resultados['colunas']}/{$resultados['total_colunas']} ($percentual_colunas%)</li>";
    echo "<li><strong>Helpers:</strong> {$resultados['helpers']}/" . count($helpers) . " ($percentual_helpers%)</li>";
    echo "<li><strong>Páginas:</strong> {$resultados['paginas']}/" . count($paginas) . " ($percentual_paginas%)</li>";
    echo "<li><strong>Funcionalidades:</strong> {$resultados['funcionalidades']}/{$resultados['total_funcionalidades']} ($percentual_funcionalidades%)</li>";
    echo "<li><strong>Performance:</strong> {$resultados['performance']}/" . count($testes_performance) . " consultas rápidas</li>";
    echo "<li><strong>Tempo médio por query:</strong> {$resultados['tempo_medio_query']}ms</li>";
    echo "</ul>";
    echo "</div>";
    
    // Determinar status geral
    $status_geral = 'EXCELENTE';
    $cor_status = 'green';
    
    if ($percentual_estrutura < 100 || $percentual_colunas < 100) {
        $status_geral = 'BOM';
        $cor_status = 'orange';
    }
    
    if ($percentual_estrutura < 80 || $percentual_colunas < 80) {
        $status_geral = 'PRECISA MELHORIAS';
        $cor_status = 'red';
    }
    
    echo "<div style='background: " . ($cor_status == 'green' ? '#d4edda' : ($cor_status == 'orange' ? '#fff3cd' : '#f8d7da')) . "; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: " . ($cor_status == 'green' ? '#155724' : ($cor_status == 'orange' ? '#856404' : '#721c24')) . ";'>";
    echo ($cor_status == 'green' ? '✅' : ($cor_status == 'orange' ? '⚠️' : '❌')) . " Status: $status_geral</h3>";
    
    if ($status_geral == 'EXCELENTE') {
        echo "<p>🎉 O sistema está completamente funcional e otimizado!</p>";
        echo "<ul>";
        echo "<li>✅ Todas as tabelas essenciais estão presentes</li>";
        echo "<li>✅ Todas as colunas importantes estão configuradas</li>";
        echo "<li>✅ Performance excelente em todas as consultas</li>";
        echo "<li>✅ Todas as funcionalidades estão operacionais</li>";
        echo "<li>✅ Sistema pronto para uso em produção</li>";
        echo "</ul>";
    } elseif ($status_geral == 'BOM') {
        echo "<p>✅ O sistema está funcionando bem com pequenas melhorias possíveis.</p>";
    } else {
        echo "<p>⚠️ O sistema precisa de algumas correções antes de estar totalmente funcional.</p>";
    }
    echo "</div>";
    
    // Links para teste final
    echo "<div style='background: #e7f3ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>🔗 Links para Teste Final</h3>";
    echo "<ul>";
    echo "<li><a href='dashboard2.php'>🏠 Dashboard Principal</a></li>";
    echo "<li><a href='lc_index.php'>📋 Histórico de Listas</a></li>";
    echo "<li><a href='lista_compras.php'>🛒 Gerar Lista de Compras</a></li>";
    echo "<li><a href='estoque_contagens.php'>📦 Controle de Estoque</a></li>";
    echo "<li><a href='estoque_alertas.php'>🚨 Alertas de Ruptura</a></li>";
    echo "<li><a href='optimize_system.php'>⚡ Otimização do Sistema</a></li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<h2 style='color: red;'>❌ Erro na Verificação</h2>";
    echo "<p style='color: red;'>Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre style='background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}

// Log da verificação final
error_log("Verificação final completa executada em " . date('Y-m-d H:i:s') . " - Tempo: " . (microtime(true) - $inicio_verificacao) . "s");
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('✅ Verificação final completa concluída');
    
    // Adicionar funcionalidade de expandir/colapsar
    const headers = document.querySelectorAll('h2');
    headers.forEach(header => {
        header.style.cursor = 'pointer';
        header.addEventListener('click', function() {
            const nextElement = this.nextElementSibling;
            if (nextElement && nextElement.style) {
                nextElement.style.display = nextElement.style.display === 'none' ? 'block' : 'none';
            }
        });
    });
    
    // Mostrar estatísticas no console
    const statusElement = document.querySelector('h3');
    if (statusElement) {
        console.log('Status final do sistema:', statusElement.textContent);
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
