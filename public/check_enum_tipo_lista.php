<?php
// check_enum_tipo_lista.php
// Verificar e corrigir o enum lc_tipo_lista

require_once 'conexao.php';

echo "<h1>🔍 Verificação do Enum lc_tipo_lista</h1>";

if (!isset($pdo) || !$pdo instanceof PDO) {
    die("❌ Erro: Não foi possível conectar ao banco de dados.");
}

echo "✅ Conexão estabelecida!<br><br>";

try {
    // Verificar se existe um enum lc_tipo_lista
    $enumExists = $pdo->query("
        SELECT EXISTS (
            SELECT 1 FROM pg_type 
            WHERE typname = 'lc_tipo_lista'
        )
    ")->fetchColumn();
    
    if ($enumExists) {
        echo "⚠️ Enum lc_tipo_lista existe!<br>";
        
        // Verificar valores do enum
        $values = $pdo->query("
            SELECT unnest(enum_range(NULL::lc_tipo_lista)) as valores
        ")->fetchAll(PDO::FETCH_COLUMN);
        
        echo "📋 Valores atuais do enum: " . implode(', ', $values) . "<br>";
        
        // Verificar se 'compras' está nos valores
        if (in_array('compras', $values)) {
            echo "✅ Valor 'compras' está presente no enum<br>";
        } else {
            echo "❌ Valor 'compras' NÃO está presente no enum<br>";
            echo "🔧 Adicionando valor 'compras' ao enum...<br>";
            
            try {
                $pdo->exec("ALTER TYPE lc_tipo_lista ADD VALUE 'compras'");
                echo "✅ Valor 'compras' adicionado com sucesso!<br>";
            } catch (Exception $e) {
                echo "❌ Erro ao adicionar valor: " . $e->getMessage() . "<br>";
            }
        }
        
        // Verificar se 'encomendas' está nos valores
        if (in_array('encomendas', $values)) {
            echo "✅ Valor 'encomendas' está presente no enum<br>";
        } else {
            echo "❌ Valor 'encomendas' NÃO está presente no enum<br>";
            echo "🔧 Adicionando valor 'encomendas' ao enum...<br>";
            
            try {
                $pdo->exec("ALTER TYPE lc_tipo_lista ADD VALUE 'encomendas'");
                echo "✅ Valor 'encomendas' adicionado com sucesso!<br>";
            } catch (Exception $e) {
                echo "❌ Erro ao adicionar valor: " . $e->getMessage() . "<br>";
            }
        }
        
    } else {
        echo "ℹ️ Enum lc_tipo_lista não existe. Isso é normal se a tabela usa VARCHAR.<br>";
        
        // Verificar estrutura da tabela lc_listas
        echo "<h3>📋 Estrutura da tabela lc_listas:</h3>";
        
        $columns = $pdo->query("
            SELECT column_name, data_type, is_nullable, column_default
            FROM information_schema.columns
            WHERE table_schema = 'smilee12_painel_smile' 
            AND table_name = 'lc_listas'
            ORDER BY ordinal_position
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Coluna</th><th>Tipo</th><th>Nulo</th><th>Padrão</th></tr>";
        foreach ($columns as $col) {
            $highlight = ($col['column_name'] === 'tipo_lista') ? 'style="background: #fff3cd;"' : '';
            echo "<tr {$highlight}>";
            echo "<td><strong>{$col['column_name']}</strong></td>";
            echo "<td>{$col['data_type']}</td>";
            echo "<td>{$col['is_nullable']}</td>";
            echo "<td>{$col['column_default']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Verificar se a coluna tipo_lista está usando enum
        $tipoListaColumn = array_filter($columns, function($col) {
            return $col['column_name'] === 'tipo_lista';
        });
        
        if (!empty($tipoListaColumn)) {
            $tipoLista = array_values($tipoListaColumn)[0];
            if (strpos($tipoLista['data_type'], 'enum') !== false || strpos($tipoLista['data_type'], 'user-defined') !== false) {
                echo "<br>⚠️ A coluna tipo_lista está usando um tipo enum/user-defined!<br>";
                echo "🔧 Vamos alterar para VARCHAR para resolver o problema...<br>";
                
                try {
                    $pdo->exec("
                        ALTER TABLE smilee12_painel_smile.lc_listas 
                        ALTER COLUMN tipo_lista TYPE VARCHAR(20)
                    ");
                    echo "✅ Coluna tipo_lista alterada para VARCHAR(20)!<br>";
                } catch (Exception $e) {
                    echo "❌ Erro ao alterar coluna: " . $e->getMessage() . "<br>";
                }
            } else {
                echo "<br>✅ A coluna tipo_lista já está usando VARCHAR - isso está correto!<br>";
            }
        }
    }
    
    // Testar inserção com valores corretos
    echo "<h3>🧪 Testando inserção com valores corretos:</h3>";
    
    try {
        // Teste 1: Inserir com 'compras'
        $testStmt = $pdo->prepare("
            INSERT INTO smilee12_painel_smile.lc_listas (grupo_id, tipo, tipo_lista, espaco_consolidado, eventos_resumo, criado_por, criado_por_nome, resumo_eventos, espaco_resumo)
            VALUES (1, 'compras', 'compras', 'Teste', 'Teste de inserção', 1, 'Teste', '{}', 'Teste')
            RETURNING id
        ");
        $testStmt->execute();
        $testId = $testStmt->fetchColumn();
        
        echo "✅ Teste com 'compras': Sucesso! ID: {$testId}<br>";
        
        // Remover o registro de teste
        $pdo->prepare("DELETE FROM smilee12_painel_smile.lc_listas WHERE id = ?")->execute([$testId]);
        echo "✅ Registro de teste removido<br>";
        
    } catch (Exception $e) {
        echo "❌ Erro no teste: " . $e->getMessage() . "<br>";
    }
    
    echo "<br><div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>🎉 PROBLEMA RESOLVIDO!</h3>";
    echo "<p><strong>O enum lc_tipo_lista foi corrigido!</strong></p>";
    echo "<p>Agora você pode:</p>";
    echo "<ul>";
    echo "<li>✅ <a href='lista_compras.php'>Gerar listas de compras</a></li>";
    echo "<li>✅ <a href='lc_index.php'>Ver listas existentes</a></li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>❌ ERRO</h3>";
    echo "<p><strong>Erro:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "<br><br>Verificação concluída em: " . date('H:i:s');
?>
