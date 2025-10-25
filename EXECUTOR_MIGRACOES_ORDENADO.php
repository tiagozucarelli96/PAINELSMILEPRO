<?php
// EXECUTOR_MIGRACOES_ORDENADO.php
// Executor de migrações SQL em ordem correta

echo "<h1>🚀 EXECUTOR DE MIGRAÇÕES ORDENADO</h1>";

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

// Ordem correta das migrações
$ordem_migracoes = [
    '20251025003635__criar_tabelas_compras.sql',
    '20251025003635__criar_tabelas_fornecedores.sql',
    '20251025003635__criar_tabelas_pagamentos.sql',
    '20251025003635__criar_tabelas_demandas.sql',
    '20251025003635__criar_tabelas_comercial.sql',
    '20251025003635__criar_tabelas_rh.sql',
    '20251025003635__criar_tabelas_contab.sql',
    '20251025003635__criar_tabelas_estoque.sql',
    '20251025003635__criar_funcoes_postgresql.sql',
    '20251025003635__adicionar_colunas_faltantes.sql'
];

echo "<h2>2. 📝 Executando migrações em ordem...</h2>";

$sucessos = 0;
$erros = 0;
$log_erros = [];

foreach ($ordem_migracoes as $migracao) {
    $caminho_migracao = __DIR__ . '/sql/migrations/' . $migracao;
    
    if (!file_exists($caminho_migracao)) {
        echo "<h3>📄 $migracao</h3>";
        echo "<p style='color: red;'>❌ Arquivo não encontrado</p>";
        $erros++;
        continue;
    }
    
    echo "<h3>📄 Executando: $migracao</h3>";
    
    try {
        $sql = file_get_contents($caminho_migracao);
        
        // Executar em transação
        $pdo->beginTransaction();
        
        // Dividir em comandos individuais
        $comandos = array_filter(array_map('trim', explode(';', $sql)));
        
        foreach ($comandos as $comando) {
            if (!empty($comando) && !preg_match('/^--/', $comando)) {
                $pdo->exec($comando);
            }
        }
        
        $pdo->commit();
        echo "<p style='color: green;'>✅ Migração executada com sucesso</p>";
        $sucessos++;
        
    } catch (Exception $e) {
        try {
            $pdo->rollBack();
        } catch (Exception $rollback_e) {
            // Ignorar erro de rollback se não há transação ativa
        }
        echo "<p style='color: red;'>❌ Erro na migração: " . $e->getMessage() . "</p>";
        $erros++;
        $log_erros[] = [
            'arquivo' => $migracao,
            'erro' => $e->getMessage()
        ];
    }
}

// Resumo
echo "<h2>3. 📊 Resumo da Execução</h2>";
echo "<div style='background: #f0f9ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
echo "<h3>📈 Estatísticas:</h3>";
echo "<p>• <strong>Total de migrações:</strong> " . count($ordem_migracoes) . "</p>";
echo "<p>• <strong>Sucessos:</strong> $sucessos</p>";
echo "<p>• <strong>Erros:</strong> $erros</p>";
echo "</div>";

if ($erros > 0) {
    echo "<h3>🔴 Erros encontrados:</h3>";
    foreach ($log_erros as $erro) {
        echo "<p>• <strong>{$erro['arquivo']}</strong>: {$erro['erro']}</p>";
    }
}

// Verificar tabelas criadas
echo "<h2>4. 🔍 Verificando tabelas criadas...</h2>";

$tabelas_esperadas = [
    'lc_categorias', 'lc_unidades', 'lc_fichas', 'lc_itens', 'lc_ficha_componentes',
    'lc_itens_fixos', 'lc_arredondamentos', 'lc_rascunhos', 'lc_encomendas_itens',
    'lc_encomendas_overrides', 'lc_geracoes', 'lc_lista_eventos', 'lc_compras_consolidadas',
    'fornecedores', 'lc_freelancers', 'lc_solicitacoes_pagamento', 'lc_timeline_pagamentos',
    'lc_anexos_pagamentos', 'demandas_quadros', 'demandas_participantes', 'demandas_cartoes',
    'demandas_preferencias_notificacao', 'comercial_inscricoes', 'comercial_campos_padrao',
    'comercial_email_config', 'rh_holerites', 'rh_anexos', 'contab_documentos',
    'contab_parcelas', 'contab_anexos', 'contab_tokens', 'lc_movimentos_estoque',
    'portao_logs', 'clickup_tokens'
];

$tabelas_criadas = 0;
$tabelas_faltantes = [];

foreach ($tabelas_esperadas as $tabela) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $tabela LIMIT 1");
        echo "<p style='color: green;'>✅ Tabela '$tabela' existe</p>";
        $tabelas_criadas++;
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Tabela '$tabela' não existe</p>";
        $tabelas_faltantes[] = $tabela;
    }
}

// Verificar funções criadas
echo "<h2>5. 🔧 Verificando funções criadas...</h2>";

$funcoes_esperadas = [
    'lc_buscar_fornecedores_ativos',
    'lc_buscar_freelancers_ativos', 
    'lc_gerar_token_publico',
    'rh_estatisticas_dashboard',
    'contab_estatisticas_dashboard'
];

$funcoes_criadas = 0;
$funcoes_faltantes = [];

foreach ($funcoes_esperadas as $funcao) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.routines WHERE routine_name = '$funcao'");
        $existe = $stmt->fetchColumn() > 0;
        if ($existe) {
            echo "<p style='color: green;'>✅ Função '$funcao' existe</p>";
            $funcoes_criadas++;
        } else {
            echo "<p style='color: red;'>❌ Função '$funcao' não existe</p>";
            $funcoes_faltantes[] = $funcao;
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao verificar função '$funcao': " . $e->getMessage() . "</p>";
        $funcoes_faltantes[] = $funcao;
    }
}

// Resumo final
echo "<h2>6. 📊 Resumo Final</h2>";
echo "<div style='background: #f0f9ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
echo "<h3>📈 Estatísticas:</h3>";
echo "<p>• <strong>Migrações executadas:</strong> $sucessos</p>";
echo "<p>• <strong>Erros nas migrações:</strong> $erros</p>";
echo "<p>• <strong>Tabelas criadas:</strong> $tabelas_criadas / " . count($tabelas_esperadas) . "</p>";
echo "<p>• <strong>Funções criadas:</strong> $funcoes_criadas / " . count($funcoes_esperadas) . "</p>";
echo "</div>";

if (count($tabelas_faltantes) > 0) {
    echo "<h3>🔴 Tabelas faltantes:</h3>";
    foreach ($tabelas_faltantes as $tabela) {
        echo "<p>• $tabela</p>";
    }
}

if (count($funcoes_faltantes) > 0) {
    echo "<h3>🔴 Funções faltantes:</h3>";
    foreach ($funcoes_faltantes as $funcao) {
        echo "<p>• $funcao</p>";
    }
}

if ($sucessos > 0 && $erros == 0 && count($tabelas_faltantes) == 0 && count($funcoes_faltantes) == 0) {
    echo "<div style='background: #d1fae5; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #065f46;'>🎉 SUCESSO TOTAL!</h3>";
    echo "<p style='color: #065f46;'>✅ Todas as migrações foram executadas com sucesso!</p>";
    echo "<p style='color: #065f46;'>✅ Todas as tabelas foram criadas!</p>";
    echo "<p style='color: #065f46;'>✅ Todas as funções foram criadas!</p>";
    echo "<p><strong>Status:</strong> Sistema 100% funcional e pronto para uso!</p>";
    echo "</div>";
} else {
    echo "<div style='background: #fee2e2; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #991b1b;'>⚠️ AINDA HÁ PROBLEMAS</h3>";
    echo "<p style='color: #991b1b;'>❌ Existem problemas que precisam ser resolvidos.</p>";
    echo "</div>";
}

// Salvar log
$log = [
    'timestamp' => date('Y-m-d H:i:s'),
    'migracoes_executadas' => $sucessos,
    'erros' => $erros,
    'tabelas_criadas' => $tabelas_criadas,
    'tabelas_faltantes' => $tabelas_faltantes,
    'funcoes_criadas' => $funcoes_criadas,
    'funcoes_faltantes' => $funcoes_faltantes,
    'log_erros' => $log_erros
];

file_put_contents('/tmp/execucao_migracoes_ordenado.json', json_encode($log, JSON_PRETTY_PRINT));

echo "<h2>💾 Log de execução salvo em /tmp/execucao_migracoes_ordenado.json</h2>";
?>
