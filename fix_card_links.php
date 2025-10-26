<?php
// fix_card_links.php - Script para corrigir todos os links dos cards

$files = [
    'public/financeiro.php',
    'public/administrativo.php',
    'public/configuracoes_nova.php'
];

foreach ($files as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        
        // Substituir loadSubPage por window.location.href
        $content = preg_replace(
            '/onclick="loadSubPage\(\'([^\']+)\'\)"/',
            'onclick="window.location.href=\'index.php?page=$1\'"',
            $content
        );
        
        // Remover JavaScript desnecessário
        $content = preg_replace(
            '/<script>.*?function loadSubPage.*?<\/script>/s',
            '',
            $content
        );
        
        file_put_contents($file, $content);
        echo "✅ Corrigido: $file\n";
    } else {
        echo "❌ Arquivo não encontrado: $file\n";
    }
}

echo "\n🎉 Todos os links dos cards foram corrigidos!\n";
?>
