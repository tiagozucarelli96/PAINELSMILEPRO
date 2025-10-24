<?php
/**
 * fix_data_inicio_error.php — Corrigir erro específico da coluna data_inicio
 */

require_once __DIR__ . '/conexao.php';

class DataInicioErrorFixer {
    private $pdo;
    private $corrections = [];
    private $errors = [];
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
    }
    
    public function run() {
        echo "<h2>🔧 Corrigindo Erro da Coluna data_inicio</h2>";
        echo "<hr>";
        
        try {
            // Verificar e corrigir problema específico do data_inicio
            $this->fixDataInicioIssue();
            
            // Gerar relatório
            $this->generateReport();
            
        } catch (Exception $e) {
            echo "<div style='color: red; padding: 10px; background: #ffe6e6; border: 1px solid #ff9999; border-radius: 5px;'>";
            echo "<strong>❌ Erro fatal:</strong> " . $e->getMessage();
            echo "</div>";
        }
    }
    
    private function fixDataInicioIssue() {
        echo "<h3>🔍 Verificando problema do data_inicio...</h3>";
        
        // Verificar se as tabelas existem
        $tables = ['eventos', 'agenda_eventos'];
        
        foreach ($tables as $table) {
            echo "<h4>📋 Verificando tabela '$table'...</h4>";
            
            try {
                // Verificar se a tabela existe
                $stmt = $this->pdo->query("SELECT COUNT(*) FROM $table LIMIT 1");
                echo "<p style='color: green;'>✅ Tabela '$table' existe</p>";
                
                // Verificar se a coluna data_inicio existe
                $stmt = $this->pdo->query("SELECT data_inicio FROM $table LIMIT 1");
                echo "<p style='color: green;'>✅ Coluna 'data_inicio' existe na tabela '$table'</p>";
                
                // Verificar se o índice existe
                $indexName = $table === 'eventos' ? 'idx_eventos_data' : 'idx_agenda_eventos_data';
                $stmt = $this->pdo->query("
                    SELECT indexname 
                    FROM pg_indexes 
                    WHERE tablename = '$table' AND indexname = '$indexName'
                ");
                
                if ($stmt->fetch()) {
                    echo "<p style='color: green;'>✅ Índice '$indexName' já existe</p>";
                } else {
                    echo "<p style='color: orange;'>⚠️ Índice '$indexName' não existe, criando...</p>";
                    
                    try {
                        $this->pdo->exec("CREATE INDEX IF NOT EXISTS $indexName ON $table(data_inicio)");
                        echo "<p style='color: green;'>✅ Índice '$indexName' criado com sucesso</p>";
                        $this->corrections[] = "Índice '$indexName' criado";
                    } catch (Exception $e) {
                        echo "<p style='color: red;'>❌ Erro ao criar índice '$indexName': " . $e->getMessage() . "</p>";
                        $this->errors[] = "Erro ao criar índice '$indexName': " . $e->getMessage();
                    }
                }
                
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'does not exist') !== false) {
                    echo "<p style='color: red;'>❌ Tabela '$table' não existe: " . $e->getMessage() . "</p>";
                    $this->errors[] = "Tabela '$table' não existe";
                } elseif (strpos($e->getMessage(), 'column') !== false && strpos($e->getMessage(), 'does not exist') !== false) {
                    echo "<p style='color: red;'>❌ Coluna 'data_inicio' não existe na tabela '$table': " . $e->getMessage() . "</p>";
                    $this->errors[] = "Coluna 'data_inicio' não existe na tabela '$table'";
                } else {
                    echo "<p style='color: red;'>❌ Erro inesperado na tabela '$table': " . $e->getMessage() . "</p>";
                    $this->errors[] = "Erro na tabela '$table': " . $e->getMessage();
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
        echo "<a href='fix_data_inicio_error.php' style='display: inline-block; padding: 10px 20px; background: #2196F3; color: white; text-decoration: none; border-radius: 5px; margin: 0 10px;'>🔍 Verificar Novamente</a>";
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
    echo "<title>Correção de Erro - data_inicio</title>";
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
    
    $fixer = new DataInicioErrorFixer();
    $fixer->run();
    
    echo "</body>";
    echo "</html>";
}
?>
