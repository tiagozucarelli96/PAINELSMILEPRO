<?php
/**
 * php_lint_utils.php — Utilitários para análise estática de PHP
 */

class PHPLintUtils {
    
    /**
     * Resolve caminho relativo para absoluto
     */
    public static function resolvePath($includePath, $fileDir) {
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
    
    /**
     * Verifica se arquivo existe
     */
    public static function fileExists($path) {
        return file_exists($path) && is_file($path);
    }
    
    /**
     * Procura arquivo em caminhos comuns
     */
    public static function findFileInCommonPaths($includePath, $projectRoot, $commonPaths = null) {
        if ($commonPaths === null) {
            $commonPaths = ['public', 'includes', 'tools', 'config', 'lib', 'src'];
        }
        
        $foundPaths = [];
        
        foreach ($commonPaths as $path) {
            $fullPath = $projectRoot . '/' . $path . '/' . $includePath;
            if (self::fileExists($fullPath)) {
                $foundPaths[] = str_replace($projectRoot . '/', '', $fullPath);
            }
        }
        
        return $foundPaths;
    }
    
    /**
     * Sugere correção para include quebrado
     */
    public static function suggestFix($includePath, $fileDir, $projectRoot) {
        $suggestions = [];
        
        // Tentar encontrar o arquivo em locais comuns
        $foundPaths = self::findFileInCommonPaths($includePath, $projectRoot);
        
        if (!empty($foundPaths)) {
            $suggestions[] = "Arquivo encontrado em: " . implode(', ', $foundPaths);
        }
        
        // Sugerir caminho com __DIR__
        if (!str_starts_with($includePath, '/') && 
            !str_starts_with($includePath, './') && 
            !str_starts_with($includePath, '../')) {
            $suggestions[] = "Considerar usar: __DIR__ . '/$includePath'";
        }
        
        if (empty($suggestions)) {
            $suggestions[] = "Verificar se o arquivo existe e o caminho está correto";
        }
        
        return implode('; ', $suggestions);
    }
    
    /**
     * Sugere otimização para include
     */
    public static function suggestOptimization($includePath, $fileDir) {
        // Se o include não usa __DIR__ ou dirname, sugerir otimização
        if (!str_starts_with($includePath, '/') && 
            !str_starts_with($includePath, './') && 
            !str_starts_with($includePath, '../')) {
            
            return "Considerar usar __DIR__ . '/$includePath' para maior segurança";
        }
        
        return null;
    }
    
    /**
     * Aplica correção segura para include
     */
    public static function applyDirContextFix($line) {
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
    
    /**
     * Verifica se include é dinâmico
     */
    public static function isDynamicInclude($line) {
        $dynamicPatterns = [
            '/(?:include|require)(?:_once)?\s*\(\s*\$[^)]+\)/',
            '/(?:include|require)(?:_once)?\s*\(\s*[^)]*\.\$[^)]+\)/'
        ];
        
        foreach ($dynamicPatterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Extrai includes estáticos de uma linha
     */
    public static function extractStaticIncludes($line) {
        $includes = [];
        $pattern = '/(?:include|require)(?:_once)?\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/';
        
        if (preg_match_all($pattern, $line, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $includes[] = $match[1];
            }
        }
        
        return $includes;
    }
    
    /**
     * Verifica se linha contém flags de risco
     */
    public static function checkRiskFlags($line) {
        $risks = [];
        
        // Detectar eval()
        if (preg_match('/\beval\s*\(/', $line)) {
            $risks[] = [
                'type' => 'eval_usage',
                'message' => 'Uso de eval() detectado - risco de segurança',
                'suggestion' => 'Considerar alternativas mais seguras'
            ];
        }
        
        // Detectar allow_url_include
        if (preg_match('/allow_url_include/', $line)) {
            $risks[] = [
                'type' => 'allow_url_include',
                'message' => 'allow_url_include detectado - risco de segurança',
                'suggestion' => 'Evitar allow_url_include por questões de segurança'
            ];
        }
        
        // Detectar includes com URLs
        if (preg_match('/include\s*\(\s*[\'"](https?:\/\/[^\'"]+)[\'"]/', $line, $matches)) {
            $risks[] = [
                'type' => 'url_include',
                'message' => 'Include com URL detectado: ' . $matches[1],
                'suggestion' => 'Evitar includes de URLs externas'
            ];
        }
        
        return $risks;
    }
    
    /**
     * Verifica se linha contém caminhos inseguros
     */
    public static function checkUnsafePaths($line) {
        $issues = [];
        
        // Detectar caminhos relativos inseguros
        if (preg_match('/include\s*\(\s*[\'"](\.\.\/[^\'"]+)[\'"]/', $line, $matches)) {
            $issues[] = [
                'type' => 'unsafe_relative_path',
                'message' => 'Caminho relativo inseguro: ' . $matches[1],
                'suggestion' => 'Usar __DIR__ . \'/\' . \'caminho\' para maior segurança'
            ];
        }
        
        // Detectar includes sem contexto de diretório
        if (preg_match('/include\s*\(\s*[\'"]([^\'"]+)[\'"]/', $line, $matches)) {
            $includePath = $matches[1];
            if (!str_starts_with($includePath, '/') && 
                !str_starts_with($includePath, './') && 
                !str_starts_with($includePath, '../') &&
                !str_contains($line, '__DIR__') &&
                !str_contains($line, 'dirname')) {
                
                $issues[] = [
                    'type' => 'missing_dir_context',
                    'message' => 'Include sem contexto de diretório: ' . $includePath,
                    'suggestion' => 'Considerar usar __DIR__ . \'/\' . \'' . $includePath . '\''
                ];
            }
        }
        
        return $issues;
    }
    
    /**
     * Executa verificação de sintaxe
     */
    public static function checkSyntax($filePath, $phpBinary = 'php') {
        $output = [];
        $returnCode = 0;
        
        exec("$phpBinary -l " . escapeshellarg($filePath) . " 2>&1", $output, $returnCode);
        
        return [
            'success' => $returnCode === 0,
            'output' => implode("\n", $output),
            'return_code' => $returnCode
        ];
    }
    
    /**
     * Formata tamanho de arquivo
     */
    public static function formatFileSize($bytes) {
        $sizes = ['B', 'KB', 'MB', 'GB'];
        if ($bytes === 0) return '0 B';
        
        $i = floor(log($bytes) / log(1024));
        return round($bytes / pow(1024, $i) * 100) / 100 . ' ' . $sizes[$i];
    }
    
    /**
     * Formata tempo
     */
    public static function formatTime($seconds) {
        if ($seconds < 60) {
            return round($seconds, 2) . 's';
        } elseif ($seconds < 3600) {
            return round($seconds / 60, 1) . 'm';
        } else {
            return round($seconds / 3600, 1) . 'h';
        }
    }
    
    /**
     * Cria backup de arquivo
     */
    public static function createBackup($filePath, $backupDir = 'backups/php_lint') {
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $backupPath = $backupDir . '/' . basename($filePath) . '.' . date('Y-m-d_H-i-s') . '.bak';
        
        if (copy($filePath, $backupPath)) {
            return $backupPath;
        }
        
        return false;
    }
    
    /**
     * Valida configuração
     */
    public static function validateConfig($config) {
        $errors = [];
        
        if (!isset($config['directories']) || !is_array($config['directories'])) {
            $errors[] = 'Configuração de diretórios inválida';
        }
        
        if (!isset($config['extensions']) || !is_array($config['extensions'])) {
            $errors[] = 'Configuração de extensões inválida';
        }
        
        if (!isset($config['include_patterns']) || !is_array($config['include_patterns'])) {
            $errors[] = 'Configuração de padrões de include inválida';
        }
        
        return $errors;
    }
}
?>
