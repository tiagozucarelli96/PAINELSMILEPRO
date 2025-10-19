<?php
/**
 * Script simples para conectar ao banco existente
 * Usa a mesma configuração que já está funcionando no projeto
 */

// Inclui a conexão que já existe
require_once 'public/conexao.php';

// Se a conexão funcionou, mostra informações básicas
if ($pdo) {
    echo "<h1>✅ Conectado ao banco existente!</h1>\n";
    
    try {
        // Informações básicas do banco
        $stmt = $pdo->query("SELECT current_database() as banco, current_user as usuario, version() as versao");
        $info = $stmt->fetch();
        
        echo "<h2>📊 Informações do Banco:</h2>\n";
        echo "<p><strong>Banco:</strong> " . htmlspecialchars($info['banco']) . "</p>\n";
        echo "<p><strong>Usuário:</strong> " . htmlspecialchars($info['usuario']) . "</p>\n";
        
        // Lista as tabelas que existem
        $stmt = $pdo->query("
            SELECT table_name 
            FROM information_schema.tables 
            WHERE table_schema = 'public' 
            ORDER BY table_name
        ");
        $tabelas = $stmt->fetchAll();
        
        echo "<h2>📋 Tabelas no banco:</h2>\n";
        echo "<ul>\n";
        foreach ($tabelas as $tabela) {
            echo "<li>" . htmlspecialchars($tabela['table_name']) . "</li>\n";
        }
        echo "</ul>\n";
        
        // Conta registros em cada tabela
        echo "<h2>📈 Contagem de registros:</h2>\n";
        echo "<table border='1' style='border-collapse: collapse;'>\n";
        echo "<tr><th>Tabela</th><th>Registros</th></tr>\n";
        
        foreach ($tabelas as $tabela) {
            $nomeTabela = $tabela['table_name'];
            try {
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM $nomeTabela");
                $count = $stmt->fetch()['total'];
                echo "<tr><td>$nomeTabela</td><td>" . number_format($count) . "</td></tr>\n";
            } catch (Exception $e) {
                echo "<tr><td>$nomeTabela</td><td>Erro</td></tr>\n";
            }
        }
        echo "</table>\n";
        
        echo "<h2>🎯 Pronto para análise!</h2>\n";
        echo "<p>Agora posso analisar seu banco e sugerir melhorias no código.</p>\n";
        
    } catch (Exception $e) {
        echo "<p>❌ Erro ao consultar banco: " . htmlspecialchars($e->getMessage()) . "</p>\n";
    }
    
} else {
    echo "<h1>❌ Erro na conexão</h1>\n";
    echo "<p>Não foi possível conectar ao banco. Verifique as configurações.</p>\n";
    if (!empty($db_error)) {
        echo "<p>Erro: " . htmlspecialchars($db_error) . "</p>\n";
    }
}
?>
