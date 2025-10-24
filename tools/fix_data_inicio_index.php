<?php
#!/usr/bin/env php
<?php
/**
 * fix_data_inicio_index.php — Corrigir erro de índice data_inicio
 */

require_once __DIR__ . '/../public/conexao.php';

class DataInicioIndexFixer {
    private $pdo;
    private $errors = [];
    private $success = [];
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
    }
    
    public function run() {
        echo "🔧 Corrigindo erro de índice data_inicio...\n";
        
        try {
            // Verificar se as tabelas existem
            $this->checkTablesExist();
            
            // Verificar se as colunas existem
            $this->checkColumnsExist();
            
            // Criar índices se necessário
            $this->createIndexes();
            
            // Relatório final
            $this->generateReport();
            
        } catch (Exception $e) {
            echo "❌ Erro: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    
    private function checkTablesExist() {
        echo "📋 Verificando se as tabelas existem...\n";
        
        $tables = ['eventos', 'agenda_eventos'];
        
        foreach ($tables as $table) {
            try {
                $stmt = $this->pdo->query("SELECT COUNT(*) FROM $table LIMIT 1");
                echo "✅ Tabela '$table' existe\n";
            } catch (Exception $e) {
                echo "❌ Tabela '$table' não existe: " . $e->getMessage() . "\n";
                $this->errors[] = "Tabela '$table' não existe";
            }
        }
    }
    
    private function checkColumnsExist() {
        echo "📋 Verificando se as colunas data_inicio existem...\n";
        
        $tables = [
            'eventos' => 'eventos',
            'agenda_eventos' => 'agenda_eventos'
        ];
        
        foreach ($tables as $table => $displayName) {
            try {
                $stmt = $this->pdo->query("SELECT data_inicio FROM $table LIMIT 1");
                echo "✅ Coluna 'data_inicio' existe na tabela '$displayName'\n";
            } catch (Exception $e) {
                echo "❌ Coluna 'data_inicio' não existe na tabela '$displayName': " . $e->getMessage() . "\n";
                $this->errors[] = "Coluna 'data_inicio' não existe na tabela '$displayName'";
            }
        }
    }
    
    private function createIndexes() {
        echo "📋 Criando índices...\n";
        
        $indexes = [
            [
                'name' => 'idx_eventos_data',
                'table' => 'eventos',
                'column' => 'data_inicio',
                'sql' => 'CREATE INDEX IF NOT EXISTS idx_eventos_data ON eventos(data_inicio)'
            ],
            [
                'name' => 'idx_agenda_eventos_data',
                'table' => 'agenda_eventos',
                'column' => 'data_inicio',
                'sql' => 'CREATE INDEX IF NOT EXISTS idx_agenda_eventos_data ON agenda_eventos(data_inicio)'
            ]
        ];
        
        foreach ($indexes as $index) {
            try {
                // Verificar se o índice já existe
                $checkSql = "SELECT indexname FROM pg_indexes WHERE tablename = '{$index['table']}' AND indexname = '{$index['name']}'";
                $stmt = $this->pdo->query($checkSql);
                $exists = $stmt->fetch();
                
                if ($exists) {
                    echo "✅ Índice '{$index['name']}' já existe\n";
                    continue;
                }
                
                // Verificar se a coluna existe antes de criar o índice
                $columnCheckSql = "SELECT column_name FROM information_schema.columns WHERE table_name = '{$index['table']}' AND column_name = '{$index['column']}'";
                $stmt = $this->pdo->query($columnCheckSql);
                $columnExists = $stmt->fetch();
                
                if (!$columnExists) {
                    echo "⚠️ Coluna '{$index['column']}' não existe na tabela '{$index['table']}', pulando índice\n";
                    $this->errors[] = "Coluna '{$index['column']}' não existe na tabela '{$index['table']}'";
                    continue;
                }
                
                // Criar o índice
                $this->pdo->exec($index['sql']);
                echo "✅ Índice '{$index['name']}' criado com sucesso\n";
                $this->success[] = "Índice '{$index['name']}' criado";
                
            } catch (Exception $e) {
                echo "❌ Erro ao criar índice '{$index['name']}': " . $e->getMessage() . "\n";
                $this->errors[] = "Erro ao criar índice '{$index['name']}': " . $e->getMessage();
            }
        }
    }
    
    private function generateReport() {
        echo "\n📊 Relatório de Correção:\n";
        echo "=" . str_repeat("=", 30) . "\n";
        
        if (!empty($this->success)) {
            echo "✅ Correções Aplicadas: " . count($this->success) . "\n";
            foreach ($this->success as $success) {
                echo "  - $success\n";
            }
        }
        
        if (!empty($this->errors)) {
            echo "❌ Erros Encontrados: " . count($this->errors) . "\n";
            foreach ($this->errors as $error) {
                echo "  - $error\n";
            }
        }
        
        if (empty($this->errors)) {
            echo "🎉 Todas as correções foram aplicadas com sucesso!\n";
            exit(0);
        } else {
            echo "⚠️ Alguns erros ainda persistem.\n";
            exit(1);
        }
    }
}

// Executar se chamado diretamente
if (php_sapi_name() === 'cli') {
    $fixer = new DataInicioIndexFixer();
    $fixer->run();
}
?>
