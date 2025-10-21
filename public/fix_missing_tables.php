<?php
// fix_missing_tables.php
// Corrigir tabelas faltantes

require_once 'conexao.php';

echo "<h1>🔧 Corrigindo Tabelas Faltantes</h1>";

if (!isset($pdo) || !$pdo instanceof PDO) {
    die("❌ Erro: Não foi possível conectar ao banco de dados.");
}

echo "✅ Conexão estabelecida!<br><br>";

try {
    // Verificar se a tabela lc_itens_fixos existe
    $tableExists = $pdo->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables
            WHERE table_schema = 'smilee12_painel_smile' 
            AND table_name = 'lc_itens_fixos'
        )
    ")->fetchColumn();
    
    if (!$tableExists) {
        echo "❌ Tabela lc_itens_fixos não existe. Criando...<br>";
        
        // Criar a tabela
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS smilee12_painel_smile.lc_itens_fixos (
                id SERIAL PRIMARY KEY,
                insumo_id INTEGER NOT NULL,
                qtd NUMERIC(12,6) NOT NULL DEFAULT 0,
                unidade_id INTEGER REFERENCES smilee12_painel_smile.lc_unidades(id),
                observacao TEXT,
                ativo BOOLEAN DEFAULT TRUE,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        echo "✅ Tabela lc_itens_fixos criada com sucesso!<br>";
        
    } else {
        echo "✅ Tabela lc_itens_fixos já existe!<br>";
    }
    
    // Verificar outras tabelas importantes
    echo "<h3>📋 Verificação de outras tabelas:</h3>";
    $tables = [
        'lc_insumos' => 'Insumos',
        'lc_unidades' => 'Unidades', 
        'lc_categorias' => 'Categorias',
        'lc_config' => 'Configurações',
        'lc_fichas' => 'Fichas Técnicas',
        'lc_listas' => 'Listas'
    ];
    
    $missingTables = [];
    
    foreach ($tables as $table => $name) {
        $exists = $pdo->query("
            SELECT EXISTS (
                SELECT FROM information_schema.tables
                WHERE table_schema = 'smilee12_painel_smile' 
                AND table_name = '{$table}'
            )
        ")->fetchColumn();
        
        if ($exists) {
            echo "✅ {$name} ({$table})<br>";
        } else {
            echo "❌ {$name} ({$table}) - FALTANDO<br>";
            $missingTables[] = $table;
        }
    }
    
    if (empty($missingTables)) {
        echo "<br><div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
        echo "<h3>🎉 SUCESSO!</h3>";
        echo "<p><strong>Todas as tabelas necessárias estão presentes!</strong></p>";
        echo "<p>O sistema está pronto para uso.</p>";
        echo "</div>";
    } else {
        echo "<br><div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
        echo "<h3>⚠️ ATENÇÃO</h3>";
        echo "<p><strong>Tabelas faltantes:</strong> " . implode(', ', $missingTables) . "</p>";
        echo "<p>Execute o script de criação de tabelas primeiro.</p>";
        echo "<p><a href='setup_complete_database.php'>→ Executar Setup Completo</a></p>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>❌ ERRO</h3>";
    echo "<p><strong>Erro:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "<br><br>Verificação concluída em: " . date('H:i:s');
?>
