<?php
// create_lc_listas_eventos.php - Criar tabela lc_listas_eventos se não existir
require_once 'conexao.php';

echo "<h1>🔧 Criando Tabela lc_listas_eventos</h1>";

try {
    // Verificar se a tabela já existe
    $exists = $pdo->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables
            WHERE table_schema = 'smilee12_painel_smile' 
            AND table_name = 'lc_listas_eventos'
        )
    ")->fetchColumn();
    
    if ($exists) {
        echo "<p style='color: orange;'>⚠️ Tabela lc_listas_eventos já existe!</p>";
        
        // Verificar estrutura atual
        $columns = $pdo->query("
            SELECT column_name, data_type
            FROM information_schema.columns 
            WHERE table_schema = 'smilee12_painel_smile' 
            AND table_name = 'lc_listas_eventos'
            ORDER BY ordinal_position
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h2>📋 Estrutura Atual:</h2>";
        echo "<ul>";
        foreach ($columns as $col) {
            echo "<li>{$col['column_name']} ({$col['data_type']})</li>";
        }
        echo "</ul>";
        
        // Verificar se tem a coluna lista_id
        $hasListaId = false;
        foreach ($columns as $col) {
            if ($col['column_name'] === 'lista_id') {
                $hasListaId = true;
                break;
            }
        }
        
        if (!$hasListaId) {
            echo "<p style='color: red;'>❌ Coluna 'lista_id' não existe! Vou adicioná-la...</p>";
            
            // Adicionar coluna lista_id
            $pdo->exec("
                ALTER TABLE smilee12_painel_smile.lc_listas_eventos 
                ADD COLUMN IF NOT EXISTS lista_id INTEGER
            ");
            
            echo "<p style='color: green;'>✅ Coluna 'lista_id' adicionada!</p>";
        } else {
            echo "<p style='color: green;'>✅ Coluna 'lista_id' já existe!</p>";
        }
        
    } else {
        echo "<p style='color: blue;'>📝 Criando tabela lc_listas_eventos...</p>";
        
        // Criar a tabela
        $pdo->exec("
            CREATE TABLE smilee12_painel_smile.lc_listas_eventos (
                id SERIAL PRIMARY KEY,
                lista_id INTEGER NOT NULL,
                evento_nome VARCHAR(255) NOT NULL,
                evento_data DATE,
                convidados INTEGER DEFAULT 0,
                observacoes TEXT,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (lista_id) REFERENCES smilee12_painel_smile.lc_listas(id) ON DELETE CASCADE
            )
        ");
        
        echo "<p style='color: green;'>✅ Tabela lc_listas_eventos criada com sucesso!</p>";
        
        // Criar índices
        $pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_lc_listas_eventos_lista_id 
            ON smilee12_painel_smile.lc_listas_eventos(lista_id)
        ");
        
        echo "<p style='color: green;'>✅ Índices criados!</p>";
    }
    
    // Verificar estrutura final
    echo "<h2>📋 Estrutura Final:</h2>";
    $columns = $pdo->query("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns 
        WHERE table_schema = 'smilee12_painel_smile' 
        AND table_name = 'lc_listas_eventos'
        ORDER BY ordinal_position
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Coluna</th><th>Tipo</th><th>Nulo</th><th>Padrão</th></tr>";
    foreach ($columns as $col) {
        echo "<tr><td>{$col['column_name']}</td><td>{$col['data_type']}</td><td>{$col['is_nullable']}</td><td>" . ($col['column_default'] ?? 'NULL') . "</td></tr>";
    }
    echo "</table>";
    
    echo "<p style='color: green; font-weight: bold;'>🎉 Tabela lc_listas_eventos está pronta para uso!</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
}
?>
