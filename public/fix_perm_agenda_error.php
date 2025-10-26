<?php
/**
 * fix_perm_agenda_error.php — Corrigir erro da coluna perm_agenda_ver
 */

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

class PermAgendaErrorFixer {
    private $pdo;
    private $corrections = [];
    private $errors = [];
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
    }
    
    public function run() {
        echo "<h2>🔧 Corrigindo Erro da Coluna perm_agenda_ver</h2>";
        echo "<hr>";
        
        try {
            // Verificar e corrigir problema específico do perm_agenda_ver
            $this->fixPermAgendaIssue();
            
            // Gerar relatório
            $this->generateReport();
            
        } catch (Exception $e) {
            echo "<div style='color: red; padding: 10px; background: #ffe6e6; border: 1px solid #ff9999; border-radius: 5px;'>";
            echo "<strong>❌ Erro fatal:</strong> " . $e->getMessage();
            echo "</div>";
        }
    }
    
    private function fixPermAgendaIssue() {
        echo "<h3>🔍 Verificando problema do perm_agenda_ver...</h3>";
        
        // Verificar se a tabela usuarios existe
        echo "<h4>📋 Verificando tabela 'usuarios'...</h4>";
        
        try {
            // Verificar se a tabela existe
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM usuarios LIMIT 1");
            echo "<p style='color: green;'>✅ Tabela 'usuarios' existe</p>";
            
            // Verificar se a coluna perm_agenda_ver existe
            $stmt = $this->pdo->query("SELECT perm_agenda_ver FROM usuarios LIMIT 1");
            echo "<p style='color: green;'>✅ Coluna 'perm_agenda_ver' existe na tabela 'usuarios'</p>";
            
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'does not exist') !== false) {
                echo "<p style='color: red;'>❌ Tabela 'usuarios' não existe: " . $e->getMessage() . "</p>";
                $this->errors[] = "Tabela 'usuarios' não existe";
                return;
            } elseif (strpos($e->getMessage(), 'column') !== false && strpos($e->getMessage(), 'does not exist') !== false) {
                echo "<p style='color: orange;'>⚠️ Coluna 'perm_agenda_ver' não existe na tabela 'usuarios', criando...</p>";
                
                try {
                    // Adicionar a coluna perm_agenda_ver
                    $this->pdo->exec("ALTER TABLE usuarios ADD COLUMN perm_agenda_ver BOOLEAN DEFAULT false");
                    echo "<p style='color: green;'>✅ Coluna 'perm_agenda_ver' criada com sucesso</p>";
                    $this->corrections[] = "Coluna 'perm_agenda_ver' criada na tabela 'usuarios'";
                    
                    // Verificar se existem outras colunas de permissão que podem estar faltando
                    $this->checkOtherPermissionColumns();
                    
                } catch (Exception $e2) {
                    echo "<p style='color: red;'>❌ Erro ao criar coluna 'perm_agenda_ver': " . $e2->getMessage() . "</p>";
                    $this->errors[] = "Erro ao criar coluna 'perm_agenda_ver': " . $e2->getMessage();
                }
            } else {
                echo "<p style='color: red;'>❌ Erro inesperado na tabela 'usuarios': " . $e->getMessage() . "</p>";
                $this->errors[] = "Erro na tabela 'usuarios': " . $e->getMessage();
            }
        }
    }
    
    private function checkOtherPermissionColumns() {
        echo "<h4>🔍 Verificando outras colunas de permissão...</h4>";
        
        // Lista de colunas de permissão que podem estar faltando
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
        echo "<a href='fix_perm_agenda_error.php' style='display: inline-block; padding: 10px 20px; background: #2196F3; color: white; text-decoration: none; border-radius: 5px; margin: 0 10px;'>🔍 Verificar Novamente</a>";
        echo "<a href='dashboard.php' style='display: inline-block; padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px; margin: 0 10px;'>🏠 Ir para Dashboard</a>";
        echo "</div>";
        
        // Status final
        if (empty($this->errors)) {
            echo "<div style='padding: 15px; background: #e8f5e8; border: 1px solid #4CAF50; border-radius: 5px; text-align: center;'>";
            echo "<h3 style='color: #2E7D32; margin: 0;'>🎉 Todas as correções foram aplicadas com sucesso!</h3>";
            echo "<p style='margin: 10px 0 0 0; color: #2E7D32;'>✅ Colunas de permissão criadas</p>";
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
    echo "<title>Correção de Erro - perm_agenda_ver</title>";
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
    
    $fixer = new PermAgendaErrorFixer();
    $fixer->run();
    
    echo "</body>";
    echo "</html>";
}
?>
