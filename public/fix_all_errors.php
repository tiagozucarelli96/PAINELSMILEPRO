<?php
// fix_all_errors.php — Script para corrigir todos os erros sistematicamente

echo "🔧 CORREÇÃO SISTEMÁTICA DE ERROS\n";
echo "================================\n\n";

$errors_fixed = 0;
$files_processed = 0;

// Lista de arquivos para processar
$files_to_fix = [
    'comercial_degust_inscritos.php',
    'comercial_degustacao_editar.php', 
    'comercial_degust_public.php',
    'comercial_pagamento.php',
    'estoque_alertas.php',
    'estoque_kardex.php',
    'estoque_contagens.php',
    'config_categorias.php',
    'config_fichas.php',
    'config_itens.php',
    'config_itens_fixos.php',
    'usuario_novo.php',
    'usuario_editar.php',
    'pagamentos_solicitar.php',
    'pagamentos_painel.php',
    'pagamentos_minhas.php',
    'pagamentos_ver.php',
    'freelancer_cadastro.php',
    'fornecedores.php',
    'fornecedor_link.php',
    'lc_pdf.php'
];

foreach ($files_to_fix as $file) {
    $file_path = "public/$file";
    if (!file_exists($file_path)) {
        echo "⚠️  Arquivo não encontrado: $file_path\n";
        continue;
    }
    
    $files_processed++;
    $content = file_get_contents($file_path);
    $original_content = $content;
    
    // 1. Corrigir referências ao sidebar_integration.php
    if (strpos($content, 'sidebar_integration.php') !== false) {
        $content = str_replace('sidebar_integration.php', 'sidebar_unified.php', $content);
        echo "✅ Corrigido sidebar_integration.php em: $file\n";
        $errors_fixed++;
    }
    
    // 2. Remover declarações duplicadas da função h()
    if (strpos($content, '') !== false) {
        $content = str_replace('', '', $content);
        echo "✅ Removida função h() duplicada em: $file\n";
        $errors_fixed++;
    }
    
    // 3. Adicionar include do helpers.php se não existir
    if (strpos($content, 'require_once __DIR__ . \'/helpers.php\';') === false && 
        strpos($content, 'require_once __DIR__ . "/helpers.php";') === false) {
        
        // Encontrar onde inserir o helpers.php (após outros requires)
        $lines = explode("\n", $content);
        $insert_position = -1;
        
        for ($i = 0; $i < count($lines); $i++) {
            if (strpos($lines[$i], 'require_once __DIR__') !== false) {
                $insert_position = $i + 1;
            }
        }
        
        if ($insert_position > 0) {
            array_splice($lines, $insert_position, 0, "require_once __DIR__ . '/core/helpers.php';");
            $content = implode("\n", $lines);
            echo "✅ Adicionado helpers.php em: $file\n";
            $errors_fixed++;
        }
    }
    
    // 4. Remover chamadas para endSidebar() se existirem
    if (strpos($content, 'endSidebar();') !== false) {
        $content = str_replace('endSidebar();', '// Sidebar já incluída no sidebar_unified.php', $content);
        echo "✅ Removida chamada endSidebar() em: $file\n";
        $errors_fixed++;
    }
    
    // Salvar arquivo se houve mudanças
    if ($content !== $original_content) {
        file_put_contents($file_path, $content);
        echo "💾 Arquivo salvo: $file_path\n";
    }
    
    echo "\n";
}

echo "📊 RESUMO DA CORREÇÃO:\n";
echo "=====================\n";
echo "Arquivos processados: $files_processed\n";
echo "Erros corrigidos: $errors_fixed\n";
echo "Status: ✅ Concluído!\n\n";

echo "🔍 VERIFICAÇÃO ADICIONAL:\n";
echo "========================\n";

// Verificar se existem outros arquivos com problemas
$all_php_files = glob('public/*.php');
$remaining_issues = 0;

foreach ($all_php_files as $file) {
    $content = file_get_contents($file);
    
    if (strpos($content, 'sidebar_integration.php') !== false) {
        echo "⚠️  Ainda tem sidebar_integration.php: $file\n";
        $remaining_issues++;
    }
    
    if (strpos($content, '
    
    if (strpos($content, 'endSidebar();') !== false) {
        echo "⚠️  Ainda tem endSidebar(): $file\n";
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
