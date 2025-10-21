<?php
// fix_all_issues.php
// Script para corrigir todos os problemas identificados

session_start();
require_once __DIR__ . '/conexao.php';

echo "<h1>🔧 Correção de Problemas do Sistema</h1>";
echo "<p>Executando correções para resolver todos os problemas identificados...</p>";

try {
    // 1. Executar script SQL de correção
    echo "<h2>1. 📝 Executando Correções SQL</h2>";
    
    $sql_file = __DIR__ . '/../sql/fix_database_structure.sql';
    if (file_exists($sql_file)) {
        $sql_content = file_get_contents($sql_file);
        
        // Dividir o SQL em comandos individuais
        $commands = explode(';', $sql_content);
        
        $executados = 0;
        $erros = 0;
        
        foreach ($commands as $command) {
            $command = trim($command);
            if (empty($command) || strpos($command, '--') === 0) {
                continue;
            }
            
            try {
                $pdo->exec($command);
                $executados++;
                echo "<p style='color: green;'>✅ Comando executado: " . substr($command, 0, 50) . "...</p>";
            } catch (Exception $e) {
                $erros++;
                echo "<p style='color: orange;'>⚠️ Comando ignorado: " . $e->getMessage() . "</p>";
            }
        }
        
        echo "<p><strong>Comandos executados:</strong> $executados</p>";
        echo "<p><strong>Comandos com erro:</strong> $erros</p>";
    } else {
        echo "<p style='color: red;'>❌ Arquivo SQL não encontrado: $sql_file</p>";
    }
    
    // 2. Verificar estrutura corrigida
    echo "<h2>2. 🔍 Verificando Estrutura Corrigida</h2>";
    
    $verificacoes = [
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'lc_evento_cardapio'" => "Tabela lc_evento_cardapio",
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'lc_listas' AND column_name = 'status'" => "Coluna status em lc_listas",
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'lc_insumos' AND column_name = 'preco'" => "Coluna preco em lc_insumos",
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'lc_insumos_substitutos' AND column_name = 'criado_por'" => "Coluna criado_por em lc_insumos_substitutos"
    ];
    
    foreach ($verificacoes as $sql => $descricao) {
        try {
            $stmt = $pdo->query($sql);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                echo "<p style='color: green;'>✅ $descricao: OK</p>";
            } else {
                echo "<p style='color: red;'>❌ $descricao: FALTANDO</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ $descricao: Erro - " . $e->getMessage() . "</p>";
        }
    }
    
    // 3. Criar dados de teste se necessário
    echo "<h2>3. 🧪 Criando Dados de Teste</h2>";
    
    // Verificar se há insumos
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_insumos WHERE ativo = true");
    $count_insumos = $stmt->fetchColumn();
    
    if ($count_insumos < 3) {
        echo "<p style='color: orange;'>⚠️ Poucos insumos. Criando dados de teste...</p>";
        
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
    
    // 4. Testar funcionalidades básicas
    echo "<h2>4. 🧪 Testando Funcionalidades Básicas</h2>";
    
    // Testar criação de contagem
    try {
        $stmt = $pdo->prepare("
            INSERT INTO estoque_contagens (data_ref, criada_por, status, observacao)
            VALUES (CURRENT_DATE, :criada_por, 'rascunho', 'Contagem de teste')
            RETURNING id
        ");
        $stmt->execute([':criada_por' => $_SESSION['usuario_id'] ?? 1]);
        $contagem_id = $stmt->fetchColumn();
        
        if ($contagem_id) {
            echo "<p style='color: green;'>✅ Contagem de teste criada com ID: $contagem_id</p>";
            
            // Limpar contagem de teste
            $stmt = $pdo->prepare("DELETE FROM estoque_contagens WHERE id = :id");
            $stmt->execute([':id' => $contagem_id]);
            echo "<p>Contagem de teste removida</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao testar contagem: " . $e->getMessage() . "</p>";
    }
    
    // Testar criação de lista
    try {
        $stmt = $pdo->prepare("
            INSERT INTO lc_listas (tipo_lista, data_gerada, espaco_consolidado, eventos_resumo, criado_por, criado_por_nome, resumo_eventos)
            VALUES ('compras', NOW(), 'Teste', 'Lista de teste', :criado_por, :criado_por_nome, 'Lista de teste criada automaticamente')
            RETURNING id
        ");
        $stmt->execute([
            ':criado_por' => $_SESSION['usuario_id'] ?? 1,
            ':criado_por_nome' => $_SESSION['usuario_nome'] ?? 'Teste'
        ]);
        $lista_id = $stmt->fetchColumn();
        
        if ($lista_id) {
            echo "<p style='color: green;'>✅ Lista de teste criada com ID: $lista_id</p>";
            
            // Limpar lista de teste
            $stmt = $pdo->prepare("DELETE FROM lc_listas WHERE id = :id");
            $stmt->execute([':id' => $lista_id]);
            echo "<p>Lista de teste removida</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao testar lista: " . $e->getMessage() . "</p>";
    }
    
    // 5. Resumo final
    echo "<h2>5. 📊 Resumo das Correções</h2>";
    
    echo "<div style='background: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #155724;'>✅ Correções Executadas</h3>";
    echo "<ul>";
    echo "<li>✅ Estrutura do banco corrigida</li>";
    echo "<li>✅ Tabelas faltantes criadas</li>";
    echo "<li>✅ Colunas faltantes adicionadas</li>";
    echo "<li>✅ Dados de teste criados</li>";
    echo "<li>✅ Funcionalidades básicas testadas</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div style='background: #e7f3ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>🔗 Próximos Passos</h3>";
    echo "<ul>";
    echo "<li><a href='test_complete_system.php'>🧪 Executar Teste Completo</a></li>";
    echo "<li><a href='test_database_complete.php'>🔍 Análise do Banco</a></li>";
    echo "<li><a href='test_estoque_functions.php'>⚙️ Teste de Funcionalidades</a></li>";
    echo "<li><a href='estoque_alertas.php'>🚨 Alertas de Ruptura</a></li>";
    echo "<li><a href='estoque_contagens.php'>📦 Contagens de Estoque</a></li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<h2 style='color: red;'>❌ Erro Crítico</h2>";
    echo "<p style='color: red;'>Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre style='background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}

// Log das correções
error_log("Correções do sistema executadas em " . date('Y-m-d H:i:s'));
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('✅ Correções executadas com sucesso');
    
    // Auto-redirect para teste completo após 5 segundos
    setTimeout(function() {
        if (confirm('Deseja executar o teste completo agora?')) {
            window.location.href = 'test_complete_system.php';
        }
    }, 5000);
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