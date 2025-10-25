<?php
// EXECUTOR_SIMPLES.php
// Executor simples de migrações SQL

echo "<h1>🚀 EXECUTOR SIMPLES DE MIGRAÇÕES</h1>";

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

// Executar migrações uma por uma
$migracoes = [
    'criar_tabelas_compras' => __DIR__ . '/sql/migrations/20251025003635__criar_tabelas_compras.sql',
    'criar_tabelas_fornecedores' => __DIR__ . '/sql/migrations/20251025003635__criar_tabelas_fornecedores.sql',
    'criar_tabelas_pagamentos' => __DIR__ . '/sql/migrations/20251025003635__criar_tabelas_pagamentos.sql',
    'criar_tabelas_demandas' => __DIR__ . '/sql/migrations/20251025003635__criar_tabelas_demandas.sql',
    'criar_tabelas_comercial' => __DIR__ . '/sql/migrations/20251025003635__criar_tabelas_comercial.sql',
    'criar_tabelas_rh' => __DIR__ . '/sql/migrations/20251025003635__criar_tabelas_rh.sql',
    'criar_tabelas_contab' => __DIR__ . '/sql/migrations/20251025003635__criar_tabelas_contab.sql',
    'criar_tabelas_estoque' => __DIR__ . '/sql/migrations/20251025003635__criar_tabelas_estoque.sql',
    'criar_funcoes_postgresql' => __DIR__ . '/sql/migrations/20251025003635__criar_funcoes_postgresql.sql',
    'adicionar_colunas_faltantes' => __DIR__ . '/sql/migrations/20251025003635__adicionar_colunas_faltantes.sql'
];

echo "<h2>2. 📝 Executando migrações...</h2>";

$sucessos = 0;
$erros = 0;

foreach ($migracoes as $nome => $arquivo) {
    echo "<h3>📄 Executando: $nome</h3>";
    
    if (!file_exists($arquivo)) {
        echo "<p style='color: red;'>❌ Arquivo não encontrado: $arquivo</p>";
        $erros++;
        continue;
    }
    
    try {
        $sql = file_get_contents($arquivo);
        
        // Executar SQL diretamente
        $pdo->exec($sql);
        
        echo "<p style='color: green;'>✅ Migração executada com sucesso</p>";
        $sucessos++;
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro na migração: " . $e->getMessage() . "</p>";
        $erros++;
    }
}

// Resumo
echo "<h2>3. 📊 Resumo da Execução</h2>";
echo "<div style='background: #f0f9ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
echo "<h3>📈 Estatísticas:</h3>";
echo "<p>• <strong>Total de migrações:</strong> " . count($migracoes) . "</p>";
echo "<p>• <strong>Sucessos:</strong> $sucessos</p>";
echo "<p>• <strong>Erros:</strong> $erros</p>";
echo "</div>";

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

echo "<h2>💾 Execução concluída</h2>";
?>
