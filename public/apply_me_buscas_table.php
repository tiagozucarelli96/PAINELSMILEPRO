<?php
/**
 * Script para criar tabela de buscas ME Eventos
 * Execute este script uma vez para criar a tabela no banco de dados
 */

require_once __DIR__ . '/conexao.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h1>🔧 Criando tabela de buscas ME Eventos</h1>\n";

try {
    // Ler o arquivo SQL
    $sql_file = __DIR__ . '/../sql/create_me_buscas_clientes_table.sql';
    
    if (!file_exists($sql_file)) {
        throw new Exception("Arquivo SQL não encontrado: $sql_file");
    }
    
    $sql = file_get_contents($sql_file);
    
    if (empty($sql)) {
        throw new Exception("Arquivo SQL está vazio");
    }
    
    echo "<p>📄 Arquivo SQL encontrado: $sql_file</p>\n";
    echo "<p>📏 Tamanho: " . strlen($sql) . " bytes</p>\n";
    
    // Executar SQL
    echo "<p>⚙️ Executando SQL...</p>\n";
    
    $pdo->exec($sql);
    
    echo "<p>✅ <strong>Tabela 'comercial_me_buscas_clientes' criada com sucesso!</strong></p>\n";
    
    // Verificar se a tabela foi criada
    $stmt = $pdo->query("
        SELECT COUNT(*) 
        FROM information_schema.tables 
        WHERE table_schema = 'public' 
        AND table_name = 'comercial_me_buscas_clientes'
    ");
    $exists = $stmt->fetchColumn();
    
    if ($exists) {
        echo "<p>✅ Tabela verificada no banco de dados</p>\n";
        
        // Contar registros (se houver)
        $stmt = $pdo->query("SELECT COUNT(*) FROM comercial_me_buscas_clientes");
        $count = $stmt->fetchColumn();
        echo "<p>📊 Total de registros na tabela: $count</p>\n";
    } else {
        echo "<p>⚠️ Tabela não encontrada após criação (pode ser normal se já existia)</p>\n";
    }
    
    echo "<p><strong>✅ Processo concluído!</strong></p>\n";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ <strong>Erro:</strong> " . htmlspecialchars($e->getMessage()) . "</p>\n";
    echo "<p>Stack trace:</p>\n<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>\n";
}

echo "<p><a href='javascript:history.back()'>← Voltar</a></p>\n";

