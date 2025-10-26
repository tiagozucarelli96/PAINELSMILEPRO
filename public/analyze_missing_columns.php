<?php
// analyze_missing_columns.php
// Análise completa de colunas referenciadas mas não existentes

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

echo "<h1>🔍 Análise de Colunas Referenciadas vs Existentes</h1>";
echo "<p>Identificando todas as colunas que são referenciadas no código mas não existem no banco...</p>";

try {
    // 1. Analisar arquivos PHP para encontrar referências a colunas
    echo "<h2>1. 📝 Analisando Referências no Código</h2>";
    
    $arquivos_php = glob(__DIR__ . '/*.php');
    $referencias_encontradas = [];
    
    foreach ($arquivos_php as $arquivo) {
        $conteudo = file_get_contents($arquivo);
        $nome_arquivo = basename($arquivo);
        
        // Padrões para encontrar referências a colunas
        $padroes = [
            '/SELECT.*?(\w+\.\w+)/i',
            '/INSERT.*?\(([^)]+)\)/i',
            '/UPDATE.*?SET.*?(\w+)/i',
            '/WHERE.*?(\w+\.\w+)/i',
            '/ORDER BY.*?(\w+)/i',
            '/GROUP BY.*?(\w+)/i'
        ];
        
        foreach ($padroes as $padrao) {
            preg_match_all($padrao, $conteudo, $matches);
            foreach ($matches[1] as $match) {
                if (strpos($match, '.') !== false) {
                    $partes = explode('.', $match);
                    if (count($partes) == 2) {
                        $tabela = trim($partes[0]);
                        $coluna = trim($partes[1]);
                        if (!isset($referencias_encontradas[$tabela])) {
                            $referencias_encontradas[$tabela] = [];
                        }
                        if (!in_array($coluna, $referencias_encontradas[$tabela])) {
                            $referencias_encontradas[$tabela][] = $coluna;
                        }
                    }
                }
            }
        }
    }
    
    echo "<h3>Referências encontradas no código:</h3>";
    foreach ($referencias_encontradas as $tabela => $colunas) {
        echo "<h4>Tabela: $tabela</h4>";
        echo "<ul>";
        foreach ($colunas as $coluna) {
            echo "<li>$coluna</li>";
        }
        echo "</ul>";
    }
    
    // 2. Verificar quais tabelas existem no banco
    echo "<h2>2. 🗄️ Verificando Estrutura do Banco</h2>";
    
    $stmt = $pdo->query("
        SELECT table_name, column_name, data_type, is_nullable, column_default
        FROM information_schema.columns 
        WHERE table_schema = 'public'
        ORDER BY table_name, ordinal_position
    ");
    $estrutura_banco = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $tabelas_banco = [];
    foreach ($estrutura_banco as $coluna) {
        $tabela = $coluna['table_name'];
        if (!isset($tabelas_banco[$tabela])) {
            $tabelas_banco[$tabela] = [];
        }
        $tabelas_banco[$tabela][] = $coluna['column_name'];
    }
    
    echo "<h3>Tabelas existentes no banco:</h3>";
    foreach ($tabelas_banco as $tabela => $colunas) {
        echo "<h4>$tabela (" . count($colunas) . " colunas)</h4>";
        echo "<ul>";
        foreach ($colunas as $coluna) {
            echo "<li>$coluna</li>";
        }
        echo "</ul>";
    }
    
    // 3. Comparar referências vs estrutura do banco
    echo "<h2>3. ⚖️ Comparação: Referências vs Banco</h2>";
    
    $problemas = [];
    $sugestoes = [];
    
    foreach ($referencias_encontradas as $tabela => $colunas_referenciadas) {
        if (isset($tabelas_banco[$tabela])) {
            $colunas_existentes = $tabelas_banco[$tabela];
            $colunas_faltando = array_diff($colunas_referenciadas, $colunas_existentes);
            
            if (!empty($colunas_faltando)) {
                $problemas[$tabela] = $colunas_faltando;
            }
        } else {
            $problemas[$tabela] = "TABELA_INEXISTENTE";
        }
    }
    
    if (!empty($problemas)) {
        echo "<div style='background: #f8d7da; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3 style='color: #721c24;'>❌ Problemas Encontrados</h3>";
        foreach ($problemas as $tabela => $problema) {
            if ($problema === "TABELA_INEXISTENTE") {
                echo "<p style='color: red;'><strong>$tabela:</strong> Tabela não existe no banco</p>";
                $sugestoes[] = "CREATE TABLE $tabela (...)";
            } else {
                echo "<p style='color: red;'><strong>$tabela:</strong> Colunas faltando: " . implode(', ', $problema) . "</p>";
                foreach ($problema as $coluna) {
                    $sugestoes[] = "ALTER TABLE $tabela ADD COLUMN $coluna VARCHAR(255)";
                }
            }
        }
        echo "</div>";
    } else {
        echo "<div style='background: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3 style='color: #155724;'>✅ Nenhum Problema Encontrado</h3>";
        echo "<p>Todas as referências no código correspondem a colunas existentes no banco.</p>";
        echo "</div>";
    }
    
    // 4. Gerar sugestões de correção
    if (!empty($sugestoes)) {
        echo "<h2>4. 🔧 Sugestões de Correção</h2>";
        echo "<div style='background: #e7f3ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3>Comandos SQL sugeridos:</h3>";
        echo "<pre style='background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto;'>";
        foreach ($sugestoes as $sugestao) {
            echo htmlspecialchars($sugestao) . ";\n";
        }
        echo "</pre>";
        echo "</div>";
    }
    
    // 5. Análise específica de arquivos problemáticos
    echo "<h2>5. 📁 Análise por Arquivo</h2>";
    
    $arquivos_problematicos = [
        'test_complete_system.php' => 'Teste completo do sistema',
        'test_estoque_functions.php' => 'Teste de funcionalidades',
        'lc_substitutes_helper.php' => 'Helper de substitutos',
        'estoque_alertas.php' => 'Alertas de estoque',
        'estoque_contagens.php' => 'Contagens de estoque'
    ];
    
    foreach ($arquivos_problematicos as $arquivo => $descricao) {
        if (file_exists(__DIR__ . '/' . $arquivo)) {
            echo "<h3>$descricao ($arquivo)</h3>";
            
            $conteudo = file_get_contents(__DIR__ . '/' . $arquivo);
            
            // Buscar por padrões específicos que podem causar problemas
            $padroes_problema = [
                '/preco/i' => 'Referência à coluna "preco"',
                '/status/i' => 'Referência à coluna "status"',
                '/criado_por/i' => 'Referência à coluna "criado_por"',
                '/lc_evento_cardapio/i' => 'Referência à tabela "lc_evento_cardapio"'
            ];
            
            foreach ($padroes_problema as $padrao => $descricao_padrao) {
                if (preg_match($padrao, $conteudo)) {
                    echo "<p style='color: orange;'>⚠️ $descricao_padrao</p>";
                }
            }
        }
    }
    
    // 6. Resumo final
    echo "<h2>6. 📊 Resumo da Análise</h2>";
    
    $total_referencias = 0;
    foreach ($referencias_encontradas as $tabela => $colunas) {
        $total_referencias += count($colunas);
    }
    
    $total_problemas = 0;
    foreach ($problemas as $tabela => $problema) {
        if ($problema === "TABELA_INEXISTENTE") {
            $total_problemas++;
        } else {
            $total_problemas += count($problema);
        }
    }
    
    echo "<div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>📈 Estatísticas</h3>";
    echo "<ul>";
    echo "<li><strong>Total de referências encontradas:</strong> $total_referencias</li>";
    echo "<li><strong>Total de problemas:</strong> $total_problemas</li>";
    echo "<li><strong>Tabelas analisadas:</strong> " . count($referencias_encontradas) . "</li>";
    echo "<li><strong>Tabelas no banco:</strong> " . count($tabelas_banco) . "</li>";
    echo "</ul>";
    echo "</div>";
    
    if ($total_problemas > 0) {
        echo "<div style='background: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3 style='color: #856404;'>⚠️ Ações Recomendadas</h3>";
        echo "<ol>";
        echo "<li>Execute o arquivo <code>sql/fix_database_structure.sql</code></li>";
        echo "<li>Execute o arquivo <code>public/fix_all_issues.php</code></li>";
        echo "<li>Reexecute os testes para verificar se os problemas foram resolvidos</li>";
        echo "<li>Atualize o código para remover referências a colunas inexistentes</li>";
        echo "</ol>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<h2 style='color: red;'>❌ Erro na Análise</h2>";
    echo "<p style='color: red;'>Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre style='background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto;'>";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}

// Log da análise
error_log("Análise de colunas referenciadas executada em " . date('Y-m-d H:i:s'));
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('✅ Análise de colunas concluída');
    
    // Adicionar funcionalidade de expandir/colapsar
    const headers = document.querySelectorAll('h2, h3');
    headers.forEach(header => {
        header.style.cursor = 'pointer';
        header.addEventListener('click', function() {
            const nextElement = this.nextElementSibling;
            if (nextElement && nextElement.style) {
                nextElement.style.display = nextElement.style.display === 'none' ? 'block' : 'none';
            }
        });
    });
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

code {
    background: #f1f3f4;
    padding: 2px 6px;
    border-radius: 3px;
    font-family: 'Courier New', monospace;
}
</style>
