<?php
#!/usr/bin/env php
<?php
/**
 * fix_schema_issues.php — Corrigir problemas específicos do schema
 */

require_once __DIR__ . '/../public/conexao.php';

class SchemaIssuesFixer {
    private $pdo;
    private $corrections = [];
    private $errors = [];
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
    }
    
    public function run() {
        echo "🔧 Corrigindo problemas do schema...\n";
        echo "=" . str_repeat("=", 40) . "\n";
        
        try {
            // Verificar e corrigir problema específico do data_inicio
            $this->fixDataInicioIssue();
            
            // Verificar outras questões comuns
            $this->checkCommonIssues();
            
            // Gerar relatório
            $this->generateReport();
            
        } catch (Exception $e) {
            echo "❌ Erro fatal: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    
    private function fixDataInicioIssue() {
        echo "🔍 Verificando problema do data_inicio...\n";
        
        // Verificar se as tabelas existem
        $tables = ['eventos', 'agenda_eventos'];
        
        foreach ($tables as $table) {
            echo "📋 Verificando tabela '$table'...\n";
            
            try {
                // Verificar se a tabela existe
                $stmt = $this->pdo->query("SELECT COUNT(*) FROM $table LIMIT 1");
                echo "  ✅ Tabela '$table' existe\n";
                
                // Verificar se a coluna data_inicio existe
                $stmt = $this->pdo->query("SELECT data_inicio FROM $table LIMIT 1");
                echo "  ✅ Coluna 'data_inicio' existe na tabela '$table'\n";
                
                // Verificar se o índice existe
                $indexName = $table === 'eventos' ? 'idx_eventos_data' : 'idx_agenda_eventos_data';
                $stmt = $this->pdo->query("
                    SELECT indexname 
                    FROM pg_indexes 
                    WHERE tablename = '$table' AND indexname = '$indexName'
                ");
                
                if ($stmt->fetch()) {
                    echo "  ✅ Índice '$indexName' já existe\n";
                } else {
                    echo "  ❌ Índice '$indexName' não existe, criando...\n";
                    
                    try {
                        $this->pdo->exec("CREATE INDEX IF NOT EXISTS $indexName ON $table(data_inicio)");
                        echo "  ✅ Índice '$indexName' criado com sucesso\n";
                        $this->corrections[] = "Índice '$indexName' criado";
                    } catch (Exception $e) {
                        echo "  ❌ Erro ao criar índice '$indexName': " . $e->getMessage() . "\n";
                        $this->errors[] = "Erro ao criar índice '$indexName': " . $e->getMessage();
                    }
                }
                
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'does not exist') !== false) {
                    echo "  ❌ Tabela '$table' não existe: " . $e->getMessage() . "\n";
                    $this->errors[] = "Tabela '$table' não existe";
                } elseif (strpos($e->getMessage(), 'column') !== false && strpos($e->getMessage(), 'does not exist') !== false) {
                    echo "  ❌ Coluna 'data_inicio' não existe na tabela '$table': " . $e->getMessage() . "\n";
                    $this->errors[] = "Coluna 'data_inicio' não existe na tabela '$table'";
                } else {
                    echo "  ❌ Erro inesperado na tabela '$table': " . $e->getMessage() . "\n";
                    $this->errors[] = "Erro na tabela '$table': " . $e->getMessage();
                }
            }
        }
    }
    
    private function checkCommonIssues() {
        echo "\n🔍 Verificando outras questões comuns...\n";
        
        // Verificar se tabelas essenciais existem
        $essentialTables = [
            'usuarios' => 'Tabela de usuários',
            'fornecedores' => 'Tabela de fornecedores',
            'lc_insumos' => 'Tabela de insumos',
            'demandas_quadros' => 'Tabela de quadros de demandas'
        ];
        
        foreach ($essentialTables as $table => $description) {
            try {
                $stmt = $this->pdo->query("SELECT COUNT(*) FROM $table LIMIT 1");
                echo "✅ $description ($table)\n";
            } catch (Exception $e) {
                echo "❌ $description ($table) - FALTANDO\n";
                $this->errors[] = "Tabela essencial '$table' não encontrada";
            }
        }
        
        // Verificar colunas essenciais
        $this->checkEssentialColumns();
    }
    
    private function checkEssentialColumns() {
        echo "\n📋 Verificando colunas essenciais...\n";
        
        $columnChecks = [
            ['table' => 'usuarios', 'column' => 'email', 'description' => 'Email dos usuários'],
            ['table' => 'usuarios', 'column' => 'perfil', 'description' => 'Perfil dos usuários'],
            ['table' => 'lc_insumos', 'column' => 'preco', 'description' => 'Preço dos insumos'],
            ['table' => 'lc_listas', 'column' => 'status', 'description' => 'Status das listas']
        ];
        
        foreach ($columnChecks as $check) {
            try {
                $stmt = $this->pdo->query("SELECT {$check['column']} FROM {$check['table']} LIMIT 1");
                echo "✅ {$check['description']} ({$check['table']}.{$check['column']})\n";
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'column') !== false && strpos($e->getMessage(), 'does not exist') !== false) {
                    echo "❌ {$check['description']} ({$check['table']}.{$check['column']}) - FALTANDO\n";
                    $this->errors[] = "Coluna '{$check['column']}' não encontrada na tabela '{$check['table']}'";
                } else {
                    echo "⚠️ Erro ao verificar {$check['description']}: " . $e->getMessage() . "\n";
                }
            }
        }
    }
    
    private function generateReport() {
        echo "\n📊 Resumo das Correções:\n";
        echo "=" . str_repeat("=", 30) . "\n";
        
        if (!empty($this->corrections)) {
            echo "✅ Correções Aplicadas: " . count($this->corrections) . "\n";
            foreach ($this->corrections as $correction) {
                echo "  - $correction\n";
            }
        }
        
        if (!empty($this->errors)) {
            echo "❌ Erros Encontrados: " . count($this->errors) . "\n";
            foreach ($this->errors as $error) {
                echo "  - $error\n";
            }
        }
        
        // Código de saída
        if (empty($this->errors)) {
            echo "\n🎉 Todas as correções foram aplicadas com sucesso!\n";
            echo "✅ Schema consolidado gerado\n";
            exit(0);
        } else {
            echo "\n⚠️ Alguns erros ainda persistem.\n";
            exit(1);
        }
    }
}

// Executar se chamado diretamente
if (php_sapi_name() === 'cli') {
    $fixer = new SchemaIssuesFixer();
    $fixer->run();
}
?>
