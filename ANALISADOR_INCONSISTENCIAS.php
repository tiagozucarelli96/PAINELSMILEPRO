<?php
// ANALISADOR_INCONSISTENCIAS.php
// Analisador de inconsistências entre queries e estrutura do banco

echo "<h1>🔍 ANALISADOR DE INCONSISTÊNCIAS</h1>";

// Carregar mapeamento
$mapeamento = json_decode(file_get_contents('/tmp/mapeamento_queries.json'), true);

// Conectar ao banco
require_once __DIR__ . '/public/conexao.php';

echo "<h2>1. 🔌 Conectando ao banco...</h2>";
try {
    $stmt = $pdo->query("SELECT current_database(), current_schema()");
    $info = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>✅ Conectado ao banco: {$info['current_database']}</p>";
    echo "<p>✅ Schema atual: {$info['current_schema']}</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro de conexão: " . $e->getMessage() . "</p>";
    exit;
}

// Função para verificar se tabela existe
function tabelaExiste($pdo, $tabela) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = '$tabela'");
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Função para verificar se coluna existe
function colunaExiste($pdo, $tabela, $coluna) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = '$tabela' AND column_name = '$coluna'");
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Função para verificar se função existe
function funcaoExiste($pdo, $funcao) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.routines WHERE routine_name = '$funcao'");
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Analisar inconsistências
echo "<h2>2. 🔍 Analisando inconsistências...</h2>";

$inconsistencias = [];
$tabelas_verificadas = [];
$colunas_verificadas = [];
$funcoes_verificadas = [];

foreach ($mapeamento as $arquivo_info) {
    $arquivo = $arquivo_info['arquivo'];
    $tabelas = $arquivo_info['tabelas'];
    $colunas = $arquivo_info['colunas'];
    
    echo "<h3>📄 $arquivo</h3>";
    
    // Verificar tabelas
    foreach ($tabelas as $tabela) {
        if (!in_array($tabela, $tabelas_verificadas)) {
            $tabelas_verificadas[] = $tabela;
            
            if (!tabelaExiste($pdo, $tabela)) {
                $inconsistencias[] = [
                    'tipo' => 'tabela_inexistente',
                    'arquivo' => $arquivo,
                    'tabela' => $tabela,
                    'severidade' => 'alta'
                ];
                echo "<p style='color: red;'>❌ Tabela '$tabela' não existe</p>";
            } else {
                echo "<p style='color: green;'>✅ Tabela '$tabela' existe</p>";
            }
        }
    }
    
    // Verificar colunas (apenas para tabelas que existem)
    foreach ($tabelas as $tabela) {
        if (tabelaExiste($pdo, $tabela)) {
            foreach ($colunas as $coluna) {
                $chave = "$tabela.$coluna";
                if (!in_array($chave, $colunas_verificadas)) {
                    $colunas_verificadas[] = $chave;
                    
                    if (!colunaExiste($pdo, $tabela, $coluna)) {
                        $inconsistencias[] = [
                            'tipo' => 'coluna_inexistente',
                            'arquivo' => $arquivo,
                            'tabela' => $tabela,
                            'coluna' => $coluna,
                            'severidade' => 'media'
                        ];
                        echo "<p style='color: orange;'>⚠️ Coluna '$coluna' não existe na tabela '$tabela'</p>";
                    } else {
                        echo "<p style='color: green;'>✅ Coluna '$coluna' existe na tabela '$tabela'</p>";
                    }
                }
            }
        }
    }
}

// Verificar funções específicas
echo "<h2>3. 🔧 Verificando funções específicas...</h2>";

$funcoes_importantes = [
    'obter_proximos_eventos',
    'obter_eventos_hoje', 
    'obter_eventos_semana',
    'lc_buscar_fornecedores_ativos',
    'lc_buscar_freelancers_ativos',
    'lc_gerar_token_publico',
    'rh_estatisticas_dashboard',
    'contab_estatisticas_dashboard'
];

foreach ($funcoes_importantes as $funcao) {
    if (!in_array($funcao, $funcoes_verificadas)) {
        $funcoes_verificadas[] = $funcao;
        
        if (!funcaoExiste($pdo, $funcao)) {
            $inconsistencias[] = [
                'tipo' => 'funcao_inexistente',
                'funcao' => $funcao,
                'severidade' => 'alta'
            ];
            echo "<p style='color: red;'>❌ Função '$funcao' não existe</p>";
        } else {
            echo "<p style='color: green;'>✅ Função '$funcao' existe</p>";
        }
    }
}

// Resumo das inconsistências
echo "<h2>4. 📊 Resumo das Inconsistências</h2>";

$tabelas_inexistentes = array_filter($inconsistencias, function($i) { return $i['tipo'] === 'tabela_inexistente'; });
$colunas_inexistentes = array_filter($inconsistencias, function($i) { return $i['tipo'] === 'coluna_inexistente'; });
$funcoes_inexistentes = array_filter($inconsistencias, function($i) { return $i['tipo'] === 'funcao_inexistente'; });

echo "<div style='background: #f0f9ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
echo "<h3>📈 Estatísticas:</h3>";
echo "<p>• <strong>Total de inconsistências:</strong> " . count($inconsistencias) . "</p>";
echo "<p>• <strong>Tabelas inexistentes:</strong> " . count($tabelas_inexistentes) . "</p>";
echo "<p>• <strong>Colunas inexistentes:</strong> " . count($colunas_inexistentes) . "</p>";
echo "<p>• <strong>Funções inexistentes:</strong> " . count($funcoes_inexistentes) . "</p>";
echo "</div>";

// Listar inconsistências por severidade
echo "<h3>🔴 Inconsistências de Alta Severidade:</h3>";
$alta_severidade = array_filter($inconsistencias, function($i) { return $i['severidade'] === 'alta'; });
foreach ($alta_severidade as $inc) {
    if ($inc['tipo'] === 'tabela_inexistente') {
        echo "<p>• <strong>{$inc['arquivo']}</strong>: Tabela '{$inc['tabela']}' não existe</p>";
    } elseif ($inc['tipo'] === 'funcao_inexistente') {
        echo "<p>• Função '{$inc['funcao']}' não existe</p>";
    }
}

echo "<h3>🟡 Inconsistências de Média Severidade:</h3>";
$media_severidade = array_filter($inconsistencias, function($i) { return $i['severidade'] === 'media'; });
foreach ($media_severidade as $inc) {
    echo "<p>• <strong>{$inc['arquivo']}</strong>: Coluna '{$inc['coluna']}' não existe na tabela '{$inc['tabela']}'</p>";
}

// Salvar relatório de inconsistências
file_put_contents('/tmp/inconsistencias.json', json_encode($inconsistencias, JSON_PRETTY_PRINT));

echo "<h2>💾 Relatório de inconsistências salvo em /tmp/inconsistencias.json</h2>";

// Próximos passos
echo "<h2>5. 🚀 Próximos Passos</h2>";
echo "<div style='background: #fef3c7; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
echo "<h3>📋 Ações Necessárias:</h3>";

if (count($tabelas_inexistentes) > 0) {
    echo "<p>1. 🗄️ Criar " . count($tabelas_inexistentes) . " tabela(s) faltante(s)</p>";
}

if (count($colunas_inexistentes) > 0) {
    echo "<p>2. 📝 Adicionar " . count($colunas_inexistentes) . " coluna(s) faltante(s)</p>";
}

if (count($funcoes_inexistentes) > 0) {
    echo "<p>3. 🔧 Criar " . count($funcoes_inexistentes) . " função(ões) faltante(s)</p>";
}

if (count($inconsistencias) == 0) {
    echo "<p>✅ <strong>Nenhuma inconsistência encontrada!</strong></p>";
}

echo "</div>";
?>
