<?php
// Script para criar as tabelas necessárias automaticamente
require_once __DIR__ . '/public/conexao.php';

echo "<h1>Configuração do Banco de Dados - Lista de Compras</h1>";

try {
    // Ler o arquivo SQL
    $sql = file_get_contents(__DIR__ . '/create_tables.sql');
    
    if (!$sql) {
        throw new Exception("Não foi possível ler o arquivo create_tables.sql");
    }
    
    // Dividir em comandos individuais (separados por ;)
    $commands = array_filter(array_map('trim', explode(';', $sql)));
    
    $successCount = 0;
    $errorCount = 0;
    
    echo "<h2>Executando comandos SQL...</h2>";
    echo "<ul>";
    
    foreach ($commands as $command) {
        if (empty($command) || strpos($command, '--') === 0) {
            continue; // Pular comentários e linhas vazias
        }
        
        try {
            $pdo->exec($command);
            $successCount++;
            echo "<li style='color: green;'>✓ " . htmlspecialchars(substr($command, 0, 50)) . "...</li>";
        } catch (PDOException $e) {
            $errorCount++;
            echo "<li style='color: red;'>✗ Erro: " . htmlspecialchars($e->getMessage()) . "</li>";
        }
    }
    
    echo "</ul>";
    
    echo "<h2>Resumo:</h2>";
    echo "<p><strong>Comandos executados com sucesso:</strong> {$successCount}</p>";
    echo "<p><strong>Erros:</strong> {$errorCount}</p>";
    
    if ($errorCount === 0) {
        echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
        echo "<h3 style='color: #155724; margin: 0 0 10px 0;'>✅ Configuração concluída com sucesso!</h3>";
        echo "<p style='margin: 0; color: #155724;'>Todas as tabelas foram criadas e o sistema está pronto para uso.</p>";
        echo "</div>";
        
        echo "<h3>Próximos passos:</h3>";
        echo "<ol>";
        echo "<li>Acesse <a href='public/lc_index.php'>Lista de Compras</a> para começar a usar</li>";
        echo "<li>Configure os <a href='public/configuracoes.php'>insumos e categorias</a></li>";
        echo "<li>Defina os <a href='public/lc_config_avancadas.php'>ajustes avançados</a></li>";
        echo "</ol>";
    } else {
        echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
        echo "<h3 style='color: #721c24; margin: 0 0 10px 0;'>⚠️ Alguns erros ocorreram</h3>";
        echo "<p style='margin: 0; color: #721c24;'>Verifique os erros acima e execute novamente se necessário.</p>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3 style='color: #721c24; margin: 0 0 10px 0;'>❌ Erro fatal</h3>";
    echo "<p style='margin: 0; color: #721c24;'>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
?>
