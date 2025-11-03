<?php
/**
 * Script para aplicar o schema completo da tabela usuarios
 * Execute uma vez para garantir que todas as colunas existem
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';

// Verificar permissões
if (empty($_SESSION['logado']) || empty($_SESSION['perm_configuracoes'])) {
    die("Acesso negado. Você precisa ter permissão de configurações.");
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corrigir Schema - Tabela usuarios</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .info { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 5px; margin: 10px 0; }
        h1 { color: #1e3a8a; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>🔧 Corrigir Schema da Tabela usuarios</h1>
    
    <?php
    try {
        if (!isset($pdo) || !$pdo instanceof PDO) {
            throw new Exception("Erro: PDO não disponível");
        }
        
        echo "<div class='info'>✅ Conexão com banco estabelecida</div>";
        
        // Ler script SQL
        $sqlFile = __DIR__ . '/../sql/fix_usuarios_table_completo.sql';
        if (!file_exists($sqlFile)) {
            throw new Exception("Arquivo SQL não encontrado: $sqlFile");
        }
        
        $sql = file_get_contents($sqlFile);
        
        // Separar comandos (remover comentários e comandos SELECT de verificação)
        $commands = [];
        $lines = explode("\n", $sql);
        $currentCommand = '';
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Pular comentários e linhas vazias
            if (empty($line) || strpos($line, '--') === 0) {
                continue;
            }
            
            // Pular comandos SELECT (verificação final)
            if (stripos($line, 'SELECT') === 0 && stripos($line, 'information_schema') !== false) {
                continue;
            }
            
            $currentCommand .= $line . " ";
            
            // Se termina com ;, é um comando completo
            if (substr(rtrim($line), -1) === ';') {
                $commands[] = trim($currentCommand);
                $currentCommand = '';
            }
        }
        
        echo "<div class='info'>📋 Encontrados " . count($commands) . " comandos SQL para executar</div>";
        
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($commands as $index => $command) {
            if (empty($command)) continue;
            
            try {
                $pdo->exec($command);
                $successCount++;
                echo "<div class='success'>✅ Comando " . ($index + 1) . " executado com sucesso</div>";
            } catch (PDOException $e) {
                // Se for erro de coluna já existente, ignorar (é idempotente)
                if (strpos($e->getMessage(), 'already exists') !== false || 
                    strpos($e->getMessage(), 'duplicate') !== false) {
                    echo "<div class='info'>ℹ️ Comando " . ($index + 1) . " ignorado (já existe): " . substr($command, 0, 50) . "...</div>";
                    $successCount++;
                } else {
                    $errorCount++;
                    echo "<div class='error'>❌ Erro no comando " . ($index + 1) . ": " . htmlspecialchars($e->getMessage()) . "</div>";
                    echo "<pre>" . htmlspecialchars(substr($command, 0, 200)) . "...</pre>";
                }
            }
        }
        
        // Verificar estrutura final
        echo "<h2>📊 Estrutura Final da Tabela usuarios</h2>";
        $stmt = $pdo->query("
            SELECT column_name, data_type, is_nullable, column_default
            FROM information_schema.columns 
            WHERE table_schema = 'public' AND table_name = 'usuarios'
            ORDER BY ordinal_position
        ");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' cellpadding='10' style='width: 100%; border-collapse: collapse;'>";
        echo "<tr><th>Coluna</th><th>Tipo</th><th>Nullable</th><th>Default</th></tr>";
        foreach ($columns as $col) {
            echo "<tr>";
            echo "<td><strong>{$col['column_name']}</strong></td>";
            echo "<td>{$col['data_type']}</td>";
            echo "<td>" . ($col['is_nullable'] === 'YES' ? 'Sim' : 'Não') . "</td>";
            echo "<td>" . ($col['column_default'] ?? '—') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<div class='success'>";
        echo "<h2>✅ Processo Concluído!</h2>";
        echo "<p>Comandos executados com sucesso: $successCount</p>";
        if ($errorCount > 0) {
            echo "<p>Comandos com erro: $errorCount</p>";
        }
        echo "<p><strong>Total de colunas na tabela: " . count($columns) . "</strong></p>";
        echo "</div>";
        
        echo "<p><a href='index.php?page=usuarios'>← Voltar para Usuários</a></p>";
        
    } catch (Exception $e) {
        echo "<div class='error'>";
        echo "<h2>❌ Erro</h2>";
        echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        echo "</div>";
    }
    ?>
</body>
</html>

