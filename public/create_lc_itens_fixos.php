<?php
// create_lc_itens_fixos.php
// Criar especificamente a tabela lc_itens_fixos

require_once 'conexao.php';

echo "<h1>🔧 Criando Tabela lc_itens_fixos</h1>";

if (!isset($pdo) || !$pdo instanceof PDO) {
    die("❌ Erro: Não foi possível conectar ao banco de dados.");
}

echo "✅ Conexão estabelecida!<br><br>";

try {
    // Verificar se a tabela já existe
    $tableExists = $pdo->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables
            WHERE table_schema = 'smilee12_painel_smile' 
            AND table_name = 'lc_itens_fixos'
        )
    ")->fetchColumn();
    
    if ($tableExists) {
        echo "✅ Tabela lc_itens_fixos já existe!<br>";
        
        // Mostrar estrutura
        $columns = $pdo->query("
            SELECT column_name, data_type, is_nullable, column_default
            FROM information_schema.columns
            WHERE table_schema = 'smilee12_painel_smile' 
            AND table_name = 'lc_itens_fixos'
            ORDER BY ordinal_position
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>📋 Estrutura da tabela:</h3>";
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Coluna</th><th>Tipo</th><th>Nulo</th><th>Padrão</th></tr>";
        foreach ($columns as $col) {
            echo "<tr>";
            echo "<td>{$col['column_name']}</td>";
            echo "<td>{$col['data_type']}</td>";
            echo "<td>{$col['is_nullable']}</td>";
            echo "<td>{$col['column_default']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Contar registros
        $count = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_itens_fixos")->fetchColumn();
        echo "<p>📊 Total de registros: {$count}</p>";
        
    } else {
        echo "❌ Tabela lc_itens_fixos não existe. Criando...<br>";
        
        // Criar a tabela
        $sql = "
            CREATE TABLE smilee12_painel_smile.lc_itens_fixos (
                id SERIAL PRIMARY KEY,
                insumo_id INTEGER NOT NULL,
                qtd NUMERIC(12,6) NOT NULL DEFAULT 0,
                unidade_id INTEGER REFERENCES smilee12_painel_smile.lc_unidades(id),
                observacao TEXT,
                ativo BOOLEAN DEFAULT TRUE,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ";
        
        $pdo->exec($sql);
        echo "✅ Tabela lc_itens_fixos criada com sucesso!<br>";
        
        // Verificar se foi criada
        $tableExists = $pdo->query("
            SELECT EXISTS (
                SELECT FROM information_schema.tables
                WHERE table_schema = 'smilee12_painel_smile' 
                AND table_name = 'lc_itens_fixos'
            )
        ")->fetchColumn();
        
        if ($tableExists) {
            echo "✅ Confirmação: Tabela criada com sucesso!<br>";
        } else {
            echo "❌ Erro: Tabela não foi criada.<br>";
        }
    }
    
    echo "<br><div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>🎉 PROBLEMA RESOLVIDO!</h3>";
    echo "<p><strong>A tabela lc_itens_fixos está disponível!</strong></p>";
    echo "<p>Agora você pode:</p>";
    echo "<ul>";
    echo "<li>✅ <a href='config_itens_fixos.php'>Configurar itens fixos</a></li>";
    echo "<li>✅ <a href='configuracoes.php'>Acessar configurações</a></li>";
    echo "<li>✅ <a href='lista_compras.php'>Gerar listas de compras</a></li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>❌ ERRO</h3>";
    echo "<p><strong>Erro:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Verifique se:</p>";
    echo "<ul>";
    echo "<li>O banco de dados está acessível</li>";
    echo "<li>O usuário tem permissões para criar tabelas</li>";
    echo "<li>A tabela lc_unidades existe (para a foreign key)</li>";
    echo "</ul>";
    echo "</div>";
}

echo "<br><br>Script executado em: " . date('H:i:s');
?>
