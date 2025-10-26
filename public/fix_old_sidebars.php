<?php
// fix_old_sidebars.php - Script para corrigir todas as páginas com sidebar antiga
if (session_status() === PHP_SESSION_NONE) { session_start(); }

echo "<h1>🔧 Correção Automática de Sidebars Antigas</h1>";

// Lista de páginas que podem ter sidebar antiga
$pages_to_check = [
    'estoque_logistico.php',
    'lista_compras.php', 
    'ver.php',
    'config_insumos.php',
    'config_fornecedores.php',
    'config_categorias.php',
    'pagamentos.php',
    'demandas.php',
    'agenda.php'
];

$fixed_pages = [];

foreach ($pages_to_check as $page) {
    echo "<h2>🔍 Verificando: $page</h2>";
    
    if (!file_exists($page)) {
        echo "❌ Arquivo não existe<br>";
        continue;
    }
    
    $content = file_get_contents($page);
    $needs_fix = false;
    $fixes = [];
    
    // Verificar se tem HTML completo
    if (strpos($content, '<!DOCTYPE html>') !== false) {
        $needs_fix = true;
        $fixes[] = "Remover DOCTYPE html";
    }
    
    if (strpos($content, '<html lang=') !== false) {
        $needs_fix = true;
        $fixes[] = "Remover tag html";
    }
    
    if (strpos($content, '<head>') !== false) {
        $needs_fix = true;
        $fixes[] = "Remover tag head";
    }
    
    if (strpos($content, '<body>') !== false) {
        $needs_fix = true;
        $fixes[] = "Remover tag body";
    }
    
    // Verificar se inclui sidebar antiga
    if (strpos($content, "include __DIR__.'/sidebar.php'") !== false) {
        $needs_fix = true;
        $fixes[] = "Remover inclusão sidebar.php";
    }
    
    if (strpos($content, "include 'sidebar.php'") !== false) {
        $needs_fix = true;
        $fixes[] = "Remover inclusão sidebar.php";
    }
    
    // Verificar se não usa sidebar_integration
    if (strpos($content, 'sidebar_integration.php') === false) {
        $needs_fix = true;
        $fixes[] = "Adicionar sidebar_integration.php";
    }
    
    if ($needs_fix) {
        echo "⚠️ Precisa de correção:<br>";
        foreach ($fixes as $fix) {
            echo "  - $fix<br>";
        }
        
        // Aplicar correções automáticas
        $new_content = $content;
        
        // Remover DOCTYPE
        $new_content = preg_replace('/<!DOCTYPE html>.*?<html[^>]*>/s', '', $new_content);
        
        // Remover head completo
        $new_content = preg_replace('/<head>.*?<\/head>/s', '', $new_content);
        
        // Remover body tags
        $new_content = preg_replace('/<\/body>.*?<\/html>/s', '', $new_content);
        $new_content = preg_replace('/<body[^>]*>/', '', $new_content);
        
        // Remover inclusões de sidebar antiga
        $new_content = preg_replace('/<\?php if \(is_file\(__DIR__\.\'\/sidebar\.php\'\)\) \{ include __DIR__\.\'\/sidebar\.php\'; \} \?>/', '', $new_content);
        $new_content = preg_replace('/<\?php include.*?sidebar\.php.*?\?>/', '', $new_content);
        
        // Adicionar sidebar_integration se não existir
        if (strpos($new_content, 'sidebar_integration.php') === false) {
            $new_content = str_replace(
                "require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';",
                "require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';\nrequire_once __DIR__ . '/sidebar_integration.php';",
                $new_content
            );
        }
        
        // Adicionar includeSidebar() se não existir
        if (strpos($new_content, 'includeSidebar()') === false) {
            $new_content = str_replace(
                "require_once __DIR__ . '/sidebar_integration.php';",
                "require_once __DIR__ . '/sidebar_integration.php';\n\n// Iniciar sidebar\nincludeSidebar();\nsetPageTitle('" . ucfirst(str_replace('.php', '', $page)) . "');",
                $new_content
            );
        }
        
        // Adicionar endSidebar() no final se não existir
        if (strpos($new_content, 'endSidebar()') === false) {
            $new_content .= "\n\n<?php endSidebar(); ?>";
        }
        
        // Salvar arquivo corrigido
        file_put_contents($page, $new_content);
        $fixed_pages[] = $page;
        
        echo "✅ Arquivo corrigido automaticamente!<br>";
    } else {
        echo "✅ Arquivo já está correto<br>";
    }
    
    echo "<hr>";
}

echo "<h2>📋 Resumo das Correções</h2>";
if (!empty($fixed_pages)) {
    echo "✅ Páginas corrigidas:<br>";
    foreach ($fixed_pages as $page) {
        echo "  - $page<br>";
    }
} else {
    echo "✅ Nenhuma página precisou de correção!<br>";
}
?>
