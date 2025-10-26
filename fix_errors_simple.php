<?php
// fix_errors_simple.php — Correção simples e direta dos erros

echo "🔧 CORREÇÃO SIMPLES DE ERROS\n";
echo "===========================\n\n";

// Lista de arquivos que realmente existem
$files_to_fix = [
    'public/comercial_degust_inscritos.php',
    'public/comercial_degustacao_editar.php', 
    'public/comercial_degust_public.php',
    'public/comercial_pagamento.php',
    'public/estoque_alertas.php',
    'public/estoque_kardex.php',
    'public/estoque_contagens.php',
    'public/config_categorias.php',
    'public/config_fichas.php',
    'public/config_itens.php',
    'public/config_itens_fixos.php',
    'public/usuario_novo.php',
    'public/usuario_editar.php',
    'public/pagamentos_solicitar.php',
    'public/pagamentos_painel.php',
    'public/pagamentos_minhas.php',
    'public/pagamentos_ver.php',
    'public/freelancer_cadastro.php',
    'public/fornecedores.php',
    'public/fornecedor_link.php',
    'public/lc_pdf.php'
];

$errors_fixed = 0;

foreach ($files_to_fix as $file) {
    if (!file_exists($file)) {
        echo "⚠️  Arquivo não encontrado: $file\n";
        continue;
    }
    
    echo "🔍 Processando: $file\n";
    $content = file_get_contents($file);
    $original_content = $content;
    
    // 1. Corrigir sidebar_integration.php
    if (strpos($content, 'sidebar_integration.php') !== false) {
        $content = str_replace('sidebar_integration.php', 'sidebar_unified.php', $content);
        echo "  ✅ Corrigido sidebar_integration.php\n";
        $errors_fixed++;
    }
    
    // 2. Remover função h() duplicada
    if (strpos($content, 'function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, \'UTF-8\'); }') !== false) {
        $content = str_replace('function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, \'UTF-8\'); }', '', $content);
        echo "  ✅ Removida função h() duplicada\n";
        $errors_fixed++;
    }
    
    // 3. Adicionar helpers.php se não existir
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
    
    // 4. Remover endSidebar()
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

echo "📊 RESUMO:\n";
echo "==========\n";
echo "Erros corrigidos: $errors_fixed\n";
echo "Status: ✅ Concluído!\n\n";
?>

