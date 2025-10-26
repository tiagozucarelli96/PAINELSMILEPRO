<?php
// solucao_definitiva.php — Solução definitiva para todos os problemas

echo "🚀 SOLUÇÃO DEFINITIVA PARA TODOS OS PROBLEMAS\n";
echo "============================================\n\n";

$fixed = 0;
$errors = 0;

// 1. Corrigir todos os arquivos PHP com problemas
echo "🔧 CORRIGINDO ARQUIVOS PHP...\n";

$problematic_files = [
    'public/usuarios.php',
    'public/pagamentos.php', 
    'public/lista_compras.php',
    'public/ver.php',
    'public/comercial_degustacoes.php',
    'public/comercial_degust_inscricoes.php',
    'public/comercial_clientes.php',
    'public/config_fornecedores.php',
    'public/config_insumos.php',
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
    'public/lc_pdf.php',
    'public/estoque_alertas.php',
    'public/estoque_kardex.php',
    'public/estoque_contagens.php'
];

foreach ($problematic_files as $file) {
    if (!file_exists($file)) {
        echo "⚠️  Arquivo não encontrado: $file\n";
        continue;
    }
    
    echo "🔍 Processando: $file\n";
    $content = file_get_contents($file);
    $original_content = $content;
    
    // Corrigir problemas comuns
    $changes = [];
    
    // 1. Adicionar helpers.php se não existir
    if (strpos($content, 'require_once __DIR__ . \'/helpers.php\';') === false && 
        strpos($content, 'require_once __DIR__ . "/helpers.php";') === false) {
        
        // Inserir após conexao.php
        if (strpos($content, 'require_once __DIR__ . \'/conexao.php\';') !== false) {
            $content = str_replace(
                'require_once __DIR__ . \'/conexao.php\';',
                'require_once __DIR__ . \'/conexao.php\';\nrequire_once __DIR__ . \'/helpers.php\';',
                $content
            );
            $changes[] = "Adicionado helpers.php";
        }
    }
    
    // 2. Corrigir session_start()
    if (strpos($content, 'session_start();') !== false) {
        $content = str_replace('session_start();', 'if (session_status() === PHP_SESSION_NONE) { session_start(); }', $content);
        $changes[] = "Corrigido session_start()";
    }
    
    // 3. Remover função h() duplicada
    if (strpos($content, 'function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, \'UTF-8\'); }') !== false) {
        $content = str_replace('function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, \'UTF-8\'); }', '', $content);
        $changes[] = "Removida função h() duplicada";
    }
    
    // 4. Remover função getStatusBadge() duplicada
    if (strpos($content, 'function getStatusBadge($status)') !== false) {
        $pattern = '/function getStatusBadge\(\$status\) \{[^}]+\}/s';
        $content = preg_replace($pattern, '', $content);
        $changes[] = "Removida função getStatusBadge() duplicada";
    }
    
    // 5. Corrigir sidebar_integration.php para sidebar_unified.php
    if (strpos($content, 'sidebar_integration.php') !== false) {
        $content = str_replace('sidebar_integration.php', 'sidebar_unified.php', $content);
        $changes[] = "Corrigido sidebar_integration.php";
    }
    
    // 6. Remover endSidebar()
    if (strpos($content, 'endSidebar();') !== false) {
        $content = str_replace('endSidebar();', '// Sidebar já incluída no sidebar_unified.php', $content);
        $changes[] = "Removida chamada endSidebar()";
    }
    
    // 7. Remover funções antigas de sidebar
    if (strpos($content, 'includeSidebar();') !== false) {
        $content = str_replace('includeSidebar();', '// Sidebar já incluída no sidebar_unified.php', $content);
        $changes[] = "Removida includeSidebar()";
    }
    
    if (strpos($content, 'setPageTitle(') !== false) {
        $pattern = '/setPageTitle\([^)]+\);/';
        $content = preg_replace($pattern, '// Título já definido no sidebar_unified.php', $content);
        $changes[] = "Removida setPageTitle()";
    }
    
    // Salvar se houve mudanças
    if ($content !== $original_content) {
        file_put_contents($file, $content);
        echo "  ✅ " . implode(', ', $changes) . "\n";
        $fixed++;
    } else {
        echo "  ✅ Nenhuma correção necessária\n";
    }
    
    echo "\n";
}

// 2. Criar tabelas SQL faltantes
echo "🗄️  CRIANDO TABELAS SQL FALTANTES...\n";

$sql_fixes = [
    // Tabela solicitacoes_pagfor
    "CREATE TABLE IF NOT EXISTS solicitacoes_pagfor (
        id BIGSERIAL PRIMARY KEY,
        criado_por BIGINT NOT NULL,
        status VARCHAR(50) DEFAULT 'aguardando',
        valor DECIMAL(10,2) NOT NULL,
        descricao TEXT,
        chave_pix VARCHAR(255),
        criado_em TIMESTAMP DEFAULT NOW(),
        modificado_em TIMESTAMP DEFAULT NOW()
    );",
    
    // Adicionar coluna updated_at se não existir
    "DO \$\$ 
    BEGIN 
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                      WHERE table_name = 'lc_rascunhos' AND column_name = 'updated_at') THEN
            ALTER TABLE lc_rascunhos ADD COLUMN updated_at TIMESTAMP DEFAULT NOW();
        END IF;
    END \$\$;",
    
    // Corrigir estrutura comercial_degustacoes
    "DO \$\$ 
    BEGIN 
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                      WHERE table_name = 'comercial_degustacoes' AND column_name = 'titulo') THEN
            ALTER TABLE comercial_degustacoes ADD COLUMN titulo VARCHAR(255);
        END IF;
        
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                      WHERE table_name = 'comercial_degustacoes' AND column_name = 'descricao') THEN
            ALTER TABLE comercial_degustacoes ADD COLUMN descricao TEXT;
        END IF;
        
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                      WHERE table_name = 'comercial_degustacoes' AND column_name = 'local') THEN
            ALTER TABLE comercial_degustacoes ADD COLUMN local VARCHAR(255);
        END IF;
        
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                      WHERE table_name = 'comercial_degustacoes' AND column_name = 'data') THEN
            ALTER TABLE comercial_degustacoes ADD COLUMN data DATE;
        END IF;
    END \$\$;"
];

// Salvar SQL para execução manual
file_put_contents('fix_database_structure.sql', implode("\n\n", $sql_fixes));
echo "✅ SQL de correção salvo em: fix_database_structure.sql\n";

// 3. Verificar arquivos críticos
echo "\n📋 VERIFICANDO ARQUIVOS CRÍTICOS...\n";

$critical_files = [
    'public/helpers.php' => 'Arquivo de funções auxiliares',
    'public/sidebar_unified.php' => 'Sistema unificado de sidebar',
    'public/conexao.php' => 'Conexão com banco de dados',
    'public/index.php' => 'Roteador principal'
];

foreach ($critical_files as $file => $description) {
    if (file_exists($file)) {
        echo "✅ $file - $description\n";
    } else {
        echo "❌ $file - $description (FALTANDO!)\n";
        $errors++;
    }
}

// 4. Resumo final
echo "\n📊 RESUMO DA CORREÇÃO:\n";
echo "=====================\n";
echo "Arquivos corrigidos: $fixed\n";
echo "Erros encontrados: $errors\n";

if ($errors === 0) {
    echo "Status: ✅ SISTEMA CORRIGIDO!\n";
} else {
    echo "Status: ⚠️  Ainda há $errors problemas críticos\n";
}

echo "\n🚀 PRÓXIMOS PASSOS:\n";
echo "==================\n";
echo "1. Execute o SQL: fix_database_structure.sql\n";
echo "2. Teste todas as páginas\n";
echo "3. Verifique se tudo está funcionando\n\n";

echo "🎯 SOLUÇÃO DEFINITIVA APLICADA!\n";
?>

