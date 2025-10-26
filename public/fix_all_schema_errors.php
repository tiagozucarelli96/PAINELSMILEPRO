<?php
/**
 * fix_all_schema_errors.php — Corrigir todos os erros de schema do banco
 */

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

class SchemaErrorFixer {
    private $pdo;
    private $corrections = [];
    private $errors = [];
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
    }
    
    public function run() {
        echo "<h2>🔧 Corrigindo Todos os Erros de Schema</h2>";
        echo "<hr>";
        
        try {
            // Aplicar schema completo
            $this->applyCompleteSchema();
            
            // Verificar e corrigir problemas específicos
            $this->fixSpecificIssues();
            
            // Gerar relatório
            $this->generateReport();
            
        } catch (Exception $e) {
            echo "<div style='color: red; padding: 10px; background: #ffe6e6; border: 1px solid #ff9999; border-radius: 5px;'>";
            echo "<strong>❌ Erro fatal:</strong> " . $e->getMessage();
            echo "</div>";
        }
    }
    
    private function applyCompleteSchema() {
        echo "<h3>📋 Aplicando Schema Completo...</h3>";
        
        $schemaPath = __DIR__ . '/../sql/schema_completo_painel_smile.sql';
        
        if (!file_exists($schemaPath)) {
            echo "<p style='color: red;'>❌ Arquivo schema_completo_painel_smile.sql não encontrado</p>";
            $this->errors[] = "Arquivo schema_completo_painel_smile.sql não encontrado";
            return;
        }
        
        echo "<p style='color: blue;'>📄 Carregando schema completo...</p>";
        
        try {
            $schemaSql = file_get_contents($schemaPath);
            $statements = array_filter(array_map('trim', explode(';', $schemaSql)));
            
            echo "<p style='color: blue;'>📝 Executando " . count($statements) . " statements...</p>";
            
            $successCount = 0;
            $errorCount = 0;
            
            foreach ($statements as $statement) {
                if (empty($statement)) continue;
                
                try {
                    $this->pdo->exec($statement);
                    $successCount++;
                } catch (Exception $e) {
                    $errorCount++;
                    // Log error but continue
                    if (strpos($e->getMessage(), 'already exists') === false) {
                        $this->errors[] = "Erro ao executar statement: " . substr($statement, 0, 100) . "... Erro: " . $e->getMessage();
                    }
                }
            }
            
            echo "<p style='color: green;'>✅ Schema aplicado: $successCount statements executados com sucesso</p>";
            if ($errorCount > 0) {
                echo "<p style='color: orange;'>⚠️ $errorCount statements com erro (alguns podem ser normais)</p>";
            }
            
            $this->corrections[] = "Schema completo aplicado ($successCount statements)";
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Erro ao aplicar schema: " . $e->getMessage() . "</p>";
            $this->errors[] = "Erro ao aplicar schema: " . $e->getMessage();
        }
    }
    
    private function fixSpecificIssues() {
        echo "<h3>🔍 Corrigindo Problemas Específicos...</h3>";
        
        // Verificar e corrigir colunas de permissão
        $this->fixPermissionColumns();
        
        // Verificar e corrigir índices
        $this->fixIndexes();
        
        // Verificar e corrigir tabelas essenciais
        $this->fixEssentialTables();
    }
    
    private function fixPermissionColumns() {
        echo "<h4>🔐 Verificando colunas de permissão...</h4>";
        
        $permissionColumns = [
            'perm_agenda_ver' => 'BOOLEAN DEFAULT false',
            'perm_agenda_editar' => 'BOOLEAN DEFAULT false',
            'perm_agenda_criar' => 'BOOLEAN DEFAULT false',
            'perm_agenda_excluir' => 'BOOLEAN DEFAULT false',
            'perm_demandas_ver' => 'BOOLEAN DEFAULT false',
            'perm_demandas_editar' => 'BOOLEAN DEFAULT false',
            'perm_demandas_criar' => 'BOOLEAN DEFAULT false',
            'perm_demandas_excluir' => 'BOOLEAN DEFAULT false',
            'perm_demandas_ver_produtividade' => 'BOOLEAN DEFAULT false',
            'perm_comercial_ver' => 'BOOLEAN DEFAULT false',
            'perm_comercial_deg_editar' => 'BOOLEAN DEFAULT false',
            'perm_comercial_deg_inscritos' => 'BOOLEAN DEFAULT false',
            'perm_comercial_conversao' => 'BOOLEAN DEFAULT false'
        ];
        
        foreach ($permissionColumns as $column => $type) {
            try {
                $stmt = $this->pdo->query("SELECT $column FROM usuarios LIMIT 1");
                echo "<p style='color: green;'>✅ Coluna '$column' existe</p>";
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'does not exist') !== false) {
                    echo "<p style='color: orange;'>⚠️ Coluna '$column' não existe, criando...</p>";
                    
                    try {
                        $this->pdo->exec("ALTER TABLE usuarios ADD COLUMN $column $type");
                        echo "<p style='color: green;'>✅ Coluna '$column' criada com sucesso</p>";
                        $this->corrections[] = "Coluna '$column' criada na tabela 'usuarios'";
                    } catch (Exception $e2) {
                        echo "<p style='color: red;'>❌ Erro ao criar coluna '$column': " . $e2->getMessage() . "</p>";
                        $this->errors[] = "Erro ao criar coluna '$column': " . $e2->getMessage();
                    }
                }
            }
        }
    }
    
    private function fixIndexes() {
        echo "<h4>📊 Verificando índices...</h4>";
        
        $indexes = [
            'idx_eventos_data' => 'eventos(data_inicio)',
            'idx_agenda_eventos_data' => 'agenda_eventos(data_inicio)',
            'idx_usuarios_email' => 'usuarios(email)',
            'idx_usuarios_perfil' => 'usuarios(perfil)'
        ];
        
        foreach ($indexes as $indexName => $tableColumn) {
            try {
                $stmt = $this->pdo->query("
                    SELECT indexname 
                    FROM pg_indexes 
                    WHERE indexname = '$indexName'
                ");
                
                if ($stmt->fetch()) {
                    echo "<p style='color: green;'>✅ Índice '$indexName' existe</p>";
                } else {
                    echo "<p style='color: orange;'>⚠️ Índice '$indexName' não existe, criando...</p>";
                    
                    try {
                        $this->pdo->exec("CREATE INDEX IF NOT EXISTS $indexName ON $tableColumn");
                        echo "<p style='color: green;'>✅ Índice '$indexName' criado com sucesso</p>";
                        $this->corrections[] = "Índice '$indexName' criado";
                    } catch (Exception $e) {
                        echo "<p style='color: red;'>❌ Erro ao criar índice '$indexName': " . $e->getMessage() . "</p>";
                        $this->errors[] = "Erro ao criar índice '$indexName': " . $e->getMessage();
                    }
                }
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Erro ao verificar índice '$indexName': " . $e->getMessage() . "</p>";
                $this->errors[] = "Erro ao verificar índice '$indexName': " . $e->getMessage();
            }
        }
    }
    
    private function fixEssentialTables() {
        echo "<h4>🏗️ Verificando tabelas essenciais...</h4>";
        
        $essentialTables = [
            'usuarios' => ['id', 'nome', 'email', 'perfil'],
            'eventos' => ['id', 'titulo', 'data_inicio', 'data_fim'],
            'agenda_eventos' => ['id', 'titulo', 'data_inicio', 'data_fim'],
            'demandas_logs' => ['id', 'usuario_id', 'acao', 'created_at'],
            'demandas_configuracoes' => ['id', 'chave', 'valor', 'created_at']
        ];
        
        foreach ($essentialTables as $table => $columns) {
            try {
                $stmt = $this->pdo->query("SELECT COUNT(*) FROM $table LIMIT 1");
                echo "<p style='color: green;'>✅ Tabela '$table' existe</p>";
                
                // Verificar colunas essenciais
                foreach ($columns as $column) {
                    try {
                        $stmt = $this->pdo->query("SELECT $column FROM $table LIMIT 1");
                        echo "<p style='color: green;'>  ✅ Coluna '$column' existe</p>";
                    } catch (Exception $e) {
                        if (strpos($e->getMessage(), 'does not exist') !== false) {
                            echo "<p style='color: red;'>  ❌ Coluna '$column' não existe na tabela '$table'</p>";
                            $this->errors[] = "Coluna '$column' não existe na tabela '$table'";
                        }
                    }
                }
                
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'does not exist') !== false) {
                    echo "<p style='color: red;'>❌ Tabela '$table' não existe</p>";
                    $this->errors[] = "Tabela '$table' não existe";
                }
            }
        }
    }
    
    private function generateReport() {
        echo "<h3>📊 Resumo das Correções</h3>";
        echo "<div style='display: flex; gap: 20px; margin: 20px 0;'>";
        
        // Correções aplicadas
        echo "<div style='flex: 1; padding: 15px; background: #e6ffe6; border: 1px solid #4CAF50; border-radius: 5px;'>";
        echo "<h4 style='color: #2E7D32; margin: 0 0 10px 0;'>✅ Correções Aplicadas</h4>";
        echo "<p style='font-size: 18px; font-weight: bold; margin: 0;'>Total: " . count($this->corrections) . "</p>";
        
        if (!empty($this->corrections)) {
            echo "<ul style='margin: 10px 0; padding-left: 20px;'>";
            foreach ($this->corrections as $correction) {
                echo "<li style='color: #2E7D32;'>$correction</li>";
            }
            echo "</ul>";
        } else {
            echo "<p style='color: #666;'>Nenhuma correção aplicada</p>";
        }
        echo "</div>";
        
        // Erros encontrados
        echo "<div style='flex: 1; padding: 15px; background: #ffe6e6; border: 1px solid #f44336; border-radius: 5px;'>";
        echo "<h4 style='color: #C62828; margin: 0 0 10px 0;'>❌ Erros Encontrados</h4>";
        echo "<p style='font-size: 18px; font-weight: bold; margin: 0;'>Total: " . count($this->errors) . "</p>";
        
        if (!empty($this->errors)) {
            echo "<ul style='margin: 10px 0; padding-left: 20px;'>";
            foreach ($this->errors as $error) {
                echo "<li style='color: #C62828;'>$error</li>";
            }
            echo "</ul>";
        } else {
            echo "<p style='color: #666;'>Nenhum erro encontrado</p>";
        }
        echo "</div>";
        
        echo "</div>";
        
        // Botões de ação
        echo "<div style='text-align: center; margin: 20px 0;'>";
        echo "<a href='fix_all_schema_errors.php' style='display: inline-block; padding: 10px 20px; background: #2196F3; color: white; text-decoration: none; border-radius: 5px; margin: 0 10px;'>🔍 Verificar Novamente</a>";
        echo "<a href='dashboard.php' style='display: inline-block; padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px; margin: 0 10px;'>🏠 Ir para Dashboard</a>";
        echo "</div>";
        
        // Status final
        if (empty($this->errors)) {
            echo "<div style='padding: 15px; background: #e8f5e8; border: 1px solid #4CAF50; border-radius: 5px; text-align: center;'>";
            echo "<h3 style='color: #2E7D32; margin: 0;'>🎉 Todas as correções foram aplicadas com sucesso!</h3>";
            echo "<p style='margin: 10px 0 0 0; color: #2E7D32;'>✅ Schema consolidado gerado</p>";
            echo "</div>";
        } else {
            echo "<div style='padding: 15px; background: #fff3e0; border: 1px solid #ff9800; border-radius: 5px; text-align: center;'>";
            echo "<h3 style='color: #E65100; margin: 0;'>⚠️ Alguns erros ainda persistem</h3>";
            echo "<p style='margin: 10px 0 0 0; color: #E65100;'>Verifique os erros listados acima</p>";
            echo "</div>";
        }
    }
}

// Executar se acessado via web
if (isset($_SERVER['HTTP_HOST'])) {
    echo "<!DOCTYPE html>";
    echo "<html lang='pt-BR'>";
    echo "<head>";
    echo "<meta charset='UTF-8'>";
    echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
    echo "<title>Correção Completa de Schema</title>";
    echo "<style>";
    echo "body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }";
    echo "h2 { color: #333; border-bottom: 2px solid #2196F3; padding-bottom: 10px; }";
    echo "h3 { color: #555; margin-top: 20px; }";
    echo "h4 { color: #666; margin-top: 15px; }";
    echo "p { margin: 5px 0; }";
    echo "ul { margin: 10px 0; }";
    echo "li { margin: 5px 0; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    
    $fixer = new SchemaErrorFixer();
    $fixer->run();
    
    echo "</body>";
    echo "</html>";
}
?>
