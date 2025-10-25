<?php
/**
 * VERIFICADOR DE COLUNAS FALTANTES
 * Analisa todos os arquivos PHP e identifica colunas que podem estar faltando
 */

require_once 'public/conexao.php';

header('Content-Type: text/html; charset=utf-8');
echo "<h1>🔍 VERIFICADOR DE COLUNAS FALTANTES</h1>";

if (!isset($pdo) || !$pdo) {
    echo "<p style='color: red;'>❌ Erro: PDO não inicializado.</p>";
    exit;
}

// Função para extrair colunas de queries SQL
function extrairColunasDeQuery($sql) {
    $colunas = [];
    
    // Padrões para identificar colunas em SELECT
    if (preg_match_all('/SELECT\s+([^FROM]+)/i', $sql, $matches)) {
        foreach ($matches[1] as $select_part) {
            // Remover funções e agrupamentos
            $select_part = preg_replace('/\b(COUNT|SUM|AVG|MAX|MIN|DISTINCT)\s*\([^)]*\)/i', '', $select_part);
            $select_part = preg_replace('/\s+AS\s+\w+/i', '', $select_part);
            
            // Dividir por vírgulas e limpar
            $parts = explode(',', $select_part);
            foreach ($parts as $part) {
                $part = trim($part);
                if (!empty($part) && !preg_match('/^\w+\.\*$/', $part)) {
                    // Remover alias de tabela
                    $part = preg_replace('/^\w+\./', '', $part);
                    if (!empty($part)) {
                        $colunas[] = $part;
                    }
                }
            }
        }
    }
    
    return array_unique($colunas);
}

// Função para extrair colunas de INSERT/UPDATE
function extrairColunasDeInsertUpdate($sql) {
    $colunas = [];
    
    // INSERT
    if (preg_match('/INSERT\s+INTO\s+\w+\s*\(([^)]+)\)/i', $sql, $matches)) {
        $parts = explode(',', $matches[1]);
        foreach ($parts as $part) {
            $colunas[] = trim($part);
        }
    }
    
    // UPDATE
    if (preg_match('/UPDATE\s+\w+\s+SET\s+([^WHERE]+)/i', $sql, $matches)) {
        $parts = explode(',', $matches[1]);
        foreach ($parts as $part) {
            if (preg_match('/(\w+)\s*=/', $part, $col_match)) {
                $colunas[] = trim($col_match[1]);
            }
        }
    }
    
    return array_unique($colunas);
}

echo "<h2>1. 🔍 Analisando arquivos PHP...</h2>";

$arquivos_php = glob('public/*.php');
$todas_colunas = [];
$tabelas_identificadas = [];

foreach ($arquivos_php as $arquivo) {
    $conteudo = file_get_contents($arquivo);
    
    // Extrair queries SQL
    preg_match_all('/(?:SELECT|INSERT|UPDATE|DELETE)\s+[^;]+/i', $conteudo, $queries);
    
    foreach ($queries[0] as $query) {
        // Limpar query
        $query = preg_replace('/\s+/', ' ', trim($query));
        
        // Identificar tabela
        if (preg_match('/(?:FROM|INTO|UPDATE)\s+(\w+)/i', $query, $table_match)) {
            $tabela = $table_match[1];
            $tabelas_identificadas[] = $tabela;
            
            // Extrair colunas
            $colunas_select = extrairColunasDeQuery($query);
            $colunas_insert_update = extrairColunasDeInsertUpdate($query);
            
            $todas_colunas = array_merge($todas_colunas, $colunas_select, $colunas_insert_update);
        }
    }
}

$tabelas_identificadas = array_unique($tabelas_identificadas);
$todas_colunas = array_unique($todas_colunas);

echo "<h2>2. 📊 Tabelas identificadas no código:</h2>";
echo "<ul>";
foreach ($tabelas_identificadas as $tabela) {
    echo "<li>" . htmlspecialchars($tabela) . "</li>";
}
echo "</ul>";

echo "<h2>3. 🔍 Verificando colunas faltantes...</h2>";

$colunas_faltantes = [];
$tabelas_verificadas = [];

foreach ($tabelas_identificadas as $tabela) {
    try {
        // Verificar se tabela existe
        $stmt = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = '$tabela'");
        if (!$stmt->fetchColumn()) {
            echo "<p style='color: red;'>❌ Tabela '$tabela' não existe</p>";
            continue;
        }
        
        // Obter colunas existentes
        $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = '$tabela'");
        $colunas_existentes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $tabelas_verificadas[] = $tabela;
        
        // Verificar colunas usadas no código
        $colunas_usadas = array_filter($todas_colunas, function($coluna) use ($tabela) {
            return !empty($coluna) && !in_array($coluna, ['*', '1', 'COUNT(*)', 'MAX(*)', 'MIN(*)']);
        });
        
        foreach ($colunas_usadas as $coluna) {
            if (!in_array($coluna, $colunas_existentes)) {
                $colunas_faltantes[] = [
                    'tabela' => $tabela,
                    'coluna' => $coluna
                ];
                echo "<p style='color: orange;'>⚠️ Coluna '$coluna' não existe na tabela '$tabela'</p>";
            }
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao verificar tabela '$tabela': " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

echo "<h2>4. 🔧 Gerando SQL para corrigir colunas faltantes...</h2>";

if (!empty($colunas_faltantes)) {
    $sql_correcoes = "-- Correções para colunas faltantes\n";
    
    foreach ($colunas_faltantes as $item) {
        $tabela = $item['tabela'];
        $coluna = $item['coluna'];
        
        // Determinar tipo de coluna baseado no nome
        $tipo = 'VARCHAR(255)';
        if (strpos($coluna, 'data') !== false || strpos($coluna, 'created') !== false || strpos($coluna, 'updated') !== false) {
            $tipo = 'TIMESTAMP';
        } elseif (strpos($coluna, 'id') !== false) {
            $tipo = 'INTEGER';
        } elseif (strpos($coluna, 'valor') !== false || strpos($coluna, 'preco') !== false || strpos($coluna, 'total') !== false) {
            $tipo = 'DECIMAL(10,2)';
        } elseif (strpos($coluna, 'ativo') !== false || strpos($coluna, 'status') !== false) {
            $tipo = 'BOOLEAN';
        }
        
        $sql_correcoes .= "ALTER TABLE $tabela ADD COLUMN IF NOT EXISTS $coluna $tipo;\n";
    }
    
    file_put_contents('CORRECAO_COLUNAS_FALTANTES.sql', $sql_correcoes);
    echo "<p style='color: green;'>✅ SQL de correção salvo em: CORRECAO_COLUNAS_FALTANTES.sql</p>";
    
    // Executar correções
    echo "<h3>🚀 Executando correções...</h3>";
    try {
        $pdo->exec($sql_correcoes);
        echo "<p style='color: green;'>✅ Todas as colunas faltantes foram adicionadas!</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao executar correções: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
} else {
    echo "<p style='color: green;'>✅ Nenhuma coluna faltante encontrada!</p>";
}

echo "<h2>5. 📊 Resumo Final</h2>";
echo "<div style='background: #f0f9ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
echo "<h3>📈 Estatísticas:</h3>";
echo "<p>• <strong>Tabelas identificadas:</strong> " . count($tabelas_identificadas) . "</p>";
echo "<p>• <strong>Tabelas verificadas:</strong> " . count($tabelas_verificadas) . "</p>";
echo "<p>• <strong>Colunas faltantes encontradas:</strong> " . count($colunas_faltantes) . "</p>";
echo "</div>";

if (count($colunas_faltantes) === 0) {
    echo "<div style='background: #d1fae5; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #065f46;'>🎉 SUCESSO TOTAL!</h3>";
    echo "<p style='color: #065f46;'>✅ Todas as colunas necessárias existem no banco!</p>";
    echo "<p><strong>Status:</strong> Sistema 100% funcional!</p>";
    echo "</div>";
} else {
    echo "<div style='background: #fee2e2; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #991b1b;'>⚠️ CORREÇÕES APLICADAS</h3>";
    echo "<p style='color: #991b1b;'>✅ " . count($colunas_faltantes) . " colunas foram adicionadas ao banco.</p>";
    echo "</div>";
}

echo "<h2>💾 Verificação concluída</h2>";
?>
