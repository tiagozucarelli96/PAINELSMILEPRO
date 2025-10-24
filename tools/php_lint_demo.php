<?php
#!/usr/bin/env php
<?php
/**
 * php_lint_demo.php — Demonstração do sistema de análise estática
 */

class PHPLintDemo {
    private $projectRoot;
    
    public function __construct() {
        $this->projectRoot = dirname(__DIR__);
    }
    
    public function run() {
        echo "🔍 Demonstração do Sistema de Análise Estática de PHP\n";
        echo str_repeat("=", 50) . "\n";
        
        // Verificar estrutura de arquivos
        $this->checkFileStructure();
        
        // Mostrar configurações
        $this->showConfiguration();
        
        // Mostrar comandos disponíveis
        $this->showCommands();
        
        // Mostrar exemplos de uso
        $this->showExamples();
        
        // Mostrar funcionalidades
        $this->showFeatures();
        
        echo "\n🚀 Para começar:\n";
        echo "  php tools/php_include_lint.php\n";
        echo "  php tools/php_include_lint.php --fix-safe\n";
    }
    
    private function checkFileStructure() {
        echo "\n📁 Verificando estrutura de arquivos...\n";
        
        $files = [
            'php_include_lint.php',
            'php_lint_runner.sh',
            'php_lint_config.php',
            'php_lint_utils.php',
            'README.md'
        ];
        
        $existing = 0;
        $missing = 0;
        
        foreach ($files as $file) {
            $path = $this->projectRoot . '/tools/' . $file;
            if (file_exists($path)) {
                echo "✅ $file\n";
                $existing++;
            } else {
                echo "❌ $file\n";
                $missing++;
            }
        }
        
        echo "\n📊 Arquivos: $existing ✅ | $missing ❌\n";
        
        if ($missing === 0) {
            echo "🎉 Estrutura completa!\n";
        } else {
            echo "⚠️ Alguns arquivos estão faltando.\n";
        }
    }
    
    private function showConfiguration() {
        echo "\n⚙️ Configuração:\n";
        echo "  Diretórios: public/, includes/, tools/\n";
        echo "  Extensões: .php\n";
        echo "  Padrões de include: include, require, include_once, require_once\n";
        echo "  Flags de risco: eval(), allow_url_include, URL includes\n";
        echo "  Correções: --fix-safe para aplicar correções seguras\n";
    }
    
    private function showCommands() {
        echo "\n📋 Comandos disponíveis:\n";
        echo "  php tools/php_include_lint.php        # Análise básica\n";
        echo "  php tools/php_include_lint.php --fix-safe # Análise com correções\n";
        echo "  ./tools/php_lint_runner.sh            # Script runner\n";
        echo "  php tools/php_lint_demo.php           # Esta demonstração\n";
    }
    
    private function showExamples() {
        echo "\n💡 Exemplos de uso:\n";
        echo "\n# Análise básica\n";
        echo "php tools/php_include_lint.php\n";
        echo "\n# Análise com correções seguras\n";
        echo "php tools/php_include_lint.php --fix-safe\n";
        echo "\n# Usar script runner\n";
        echo "./tools/php_lint_runner.sh\n";
        echo "\n# Ver relatório\n";
        echo "cat tools/php_lint_report.txt\n";
        echo "\n# Ver relatório JSON\n";
        echo "cat tools/php_lint_report.json\n";
    }
    
    private function showFeatures() {
        echo "\n🎯 Funcionalidades:\n";
        echo "✅ Detecção de includes quebrados\n";
        echo "✅ Verificação de sintaxe PHP\n";
        echo "✅ Detecção de flags de risco\n";
        echo "✅ Sugestões de correção\n";
        echo "✅ Correções automáticas seguras\n";
        echo "✅ Relatórios HTML e JSON\n";
        echo "✅ Logs detalhados\n";
        echo "✅ Backup antes de modificar\n";
    }
    
    private function showOutputs() {
        echo "\n📊 Saídas geradas:\n";
        echo "📄 tools/php_lint_report.json - Relatório JSON detalhado\n";
        echo "📋 tools/php_lint_report.txt - Relatório texto legível\n";
        echo "📝 logs/php_lint.log - Logs de execução\n";
        echo "💾 backups/ - Backups de arquivos modificados\n";
    }
}

// Executar se chamado diretamente
if (php_sapi_name() === 'cli') {
    $demo = new PHPLintDemo();
    $demo->run();
}
?>
