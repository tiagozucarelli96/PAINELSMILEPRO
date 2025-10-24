<?php
// VERIFICACAO_REAL_BANCO.php
// Verificação real das dependências do banco

session_start();
require_once __DIR__ . '/public/conexao.php';

echo "<h1>🔍 VERIFICAÇÃO REAL DO BANCO DE DADOS</h1>";
echo "<p>Verificando problemas reais no sistema...</p>";

try {
    // 1. Verificar tabelas principais
    echo "<h2>1. 📊 Verificação de Tabelas Principais</h2>";
    
    $tabelas_principais = [
        'usuarios', 'eventos', 'fornecedores', 'lc_insumos', 'lc_categorias',
        'lc_unidades', 'lc_fichas', 'lc_listas', 'agenda_eventos', 'agenda_espacos',
        'comercial_degustacoes', 'comercial_campos_padrao', 'pagamentos_solicitacoes',
        'estoque_contagens', 'demandas_quadros', 'demandas_cartoes'
    ];
    
    $tabelas_ok = [];
    $tabelas_erro = [];
    
    foreach ($tabelas_principais as $tabela) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.$tabela LIMIT 1");
            $tabelas_ok[] = $tabela;
            echo "<p style='color: green;'>✅ $tabela - OK</p>";
        } catch (Exception $e) {
            $tabelas_erro[] = $tabela;
            echo "<p style='color: red;'>❌ $tabela - ERRO: " . $e->getMessage() . "</p>";
        }
    }
    
    // 2. Verificar colunas específicas que causam erro
    echo "<h2>2. 🔍 Verificação de Colunas Específicas</h2>";
    
    $colunas_problema = [
        'usuarios' => ['perm_agenda_ver', 'perm_agenda_meus', 'perm_agenda_relatorios'],
        'eventos' => ['descricao', 'data_inicio', 'data_fim', 'local', 'status'],
        'comercial_campos_padrao' => ['criado_em']
    ];
    
    foreach ($colunas_problema as $tabela => $colunas) {
        echo "<h3>📋 Tabela: $tabela</h3>";
        foreach ($colunas as $coluna) {
            try {
                $stmt = $pdo->query("SELECT $coluna FROM smilee12_painel_smile.$tabela LIMIT 1");
                echo "<p style='color: green;'>✅ $tabela.$coluna - OK</p>";
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ $tabela.$coluna - ERRO: " . $e->getMessage() . "</p>";
            }
        }
    }
    
    // 3. Verificar funções PostgreSQL
    echo "<h2>3. 🔧 Verificação de Funções PostgreSQL</h2>";
    
    $funcoes_teste = [
        'obter_proximos_eventos' => 'SELECT * FROM obter_proximos_eventos(1, 24) LIMIT 1',
        'obter_eventos_hoje' => 'SELECT * FROM obter_eventos_hoje(1) LIMIT 1',
        'obter_eventos_semana' => 'SELECT * FROM obter_eventos_semana(1) LIMIT 1'
    ];
    
    foreach ($funcoes_teste as $funcao => $sql) {
        try {
            $stmt = $pdo->query($sql);
            echo "<p style='color: green;'>✅ $funcao - OK</p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ $funcao - ERRO: " . $e->getMessage() . "</p>";
        }
    }
    
    // 4. Testar consultas específicas que causam erro
    echo "<h2>4. 🧪 Teste de Consultas Específicas</h2>";
    
    $consultas_teste = [
        'Dashboard Agenda' => "SELECT * FROM obter_proximos_eventos(1, 24)",
        'Comercial Campos' => "SELECT campos_json FROM smilee12_painel_smile.comercial_campos_padrao WHERE ativo = TRUE ORDER BY criado_em DESC LIMIT 1",
        'Usuários Permissões' => "SELECT perm_agenda_ver, perm_agenda_meus FROM smilee12_painel_smile.usuarios WHERE id = 1"
    ];
    
    foreach ($consultas_teste as $nome => $sql) {
        try {
            $stmt = $pdo->query($sql);
            $resultado = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo "<p style='color: green;'>✅ $nome - OK (" . count($resultado) . " registros)</p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ $nome - ERRO: " . $e->getMessage() . "</p>";
        }
    }
    
    // 5. Verificar estrutura da tabela eventos
    echo "<h2>5. 📋 Estrutura da Tabela eventos</h2>";
    
    try {
        $stmt = $pdo->query("
            SELECT column_name, data_type, is_nullable 
            FROM information_schema.columns 
            WHERE table_name = 'eventos' 
            AND table_schema = 'smilee12_painel_smile'
            ORDER BY ordinal_position
        ");
        $colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Coluna</th><th>Tipo</th><th>Nulo</th></tr>";
        foreach ($colunas as $col) {
            echo "<tr><td>{$col['column_name']}</td><td>{$col['data_type']}</td><td>{$col['is_nullable']}</td></tr>";
        }
        echo "</table>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao verificar estrutura: " . $e->getMessage() . "</p>";
    }
    
    // 6. Resumo final
    echo "<h2>6. 📊 Resumo Final</h2>";
    
    $total_tabelas = count($tabelas_principais);
    $tabelas_funcionando = count($tabelas_ok);
    $tabelas_com_problema = count($tabelas_erro);
    
    echo "<div style='background: #f0f9ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>📈 Estatísticas:</h3>";
    echo "<p>• <strong>Tabelas testadas:</strong> $total_tabelas</p>";
    echo "<p>• <strong>Tabelas funcionando:</strong> $tabelas_funcionando</p>";
    echo "<p>• <strong>Tabelas com problema:</strong> $tabelas_com_problema</p>";
    echo "</div>";
    
    if ($tabelas_com_problema == 0) {
        echo "<div style='background: #d1fae5; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3 style='color: #065f46;'>🎉 SISTEMA FUNCIONANDO!</h3>";
        echo "<p style='color: #065f46;'>✅ Todas as tabelas principais estão funcionando corretamente!</p>";
        echo "</div>";
    } else {
        echo "<div style='background: #fee2e2; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3 style='color: #991b1b;'>⚠️ PROBLEMAS DETECTADOS</h3>";
        echo "<p style='color: #991b1b;'>❌ Existem $tabelas_com_problema tabela(s) com problema(s).</p>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='background: #fee2e2; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #991b1b;'>❌ ERRO GERAL</h3>";
    echo "<p style='color: #991b1b;'>Erro durante a verificação: " . $e->getMessage() . "</p>";
    echo "</div>";
}
?>
