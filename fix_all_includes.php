<?php
// fix_all_includes.php - Corrigir includes em todos os arquivos PHP

echo "🔧 CORRIGINDO INCLUDES EM TODOS OS ARQUIVOS PHP\n";
echo "==========================================\n\n";

$files_processed = 0;
$errors_fixed = 0;

// Lista de arquivos PHP
$php_files = glob('public/*.php');

foreach ($php_files as $file) {
    $filename = basename($file);
    
    // Pular arquivos que não devem ser modificados
    if (in_array($filename, ['index.php', 'conexao.php', 'helpers.php', 'sidebar_integration.php', 'sidebar_unified.php'])) {
        continue;
    }
    
    echo "📄 Processando: $filename\n";
    $content = file_get_contents($file);
    $original_content = $content;
    $changed = false;
    
    // 1. Adicionar core/helpers.php se não existir
    if (strpos($content, "require_once __DIR__ . '/core/helpers.php'") === false && 
        strpos($content, "require_once __DIR__ . '/core/helpers.php';") === false) {
        
        // Se já tem helpers.php antigo, substituir
        if (strpos($content, "require_once __DIR__ . '/helpers.php'") !== false) {
            $content = str_replace(
                "require_once __DIR__ . '/helpers.php'",
                "require_once __DIR__ . '/core/helpers.php'",
                $content
            );
            echo "  ✅ Substituído helpers.php por core/helpers.php\n";
            $changed = true;
        } else {
            // Adicionar após conexao.php
            if (strpos($content, "require_once __DIR__ . '/conexao.php'") !== false) {
                $content = str_replace(
                    "require_once __DIR__ . '/conexao.php';",
                    "require_once __DIR__ . '/conexao.php';\nrequire_once __DIR__ . '/core/helpers.php';",
                    $content
                );
                echo "  ✅ Adicionado core/helpers.php\n";
                $changed = true;
            }
        }
    }
    
    // 2. Remover função h() duplicada
    if (preg_match('/function\s+h\s*\([^)]*\)\s*\{[^}]+\}/', $content)) {
        $content = preg_replace('/function\s+h\s*\([^)]*\)\s*\{[^}]+\}/', '', $content);
        echo "  ✅ Removida função h() duplicada\n";
        $changed = true;
    }
    
    // 3. Remover função getStatusBadge() duplicada
    if (preg_match('/function\s+getStatusBadge\s*\([^)]*\)\s*\{[^}]+\}/', $content)) {
        $content = preg_replace('/function\s+getStatusBadge\s*\([^)]*\)\s*\{[^}]+\}/', '', $content);
        echo "  ✅ Removida função getStatusBadge() duplicada\n";
        $changed = true;
    }
    
    // 4. Corrigir session_start()
    if (strpos($content, 'session_start();') !== false && strpos($content, 'session_status()') === false) {
        $content = str_replace(
            'session_start();',
            'if (session_status() === PHP_SESSION_NONE) { session_start(); }',
            $content
        );
        echo "  ✅ Corrigido session_start()\n";
        $changed = true;
    }
    
    // Salvar se houve mudanças
    if ($changed) {
        file_put_contents($file, $content);
        $errors_fixed++;
        echo "  💾 Arquivo salvo\n\n";
    } else {
        echo "  ✅ Nenhuma correção necessária\n\n";
    }
    
    $files_processed++;
}

echo "📊 RESUMO:\n";
echo "==========\n";
echo "Arquivos processados: $files_processed\n";
echo "Erros corrigidos: $errors_fixed\n";
echo "✅ Concluído!\n";
?>
