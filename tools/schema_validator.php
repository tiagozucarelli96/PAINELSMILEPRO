<?php
#!/usr/bin/env php
<?php
/**
 * schema_validator.php — Validador e corretor de schema do banco de dados
 */

require_once __DIR__ . '/../public/conexao.php';

class SchemaValidator {
    private $pdo;
    private $errors = [];
    private $warnings = [];
    private $fixes = [];
    private $tables = [];
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
    }
    
    public function run() {
        echo "🔍 Validando schema do banco de dados...\n";
        echo "=" . str_repeat("=", 40) . "\n";
        
        try {
            // Obter lista de tabelas
            $this->getTables();
            
            // Verificar tabelas essenciais
            $this->checkEssentialTables();
            
            // Verificar colunas essenciais
            $this->checkEssentialColumns();
            
            // Verificar índices
            $this->checkIndexes();
            
            // Aplicar correções se necessário
            $this->applyFixes();
            
            // Gerar relatório
            $this->generateReport();
            
        } catch (Exception $e) {
            echo "❌ Erro fatal: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    
    private function getTables() {
        echo "📋 Obtendo lista de tabelas...\n";
        
        try {
            $stmt = $this->pdo->query("
                SELECT table_name 
                FROM information_schema.tables 
                WHERE table_schema = 'public' 
                ORDER BY table_name
            ");
            
            $this->tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo "✅ Encontradas " . count($this->tables) . " tabelas\n";
            
        } catch (Exception $e) {
            echo "❌ Erro ao obter tabelas: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
    
    private function checkEssentialTables() {
        echo "\n📋 Verificando tabelas essenciais...\n";
        
        $essentialTables = [
            'usuarios' => 'Tabela de usuários',
            'eventos' => 'Tabela de eventos',
            'fornecedores' => 'Tabela de fornecedores',
            'agenda_eventos' => 'Tabela de eventos da agenda',
            'lc_insumos' => 'Tabela de insumos',
            'lc_listas' => 'Tabela de listas de compras',
            'demandas_quadros' => 'Tabela de quadros de demandas',
            'demandas_cartoes' => 'Tabela de cartões de demandas'
        ];
        
        foreach ($essentialTables as $table => $description) {
            if (in_array($table, $this->tables)) {
                echo "✅ $description ($table)\n";
            } else {
                echo "❌ $description ($table) - FALTANDO\n";
                $this->errors[] = "Tabela essencial '$table' não encontrada";
            }
        }
    }
    
    private function checkEssentialColumns() {
        echo "\n📋 Verificando colunas essenciais...\n";
        
        $essentialColumns = [
            'usuarios' => ['id', 'nome', 'email', 'senha', 'perfil', 'ativo'],
            'eventos' => ['id', 'titulo', 'data_inicio', 'data_fim', 'usuario_id'],
            'agenda_eventos' => ['id', 'titulo', 'data_inicio', 'data_fim', 'usuario_id'],
            'lc_insumos' => ['id', 'nome', 'unidade_id', 'preco'],
            'demandas_quadros' => ['id', 'nome', 'descricao', 'criado_por'],
            'demandas_cartoes' => ['id', 'titulo', 'descricao', 'quadro_id', 'responsavel_id']
        ];
        
        foreach ($essentialColumns as $table => $columns) {
            if (!in_array($table, $this->tables)) {
                continue; // Tabela não existe, já foi reportado
            }
            
            echo "🔍 Verificando colunas da tabela '$table'...\n";
            
            foreach ($columns as $column) {
                try {
                    $stmt = $this->pdo->query("
                        SELECT column_name 
                        FROM information_schema.columns 
                        WHERE table_name = '$table' AND column_name = '$column'
                    ");
                    
                    if ($stmt->fetch()) {
                        echo "  ✅ $column\n";
                    } else {
                        echo "  ❌ $column - FALTANDO\n";
                        $this->errors[] = "Coluna '$column' não encontrada na tabela '$table'";
                    }
                    
                } catch (Exception $e) {
                    echo "  ⚠️ Erro ao verificar coluna '$column': " . $e->getMessage() . "\n";
                    $this->warnings[] = "Erro ao verificar coluna '$column' na tabela '$table'";
                }
            }
        }
    }
    
    private function checkIndexes() {
        echo "\n📋 Verificando índices...\n";
        
        $essentialIndexes = [
            'idx_eventos_data' => ['table' => 'eventos', 'column' => 'data_inicio'],
            'idx_agenda_eventos_data' => ['table' => 'agenda_eventos', 'column' => 'data_inicio'],
            'idx_usuarios_email' => ['table' => 'usuarios', 'column' => 'email'],
            'idx_demandas_cartoes_quadro' => ['table' => 'demandas_cartoes', 'column' => 'quadro_id']
        ];
        
        foreach ($essentialIndexes as $indexName => $info) {
            try {
                // Verificar se a tabela existe
                if (!in_array($info['table'], $this->tables)) {
                    echo "⚠️ Índice '$indexName' - tabela '{$info['table']}' não existe\n";
                    continue;
                }
                
                // Verificar se a coluna existe
                $stmt = $this->pdo->query("
                    SELECT column_name 
                    FROM information_schema.columns 
                    WHERE table_name = '{$info['table']}' AND column_name = '{$info['column']}'
                ");
                
                if (!$stmt->fetch()) {
                    echo "⚠️ Índice '$indexName' - coluna '{$info['column']}' não existe na tabela '{$info['table']}'\n";
                    $this->warnings[] = "Coluna '{$info['column']}' não existe para criar índice '$indexName'";
                    continue;
                }
                
                // Verificar se o índice existe
                $stmt = $this->pdo->query("
                    SELECT indexname 
                    FROM pg_indexes 
                    WHERE tablename = '{$info['table']}' AND indexname = '$indexName'
                ");
                
                if ($stmt->fetch()) {
                    echo "✅ $indexName\n";
                } else {
                    echo "❌ $indexName - FALTANDO\n";
                    $this->fixes[] = "CREATE INDEX IF NOT EXISTS $indexName ON {$info['table']}({$info['column']})";
                }
                
            } catch (Exception $e) {
                echo "⚠️ Erro ao verificar índice '$indexName': " . $e->getMessage() . "\n";
                $this->warnings[] = "Erro ao verificar índice '$indexName'";
            }
        }
    }
    
    private function applyFixes() {
        if (empty($this->fixes)) {
            echo "\n✅ Nenhuma correção necessária\n";
            return;
        }
        
        echo "\n🔧 Aplicando correções...\n";
        
        foreach ($this->fixes as $fix) {
            try {
                $this->pdo->exec($fix);
                echo "✅ Aplicado: $fix\n";
            } catch (Exception $e) {
                echo "❌ Erro ao aplicar: $fix - " . $e->getMessage() . "\n";
                $this->errors[] = "Erro ao aplicar correção: $fix";
            }
        }
    }
    
    private function generateReport() {
        echo "\n📊 Relatório de Validação:\n";
        echo "=" . str_repeat("=", 30) . "\n";
        
        echo "📈 Estatísticas:\n";
        echo "  - Tabelas encontradas: " . count($this->tables) . "\n";
        echo "  - Erros: " . count($this->errors) . "\n";
        echo "  - Avisos: " . count($this->warnings) . "\n";
        echo "  - Correções aplicadas: " . count($this->fixes) . "\n";
        
        if (!empty($this->errors)) {
            echo "\n❌ Erros Encontrados:\n";
            foreach ($this->errors as $error) {
                echo "  - $error\n";
            }
        }
        
        if (!empty($this->warnings)) {
            echo "\n⚠️ Avisos:\n";
            foreach ($this->warnings as $warning) {
                echo "  - $warning\n";
            }
        }
        
        if (!empty($this->fixes)) {
            echo "\n✅ Correções Aplicadas:\n";
            foreach ($this->fixes as $fix) {
                echo "  - $fix\n";
            }
        }
        
        // Código de saída
        if (empty($this->errors)) {
            echo "\n🎉 Validação concluída com sucesso!\n";
            exit(0);
        } else {
            echo "\n⚠️ Validação concluída com erros.\n";
            exit(1);
        }
    }
}

// Executar se chamado diretamente
if (php_sapi_name() === 'cli') {
    $validator = new SchemaValidator();
    $validator->run();
}
?>
