<?php
// fix_all_remaining_errors.php — Correção completa de todos os erros restantes

echo "🔧 CORREÇÃO COMPLETA DE ERROS RESTANTES\n";
echo "=====================================\n\n";

$errors_fixed = 0;
$files_processed = 0;

// Lista de arquivos que ainda têm problemas
$problematic_files = [
    'public/ver.php',
    'public/lista_compras.php', 
    'public/usuarios.php',
    'public/comercial_clientes.php',
    'public/estoque_kardex.php',
    'public/estoque_contagens.php',
    'public/lc_pdf.php',
    'public/estoque_alertas.php',
    'public/config_fornecedores.php'
];

foreach ($problematic_files as $file) {
    if (!file_exists($file)) {
        echo "⚠️  Arquivo não encontrado: $file\n";
        continue;
    }
    
    $files_processed++;
    echo "🔍 Processando: $file\n";
    $content = file_get_contents($file);
    $original_content = $content;
    
    // 1. Corrigir session_start() notices
    if (strpos($content, 'session_start();') !== false) {
        $content = str_replace('session_start();', 'if (session_status() === PHP_SESSION_NONE) { session_start(); }', $content);
        echo "  ✅ Corrigido session_start() notice\n";
        $errors_fixed++;
    }
    
    // 2. Remover função h() duplicada
    if (strpos($content, 'function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, \'UTF-8\'); }') !== false) {
        $content = str_replace('function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, \'UTF-8\'); }', '', $content);
        echo "  ✅ Removida função h() duplicada\n";
        $errors_fixed++;
    }
    
    // 3. Remover função getStatusBadge() duplicada
    if (strpos($content, 'function getStatusBadge($status)') !== false) {
        // Remover toda a função getStatusBadge
        $pattern = '/function getStatusBadge\(\$status\) \{[^}]+\}/s';
        $content = preg_replace($pattern, '', $content);
        echo "  ✅ Removida função getStatusBadge() duplicada\n";
        $errors_fixed++;
    }
    
    // 4. Adicionar helpers.php se não existir
    if (strpos($content, 'require_once __DIR__ . \'/helpers.php\';') === false && 
        strpos($content, 'require_once __DIR__ . "/helpers.php";') === false) {
        
        // Inserir após outros requires
        $lines = explode("\n", $content);
        $insert_position = -1;
        
        for ($i = 0; $i < count($lines); $i++) {
            if (strpos($lines[$i], 'require_once __DIR__') !== false) {
                $insert_position = $i + 1;
            }
        }
        
        if ($insert_position > 0) {
            array_splice($lines, $insert_position, 0, "require_once __DIR__ . '/helpers.php';");
            $content = implode("\n", $lines);
            echo "  ✅ Adicionado helpers.php\n";
            $errors_fixed++;
        }
    }
    
    // 5. Corrigir sidebar_integration.php para sidebar_unified.php
    if (strpos($content, 'sidebar_integration.php') !== false) {
        $content = str_replace('sidebar_integration.php', 'sidebar_unified.php', $content);
        echo "  ✅ Corrigido sidebar_integration.php\n";
        $errors_fixed++;
    }
    
    // 6. Remover endSidebar() se existir
    if (strpos($content, 'endSidebar();') !== false) {
        $content = str_replace('endSidebar();', '// Sidebar já incluída no sidebar_unified.php', $content);
        echo "  ✅ Removida chamada endSidebar()\n";
        $errors_fixed++;
    }
    
    // Salvar se houve mudanças
    if ($content !== $original_content) {
        file_put_contents($file, $content);
        echo "  💾 Arquivo salvo\n";
    } else {
        echo "  ✅ Nenhuma correção necessária\n";
    }
    
    echo "\n";
}

echo "📊 RESUMO DA CORREÇÃO:\n";
echo "=====================\n";
echo "Arquivos processados: $files_processed\n";
echo "Erros corrigidos: $errors_fixed\n";
echo "Status: ✅ Concluído!\n\n";

echo "🔍 VERIFICAÇÃO FINAL:\n";
echo "====================\n";

// Verificar se ainda há problemas
$remaining_issues = 0;
$all_php_files = glob('public/*.php');

foreach ($all_php_files as $file) {
    $content = file_get_contents($file);
    
    if (strpos($content, 'function h($s){ return htmlspecialchars') !== false) {
        echo "⚠️  Ainda tem função h() duplicada: $file\n";
        $remaining_issues++;
    }
    
    if (strpos($content, 'function getStatusBadge($status)') !== false) {
        echo "⚠️  Ainda tem função getStatusBadge() duplicada: $file\n";
        $remaining_issues++;
    }
    
    if (strpos($content, 'session_start();') !== false) {
        echo "⚠️  Ainda tem session_start() sem verificação: $file\n";
        $remaining_issues++;
    }
}

if ($remaining_issues === 0) {
    echo "✅ Nenhum problema encontrado!\n";
} else {
    echo "⚠️  $remaining_issues problemas ainda precisam ser corrigidos.\n";
}

echo "\n🎉 CORREÇÃO FINALIZADA!\n";
?>

