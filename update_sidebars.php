<?php
// update_sidebars.php - Script para atualizar todas as páginas para usar nova sidebar

$pages_to_update = [
    'comercial_degustacoes.php',
    'comercial_degust_inscricoes.php', 
    'comercial_clientes.php',
    'usuarios.php',
    'agenda.php',
    'demandas.php',
    'ver.php'
];

foreach ($pages_to_update as $file) {
    $filepath = __DIR__ . '/public/' . $file;
    
    if (file_exists($filepath)) {
        echo "Atualizando: $file\n";
        
        $content = file_get_contents($filepath);
        
        // Verificar se já tem sidebar_integration
        if (strpos($content, 'sidebar_integration.php') !== false) {
            echo "  ✅ Já atualizada\n";
            continue;
        }
        
        // Adicionar sidebar_integration no início
        $content = preg_replace(
            '/(require_once __DIR__ \. \'\/conexao\.php\';)/',
            "$1\nrequire_once __DIR__ . '/sidebar_integration.php';",
            $content
        );
        
        // Adicionar includeSidebar após verificação de permissões
        $content = preg_replace(
            '/(exit;)/',
            "$1\n\n// Iniciar sidebar\nincludeSidebar();\nsetPageTitle('" . ucfirst(str_replace(['.php', '_'], ['', ' '], $file)) . "');",
            $content
        );
        
        // Remover HTML completo e deixar apenas conteúdo
        $content = preg_replace(
            '/<!DOCTYPE html>.*?<body[^>]*>/s',
            '<div class="page-container">',
            $content
        );
        
        $content = preg_replace(
            '/<\/body>.*?<\/html>/s',
            '</div>',
            $content
        );
        
        // Remover include da sidebar antiga
        $content = preg_replace(
            '/<\?php if \(is_file\(__DIR__\.\'\/sidebar\.php\'\)\) \{ include __DIR__\.\'\/sidebar\.php\'; \} \?>/',
            '',
            $content
        );
        
        // Adicionar endSidebar no final
        $content = preg_replace(
            '/(<\/div>\s*$)/',
            "$1\n\n<?php\n// Finalizar sidebar\nendSidebar();\n?>",
            $content
        );
        
        file_put_contents($filepath, $content);
        echo "  ✅ Atualizada\n";
    } else {
        echo "❌ Arquivo não encontrado: $file\n";
    }
}

echo "\n🎉 Atualização concluída!\n";
?>
