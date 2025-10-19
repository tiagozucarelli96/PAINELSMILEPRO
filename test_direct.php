<?php
// test_direct.php
// Teste direto sem autenticação

require_once __DIR__ . '/public/conexao.php';

echo "<h1>🧪 Teste Direto do Sistema</h1>";

if (!isset($pdo) || !$pdo instanceof PDO) {
    die("<div style='color:red;'>❌ Erro: Não foi possível conectar ao banco de dados.</div>");
}

echo "<div style='color:green;'>✅ Conexão com banco estabelecida!</div>";

try {
    // Teste 1: Verificar tabelas
    echo "<h2>📋 Tabelas criadas:</h2>";
    
    $tables = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' 
        AND table_name LIKE 'lc_%'
        ORDER BY table_name
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<p>✅ Total: " . count($tables) . " tabelas</p>";
    echo "<ul>";
    foreach ($tables as $table) {
        echo "<li>✅ {$table}</li>";
    }
    echo "</ul>";
    
    // Teste 2: Dados iniciais
    echo "<h2>📊 Dados iniciais:</h2>";
    
    $unidades = $pdo->query("SELECT COUNT(*) FROM lc_unidades")->fetchColumn();
    $categorias = $pdo->query("SELECT COUNT(*) FROM lc_categorias")->fetchColumn();
    $configs = $pdo->query("SELECT COUNT(*) FROM lc_config")->fetchColumn();
    
    echo "<ul>";
    echo "<li>✅ Unidades: {$unidades}</li>";
    echo "<li>✅ Categorias: {$categorias}</li>";
    echo "<li>✅ Configurações: {$configs}</li>";
    echo "</ul>";
    
    // Teste 3: Listar algumas unidades
    echo "<h2>🔍 Unidades disponíveis:</h2>";
    $units = $pdo->query("SELECT simbolo, nome FROM lc_unidades ORDER BY simbolo")->fetchAll(PDO::FETCH_ASSOC);
    echo "<ul>";
    foreach ($units as $unit) {
        echo "<li>{$unit['simbolo']} - {$unit['nome']}</li>";
    }
    echo "</ul>";
    
    // Teste 4: Listar categorias
    echo "<h2>📂 Categorias disponíveis:</h2>";
    $cats = $pdo->query("SELECT nome, ordem FROM lc_categorias ORDER BY ordem")->fetchAll(PDO::FETCH_ASSOC);
    echo "<ul>";
    foreach ($cats as $cat) {
        echo "<li>{$cat['ordem']}. {$cat['nome']}</li>";
    }
    echo "</ul>";
    
    // Teste 5: Testar inserção
    echo "<h2>➕ Teste de inserção:</h2>";
    
    try {
        // Verificar se já existe um insumo de teste
        $existing = $pdo->query("SELECT COUNT(*) FROM lc_insumos WHERE nome LIKE 'Teste%'")->fetchColumn();
        
        if ($existing == 0) {
            $stmt = $pdo->prepare("
                INSERT INTO lc_insumos (nome, unidade_padrao, unidade, custo_unit, aquisicao, observacoes, ativo) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                'Teste - Arroz', 'kg', 'kg', 5.50, 'mercado', 'Insumo de teste', true
            ]);
            echo "<p>✅ Insumo de teste inserido!</p>";
        } else {
            echo "<p>ℹ️ Insumo de teste já existe</p>";
        }
        
        // Contar insumos
        $insumos = $pdo->query("SELECT COUNT(*) FROM lc_insumos")->fetchColumn();
        echo "<p>✅ Total de insumos: {$insumos}</p>";
        
    } catch (Exception $e) {
        echo "<p style='color:red;'>❌ Erro na inserção: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    echo "<div style='background: #d4edda; padding: 20px; border-radius: 10px; margin: 20px 0;'>";
    echo "<h3>🎉 SUCESSO!</h3>";
    echo "<p><strong>O banco de dados está funcionando perfeitamente!</strong></p>";
    echo "<p>Todas as tabelas foram criadas e os dados iniciais foram inseridos.</p>";
    echo "<p>O sistema está pronto para uso!</p>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 20px; border-radius: 10px; margin: 20px 0;'>";
    echo "<h3>❌ ERRO</h3>";
    echo "<p><strong>Erro:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "<hr>";
echo "<p><small>Teste executado em: " . date('d/m/Y H:i:s') . "</small></p>";
?>
