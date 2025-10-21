<?php
// test_complete_system.php
// Teste completo de todo o sistema de estoque

session_start();
require_once __DIR__ . '/conexao.php';

// Configurar tratamento de erros
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Definir usuário de teste
$_SESSION['perfil'] = 'ADM';
$_SESSION['usuario_id'] = 1;
$_SESSION['usuario_nome'] = 'Administrador';

echo "<h1>🚀 Teste Completo do Sistema de Estoque</h1>";
echo "<p>Executando análise completa de todas as funcionalidades...</p>";

$inicio_teste = microtime(true);
$resultados = [];

try {
    // Teste 1: Verificar conexão
    echo "<h2>1. 🔌 Teste de Conexão</h2>";
    $inicio = microtime(true);
    
    if ($pdo) {
        $stmt = $pdo->query("SELECT version()");
        $version = $stmt->fetchColumn();
        $tempo = round((microtime(true) - $inicio) * 1000, 2);
        
        echo "<p style='color: green;'>✅ Conexão estabelecida (${tempo}ms)</p>";
        echo "<p>PostgreSQL: $version</p>";
        $resultados['conexao'] = true;
    } else {
        throw new Exception("Falha na conexão");
    }
    
    // Teste 2: Verificar estrutura do banco
    echo "<h2>2. 🏗️ Estrutura do Banco</h2>";
    $inicio = microtime(true);
    
    $tabelas_necessarias = [
        'lc_insumos' => 'Insumos',
        'lc_unidades' => 'Unidades',
        'lc_categorias' => 'Categorias',
        'fornecedores' => 'Fornecedores',
        'lc_fichas' => 'Fichas',
        'lc_ficha_componentes' => 'Componentes',
        'lc_listas' => 'Listas',
        'lc_compras_consolidadas' => 'Compras',
        'lc_listas_eventos' => 'Eventos',
        'lc_evento_cardapio' => 'Cardápio',
        'estoque_contagens' => 'Contagens',
        'estoque_contagem_itens' => 'Itens Contagem',
        'lc_insumos_substitutos' => 'Substitutos'
    ];
    
    $tabelas_ok = 0;
    $tabelas_faltando = [];
    
    foreach ($tabelas_necessarias as $tabela => $descricao) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = '$tabela'");
        $existe = $stmt->fetchColumn() > 0;
        
        if ($existe) {
            $tabelas_ok++;
            echo "<p style='color: green;'>✅ $tabela</p>";
        } else {
            $tabelas_faltando[] = $tabela;
            echo "<p style='color: red;'>❌ $tabela (FALTANDO)</p>";
        }
    }
    
    $tempo = round((microtime(true) - $inicio) * 1000, 2);
    echo "<p>Estrutura verificada em ${tempo}ms</p>";
    $resultados['estrutura'] = $tabelas_ok;
    $resultados['tabelas_faltando'] = $tabelas_faltando;
    
    // Teste 3: Verificar dados essenciais
    echo "<h2>3. 📊 Dados Essenciais</h2>";
    $inicio = microtime(true);
    
    $dados_essenciais = [
        'lc_unidades' => 'Unidades de medida',
        'lc_categorias' => 'Categorias de insumos',
        'lc_insumos' => 'Insumos cadastrados',
        'fornecedores' => 'Fornecedores cadastrados'
    ];
    
    $dados_ok = 0;
    
    foreach ($dados_essenciais as $tabela => $descricao) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM $tabela");
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                $dados_ok++;
                echo "<p style='color: green;'>✅ $descricao: $count registros</p>";
            } else {
                echo "<p style='color: orange;'>⚠️ $descricao: 0 registros</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ $descricao: Erro - " . $e->getMessage() . "</p>";
        }
    }
    
    $tempo = round((microtime(true) - $inicio) * 1000, 2);
    echo "<p>Dados verificados em ${tempo}ms</p>";
    $resultados['dados_essenciais'] = $dados_ok;
    
    // Teste 4: Verificar campos específicos de estoque
    echo "<h2>4. 📦 Campos de Estoque</h2>";
    $inicio = microtime(true);
    
    $campos_estoque = [
        'lc_insumos' => ['estoque_atual', 'estoque_minimo', 'embalagem_multiplo', 'ean_code'],
        'estoque_contagens' => ['data_ref', 'status', 'observacao'],
        'estoque_contagem_itens' => ['qtd_digitada', 'fator_aplicado', 'qtd_contada_base'],
        'lc_insumos_substitutos' => ['equivalencia', 'prioridade', 'ativo']
    ];
    
    $campos_ok = 0;
    $total_campos = 0;
    
    foreach ($campos_estoque as $tabela => $campos) {
        if (in_array($tabela, array_keys($tabelas_necessarias))) {
            echo "<h3>$tabela</h3>";
            foreach ($campos as $campo) {
                $total_campos++;
                try {
                    $stmt = $pdo->query("
                        SELECT COUNT(*) FROM information_schema.columns 
                        WHERE table_name = '$tabela' AND column_name = '$campo'
                    ");
                    $existe = $stmt->fetchColumn() > 0;
                    
                    if ($existe) {
                        $campos_ok++;
                        echo "<p style='color: green;'>✅ $campo</p>";
                    } else {
                        echo "<p style='color: red;'>❌ $campo (FALTANDO)</p>";
                    }
                } catch (Exception $e) {
                    echo "<p style='color: red;'>❌ $campo: Erro - " . $e->getMessage() . "</p>";
                }
            }
        }
    }
    
    $tempo = round((microtime(true) - $inicio) * 1000, 2);
    echo "<p>Campos verificados em ${tempo}ms</p>";
    $resultados['campos_estoque'] = $campos_ok;
    $resultados['total_campos'] = $total_campos;
    
    // Teste 5: Verificar helpers
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
    
    // Teste 6: Verificar páginas principais
    echo "<h2>6. 🌐 Páginas do Sistema</h2>";
    $inicio = microtime(true);
    
    $paginas = [
        'estoque_alertas.php' => 'Alertas de ruptura',
        'estoque_contagens.php' => 'Contagens de estoque',
        'estoque_contar.php' => 'Assistente de contagem',
        'estoque_desvios.php' => 'Relatório de desvios',
        'estoque_sugestao.php' => 'Sugestão de compra'
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
    
    // Teste 7: Verificar integridade de dados
    echo "<h2>7. 🔍 Integridade de Dados</h2>";
    $inicio = microtime(true);
    
    $verificacoes = [
        "SELECT COUNT(*) FROM lc_insumos WHERE preco IS NULL OR preco <= 0" => "Insumos sem preço",
        "SELECT COUNT(*) FROM lc_insumos WHERE estoque_atual < 0" => "Estoque negativo",
        "SELECT COUNT(*) FROM lc_insumos WHERE estoque_minimo < 0" => "Estoque mínimo negativo",
        "SELECT COUNT(*) FROM lc_insumos WHERE embalagem_multiplo <= 0" => "Embalagem inválida"
    ];
    
    $problemas = 0;
    
    foreach ($verificacoes as $sql => $descricao) {
        try {
            $stmt = $pdo->query($sql);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                $problemas++;
                echo "<p style='color: orange;'>⚠️ $descricao: $count registros</p>";
            } else {
                echo "<p style='color: green;'>✅ $descricao: OK</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ $descricao: Erro - " . $e->getMessage() . "</p>";
        }
    }
    
    $tempo = round((microtime(true) - $inicio) * 1000, 2);
    echo "<p>Integridade verificada em ${tempo}ms</p>";
    $resultados['problemas_integridade'] = $problemas;
    
    // Teste 8: Teste de performance
    echo "<h2>8. ⚡ Performance</h2>";
    $inicio = microtime(true);
    
    $testes_performance = [
        "SELECT COUNT(*) FROM lc_insumos WHERE ativo = true" => "Insumos ativos",
        "SELECT COUNT(*) FROM lc_fichas WHERE ativo = true" => "Fichas ativas",
        "SELECT COUNT(*) FROM lc_listas WHERE status = 'rascunho'" => "Listas rascunho",
        "SELECT COUNT(*) FROM estoque_contagens WHERE status = 'fechada'" => "Contagens fechadas"
    ];
    
    $performance_ok = 0;
    
    foreach ($testes_performance as $sql => $descricao) {
        try {
            $inicio_query = microtime(true);
            $stmt = $pdo->query($sql);
            $resultado = $stmt->fetchColumn();
            $tempo_query = round((microtime(true) - $inicio_query) * 1000, 2);
            
            if ($tempo_query < 100) { // Menos de 100ms
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
    
    // Teste 9: Criar dados de teste se necessário
    echo "<h2>9. 🧪 Dados de Teste</h2>";
    $inicio = microtime(true);
    
    // Verificar se há dados suficientes
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_insumos WHERE ativo = true");
    $count_insumos = $stmt->fetchColumn();
    
    if ($count_insumos < 3) {
        echo "<p style='color: orange;'>⚠️ Poucos insumos para teste. Criando dados...</p>";
        
        $insumos_teste = [
            ['nome' => 'Arroz Branco', 'unidade_padrao' => 'kg', 'preco' => 5.50, 'fator_correcao' => 1.0, 'estoque_atual' => 2.5, 'estoque_minimo' => 5.0, 'embalagem_multiplo' => 1],
            ['nome' => 'Leite UHT 1L', 'unidade_padrao' => 'L', 'preco' => 4.20, 'fator_correcao' => 1.0, 'estoque_atual' => 1.0, 'estoque_minimo' => 3.0, 'embalagem_multiplo' => 12],
            ['nome' => 'Açúcar Cristal', 'unidade_padrao' => 'kg', 'preco' => 3.80, 'fator_correcao' => 1.0, 'estoque_atual' => 0.5, 'estoque_minimo' => 2.0, 'embalagem_multiplo' => 1]
        ];
        
        $inseridos = 0;
        foreach ($insumos_teste as $insumo) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO lc_insumos (nome, unidade_padrao, preco, fator_correcao, estoque_atual, estoque_minimo, embalagem_multiplo, ativo)
                    VALUES (?, ?, ?, ?, ?, ?, ?, true)
                    ON CONFLICT (nome) DO NOTHING
                ");
                $stmt->execute([
                    $insumo['nome'],
                    $insumo['unidade_padrao'],
                    $insumo['preco'],
                    $insumo['fator_correcao'],
                    $insumo['estoque_atual'],
                    $insumo['estoque_minimo'],
                    $insumo['embalagem_multiplo']
                ]);
                $inseridos++;
            } catch (Exception $e) {
                echo "<p style='color: red;'>Erro ao inserir {$insumo['nome']}: " . $e->getMessage() . "</p>";
            }
        }
        
        if ($inseridos > 0) {
            echo "<p style='color: green;'>✅ $inseridos insumos de teste criados</p>";
        }
    } else {
        echo "<p style='color: green;'>✅ Dados suficientes para teste</p>";
    }
    
    $tempo = round((microtime(true) - $inicio) * 1000, 2);
    echo "<p>Dados de teste verificados em ${tempo}ms</p>";
    $resultados['dados_teste'] = true;
    
    // Resumo final
    $tempo_total = round((microtime(true) - $inicio_teste) * 1000, 2);
    
    echo "<h2>10. 📊 Resumo Final</h2>";
    
    $total_tabelas = count($tabelas_necessarias);
    $percentual_tabelas = round(($resultados['estrutura'] / $total_tabelas) * 100, 1);
    
    $total_helpers = count($helpers);
    $percentual_helpers = round(($resultados['helpers'] / $total_helpers) * 100, 1);
    
    $total_paginas = count($paginas);
    $percentual_paginas = round(($resultados['paginas'] / $total_paginas) * 100, 1);
    
    echo "<div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>📈 Estatísticas Gerais</h3>";
    echo "<ul>";
    echo "<li><strong>Tempo total:</strong> ${tempo_total}ms</li>";
    echo "<li><strong>Tabelas:</strong> {$resultados['estrutura']}/$total_tabelas ($percentual_tabelas%)</li>";
    echo "<li><strong>Helpers:</strong> {$resultados['helpers']}/$total_helpers ($percentual_helpers%)</li>";
    echo "<li><strong>Páginas:</strong> {$resultados['paginas']}/$total_paginas ($percentual_paginas%)</li>";
    echo "<li><strong>Dados essenciais:</strong> {$resultados['dados_essenciais']}/" . count($dados_essenciais) . "</li>";
    echo "<li><strong>Campos de estoque:</strong> {$resultados['campos_estoque']}/{$resultados['total_campos']}</li>";
    echo "<li><strong>Problemas de integridade:</strong> {$resultados['problemas_integridade']}</li>";
    echo "<li><strong>Performance:</strong> {$resultados['performance']}/" . count($testes_performance) . "</li>";
    echo "</ul>";
    echo "</div>";
    
    // Determinar status geral
    $status_geral = 'OK';
    $cor_status = 'green';
    
    if ($percentual_tabelas < 100) {
        $status_geral = 'INCOMPLETO';
        $cor_status = 'orange';
    }
    
    if ($resultados['problemas_integridade'] > 0) {
        $status_geral = 'COM PROBLEMAS';
        $cor_status = 'red';
    }
    
    echo "<div style='background: " . ($cor_status == 'green' ? '#d4edda' : ($cor_status == 'orange' ? '#fff3cd' : '#f8d7da')) . "; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: " . ($cor_status == 'green' ? '#155724' : ($cor_status == 'orange' ? '#856404' : '#721c24')) . ";'>";
    echo ($cor_status == 'green' ? '✅' : ($cor_status == 'orange' ? '⚠️' : '❌')) . " Status: $status_geral</h3>";
    
    if ($status_geral == 'OK') {
        echo "<p>O sistema está completamente funcional e pronto para uso!</p>";
    } elseif ($status_geral == 'INCOMPLETO') {
        echo "<p>O sistema está parcialmente funcional. Execute o arquivo sql/008_estoque_contagem.sql para completar a instalação.</p>";
    } else {
        echo "<p>O sistema tem problemas que precisam ser corrigidos antes do uso.</p>";
    }
    echo "</div>";
    
    // Ações recomendadas
    if (!empty($resultados['tabelas_faltando'])) {
        echo "<div style='background: #f8d7da; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3 style='color: #721c24;'>🚨 Ações Necessárias</h3>";
        echo "<ol>";
        echo "<li><strong>Execute o arquivo SQL:</strong> sql/008_estoque_contagem.sql</li>";
        echo "<li><strong>Verifique as permissões:</strong> O usuário do banco deve ter permissões de CREATE TABLE</li>";
        echo "<li><strong>Reexecute este teste:</strong> Após executar o SQL, rode novamente este teste</li>";
        echo "</ol>";
        echo "</div>";
    }
    
    // Links para teste
    echo "<div style='background: #e7f3ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>🔗 Links para Teste</h3>";
    echo "<ul>";
    echo "<li><a href='estoque_alertas.php'>🚨 Alertas de Ruptura</a></li>";
    echo "<li><a href='estoque_contagens.php'>📦 Contagens de Estoque</a></li>";
    echo "<li><a href='test_substitutos.php'>🔄 Teste de Substitutos</a></li>";
    echo "<li><a href='test_me_integration.php'>🔗 Teste ME Eventos</a></li>";
    echo "<li><a href='test_database_complete.php'>🔍 Análise Completa do Banco</a></li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<h2 style='color: red;'>❌ Erro Crítico</h2>";
    echo "<p style='color: red;'>Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre style='background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
    
    echo "<div style='background: #f8d7da; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>🚨 Ações de Emergência</h3>";
    echo "<ol>";
    echo "<li>Verifique se o PostgreSQL está rodando</li>";
    echo "<li>Confirme as credenciais em conexao.php</li>";
    echo "<li>Execute o arquivo sql/008_estoque_contagem.sql</li>";
    echo "<li>Verifique as permissões do banco de dados</li>";
    echo "</ol>";
    echo "</div>";
}

// Log do teste completo
error_log("Teste completo do sistema executado em " . date('Y-m-d H:i:s') . " - Tempo: " . (microtime(true) - $inicio_teste) . "s");
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('✅ Teste completo do sistema finalizado');
    
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
    
    // Auto-refresh se houver erros críticos
    const errorElements = document.querySelectorAll('[style*="color: red"]');
    if (errorElements.length > 5) {
        console.log('⚠️ Muitos erros detectados, considere executar o SQL');
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

ul, ol {
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

table {
    margin: 10px 0;
    border-collapse: collapse;
    width: 100%;
}

th, td {
    padding: 8px;
    text-align: left;
    border: 1px solid #ddd;
}

th {
    background-color: #f2f2f2;
}
</style>
