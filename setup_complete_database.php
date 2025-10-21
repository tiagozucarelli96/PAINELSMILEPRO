<?php
// setup_complete_database.php
// Script para criar todas as tabelas necessárias para o sistema de Lista de Compras

session_start();
require_once __DIR__ . '/public/conexao.php';

echo "<h1>🚀 Configuração Completa do Banco de Dados</h1>";
echo "<p>Este script criará todas as tabelas necessárias para o sistema de Lista de Compras.</p>";

if (!isset($pdo) || !$pdo instanceof PDO) {
    die("<div style='color:red;'>❌ Erro: Não foi possível conectar ao banco de dados. Verifique a configuração em public/conexao.php.</div>");
}

echo "<div style='color:green;'>✅ Conexão com banco de dados estabelecida!</div>";

try {
    // Ler o arquivo SQL
    $sql = file_get_contents(__DIR__ . '/create_all_tables.sql');
    if ($sql === false) {
        throw new Exception("Não foi possível ler o arquivo create_all_tables.sql");
    }

    echo "<h2>📋 Executando script SQL...</h2>";
    echo "<pre style='background:#f5f5f5; padding:10px; border-radius:5px; max-height:300px; overflow-y:auto;'>";

    // Executar o script SQL
    $pdo->exec($sql);
    
    echo "✅ Script SQL executado com sucesso!\n";
    echo "</pre>";

    // Verificar tabelas criadas
    echo "<h2>🔍 Verificação das tabelas criadas:</h2>";
    
    $tables = $pdo->query("
        SELECT table_name, 
               (SELECT COUNT(*) FROM information_schema.columns 
                WHERE table_name = t.table_name AND table_schema = 'public') as column_count
        FROM information_schema.tables t
        WHERE table_schema = 'public' 
        AND table_name LIKE 'lc_%'
        ORDER BY table_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>Tabela</th><th>Colunas</th><th>Status</th></tr>";
    
    foreach ($tables as $table) {
        echo "<tr>";
        echo "<td><strong>{$table['table_name']}</strong></td>";
        echo "<td>{$table['column_count']}</td>";
        echo "<td style='color:green;'>✅ OK</td>";
        echo "</tr>";
    }
    echo "</table>";

    // Verificar dados iniciais
    echo "<h2>📊 Dados iniciais inseridos:</h2>";
    
    $unidades = $pdo->query("SELECT COUNT(*) FROM lc_unidades")->fetchColumn();
    $categorias = $pdo->query("SELECT COUNT(*) FROM lc_categorias")->fetchColumn();
    $configs = $pdo->query("SELECT COUNT(*) FROM lc_config")->fetchColumn();
    
    echo "<ul>";
    echo "<li>✅ Unidades: {$unidades} registros</li>";
    echo "<li>✅ Categorias: {$categorias} registros</li>";
    echo "<li>✅ Configurações: {$configs} registros</li>";
    echo "</ul>";

    // Testar algumas queries básicas
    echo "<h2>🧪 Testando funcionalidades básicas:</h2>";
    
    try {
        // Teste 1: Listar categorias
        $cats = $pdo->query("SELECT nome FROM lc_categorias ORDER BY ordem")->fetchAll(PDO::FETCH_COLUMN);
        echo "<p>✅ Categorias disponíveis: " . implode(', ', $cats) . "</p>";
        
        // Teste 2: Listar unidades
        $units = $pdo->query("SELECT simbolo FROM lc_unidades ORDER BY simbolo")->fetchAll(PDO::FETCH_COLUMN);
        echo "<p>✅ Unidades disponíveis: " . implode(', ', $units) . "</p>";
        
        // Teste 3: Verificar configurações
        $configs = $pdo->query("SELECT chave, valor FROM lc_config ORDER BY chave")->fetchAll(PDO::FETCH_KEY_PAIR);
        echo "<p>✅ Configurações carregadas: " . count($configs) . " itens</p>";
        
    } catch (Exception $e) {
        echo "<p style='color:orange;'>⚠️ Aviso: " . htmlspecialchars($e->getMessage()) . "</p>";
    }

    echo "<div style='background: #d4edda; padding: 20px; border-radius: 10px; margin: 20px 0; border-left: 5px solid #28a745;'>";
    echo "<h3>🎉 SUCESSO! Configuração concluída!</h3>";
    echo "<p><strong>O sistema de Lista de Compras está pronto para uso!</strong></p>";
    echo "<ul>";
    echo "<li>✅ Todas as tabelas foram criadas</li>";
    echo "<li>✅ Dados iniciais foram inseridos</li>";
    echo "<li>✅ Configurações padrão foram definidas</li>";
    echo "<li>✅ Índices de performance foram criados</li>";
    echo "</ul>";
    echo "<p><strong>Próximos passos:</strong></p>";
    echo "<ul>";
    echo "<li>🔗 <a href='public/configuracoes.php'>Configurar categorias, unidades e insumos</a></li>";
    echo "<li>📋 <a href='public/lista_compras.php'>Gerar primeira lista de compras</a></li>";
    echo "<li>📊 <a href='public/lc_index.php'>Ver histórico de listas</a></li>";
    echo "</ul>";
    echo "</div>";

} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 20px; border-radius: 10px; margin: 20px 0; border-left: 5px solid #dc3545;'>";
    echo "<h3>❌ ERRO na configuração</h3>";
    echo "<p><strong>Erro:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Verifique se:</p>";
    echo "<ul>";
    echo "<li>O banco de dados está acessível</li>";
    echo "<li>O usuário tem permissões para criar tabelas</li>";
    echo "<li>O arquivo create_all_tables.sql existe</li>";
    echo "</ul>";
    echo "</div>";
}

echo "<hr>";
echo "<p><small>Script executado em: " . date('d/m/Y H:i:s') . "</small></p>";
?>
