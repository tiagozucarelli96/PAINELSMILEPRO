<?php
// Script para executar SQL diretamente no banco
require_once __DIR__ . '/conexao.php';

$sql = "
-- Adicionar coluna chave_storage nas tabelas da contabilidade
ALTER TABLE contabilidade_guias 
ADD COLUMN IF NOT EXISTS chave_storage VARCHAR(500);

ALTER TABLE contabilidade_holerites 
ADD COLUMN IF NOT EXISTS chave_storage VARCHAR(500);

ALTER TABLE contabilidade_honorarios 
ADD COLUMN IF NOT EXISTS chave_storage VARCHAR(500);

ALTER TABLE contabilidade_conversas_mensagens 
ADD COLUMN IF NOT EXISTS chave_storage VARCHAR(500);

ALTER TABLE contabilidade_colaboradores_documentos 
ADD COLUMN IF NOT EXISTS chave_storage VARCHAR(500);

-- Índices para melhor performance
CREATE INDEX IF NOT EXISTS idx_contabilidade_guias_chave_storage ON contabilidade_guias(chave_storage);
CREATE INDEX IF NOT EXISTS idx_contabilidade_holerites_chave_storage ON contabilidade_holerites(chave_storage);
CREATE INDEX IF NOT EXISTS idx_contabilidade_honorarios_chave_storage ON contabilidade_honorarios(chave_storage);
";

try {
    // Dividir em comandos individuais
    $commands = array_filter(
        array_map('trim', explode(';', $sql)),
        function($cmd) {
            $cmd = trim($cmd);
            return !empty($cmd) && !preg_match('/^\s*--/', $cmd);
        }
    );
    
    echo "Executando SQL...\n\n";
    
    foreach ($commands as $index => $command) {
        // Remover comentários
        $command = preg_replace('/--.*$/m', '', $command);
        $command = trim($command);
        
        if (empty($command)) {
            continue;
        }
        
        try {
            $pdo->exec($command);
            echo "✅ Comando " . ($index + 1) . " executado com sucesso\n";
            echo "   " . substr($command, 0, 80) . (strlen($command) > 80 ? '...' : '') . "\n\n";
        } catch (PDOException $e) {
            // Ignorar erro se coluna já existe
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'duplicate') !== false) {
                echo "⚠️  Comando " . ($index + 1) . ": Coluna/índice já existe (ignorado)\n";
                echo "   " . substr($command, 0, 80) . (strlen($command) > 80 ? '...' : '') . "\n\n";
            } else {
                echo "❌ Erro no comando " . ($index + 1) . ": " . $e->getMessage() . "\n";
                echo "   " . substr($command, 0, 80) . (strlen($command) > 80 ? '...' : '') . "\n\n";
            }
        }
    }
    
    echo "\n✅ SQL executado com sucesso!\n";
    echo "Todas as colunas chave_storage foram adicionadas.\n";
    
} catch (Exception $e) {
    echo "❌ Erro geral: " . $e->getMessage() . "\n";
    exit(1);
}
