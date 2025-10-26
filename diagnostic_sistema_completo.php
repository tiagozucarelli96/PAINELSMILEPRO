<?php
// diagnostic_sistema_completo.php — Diagnóstico completo e correção definitiva

echo "🔍 DIAGNÓSTICO COMPLETO DO SISTEMA\n";
echo "==================================\n\n";

$problems = [];
$solutions = [];

// 1. Verificar arquivos PHP com problemas
echo "📁 VERIFICANDO ARQUIVOS PHP...\n";
$php_files = glob('public/*.php');
foreach ($php_files as $file) {
    $content = file_get_contents($file);
    $filename = basename($file);
    
    // Verificar problemas comuns
    if (strpos($content, 'function h($s){ return htmlspecialchars') !== false) {
        $problems[] = "Função h() duplicada em: $filename";
        $solutions[] = "Remover função h() duplicada de $filename";
    }
    
    if (strpos($content, 'function getStatusBadge($status)') !== false) {
        $problems[] = "Função getStatusBadge() duplicada em: $filename";
        $solutions[] = "Remover função getStatusBadge() duplicada de $filename";
    }
    
    if (strpos($content, 'session_start();') !== false) {
        $problems[] = "session_start() sem verificação em: $filename";
        $solutions[] = "Corrigir session_start() em $filename";
    }
    
    if (strpos($content, 'endSidebar();') !== false) {
        $problems[] = "Chamada endSidebar() em: $filename";
        $solutions[] = "Remover endSidebar() de $filename";
    }
    
    if (strpos($content, 'sidebar_integration.php') !== false) {
        $problems[] = "Referência sidebar_integration.php em: $filename";
        $solutions[] = "Corrigir para sidebar_unified.php em $filename";
    }
    
    if (strpos($content, 'includeSidebar();') !== false || strpos($content, 'setPageTitle(') !== false) {
        $problems[] = "Funções de sidebar antigas em: $filename";
        $solutions[] = "Remover funções antigas de $filename";
    }
}

// 2. Verificar rotas no index.php
echo "🛣️  VERIFICANDO ROTAS...\n";
$index_content = file_get_contents('public/index.php');
$routes_section = false;
$missing_routes = [
    'comercial_degust_inscricoes',
    'comercial_degustacao_editar', 
    'comercial_degust_public',
    'comercial_pagamento',
    'estoque_alertas',
    'estoque_kardex',
    'estoque_contagens',
    'config_categorias',
    'config_fichas',
    'config_itens',
    'config_itens_fixos',
    'usuario_novo',
    'usuario_editar',
    'pagamentos_solicitar',
    'pagamentos_painel',
    'pagamentos_minhas',
    'pagamentos_ver',
    'freelancer_cadastro',
    'fornecedores',
    'fornecedor_link',
    'lc_pdf'
];

foreach ($missing_routes as $route) {
    if (strpos($index_content, "'$route'") === false) {
        $problems[] = "Rota '$route' não encontrada no index.php";
        $solutions[] = "Adicionar rota '$route' no index.php";
    }
}

// 3. Verificar arquivos helpers
echo "🔧 VERIFICANDO HELPERS...\n";
if (!file_exists('public/helpers.php')) {
    $problems[] = "Arquivo helpers.php não existe";
    $solutions[] = "Criar arquivo helpers.php";
}

// 4. Verificar sidebar_unified.php
echo "📱 VERIFICANDO SIDEBAR...\n";
if (!file_exists('public/sidebar_unified.php')) {
    $problems[] = "Arquivo sidebar_unified.php não existe";
    $solutions[] = "Criar arquivo sidebar_unified.php";
}

// 5. Verificar conexão com banco
echo "🗄️  VERIFICANDO BANCO DE DADOS...\n";
try {
    require_once 'public/conexao.php';
    if (!isset($pdo)) {
        $problems[] = "Conexão com banco de dados falhou";
        $solutions[] = "Verificar configuração de conexão";
    }
} catch (Exception $e) {
    $problems[] = "Erro de conexão: " . $e->getMessage();
    $solutions[] = "Corrigir configuração de banco de dados";
}

// Exibir resultados
echo "\n📊 RESUMO DO DIAGNÓSTICO:\n";
echo "==========================\n";
echo "Problemas encontrados: " . count($problems) . "\n";
echo "Soluções necessárias: " . count($solutions) . "\n\n";

if (count($problems) > 0) {
    echo "❌ PROBLEMAS ENCONTRADOS:\n";
    echo "========================\n";
    foreach ($problems as $i => $problem) {
        echo ($i + 1) . ". $problem\n";
    }
    
    echo "\n✅ SOLUÇÕES PROPOSTAS:\n";
    echo "=====================\n";
    foreach ($solutions as $i => $solution) {
        echo ($i + 1) . ". $solution\n";
    }
} else {
    echo "✅ Nenhum problema encontrado!\n";
}

echo "\n🚀 PRÓXIMOS PASSOS:\n";
echo "==================\n";
echo "1. Executar correções automáticas\n";
echo "2. Testar todas as funcionalidades\n";
echo "3. Verificar se tudo está funcionando\n\n";

// Salvar diagnóstico
file_put_contents('diagnostic_report.txt', 
    "DIAGNÓSTICO DO SISTEMA - " . date('Y-m-d H:i:s') . "\n" .
    "Problemas: " . count($problems) . "\n" .
    "Soluções: " . count($solutions) . "\n\n" .
    "PROBLEMAS:\n" . implode("\n", $problems) . "\n\n" .
    "SOLUÇÕES:\n" . implode("\n", $solutions)
);

echo "📄 Relatório salvo em: diagnostic_report.txt\n";
echo "🎯 Sistema pronto para correção!\n";
?>

