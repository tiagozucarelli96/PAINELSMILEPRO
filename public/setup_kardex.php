<?php
// setup_kardex.php
// Script para configurar o módulo Kardex

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

echo "<h1>🔧 Configuração do Módulo Kardex</h1>";
echo "<p>Executando script SQL para criar as tabelas e funcionalidades do módulo Kardex...</p>";

if (!$pdo) {
    echo "<p style='color: red;'>❌ Erro de conexão com o banco de dados: " . htmlspecialchars($db_error) . "</p>";
    exit;
}

try {
    // Ler e executar o script SQL
    $sql_file = __DIR__ . '/../sql/009_kardex_movimentos.sql';
    
    if (!file_exists($sql_file)) {
        throw new Exception("Arquivo SQL não encontrado: $sql_file");
    }
    
    $sql_content = file_get_contents($sql_file);
    
    if ($sql_content === false) {
        throw new Exception("Erro ao ler arquivo SQL");
    }
    
    echo "<h2>📄 Executando Script SQL</h2>";
    echo "<p>Arquivo: <code>009_kardex_movimentos.sql</code></p>";
    
    // Dividir o script em comandos individuais
    $commands = array_filter(
        array_map('trim', explode(';', $sql_content)),
        function($cmd) {
            return !empty($cmd) && !preg_match('/^--/', $cmd);
        }
    );
    
    $executed = 0;
    $errors = 0;
    
    foreach ($commands as $command) {
        if (empty(trim($command))) continue;
        
        try {
            $pdo->exec($command);
            $executed++;
            echo "<p style='color: green;'>✅ Comando executado com sucesso</p>";
        } catch (PDOException $e) {
            $errors++;
            echo "<p style='color: orange;'>⚠️ Aviso: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    
    echo "<h2>📊 Resumo da Execução</h2>";
    echo "<ul>";
    echo "<li><strong>Comandos executados:</strong> $executed</li>";
    echo "<li><strong>Erros/Avisos:</strong> $errors</li>";
    echo "</ul>";
    
    // Verificar se as tabelas foram criadas
    echo "<h2>🔍 Verificação das Tabelas</h2>";
    
    $tabelas_kardex = [
        'lc_movimentos_estoque' => 'Movimentos de estoque',
        'lc_eventos_baixados' => 'Baixas por evento',
        'lc_ajustes_estoque' => 'Ajustes de estoque',
        'lc_perdas_devolucoes' => 'Perdas e devoluções',
        'lc_config_estoque' => 'Configurações do módulo'
    ];
    
    $tabelas_criadas = 0;
    
    foreach ($tabelas_kardex as $tabela => $descricao) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = '$tabela'");
            $existe = $stmt->fetchColumn() > 0;
            
            if ($existe) {
                $tabelas_criadas++;
                echo "<p style='color: green;'>✅ $tabela - $descricao</p>";
            } else {
                echo "<p style='color: red;'>❌ $tabela - $descricao (NÃO CRIADA)</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ $tabela - Erro: " . $e->getMessage() . "</p>";
        }
    }
    
    // Verificar funções
    echo "<h2>🔧 Verificação das Funções</h2>";
    
    $funcoes_kardex = [
        'lc_calcular_saldo_insumo' => 'Calcular saldo de insumo',
        'lc_calcular_saldo_insumo_data' => 'Calcular saldo em período'
    ];
    
    $funcoes_criadas = 0;
    
    foreach ($funcoes_kardex as $funcao => $descricao) {
        try {
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM information_schema.routines 
                WHERE routine_name = '$funcao' AND routine_type = 'FUNCTION'
            ");
            $existe = $stmt->fetchColumn() > 0;
            
            if ($existe) {
                $funcoes_criadas++;
                echo "<p style='color: green;'>✅ $funcao - $descricao</p>";
            } else {
                echo "<p style='color: red;'>❌ $funcao - $descricao (NÃO CRIADA)</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ $funcao - Erro: " . $e->getMessage() . "</p>";
        }
    }
    
    // Verificar views
    echo "<h2>👁️ Verificação das Views</h2>";
    
    $views_kardex = [
        'v_kardex_completo' => 'View completa do Kardex',
        'v_resumo_movimentos_insumo' => 'Resumo de movimentos por insumo'
    ];
    
    $views_criadas = 0;
    
    foreach ($views_kardex as $view => $descricao) {
        try {
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM information_schema.views 
                WHERE table_name = '$view'
            ");
            $existe = $stmt->fetchColumn() > 0;
            
            if ($existe) {
                $views_criadas++;
                echo "<p style='color: green;'>✅ $view - $descricao</p>";
            } else {
                echo "<p style='color: red;'>❌ $view - $descricao (NÃO CRIADA)</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ $view - Erro: " . $e->getMessage() . "</p>";
        }
    }
    
    // Resumo final
    echo "<h2>📈 Resumo Final</h2>";
    
    $total_tabelas = count($tabelas_kardex);
    $total_funcoes = count($funcoes_kardex);
    $total_views = count($views_kardex);
    
    $percentual_tabelas = round(($tabelas_criadas / $total_tabelas) * 100, 1);
    $percentual_funcoes = round(($funcoes_criadas / $total_funcoes) * 100, 1);
    $percentual_views = round(($views_criadas / $total_views) * 100, 1);
    
    echo "<div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>📊 Estatísticas de Criação</h3>";
    echo "<ul>";
    echo "<li><strong>Tabelas:</strong> $tabelas_criadas/$total_tabelas ($percentual_tabelas%)</li>";
    echo "<li><strong>Funções:</strong> $funcoes_criadas/$total_funcoes ($percentual_funcoes%)</li>";
    echo "<li><strong>Views:</strong> $views_criadas/$total_views ($percentual_views%)</li>";
    echo "<li><strong>Comandos SQL:</strong> $executed executados</li>";
    echo "</ul>";
    echo "</div>";
    
    if ($tabelas_criadas == $total_tabelas && $funcoes_criadas == $total_funcoes && $views_criadas == $total_views) {
        echo "<div style='background: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3 style='color: #155724;'>✅ Módulo Kardex Configurado com Sucesso!</h3>";
        echo "<p>O módulo Kardex foi configurado completamente. Você pode agora:</p>";
        echo "<ul>";
        echo "<li>📒 Acessar o <a href='estoque_kardex.php'>Kardex</a> para visualizar movimentos</li>";
        echo "<li>📊 Usar o <a href='estoque_contagens.php'>Controle de Estoque</a> para contagens</li>";
        echo "<li>🚨 Configurar <a href='estoque_alertas.php'>Alertas de Ruptura</a></li>";
        echo "<li>📈 Gerar <a href='estoque_desvios.php'>Relatórios de Desvios</a></li>";
        echo "</ul>";
        echo "</div>";
    } else {
        echo "<div style='background: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3 style='color: #856404;'>⚠️ Configuração Parcial</h3>";
        echo "<p>Algumas funcionalidades podem não estar disponíveis. Verifique os erros acima.</p>";
        echo "</div>";
    }
    
    // Links para teste
    echo "<div style='background: #e7f3ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>🔗 Links para Teste</h3>";
    echo "<ul>";
    echo "<li><a href='estoque_kardex.php'>📒 Kardex - Histórico de Movimentos</a></li>";
    echo "<li><a href='estoque_contagens.php'>📊 Controle de Estoque</a></li>";
    echo "<li><a href='lc_index.php'>🛒 Gestão de Compras</a></li>";
    echo "<li><a href='dashboard2.php'>🏠 Dashboard Principal</a></li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<h2 style='color: red;'>❌ Erro na Configuração</h2>";
    echo "<p style='color: red;'>Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre style='background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}

// Log da configuração
error_log("Configuração do módulo Kardex executada em " . date('Y-m-d H:i:s'));
?>

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

code {
    background: #e9ecef;
    padding: 2px 6px;
    border-radius: 3px;
    font-family: 'Courier New', monospace;
}
</style>
