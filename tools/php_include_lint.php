<?php
#!/usr/bin/env php
<?php
/**
 * php_include_lint.php — Análise estática de PHP para detectar includes quebrados e erros
 * 
 * Funcionalidades:
 * - Detectar includes/requires quebrados
 * - Verificar erros de sintaxe (php -l)
 * - Detectar uso inseguro de caminhos relativos
 * - Identificar flags de risco (eval, allow_url_include)
 * - Sugerir correções seguras
 */

class PHPIncludeLinter {
    private $projectRoot;
    private $errors = [];
    private $warnings = [];
    private $suggestions = [];
    private $unresolved = [];
    private $syntaxErrors = [];
    private $fixSafe = false;
    private $logFile;
    
    public function __construct() {
        $this->projectRoot = dirname(__DIR__);
        $this->logFile = $this->projectRoot . '/logs/php_lint.log';
        $this->fixSafe = in_array('--fix-safe', $argv ?? []);
        
        // Criar diretório de logs
        if (!is_dir(dirname($this->logFile))) {
            mkdir(dirname($this->logFile), 0755, true);
        }
    }
    
    public function run() {
        $this->log("Iniciando análise estática de PHP...");
        
        try {
            // Encontrar todos os arquivos PHP
            $phpFiles = $this->findPHPFiles();
            $this->log("Encontrados " . count($phpFiles) . " arquivos PHP");
            
            // Analisar cada arquivo
            foreach ($phpFiles as $file) {
                $this->analyzeFile($file);
            }
            
            // Verificar sintaxe
            $this->checkSyntax($phpFiles);
            
            // Gerar relatórios
            $this->generateReports();
            
            // Aplicar correções se solicitado
            if ($this->fixSafe) {
                $this->applySafeFixes();
            }
            
            // Código de saída
            $hasErrors = !empty($this->errors) || !empty($this->syntaxErrors);
            $exitCode = $hasErrors ? 1 : 0;
            
            $this->log("Análise concluída. Exit code: $exitCode");
            exit($exitCode);
            
        } catch (Exception $e) {
            $this->log("Erro fatal: " . $e->getMessage());
            exit(1);
        }
    }
    
    private function findPHPFiles() {
        $files = [];
        $directories = ['public', 'includes', 'tools'];
        
        foreach ($directories as $dir) {
            $path = $this->projectRoot . '/' . $dir;
            if (is_dir($path)) {
                $files = array_merge($files, $this->scanDirectory($path));
            }
        }
        
        return $files;
    }
    
    private function scanDirectory($dir) {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }
    
    private function analyzeFile($filePath) {
        $relativePath = str_replace($this->projectRoot . '/', '', $filePath);
        $this->log("Analisando: $relativePath");
        
        $content = file_get_contents($filePath);
        if ($content === false) {
            $this->errors[] = [
                'file' => $relativePath,
                'line' => 0,
                'type' => 'file_read_error',
                'message' => 'Não foi possível ler o arquivo',
                'suggestion' => 'Verificar permissões do arquivo'
            ];
            return;
        }
        
        // Detectar includes/requires
        $this->detectIncludes($filePath, $content);
        
        // Detectar flags de risco
        $this->detectRiskFlags($filePath, $content);
        
        // Detectar uso inseguro de caminhos
        $this->detectUnsafePaths($filePath, $content);
    }
    
    private function detectIncludes($filePath, $content) {
        $lines = explode("\n", $content);
        $relativePath = str_replace($this->projectRoot . '/', '', $filePath);
        $fileDir = dirname($filePath);
        
        // Padrões para includes/requires
        $patterns = [
            '/(?:include|require)(?:_once)?\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            '/(?:include|require)(?:_once)?\s*\(\s*\$[^)]+\)/',
            '/(?:include|require)(?:_once)?\s*\(\s*[^)]*\.\$[^)]+\)/'
        ];
        
        foreach ($lines as $lineNum => $line) {
            $lineNum++; // 1-indexed
            
            // Includes estáticos
            if (preg_match($patterns[0], $line, $matches)) {
                $includePath = $matches[1];
                $resolvedPath = $this->resolvePath($includePath, $fileDir);
                
                if (!$this->fileExists($resolvedPath)) {
                    $this->errors[] = [
                        'file' => $relativePath,
                        'line' => $lineNum,
                        'type' => 'broken_include',
                        'message' => "Include quebrado: $includePath",
                        'suggestion' => $this->suggestFix($includePath, $fileDir),
                        'include_path' => $includePath,
                        'resolved_path' => $resolvedPath
                    ];
                } else {
                    // Verificar se pode ser otimizado
                    $suggestion = $this->suggestOptimization($includePath, $fileDir);
                    if ($suggestion) {
                        $this->suggestions[] = [
                            'file' => $relativePath,
                            'line' => $lineNum,
                            'type' => 'optimization',
                            'message' => "Possível otimização: $includePath",
                            'suggestion' => $suggestion
                        ];
                    }
                }
            }
            
            // Includes dinâmicos
            if (preg_match($patterns[1], $line) || preg_match($patterns[2], $line)) {
                $this->unresolved[] = [
                    'file' => $relativePath,
                    'line' => $lineNum,
                    'type' => 'dynamic_include',
                    'message' => 'Include dinâmico detectado',
                    'code' => trim($line)
                ];
            }
        }
    }
    
    private function detectRiskFlags($filePath, $content) {
        $relativePath = str_replace($this->projectRoot . '/', '', $filePath);
        $lines = explode("\n", $content);
        
        foreach ($lines as $lineNum => $line) {
            $lineNum++; // 1-indexed
            
            // Detectar eval()
            if (preg_match('/\beval\s*\(/', $line)) {
                $this->warnings[] = [
                    'file' => $relativePath,
                    'line' => $lineNum,
                    'type' => 'eval_usage',
                    'message' => 'Uso de eval() detectado - risco de segurança',
                    'suggestion' => 'Considerar alternativas mais seguras'
                ];
            }
            
            // Detectar allow_url_include
            if (preg_match('/allow_url_include/', $line)) {
                $this->warnings[] = [
                    'file' => $relativePath,
                    'line' => $lineNum,
                    'type' => 'allow_url_include',
                    'message' => 'allow_url_include detectado - risco de segurança',
                    'suggestion' => 'Evitar allow_url_include por questões de segurança'
                ];
            }
            
            // Detectar includes com URLs
            if (preg_match('/include\s*\(\s*[\'"](https?:\/\/[^\'"]+)[\'"]/', $line, $matches)) {
                $this->warnings[] = [
                    'file' => $relativePath,
                    'line' => $lineNum,
                    'type' => 'url_include',
                    'message' => 'Include com URL detectado: ' . $matches[1],
                    'suggestion' => 'Evitar includes de URLs externas'
                ];
            }
        }
    }
    
    private function detectUnsafePaths($filePath, $content) {
        $relativePath = str_replace($this->projectRoot . '/', '', $filePath);
        $lines = explode("\n", $content);
        
        foreach ($lines as $lineNum => $line) {
            $lineNum++; // 1-indexed
            
            // Detectar caminhos relativos inseguros
            if (preg_match('/include\s*\(\s*[\'"](\.\.\/[^\'"]+)[\'"]/', $line, $matches)) {
                $this->warnings[] = [
                    'file' => $relativePath,
                    'line' => $lineNum,
                    'type' => 'unsafe_relative_path',
                    'message' => 'Caminho relativo inseguro: ' . $matches[1],
                    'suggestion' => 'Usar __DIR__ . \'/\' . \'caminho\' para maior segurança'
                ];
            }
            
            // Detectar includes sem __DIR__ ou dirname
            if (preg_match('/include\s*\(\s*[\'"]([^\'"]+)[\'"]/', $line, $matches)) {
                $includePath = $matches[1];
                if (!str_starts_with($includePath, '/') && 
                    !str_starts_with($includePath, './') && 
                    !str_starts_with($includePath, '../') &&
                    !str_contains($line, '__DIR__') &&
                    !str_contains($line, 'dirname')) {
                    
                    $this->suggestions[] = [
                        'file' => $relativePath,
                        'line' => $lineNum,
                        'type' => 'missing_dir_context',
                        'message' => 'Include sem contexto de diretório: ' . $includePath,
                        'suggestion' => 'Considerar usar __DIR__ . \'/\' . \'' . $includePath . '\''
                    ];
                }
            }
        }
    }
    
    private function checkSyntax($phpFiles) {
        $this->log("Verificando sintaxe de " . count($phpFiles) . " arquivos...");
        
        foreach ($phpFiles as $file) {
            $relativePath = str_replace($this->projectRoot . '/', '', $file);
            
            // Executar php -l
            $output = [];
            $returnCode = 0;
            exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $returnCode);
            
            if ($returnCode !== 0) {
                $this->syntaxErrors[] = [
                    'file' => $relativePath,
                    'type' => 'syntax_error',
                    'message' => implode("\n", $output),
                    'suggestion' => 'Corrigir erros de sintaxe antes de prosseguir'
                ];
            }
        }
    }
    
    private function resolvePath($includePath, $fileDir) {
        // Se já é absoluto
        if (str_starts_with($includePath, '/')) {
            return $includePath;
        }
        
        // Se começa com ./
        if (str_starts_with($includePath, './')) {
            return $fileDir . '/' . substr($includePath, 2);
        }
        
        // Se começa com ../
        if (str_starts_with($includePath, '../')) {
            return $fileDir . '/' . $includePath;
        }
        
        // Caminho relativo simples
        return $fileDir . '/' . $includePath;
    }
    
    private function fileExists($path) {
        return file_exists($path) && is_file($path);
    }
    
    private function suggestFix($includePath, $fileDir) {
        $suggestions = [];
        
        // Tentar encontrar o arquivo em locais comuns
        $commonPaths = [
            $fileDir . '/' . $includePath,
            $this->projectRoot . '/public/' . $includePath,
            $this->projectRoot . '/includes/' . $includePath,
            $this->projectRoot . '/' . $includePath
        ];
        
        foreach ($commonPaths as $path) {
            if ($this->fileExists($path)) {
                $relativePath = str_replace($this->projectRoot . '/', '', $path);
                $suggestions[] = "Arquivo encontrado em: $relativePath";
            }
        }
        
        if (empty($suggestions)) {
            $suggestions[] = "Verificar se o arquivo existe e o caminho está correto";
        }
        
        return implode('; ', $suggestions);
    }
    
    private function suggestOptimization($includePath, $fileDir) {
        // Se o include não usa __DIR__ ou dirname, sugerir otimização
        if (!str_starts_with($includePath, '/') && 
            !str_starts_with($includePath, './') && 
            !str_starts_with($includePath, '../')) {
            
            return "Considerar usar __DIR__ . '/$includePath' para maior segurança";
        }
        
        return null;
    }
    
    private function applySafeFixes() {
        $this->log("Aplicando correções seguras...");
        
        $fixesApplied = 0;
        
        foreach ($this->suggestions as $suggestion) {
            if ($suggestion['type'] === 'missing_dir_context') {
                $filePath = $this->projectRoot . '/' . $suggestion['file'];
                $content = file_get_contents($filePath);
                
                if ($content !== false) {
                    $lines = explode("\n", $content);
                    $lineIndex = $suggestion['line'] - 1;
                    
                    if (isset($lines[$lineIndex])) {
                        $originalLine = $lines[$lineIndex];
                        $fixedLine = $this->applyDirContextFix($originalLine);
                        
                        if ($fixedLine !== $originalLine) {
                            $lines[$lineIndex] = $fixedLine;
                            $newContent = implode("\n", $lines);
                            
                            if (file_put_contents($filePath, $newContent)) {
                                $fixesApplied++;
                                $this->log("Correção aplicada em: " . $suggestion['file']);
                            }
                        }
                    }
                }
            }
        }
        
        $this->log("Correções aplicadas: $fixesApplied");
    }
    
    private function applyDirContextFix($line) {
        // Aplicar correção segura para includes sem contexto de diretório
        if (preg_match('/include\s*\(\s*[\'"]([^\'"]+)[\'"]/', $line, $matches)) {
            $includePath = $matches[1];
            if (!str_starts_with($includePath, '/') && 
                !str_starts_with($includePath, './') && 
                !str_starts_with($includePath, '../') &&
                !str_contains($line, '__DIR__') &&
                !str_contains($line, 'dirname')) {
                
                $fixedLine = str_replace(
                    $matches[0],
                    "include(__DIR__ . '/$includePath')",
                    $line
                );
                
                return $fixedLine;
            }
        }
        
        return $line;
    }
    
    private function generateReports() {
        $this->log("Gerando relatórios...");
        
        // Relatório JSON
        $jsonReport = [
            'summary' => [
                'total_errors' => count($this->errors),
                'total_warnings' => count($this->warnings),
                'total_suggestions' => count($this->suggestions),
                'total_unresolved' => count($this->unresolved),
                'total_syntax_errors' => count($this->syntaxErrors),
                'timestamp' => date('Y-m-d H:i:s')
            ],
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'suggestions' => $this->suggestions,
            'unresolved' => $this->unresolved,
            'syntax_errors' => $this->syntaxErrors
        ];
        
        file_put_contents($this->projectRoot . '/tools/php_lint_report.json', json_encode($jsonReport, JSON_PRETTY_PRINT));
        
        // Relatório texto
        $textReport = $this->generateTextReport();
        file_put_contents($this->projectRoot . '/tools/php_lint_report.txt', $textReport);
        
        $this->log("Relatórios gerados:");
        $this->log("- tools/php_lint_report.json");
        $this->log("- tools/php_lint_report.txt");
    }
    
    private function generateTextReport() {
        $report = "=== RELATÓRIO DE ANÁLISE ESTÁTICA DE PHP ===\n";
        $report .= "Gerado em: " . date('Y-m-d H:i:s') . "\n\n";
        
        $report .= "=== RESUMO ===\n";
        $report .= "Erros: " . count($this->errors) . "\n";
        $report .= "Avisos: " . count($this->warnings) . "\n";
        $report .= "Sugestões: " . count($this->suggestions) . "\n";
        $report .= "Não resolvidos: " . count($this->unresolved) . "\n";
        $report .= "Erros de sintaxe: " . count($this->syntaxErrors) . "\n\n";
        
        if (!empty($this->errors)) {
            $report .= "=== ERROS ===\n";
            foreach ($this->errors as $error) {
                $report .= "Arquivo: {$error['file']}\n";
                $report .= "Linha: {$error['line']}\n";
                $report .= "Tipo: {$error['type']}\n";
                $report .= "Mensagem: {$error['message']}\n";
                $report .= "Sugestão: {$error['suggestion']}\n";
                $report .= "---\n";
            }
        }
        
        if (!empty($this->warnings)) {
            $report .= "=== AVISOS ===\n";
            foreach ($this->warnings as $warning) {
                $report .= "Arquivo: {$warning['file']}\n";
                $report .= "Linha: {$warning['line']}\n";
                $report .= "Tipo: {$warning['type']}\n";
                $report .= "Mensagem: {$warning['message']}\n";
                $report .= "Sugestão: {$warning['suggestion']}\n";
                $report .= "---\n";
            }
        }
        
        if (!empty($this->syntaxErrors)) {
            $report .= "=== ERROS DE SINTAXE ===\n";
            foreach ($this->syntaxErrors as $error) {
                $report .= "Arquivo: {$error['file']}\n";
                $report .= "Mensagem: {$error['message']}\n";
                $report .= "Sugestão: {$error['suggestion']}\n";
                $report .= "---\n";
            }
        }
        
        if (!empty($this->suggestions)) {
            $report .= "=== SUGESTÕES ===\n";
            foreach ($this->suggestions as $suggestion) {
                $report .= "Arquivo: {$suggestion['file']}\n";
                $report .= "Linha: {$suggestion['line']}\n";
                $report .= "Tipo: {$suggestion['type']}\n";
                $report .= "Mensagem: {$suggestion['message']}\n";
                $report .= "Sugestão: {$suggestion['suggestion']}\n";
                $report .= "---\n";
            }
        }
        
        if (!empty($this->unresolved)) {
            $report .= "=== NÃO RESOLVIDOS ===\n";
            foreach ($this->unresolved as $unresolved) {
                $report .= "Arquivo: {$unresolved['file']}\n";
                $report .= "Linha: {$unresolved['line']}\n";
                $report .= "Tipo: {$unresolved['type']}\n";
                $report .= "Mensagem: {$unresolved['message']}\n";
                $report .= "Código: {$unresolved['code']}\n";
                $report .= "---\n";
            }
        }
        
        return $report;
    }
    
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";
        
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
        echo $logMessage;
    }
}

// Executar se chamado diretamente
if (php_sapi_name() === 'cli') {
    $linter = new PHPIncludeLinter();
    $linter->run();
}
?>
