<?php
/**
 * php_lint_config.php — Configurações para análise estática de PHP
 */

return [
    // Diretórios para analisar
    'directories' => [
        'public',
        'includes',
        'tools'
    ],
    
    // Extensões de arquivo para analisar
    'extensions' => ['php'],
    
    // Padrões de arquivo para ignorar
    'ignore_patterns' => [
        '/vendor/',
        '/node_modules/',
        '/.git/',
        '/logs/',
        '/cache/',
        '/tmp/',
        '/test/',
        '/tests/'
    ],
    
    // Padrões de include para detectar
    'include_patterns' => [
        '/(?:include|require)(?:_once)?\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
        '/(?:include|require)(?:_once)?\s*\(\s*\$[^)]+\)/',
        '/(?:include|require)(?:_once)?\s*\(\s*[^)]*\.\$[^)]+\)/'
    ],
    
    // Flags de risco para detectar
    'risk_flags' => [
        'eval' => [
            'pattern' => '/\beval\s*\(/',
            'message' => 'Uso de eval() detectado - risco de segurança',
            'suggestion' => 'Considerar alternativas mais seguras'
        ],
        'allow_url_include' => [
            'pattern' => '/allow_url_include/',
            'message' => 'allow_url_include detectado - risco de segurança',
            'suggestion' => 'Evitar allow_url_include por questões de segurança'
        ],
        'url_include' => [
            'pattern' => '/include\s*\(\s*[\'"](https?:\/\/[^\'"]+)[\'"]/',
            'message' => 'Include com URL detectado',
            'suggestion' => 'Evitar includes de URLs externas'
        ]
    ],
    
    // Caminhos inseguros para detectar
    'unsafe_paths' => [
        'relative_unsafe' => [
            'pattern' => '/include\s*\(\s*[\'"](\.\.\/[^\'"]+)[\'"]/',
            'message' => 'Caminho relativo inseguro',
            'suggestion' => 'Usar __DIR__ . \'/\' . \'caminho\' para maior segurança'
        ],
        'missing_dir_context' => [
            'pattern' => '/include\s*\(\s*[\'"]([^\'"]+)[\'"]/',
            'message' => 'Include sem contexto de diretório',
            'suggestion' => 'Considerar usar __DIR__ . \'/\' . \'caminho\''
        ]
    ],
    
    // Caminhos comuns para procurar arquivos
    'common_paths' => [
        'public',
        'includes',
        'tools',
        'config',
        'lib',
        'src'
    ],
    
    // Configurações de correção
    'fix_settings' => [
        'safe_fixes' => [
            'missing_dir_context' => true,
            'relative_to_absolute' => false
        ],
        'backup_original' => true,
        'backup_dir' => 'backups/php_lint'
    ],
    
    // Configurações de relatório
    'report_settings' => [
        'json_output' => 'tools/php_lint_report.json',
        'text_output' => 'tools/php_lint_report.txt',
        'log_output' => 'logs/php_lint.log',
        'include_suggestions' => true,
        'include_unresolved' => true,
        'include_syntax_errors' => true
    ],
    
    // Configurações de sintaxe
    'syntax_settings' => [
        'check_syntax' => true,
        'php_binary' => 'php',
        'timeout' => 30
    ],
    
    // Configurações de logging
    'logging' => [
        'enabled' => true,
        'level' => 'INFO', // DEBUG, INFO, WARNING, ERROR
        'file' => 'logs/php_lint.log',
        'max_size' => '10MB',
        'rotate' => true
    ]
];
?>
