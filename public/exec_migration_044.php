<?php
/**
 * exec_migration_044.php
 * Executa a migration 044_vendas_tipo_15anos.sql
 */

require_once __DIR__ . '/conexao.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = $GLOBALS['pdo'];

if (!$pdo) {
    echo "ERRO: Conexão com banco não disponível.\n";
    exit(1);
}

echo "=== Executando Migration 044: Adicionar tipo '15anos' ===\n\n";

try {
    // Verificar constraint atual
    $stmt = $pdo->query("
        SELECT conname, pg_get_constraintdef(oid) as definition
        FROM pg_constraint 
        WHERE conrelid = 'vendas_pre_contratos'::regclass 
        AND conname LIKE '%tipo_evento%'
    ");
    $current = $stmt->fetch();
    
    if ($current) {
        echo "Constraint atual: {$current['conname']}\n";
        echo "Definição: {$current['definition']}\n\n";
    } else {
        echo "Nenhuma constraint de tipo_evento encontrada.\n\n";
    }
    
    // Executar migration
    echo "Removendo constraint antiga...\n";
    $pdo->exec("ALTER TABLE vendas_pre_contratos DROP CONSTRAINT IF EXISTS vendas_pre_contratos_tipo_evento_check");
    echo "OK\n\n";
    
    echo "Adicionando nova constraint com '15anos'...\n";
    $pdo->exec("ALTER TABLE vendas_pre_contratos ADD CONSTRAINT vendas_pre_contratos_tipo_evento_check CHECK (tipo_evento IN ('casamento', '15anos', 'infantil', 'pj'))");
    echo "OK\n\n";
    
    echo "Criando índice para tipo 15anos...\n";
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vendas_pre_contratos_tipo_15anos ON vendas_pre_contratos(tipo_evento) WHERE tipo_evento = '15anos'");
    echo "OK\n\n";
    
    // Verificar resultado
    $stmt = $pdo->query("
        SELECT conname, pg_get_constraintdef(oid) as definition
        FROM pg_constraint 
        WHERE conrelid = 'vendas_pre_contratos'::regclass 
        AND conname LIKE '%tipo_evento%'
    ");
    $updated = $stmt->fetch();
    
    if ($updated) {
        echo "=== SUCESSO ===\n";
        echo "Nova constraint: {$updated['conname']}\n";
        echo "Definição: {$updated['definition']}\n";
    }
    
    echo "\n=== Migration 044 executada com sucesso! ===\n";
    
} catch (PDOException $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
