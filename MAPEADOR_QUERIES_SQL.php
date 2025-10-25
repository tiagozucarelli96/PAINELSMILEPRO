<?php
// MAPEADOR_QUERIES_SQL.php
// Mapeador completo de queries SQL em todos os arquivos PHP

echo "<h1>🔍 MAPEADOR DE QUERIES SQL</h1>";

// Função para extrair queries de um arquivo
function extrairQueries($arquivo) {
    $conteudo = file_get_contents($arquivo);
    $queries = [];
    
    // Padrões para encontrar queries
    $padroes = [
        // PDO queries
        '/\$[a-zA-Z_][a-zA-Z0-9_]*\s*->\s*query\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
        '/\$[a-zA-Z_][a-zA-Z0-9_]*\s*->\s*prepare\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
        '/\$[a-zA-Z_][a-zA-Z0-9_]*\s*->\s*exec\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
        
        // Strings SQL diretas
        '/[\'"](SELECT\s+[^;\'"]+)[\'"]/i',
        '/[\'"](INSERT\s+[^;\'"]+)[\'"]/i',
        '/[\'"](UPDATE\s+[^;\'"]+)[\'"]/i',
        '/[\'"](DELETE\s+[^;\'"]+)[\'"]/i',
        '/[\'"](CREATE\s+[^;\'"]+)[\'"]/i',
        '/[\'"](ALTER\s+[^;\'"]+)[\'"]/i',
        '/[\'"](DROP\s+[^;\'"]+)[\'"]/i',
        
        // Queries em variáveis
        '/\$[a-zA-Z_][a-zA-Z0-9_]*\s*=\s*[\'"]([^\'"]+)[\'"]/',
    ];
    
    foreach ($padroes as $padrao) {
        preg_match_all($padrao, $conteudo, $matches);
        foreach ($matches[1] as $query) {
            if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\b/i', $query)) {
                $queries[] = trim($query);
            }
        }
    }
    
    return array_unique($queries);
}

// Função para extrair tabelas e colunas de uma query
function extrairTabelasColunas($query) {
    $tabelas = [];
    $colunas = [];
    
    // Extrair tabelas (FROM, JOIN, INSERT INTO, UPDATE)
    preg_match_all('/\bFROM\s+([a-zA-Z_][a-zA-Z0-9_]*)/i', $query, $matches);
    foreach ($matches[1] as $tabela) {
        $tabelas[] = $tabela;
    }
    
    preg_match_all('/\bJOIN\s+([a-zA-Z_][a-zA-Z0-9_]*)/i', $query, $matches);
    foreach ($matches[1] as $tabela) {
        $tabelas[] = $tabela;
    }
    
    preg_match_all('/\bINSERT\s+INTO\s+([a-zA-Z_][a-zA-Z0-9_]*)/i', $query, $matches);
    foreach ($matches[1] as $tabela) {
        $tabelas[] = $tabela;
    }
    
    preg_match_all('/\bUPDATE\s+([a-zA-Z_][a-zA-Z0-9_]*)/i', $query, $matches);
    foreach ($matches[1] as $tabela) {
        $tabelas[] = $tabela;
    }
    
    preg_match_all('/\bDELETE\s+FROM\s+([a-zA-Z_][a-zA-Z0-9_]*)/i', $query, $matches);
    foreach ($matches[1] as $tabela) {
        $tabelas[] = $tabela;
    }
    
    // Extrair colunas (SELECT, SET, VALUES)
    preg_match_all('/\bSELECT\s+([^FROM]+)/i', $query, $matches);
    foreach ($matches[1] as $select) {
        preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/', $select, $colMatches);
        foreach ($colMatches[1] as $col) {
            if (!in_array(strtoupper($col), ['SELECT', 'DISTINCT', 'AS', 'FROM'])) {
                $colunas[] = $col;
            }
        }
    }
    
    preg_match_all('/\bSET\s+([^WHERE]+)/i', $query, $matches);
    foreach ($matches[1] as $set) {
        preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\s*=/', $set, $colMatches);
        foreach ($colMatches[1] as $col) {
            $colunas[] = $col;
        }
    }
    
    return [
        'tabelas' => array_unique($tabelas),
        'colunas' => array_unique($colunas)
    ];
}

// Listar todos os arquivos PHP em public/
$arquivos_php = glob(__DIR__ . '/public/*.php');
$relatorio = [];

echo "<h2>📁 Arquivos PHP encontrados: " . count($arquivos_php) . "</h2>";

$total_queries = 0;
$total_arquivos = 0;

foreach ($arquivos_php as $arquivo) {
    $nome_arquivo = basename($arquivo);
    echo "<h3>📄 $nome_arquivo</h3>";
    
    $queries = extrairQueries($arquivo);
    $total_queries += count($queries);
    $total_arquivos++;
    
    if (empty($queries)) {
        echo "<p style='color: gray;'>Nenhuma query SQL encontrada</p>";
        continue;
    }
    
    echo "<p><strong>Queries encontradas:</strong> " . count($queries) . "</p>";
    
    $arquivo_info = [
        'arquivo' => $nome_arquivo,
        'queries' => [],
        'tabelas' => [],
        'colunas' => []
    ];
    
    foreach ($queries as $i => $query) {
        echo "<div style='background: #f8f9fa; padding: 10px; margin: 5px 0; border-radius: 4px;'>";
        echo "<strong>Query " . ($i + 1) . ":</strong><br>";
        echo "<code style='background: #e9ecef; padding: 5px; display: block; margin: 5px 0;'>" . htmlspecialchars($query) . "</code>";
        
        $info = extrairTabelasColunas($query);
        $arquivo_info['queries'][] = $query;
        $arquivo_info['tabelas'] = array_merge($arquivo_info['tabelas'], $info['tabelas']);
        $arquivo_info['colunas'] = array_merge($arquivo_info['colunas'], $info['colunas']);
        
        if (!empty($info['tabelas'])) {
            echo "<strong>Tabelas:</strong> " . implode(', ', $info['tabelas']) . "<br>";
        }
        if (!empty($info['colunas'])) {
            echo "<strong>Colunas:</strong> " . implode(', ', $info['colunas']) . "<br>";
        }
        echo "</div>";
    }
    
    $arquivo_info['tabelas'] = array_unique($arquivo_info['tabelas']);
    $arquivo_info['colunas'] = array_unique($arquivo_info['colunas']);
    
    $relatorio[] = $arquivo_info;
}

// Resumo
echo "<h2>📊 Resumo do Mapeamento</h2>";
echo "<div style='background: #f0f9ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
echo "<h3>📈 Estatísticas:</h3>";
echo "<p>• <strong>Arquivos PHP analisados:</strong> $total_arquivos</p>";
echo "<p>• <strong>Total de queries encontradas:</strong> $total_queries</p>";

// Contar tabelas únicas
$todas_tabelas = [];
$todas_colunas = [];

foreach ($relatorio as $info) {
    $todas_tabelas = array_merge($todas_tabelas, $info['tabelas']);
    $todas_colunas = array_merge($todas_colunas, $info['colunas']);
}

$todas_tabelas = array_unique($todas_tabelas);
$todas_colunas = array_unique($todas_colunas);

echo "<p>• <strong>Tabelas únicas encontradas:</strong> " . count($todas_tabelas) . "</p>";
echo "<p>• <strong>Colunas únicas encontradas:</strong> " . count($todas_colunas) . "</p>";
echo "</div>";

// Listar tabelas encontradas
echo "<h3>🗄️ Tabelas encontradas:</h3>";
echo "<ul>";
foreach ($todas_tabelas as $tabela) {
    echo "<li>$tabela</li>";
}
echo "</ul>";

// Salvar relatório em JSON para análise posterior
file_put_contents('/tmp/mapeamento_queries.json', json_encode($relatorio, JSON_PRETTY_PRINT));

echo "<h2>💾 Relatório salvo em /tmp/mapeamento_queries.json</h2>";
?>
